<?php
/**
 * One-way pull: DreamHost production bakerysf → local bakerysf_local.
 *
 * Does NOT change app .env (stays on 127.0.0.1). Read-only against production.
 * Dump land in storage/dumps/ (gitignored). Does not echo passwords.
 *
 * Usage:
 *   C:\php\php.exe scripts/pull_prod_to_local.php
 *   C:\php\php.exe scripts/pull_prod_to_local.php --admin-password=YourLocalPass
 *   C:\php\php.exe scripts/pull_prod_to_local.php --skip-admin
 *
 * Requires:
 *   bakery/.env (local target)
 *   bakery/.env.production.pull (PROD_DB_* source)
 *   mysqldump + mysql on PATH (Scoop MariaDB shims)
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$skipAdmin = in_array('--skip-admin', $argv, true);
$adminPassword = getenv('LOCAL_ADMIN_PASSWORD') ?: '';
$adminEmail = getenv('LOCAL_ADMIN_EMAIL') ?: 'danny@sourflour.org';
$adminName = getenv('LOCAL_ADMIN_NAME') ?: 'Danny';
$adminRole = getenv('LOCAL_ADMIN_ROLE') ?: 'administrator';

foreach ($argv as $arg) {
    if (strpos($arg, '--admin-password=') === 0) {
        $adminPassword = substr($arg, strlen('--admin-password='));
    }
    if (strpos($arg, '--admin-email=') === 0) {
        $adminEmail = substr($arg, strlen('--admin-email='));
    }
}

$localEnv = $root . DIRECTORY_SEPARATOR . '.env';
$prodEnv = $root . DIRECTORY_SEPARATOR . '.env.production.pull';

if (!is_readable($localEnv)) {
    fwrite(STDERR, "Missing bakery/.env — copy from .env.example first.\n");
    exit(1);
}
if (!is_readable($prodEnv)) {
    fwrite(STDERR, "Missing bakery/.env.production.pull — copy from .env.production.pull.example and fill PROD_DB_*.\n");
    exit(1);
}

// Load prod pull first, then local (local DB_* must win if somehow duplicated)
bakery_load_env_file($prodEnv);
bakery_load_env_file($localEnv);

// Re-load LOCAL_ADMIN_* from prod pull file after local .env (optional overrides)
if ($adminPassword === '' && !empty($_ENV['LOCAL_ADMIN_PASSWORD'])) {
    $adminPassword = (string)$_ENV['LOCAL_ADMIN_PASSWORD'];
}
if (!empty($_ENV['LOCAL_ADMIN_EMAIL'])) {
    $adminEmail = (string)$_ENV['LOCAL_ADMIN_EMAIL'];
}
if (!empty($_ENV['LOCAL_ADMIN_NAME'])) {
    $adminName = (string)$_ENV['LOCAL_ADMIN_NAME'];
}
if (!empty($_ENV['LOCAL_ADMIN_ROLE'])) {
    $adminRole = (string)$_ENV['LOCAL_ADMIN_ROLE'];
}

try {
    $prodHost = bakery_env('PROD_DB_HOST');
    $prodPort = bakery_env('PROD_DB_PORT', '3306');
    $prodName = bakery_env('PROD_DB_NAME');
    $prodUser = bakery_env('PROD_DB_USER');
    $prodPass = bakery_env('PROD_DB_PASS');

    $localHost = bakery_env('DB_HOST');
    $localPort = bakery_env('DB_PORT', '3306');
    $localName = bakery_env('DB_NAME');
    $localUser = bakery_env('DB_USER');
    $localPass = bakery_env('DB_PASS');
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$prodHostLower = strtolower($prodHost);
$prodNameLower = strtolower($prodName);
$localHostLower = strtolower($localHost);
$localNameLower = strtolower($localName);

// Source must look like production
$prodLooksOk = (
    strpos($prodHostLower, 'sourflour') !== false ||
    strpos($prodHostLower, 'dreamhost') !== false ||
    $prodNameLower === 'bakerysf'
);
if (!$prodLooksOk) {
    fwrite(STDERR, "Refusing: PROD_DB_HOST/NAME do not look like production (expected sourflour/dreamhost or bakerysf).\n");
    exit(1);
}
if ($prodNameLower !== 'bakerysf' && strpos($prodNameLower, '_local') !== false) {
    fwrite(STDERR, "Refusing: PROD_DB_NAME looks local.\n");
    exit(1);
}

// Target must be local only
if (!in_array($localHostLower, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: DB_HOST must be 127.0.0.1 or localhost.\n");
    exit(1);
}
if (strpos($localHostLower, 'sourflour') !== false || strpos($localHostLower, 'dreamhost') !== false) {
    fwrite(STDERR, "Refusing: local DB_HOST looks like production.\n");
    exit(1);
}
if ($localNameLower === 'bakerysf' || (strpos($localNameLower, '_local') === false && strpos($localNameLower, 'test') === false)) {
    fwrite(STDERR, "Refusing: DB_NAME must be nonproduction (e.g. bakerysf_local).\n");
    exit(1);
}

function bakery_find_cli_tool($names) {
    $extra = [];
    $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
    if ($home !== '') {
        $extra[] = $home . DIRECTORY_SEPARATOR . 'scoop' . DIRECTORY_SEPARATOR . 'shims';
        $extra[] = $home . DIRECTORY_SEPARATOR . 'scoop' . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'mariadb' . DIRECTORY_SEPARATOR . 'current' . DIRECTORY_SEPARATOR . 'bin';
    }
    $path = getenv('PATH') ?: '';
    $dirs = array_merge($extra, explode(PATH_SEPARATOR, $path));
    foreach ($names as $name) {
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
            if (PHP_OS_FAMILY === 'Windows' && is_file($candidate . '.exe')) {
                return $candidate . '.exe';
            }
        }
    }
    return null;
}

$mysqldump = bakery_find_cli_tool(['mysqldump', 'mariadb-dump']);
$mysql = bakery_find_cli_tool(['mysql', 'mariadb']);
if (!$mysqldump || !$mysql) {
    fwrite(STDERR, "Need mysqldump and mysql on PATH (install Scoop MariaDB: scoop install mariadb).\n");
    exit(1);
}
echo "Using dump client: {$mysqldump}\n";
echo "Using import client: {$mysql}\n";

function bakery_pdo_connect($host, $port, $user, $pass, $dbname = null) {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($dbname !== null && $dbname !== '') {
        $dsn .= ";dbname={$dbname}";
    }
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 20,
    ]);
}

function bakery_table_counts(PDO $db, array $tables) {
    $out = [];
    foreach ($tables as $table) {
        try {
            $exists = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchColumn();
            if (!$exists) {
                $out[$table] = null;
                continue;
            }
            $out[$table] = (int)$db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (Throwable $e) {
            $out[$table] = null;
        }
    }
    return $out;
}

function bakery_run_sql_file(PDO $db, $path) {
    if (!is_readable($path)) {
        throw new RuntimeException("SQL file not readable: {$path}");
    }
    $sql = file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (strpos($trim, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    $statements = [];
    $current = '';
    $inString = false;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $ch = $buf[$i];
        if ($ch === "'" && ($i === 0 || $buf[$i - 1] !== '\\')) {
            $inString = !$inString;
            $current .= $ch;
            continue;
        }
        if ($ch === ';' && !$inString) {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
}

$spotTables = ['customers', 'products', 'orders', 'standing_orders', 'drivers', 'default_quantities', 'users'];

echo "Testing production connection ({$prodHost}/{$prodName})...\n";
try {
    $prodDb = bakery_pdo_connect($prodHost, $prodPort, $prodUser, $prodPass, $prodName);
} catch (Throwable $e) {
    fwrite(STDERR, "Production connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "If Access denied for user@YOUR_IP: whitelist your public IP in DreamHost\n");
    fwrite(STDERR, "MySQL Databases → user {$prodUser} → Allowable Hosts (keep %.dreamhost.com).\n");
    exit(1);
}
$prodCounts = bakery_table_counts($prodDb, $spotTables);
echo "Production spot counts:\n";
foreach ($prodCounts as $t => $c) {
    echo "  {$t}=" . ($c === null ? 'missing' : $c) . "\n";
}

echo "Testing local connection ({$localHost}/{$localName})...\n";
try {
    $localServer = bakery_pdo_connect($localHost, $localPort, $localUser, $localPass, null);
} catch (Throwable $e) {
    fwrite(STDERR, "Local MySQL connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Start local MariaDB: scripts/start_local_mariadb.ps1\n");
    exit(1);
}

$dumpDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps';
if (!is_dir($dumpDir) && !mkdir($dumpDir, 0775, true) && !is_dir($dumpDir)) {
    fwrite(STDERR, "Cannot create {$dumpDir}\n");
    exit(1);
}
$dumpFile = $dumpDir . DIRECTORY_SEPARATOR . 'bakerysf_prod_' . date('Ymd_His') . '.sql';
echo "Dumping production to storage/dumps/" . basename($dumpFile) . " ...\n";

$dumpCmd = [
    $mysqldump,
    '--host=' . $prodHost,
    '--port=' . $prodPort,
    '--user=' . $prodUser,
    '--password=' . $prodPass,
    '--single-transaction',
    '--triggers',
    '--hex-blob',
    '--default-character-set=utf8mb4',
    // DreamHost shared MySQL: untrusted cert + limited privileges
    '--skip-ssl-verify-server-cert',
    '--no-tablespaces',
    // Skip routines — bakerysf often lacks SHOW CREATE PROCEDURE
    '--skip-routines',
    $prodName,
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $dumpFile, 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($dumpCmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start mysqldump\n");
    exit(1);
}
fclose($pipes[0]);
$dumpErr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$dumpCode = proc_close($proc);
if ($dumpCode !== 0 || !is_readable($dumpFile) || filesize($dumpFile) < 100) {
    @unlink($dumpFile);
    fwrite(STDERR, "mysqldump failed (exit {$dumpCode}).\n");
    if ($dumpErr !== '') {
        // Redact password if somehow echoed
        $safe = str_replace($prodPass, '***', $dumpErr);
        fwrite(STDERR, $safe . "\n");
    }
    exit(1);
}
echo "Dump OK (" . round(filesize($dumpFile) / 1048576, 2) . " MiB)\n";

// Local bakery_local lacks SET USER — strip production DEFINER clauses from views/routines
echo "Stripping DEFINER clauses for local import...\n";
$dumpSql = file_get_contents($dumpFile);
if ($dumpSql === false) {
    fwrite(STDERR, "Cannot read dump file for DEFINER strip\n");
    exit(1);
}
$stripped = preg_replace('/\s*DEFINER=`[^`]+`@`[^`]+`/', '', $dumpSql);
if ($stripped === null) {
    fwrite(STDERR, "DEFINER strip regex failed\n");
    exit(1);
}
if (file_put_contents($dumpFile, $stripped) === false) {
    fwrite(STDERR, "Cannot write stripped dump\n");
    exit(1);
}
unset($dumpSql, $stripped);

$safeLocalName = str_replace('`', '``', $localName);
echo "Recreating local database {$localName}...\n";
$localServer->exec("DROP DATABASE IF EXISTS `{$safeLocalName}`");
$localServer->exec(
    "CREATE DATABASE `{$safeLocalName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

echo "Importing dump into {$localName}...\n";
$importCmd = [
    $mysql,
    '--host=' . $localHost,
    '--port=' . $localPort,
    '--user=' . $localUser,
    '--password=' . $localPass,
    '--default-character-set=utf8mb4',
    $localName,
];
$descriptors = [
    0 => ['file', $dumpFile, 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($importCmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start mysql import\n");
    exit(1);
}
$importOut = stream_get_contents($pipes[1]);
$importErr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$importCode = proc_close($proc);
if ($importCode !== 0) {
    fwrite(STDERR, "mysql import failed (exit {$importCode}).\n");
    if ($importErr !== '') {
        $safe = str_replace($localPass, '***', $importErr);
        fwrite(STDERR, $safe . "\n");
    }
    if ($importOut !== '') {
        fwrite(STDERR, $importOut . "\n");
    }
    exit(1);
}
echo "Import OK\n";

$localDb = bakery_pdo_connect($localHost, $localPort, $localUser, $localPass, $localName);

$usersExists = (bool)$localDb->query("SHOW TABLES LIKE 'users'")->fetchColumn();
$rolesExists = (bool)$localDb->query("SHOW TABLES LIKE 'roles'")->fetchColumn();
if (!$usersExists || !$rolesExists) {
    $authFile = $root . '/database/schema/002_auth.sql';
    echo "Applying auth schema (002_auth.sql)...\n";
    bakery_run_sql_file($localDb, $authFile);
} else {
    echo "Auth tables already present in dump; ensuring role seeds...\n";
    bakery_run_sql_file($localDb, $root . '/database/schema/002_auth.sql');
}

// Confirm views survived DEFINER strip + import
foreach (['v_daily_routes', 'v_dough_types_with_product_lines'] as $view) {
    $exists = (bool)$localDb->query('SHOW FULL TABLES WHERE Tables_in_' . str_replace('`', '``', $localName) . ' = ' . $localDb->quote($view))->fetch();
    // Fallback simpler check
    if (!$exists) {
        try {
            $localDb->query('SELECT 1 FROM `' . str_replace('`', '``', $view) . '` LIMIT 1');
            $exists = true;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    echo $exists ? "View OK: {$view}\n" : "View MISSING: {$view}\n";
}

$migrate = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'run_migrations.php';
if (is_readable($migrate)) {
    echo "Applying post-import migrations...\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($migrate), $migrateCode);
    if ($migrateCode !== 0) {
        fwrite(STDERR, "Warning: run_migrations.php exited with code {$migrateCode}\n");
    }
}

if (!$skipAdmin) {
    $ensure = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_local_admin.php';
    $ensureCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensure);
    if ($adminPassword !== '') {
        $ensureCmd .= ' --password=' . escapeshellarg($adminPassword);
    }
    if ($adminEmail !== '') {
        $ensureCmd .= ' --email=' . escapeshellarg($adminEmail);
    }
    if ($adminName !== '') {
        $ensureCmd .= ' --name=' . escapeshellarg($adminName);
    }
    if ($adminRole !== '') {
        $ensureCmd .= ' --role=' . escapeshellarg($adminRole);
    }
    passthru($ensureCmd, $ensureCode);
    if ($ensureCode !== 0) {
        fwrite(STDERR, "Failed to ensure local admin. Set LOCAL_ADMIN_PASSWORD or pass --admin-password=...\n");
        exit(1);
    }
} else {
    echo "Skipped local admin upsert (--skip-admin)\n";
}

$localCounts = bakery_table_counts($localDb, $spotTables);
echo "\nSpot-check counts (prod → local):\n";
$mismatch = false;
foreach ($spotTables as $t) {
    $p = $prodCounts[$t];
    $l = $localCounts[$t];
    $pLabel = $p === null ? 'missing' : (string)$p;
    $lLabel = $l === null ? 'missing' : (string)$l;
    $note = '';
    if ($t === 'users') {
        $note = ' (local auth may differ)';
    } elseif ($p !== null && $l !== null && $p !== $l) {
        $note = ' MISMATCH';
        $mismatch = true;
    } elseif ($p !== null && $l !== null && $p === $l) {
        $note = ' OK';
    }
    echo "  {$t}: {$pLabel} → {$lLabel}{$note}\n";
}

echo "\nDone. App .env unchanged (still local-only).\n";
echo "Verify: C:\\php\\php.exe scripts\\verify_local_env.php\n";
echo "Login: http://localhost:8080/bakery/login.php\n";
echo "Dump kept at: storage/dumps/" . basename($dumpFile) . " (gitignored, contains PII)\n";

if ($mismatch) {
    fwrite(STDERR, "Warning: one or more core table counts differ — inspect dump/import logs.\n");
    exit(2);
}
exit(0);

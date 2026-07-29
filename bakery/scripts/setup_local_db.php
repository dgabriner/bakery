<?php
/**
 * Create/reset bakerysf_local from sanitized schema + fixtures.
 * CLI only. Refuses non-local hosts/names. Does not print passwords.
 *
 * Usage:
 *   C:\php\php.exe bakery/scripts/setup_local_db.php
 *   C:\php\php.exe bakery/scripts/setup_local_db.php --reset
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (!is_readable($envPath)) {
    fwrite(STDERR, "Missing bakery/.env — copy from .env.example first.\n");
    exit(1);
}
bakery_load_env_file($envPath);

$host = $_ENV['DB_HOST'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$hostLower = strtolower($host);
$nameLower = strtolower($name);

if (strpos($hostLower, 'sourflour') !== false || strpos($hostLower, 'dreamhost') !== false) {
    fwrite(STDERR, "Refusing: DB_HOST looks like production.\n");
    exit(1);
}
if (!in_array($hostLower, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: DB_HOST must be 127.0.0.1 or localhost for this script.\n");
    exit(1);
}
if ($nameLower === 'bakerysf' || (strpos($nameLower, '_local') === false && strpos($nameLower, 'test') === false)) {
    fwrite(STDERR, "Refusing: DB_NAME must be a nonproduction name like bakerysf_local.\n");
    exit(1);
}

$reset = in_array('--reset', $argv, true);

try {
    $server = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($reset) {
        echo "WARNING: --reset will DROP bakerysf_local and destroy any production pull data.\n";
        $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $name) . '`');
        echo "Dropped database {$name}\n";
    }

    $server->exec(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    echo "Ensured database {$name}\n";

    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $schemaFile = $root . '/database/schema/001_baseline.sql';
    $fixtureFile = $root . '/database/fixtures/001_demo_data.sql';

    run_sql_file($db, $schemaFile);
    echo "Applied schema: database/schema/001_baseline.sql\n";

    run_sql_file($db, $fixtureFile);
    echo "Applied fixtures: database/fixtures/001_demo_data.sql\n";

    $authFile = $root . '/database/schema/002_auth.sql';
    if (is_readable($authFile)) {
        run_sql_file($db, $authFile);
        echo "Applied schema: database/schema/002_auth.sql\n";
    }

    $customers = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $products = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    echo "Fixture counts: customers={$customers}, products={$products}\n";

    // Restore durable local admin if LOCAL_ADMIN_PASSWORD is configured
    $ensure = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_local_admin.php';
    if (is_readable($ensure)) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensure);
        passthru($cmd, $ensureCode);
        if ($ensureCode !== 0) {
            echo "Note: local admin not ensured (set LOCAL_ADMIN_PASSWORD in .env). Seed fixtures: scripts/seed_local_users.php\n";
        }
    } else {
        echo "Local database ready. Run scripts/seed_local_users.php for login accounts.\n";
    }
    $migrate = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'run_migrations.php';
    if (is_readable($migrate)) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($migrate), $migrateCode);
        if ($migrateCode !== 0) {
            fwrite(STDERR, "Warning: run_migrations.php exited with code {$migrateCode}\n");
        }
    }

    if ($reset) {
        echo "WARNING: --reset replaced the DB with demo fixtures. Re-run scripts/pull_prod_to_local.php to restore production data.\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Setup failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Is MySQL/MariaDB running locally? See docs/LOCAL_SETUP.md\n");
    exit(1);
}

/**
 * Execute a multi-statement SQL file.
 * Splits on semicolons outside single-quoted strings so COMMENT '...;...' is safe.
 */
function run_sql_file(PDO $db, $path) {
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

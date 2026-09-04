<?php
/**
 * Create/reset bakerysf_local from sanitized schema + fixtures.
 * CLI only. Refuses non-local hosts/names. Does not print passwords.
 *
 * Usage:
 *   C:\php\php.exe bakery/scripts/setup_local_db.php --reset --force-reset --database=bakerysf_test
 *
 * bakerysf_local is the production mirror and is never loaded with demo fixtures.
 * Isolated tests must pass --database=bakerysf_test.
 */
define('ACCESS_ALLOWED', true);

function bakery_prod_data_marker_path($root) {
    return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.prod_data_active';
}

function bakery_refuse_reset_without_force($root, $argv) {
    if (!in_array('--reset', $argv, true) || in_array('--force-reset', $argv, true)) {
        return;
    }
    $marker = bakery_prod_data_marker_path($root);
    if (is_readable($marker)) {
        fwrite(STDERR, "Refusing --reset: production pull data is active (see storage/.prod_data_active).\n");
        fwrite(STDERR, "Restore real data: scripts/pull_prod_to_local.php\n");
        fwrite(STDERR, "Run tests (demo fixtures): add --force-reset to this command.\n");
        exit(1);
    }
}

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

// Prefer $_ENV, then getenv — CI injects DB_* via process env; PHP may omit them from $_ENV.
$host = (string)($_ENV['DB_HOST'] ?? (getenv('DB_HOST') !== false ? getenv('DB_HOST') : ''));
$port = (string)($_ENV['DB_PORT'] ?? (getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306'));
$name = (string)($_ENV['DB_NAME'] ?? (getenv('DB_NAME') !== false ? getenv('DB_NAME') : ''));
$user = (string)($_ENV['DB_USER'] ?? (getenv('DB_USER') !== false ? getenv('DB_USER') : ''));
$pass = (string)($_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
if ($port === '') {
    $port = '3306';
}

foreach ($argv as $arg) {
    if (strpos($arg, '--database=') === 0) {
        $name = substr($arg, strlen('--database='));
    }
}

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
$forceReset = in_array('--force-reset', $argv, true);

if ($nameLower === 'bakerysf_local') {
    fwrite(STDERR, "Refusing: bakerysf_local is the production mirror and cannot be loaded with demo fixtures.\n");
    fwrite(STDERR, "Refresh real data: php scripts/pull_prod_to_local.php\n");
    fwrite(STDERR, "Isolated tests: php scripts/setup_local_db.php --reset --force-reset --database=bakerysf_test\n");
    exit(1);
}
if (strpos($nameLower, 'test') === false && strpos($nameLower, 'dev') === false) {
    fwrite(STDERR, "Refusing: setup_local_db.php only builds isolated test/dev databases, not the app mirror.\n");
    exit(1);
}

bakery_refuse_reset_without_force($root, $argv);

putenv('DB_NAME=' . $name);
$_ENV['DB_NAME'] = $name;
$_SERVER['DB_NAME'] = $name;

try {
    $server = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    require_once $root . '/includes/config.php';
    require_once $root . '/includes/database.php';
    require_once $root . '/includes/test_target_guard.php';
    bakery_assert_local_test_target($server, true);

    if ($reset) {
        echo "WARNING: resetting isolated database {$name} with demo fixtures.\n";
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

    // Restore durable local admin / staff codes
    $ensure = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_local_admin.php';
    if (is_readable($ensure)) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensure);
        passthru($cmd, $ensureCode);
        if ($ensureCode !== 0) {
            echo "Note: local admin not ensured (defaults to code 9741 via ensure_local_admin). Seed: scripts/seed_local_users.php\n";
        }
    }
    $staff = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_staff_codes.php';
    if (is_readable($staff)) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($staff), $staffCode);
        if ($staffCode !== 0) {
            echo "Note: staff codes not ensured. Run scripts/ensure_staff_codes.php\n";
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

    // Stable fictional portal fixture for isolated local portal/account tests.
    if (table_exists($db, 'customers') && column_exists($db, 'customers', 'portal_enabled')) {
        $fixture = $db->prepare(
            "UPDATE customers
             SET portal_enabled = 1, portal_phone = '5550101', portal_code = '0001'
             WHERE id = 1"
        );
        $fixture->execute();
        echo "Ensured fictional portal test fixture on customer #1\n";
    }

    if ($reset) {
        echo "Isolated test database {$name} reset with demo fixtures. App mirror bakerysf_local was not changed.\n";
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

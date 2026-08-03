<?php
/**
 * Switch local app between bakerysf_local and live production DB.
 *
 * Usage:
 *   C:\php\php.exe scripts/switch_db.php          # show current mode
 *   C:\php\php.exe scripts/switch_db.php local    # USE_PROD_DB=false
 *   C:\php\php.exe scripts/switch_db.php prod     # USE_PROD_DB=true
 *
 * Does not print secrets. Requires bakery/.env; prod mode needs .env.production.pull.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$pullPath = $root . DIRECTORY_SEPARATOR . '.env.production.pull';

if (!is_readable($envPath)) {
    fwrite(STDERR, "Missing bakery/.env\n");
    exit(1);
}

$mode = isset($argv[1]) ? strtolower(trim($argv[1])) : '';

function bakery_switch_read_flag($envPath) {
    $contents = file_get_contents($envPath);
    if ($contents === false) {
        return false;
    }
    if (preg_match('/^\s*USE_PROD_DB\s*=\s*(\S+)/mi', $contents, $m)) {
        return in_array(strtolower($m[1]), ['1', 'true', 'yes', 'on'], true);
    }
    return false;
}

function bakery_switch_set_flag($envPath, $enabled) {
    $contents = file_get_contents($envPath);
    if ($contents === false) {
        throw new RuntimeException('Cannot read .env');
    }
    $value = $enabled ? 'true' : 'false';
    $line = 'USE_PROD_DB=' . $value;
    if (preg_match('/^\s*USE_PROD_DB\s*=.*$/mi', $contents)) {
        $contents = preg_replace('/^\s*USE_PROD_DB\s*=.*$/mi', $line, $contents, 1);
    } else {
        // Insert after APP_ENV block if present, else at top after first line
        if (preg_match('/^APP_ENV=.*$/mi', $contents)) {
            $contents = preg_replace('/^(APP_ENV=.*)$/mi', "$1\n" . $line, $contents, 1);
        } else {
            $contents = $line . "\n" . $contents;
        }
    }
    if (file_put_contents($envPath, $contents) === false) {
        throw new RuntimeException('Cannot write .env');
    }
}

$current = bakery_switch_read_flag($envPath);

if ($mode === '') {
    echo "Current DB mode: " . ($current ? 'prod (live DreamHost)' : 'local (bakerysf_local)') . "\n";
    echo "  USE_PROD_DB=" . ($current ? 'true' : 'false') . "\n";
    echo "Switch with: php scripts/switch_db.php local|prod\n";
    exit(0);
}

if (!in_array($mode, ['local', 'prod', 'production'], true)) {
    fwrite(STDERR, "Usage: php scripts/switch_db.php [local|prod]\n");
    exit(1);
}

$wantProd = ($mode === 'prod' || $mode === 'production');

if ($wantProd) {
    if (!is_readable($pullPath)) {
        fwrite(STDERR, "Missing bakery/.env.production.pull — copy from .env.production.pull.example and set PROD_DB_*.\n");
        exit(1);
    }
    echo "*** WARNING: Local app will write to the LIVE production database. ***\n";
    echo "Whitelist your public IP in DreamHost MySQL Allowable Hosts if needed.\n";
}

try {
    bakery_switch_set_flag($envPath, $wantProd);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Switched to " . ($wantProd ? 'prod' : 'local') . ".\n";
echo "  USE_PROD_DB=" . ($wantProd ? 'true' : 'false') . "\n";
echo "Restart is not required for PHP built-in server (reads .env each request).\n";
if ($wantProd) {
    echo "Banner should show: LOCAL APP → LIVE PRODUCTION DB\n";
    echo "Login uses production users (not local seed passwords).\n";
} else {
    echo "Banner should show: LOCAL ENVIRONMENT — bakerysf_local\n";
}
exit(0);

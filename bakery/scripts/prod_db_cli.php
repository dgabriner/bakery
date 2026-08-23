<?php
/**
 * Shared CLI helpers for production ↔ local MySQL sync scripts.
 * CLI only — not for web requests.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

function prod_db_root() {
    return dirname(__DIR__);
}

function prod_db_load_envs($root) {
    $localEnv = $root . DIRECTORY_SEPARATOR . '.env';
    $prodEnv = $root . DIRECTORY_SEPARATOR . '.env.production.pull';
    if (!is_readable($localEnv)) {
        throw new RuntimeException('Missing bakery/.env — copy from .env.example first.');
    }
    if (!is_readable($prodEnv)) {
        throw new RuntimeException('Missing bakery/.env.production.pull — copy from .env.production.pull.example and fill PROD_DB_*.');
    }
    require_once $root . '/includes/env_loader.php';
    bakery_load_env_file($prodEnv);
    bakery_load_env_file($localEnv);
    return [
        'prod' => [
            'host' => bakery_env('PROD_DB_HOST'),
            'port' => bakery_env('PROD_DB_PORT', '3306'),
            'name' => bakery_env('PROD_DB_NAME'),
            'user' => bakery_env('PROD_DB_USER'),
            'pass' => bakery_env('PROD_DB_PASS'),
        ],
        'local' => [
            'host' => bakery_env('DB_HOST'),
            'port' => bakery_env('DB_PORT', '3306'),
            'name' => bakery_env('DB_NAME'),
            'user' => bakery_env('DB_USER'),
            'pass' => bakery_env('DB_PASS'),
        ],
    ];
}

function prod_db_validate_targets(array $prod, array $local) {
    $prodHostLower = strtolower($prod['host']);
    $prodNameLower = strtolower($prod['name']);
    $localHostLower = strtolower($local['host']);
    $localNameLower = strtolower($local['name']);

    $prodLooksOk = (
        strpos($prodHostLower, 'sourflour') !== false ||
        strpos($prodHostLower, 'dreamhost') !== false ||
        $prodNameLower === 'bakerysf'
    );
    if (!$prodLooksOk) {
        throw new RuntimeException('Refusing: PROD_DB_HOST/NAME do not look like production.');
    }
    if ($prodNameLower !== 'bakerysf' && strpos($prodNameLower, '_local') !== false) {
        throw new RuntimeException('Refusing: PROD_DB_NAME looks local.');
    }
    if (!in_array($localHostLower, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing: DB_HOST must be 127.0.0.1 or localhost.');
    }
    if (strpos($localHostLower, 'sourflour') !== false || strpos($localHostLower, 'dreamhost') !== false) {
        throw new RuntimeException('Refusing: local DB_HOST looks like production.');
    }
    if ($localNameLower === 'bakerysf' || (strpos($localNameLower, '_local') === false && strpos($localNameLower, 'test') === false)) {
        throw new RuntimeException('Refusing: DB_NAME must be nonproduction (e.g. bakerysf_local).');
    }
}

function prod_db_find_cli_tool(array $names) {
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

function prod_db_pdo_connect($host, $port, $user, $pass, $dbname = null) {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($dbname !== null && $dbname !== '') {
        $dsn .= ";dbname={$dbname}";
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 15;
    }
    return new PDO($dsn, $user, $pass, $options);
}

function prod_db_table_counts(PDO $db, array $tables) {
    $out = [];
    foreach ($tables as $table) {
        try {
            $exists = $db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetchColumn();
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

function prod_db_ensure_dump_dir($root) {
    $dumpDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps';
    if (!is_dir($dumpDir) && !mkdir($dumpDir, 0775, true) && !is_dir($dumpDir)) {
        throw new RuntimeException("Cannot create {$dumpDir}");
    }
    return $dumpDir;
}

/** Read client capabilities once so old DreamHost tools do not receive new-only flags. */
function prod_db_cli_supports_option(string $binary, string $option): bool {
    static $helpByBinary = [];
    if (!array_key_exists($binary, $helpByBinary)) {
        $proc = proc_open([$binary, '--help'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $helpByBinary[$binary] = '';
        } else {
            fclose($pipes[0]);
            $helpByBinary[$binary] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }
    return stripos((string)$helpByBinary[$binary], ltrim($option, '-')) !== false;
}

/**
 * @return string path to dump file
 */
function prod_db_mysqldump(array $cfg, $mysqldump, $outFile, array $options = []) {
    $ignoreTables = $options['ignore_tables'] ?? [];
    $cmd = [
        $mysqldump,
        '--host=' . $cfg['host'],
        '--port=' . $cfg['port'],
        '--user=' . $cfg['user'],
        '--password=' . $cfg['pass'],
        '--single-transaction',
        '--triggers',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--skip-routines',
        '--add-drop-table',
    ];
    if (prod_db_cli_supports_option($mysqldump, '--ssl-verify-server-cert')) {
        $cmd[] = '--skip-ssl-verify-server-cert';
    }
    if (prod_db_cli_supports_option($mysqldump, '--no-tablespaces')) {
        $cmd[] = '--no-tablespaces';
    }
    foreach ($ignoreTables as $table) {
        $cmd[] = '--ignore-table=' . $cfg['name'] . '.' . $table;
    }
    $cmd[] = $cfg['name'];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start mysqldump');
    }
    fclose($pipes[0]);
    $dumpErr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $dumpCode = proc_close($proc);
    if ($dumpCode !== 0 || !is_readable($outFile) || filesize($outFile) < 100) {
        @unlink($outFile);
        $safe = str_replace($cfg['pass'], '***', $dumpErr);
        throw new RuntimeException("mysqldump failed (exit {$dumpCode}): {$safe}");
    }
    return $outFile;
}

function prod_db_mysql_import(array $cfg, $mysql, $sqlFile) {
    $cmd = [
        $mysql,
        '--host=' . $cfg['host'],
        '--port=' . $cfg['port'],
        '--user=' . $cfg['user'],
        '--password=' . $cfg['pass'],
        '--default-character-set=utf8mb4',
        $cfg['name'],
    ];
    if (prod_db_cli_supports_option($mysql, '--ssl-verify-server-cert')) {
        array_splice($cmd, count($cmd) - 1, 0, ['--skip-ssl-verify-server-cert']);
    }
    $descriptors = [
        0 => ['file', $sqlFile, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start mysql import');
    }
    $importOut = stream_get_contents($pipes[1]);
    $importErr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $importCode = proc_close($proc);
    if ($importCode !== 0) {
        $safe = str_replace($cfg['pass'], '***', $importErr);
        throw new RuntimeException("mysql import failed (exit {$importCode}): {$safe} {$importOut}");
    }
}

function prod_db_strip_definer($sqlFile) {
    $dumpSql = file_get_contents($sqlFile);
    if ($dumpSql === false) {
        throw new RuntimeException("Cannot read dump: {$sqlFile}");
    }
    $stripped = preg_replace('/\s*DEFINER=`[^`]+`@`[^`]+`/', '', $dumpSql);
    if ($stripped === null) {
        throw new RuntimeException('DEFINER strip regex failed');
    }
    if (file_put_contents($sqlFile, $stripped) === false) {
        throw new RuntimeException("Cannot write stripped dump: {$sqlFile}");
    }
}

function prod_db_rewrite_db_name($sqlFile, $fromName, $toName) {
    if ($fromName === $toName) {
        return;
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException("Cannot read dump for DB rewrite: {$sqlFile}");
    }
    $sql = str_replace('`' . $fromName . '`.', '`' . $toName . '`.', $sql);
    $sql = str_replace('DATABASE `' . $fromName . '`', 'DATABASE `' . $toName . '`', $sql);
    $sql = str_replace('USE `' . $fromName . '`', 'USE `' . $toName . '`', $sql);
    if (file_put_contents($sqlFile, $sql) === false) {
        throw new RuntimeException("Cannot write DB-rewritten dump: {$sqlFile}");
    }
}

function prod_db_spot_tables() {
    return ['customers', 'products', 'standing_orders', 'drivers', 'default_quantities', 'users', 'daily_orders'];
}

function prod_db_push_exclude_tables($includeAuth) {
    $exclude = ['schema_migrations'];
    if (!$includeAuth) {
        $exclude[] = 'users';
        $exclude[] = 'roles';
        $exclude[] = 'permissions';
        $exclude[] = 'role_permissions';
    }
    return $exclude;
}

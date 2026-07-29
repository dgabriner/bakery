<?php
/**
 * Compare production vs local table/view inventory (counts only, no row data).
 * Usage: C:\php\php.exe scripts/compare_prod_local.php
 */
define('ACCESS_ALLOWED', true);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
bakery_load_env_file($root . '/.env.production.pull');
bakery_load_env_file($root . '/.env');

function connect($host, $port, $user, $pass, $name) {
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$prod = connect(
    bakery_env('PROD_DB_HOST'),
    bakery_env('PROD_DB_PORT', '3306'),
    bakery_env('PROD_DB_USER'),
    bakery_env('PROD_DB_PASS'),
    bakery_env('PROD_DB_NAME')
);
$local = connect(
    bakery_env('DB_HOST'),
    bakery_env('DB_PORT', '3306'),
    bakery_env('DB_USER'),
    bakery_env('DB_PASS'),
    bakery_env('DB_NAME')
);

function inventory(PDO $db) {
    $rows = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $out = [];
    foreach ($rows as $r) {
        $out[$r[0]] = $r[1];
    }
    return $out;
}

function count_table(PDO $db, $table) {
    try {
        return (int)$db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$pm = inventory($prod);
$lm = inventory($local);
$all = array_unique(array_merge(array_keys($pm), array_keys($lm)));
sort($all);

echo "table\tprod\tlocal\tprod_n\tlocal_n\tstatus\n";
$gaps = 0;
foreach ($all as $t) {
    $pt = $pm[$t] ?? 'MISSING';
    $lt = $lm[$t] ?? 'MISSING';
    $pc = ($pt === 'BASE TABLE') ? count_table($prod, $t) : '-';
    $lc = ($lt === 'BASE TABLE') ? count_table($local, $t) : '-';
    $status = 'ok';
    if ($t === 'users' || $t === 'roles' || $t === 'permissions' || $t === 'role_permissions') {
        $status = 'local-auth';
    } elseif ($pt !== $lt) {
        $status = 'GAP';
        $gaps++;
    } elseif ($pt === 'BASE TABLE' && $pc !== $lc) {
        $status = 'COUNT_DIFF';
        $gaps++;
    }
    $pcLabel = $pc === null ? 'err' : $pc;
    $lcLabel = $lc === null ? 'err' : $lc;
    echo "{$t}\t{$pt}\t{$lt}\t{$pcLabel}\t{$lcLabel}\t{$status}\n";
}
echo $gaps === 0 ? "SUMMARY: no structural/count gaps (auth tables excluded)\n" : "SUMMARY: {$gaps} gap(s)\n";
exit($gaps === 0 ? 0 : 2);

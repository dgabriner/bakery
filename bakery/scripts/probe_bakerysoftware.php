<?php
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require $root . '/includes/env_loader.php';
bakery_load_env_file($root . '/.env.staging.dreamhost');
$host = bakery_env('DB_HOST');
$name = bakery_env('DB_NAME');
$user = bakery_env('DB_USER');
$pass = bakery_env('DB_PASS');
if (strtolower($name) !== 'bakerysoftware') {
    fwrite(STDERR, "Refusing probe: not bakerysoftware\n");
    exit(1);
}
if (strtolower($name) === 'bakerysf') {
    fwrite(STDERR, "Refusing probe: bakerysf\n");
    exit(1);
}
echo "name={$name}\n";
$pdo = new PDO(
    "mysql:host={$host};port=3306;dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]
);
echo 'database=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'table_count=' . count($tables) . "\n";
foreach (['customers', 'products', 'users', 'standing_orders', 'daily_orders'] as $table) {
    $exists = in_array($table, $tables, true);
    if (!$exists) {
        echo "{$table}=missing\n";
        continue;
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
    echo "{$table}={$count}\n";
}

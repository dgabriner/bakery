<?php
/** Apply the unique 4-digit customer portal-code index to production. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);
    $index = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "customers" AND INDEX_NAME = ?'
    );
    $index->execute(['uq_customers_portal_code']);
    if ((int)$index->fetchColumn() > 0) {
        echo "OK: unique customer portal codes are installed on production.\n";
        exit(0);
    }
    $duplicates = $db->query(
        'SELECT portal_code FROM customers
         WHERE portal_code IS NOT NULL AND portal_code <> ""
         GROUP BY portal_code HAVING COUNT(*) > 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($duplicates) {
        throw new RuntimeException('Duplicate portal codes must be resolved before a unique index can be added.');
    }
    if (!in_array('--confirm', $argv, true)) {
        echo "PENDING: portal codes are unique but the enforcing index is missing.\n";
        echo "Rerun with --confirm to apply database/schema/043_unique_customer_portal_codes.sql.\n";
        exit(2);
    }
    $db->exec('CREATE UNIQUE INDEX uq_customers_portal_code ON customers (portal_code)');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')
        ->execute(['043_unique_customer_portal_codes']);
    echo "OK: unique customer portal codes applied to production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

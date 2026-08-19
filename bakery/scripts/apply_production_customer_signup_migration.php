<?php
/**
 * Apply the phone + PIN signup schema to the configured production database.
 *
 * Usage:
 *   php scripts/apply_production_customer_signup_migration.php
 *   php scripts/apply_production_customer_signup_migration.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';

function customer_signup_prod_column_exists(PDO $db, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "customers" AND COLUMN_NAME = ?'
    );
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function customer_signup_prod_index_exists(PDO $db, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "customers" AND INDEX_NAME = ?'
    );
    $stmt->execute([$index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);

    $columnsReady = customer_signup_prod_column_exists($db, 'portal_phone_key')
        && customer_signup_prod_column_exists($db, 'portal_code_hash');
    $indexReady = customer_signup_prod_index_exists($db, 'uq_customers_portal_phone_key');
    if ($columnsReady && $indexReady) {
        echo "OK: phone + PIN signup schema is installed on production.\n";
        exit(0);
    }

    echo "PENDING: phone + PIN signup schema needs ";
    echo $columnsReady ? 'no columns' : 'customer columns';
    echo $indexReady ? '' : ($columnsReady ? ' and unique phone index' : ' and unique phone index');
    echo ".\n";
    if (!in_array('--confirm', $argv, true)) {
        echo "Rerun with --confirm to apply database/schema/042_customer_phone_pin_signup.sql.\n";
        exit(2);
    }

    if (!customer_signup_prod_column_exists($db, 'portal_phone_key')) {
        $db->exec('ALTER TABLE customers ADD COLUMN portal_phone_key CHAR(10) NULL DEFAULT NULL AFTER portal_phone');
        echo "Added customers.portal_phone_key\n";
    }
    if (!customer_signup_prod_column_exists($db, 'portal_code_hash')) {
        $db->exec('ALTER TABLE customers ADD COLUMN portal_code_hash VARCHAR(255) NULL DEFAULT NULL AFTER portal_code');
        echo "Added customers.portal_code_hash\n";
    }
    if (!customer_signup_prod_index_exists($db, 'uq_customers_portal_phone_key')) {
        $db->exec('CREATE UNIQUE INDEX uq_customers_portal_phone_key ON customers (portal_phone_key)');
        echo "Added uq_customers_portal_phone_key\n";
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')
        ->execute(['042_customer_phone_pin_signup']);
    echo "OK: phone + PIN signup schema applied to production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

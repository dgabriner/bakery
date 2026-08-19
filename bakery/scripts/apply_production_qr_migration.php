<?php
/**
 * Apply only migration 029 to the configured production database.
 *
 * Usage:
 *   php scripts/apply_production_qr_migration.php          # read-only status
 *   php scripts/apply_production_qr_migration.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);

    $check = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $check->execute(['customer_qr_login_invites']);
    if ((int)$check->fetchColumn() > 0) {
        echo "OK: customer_qr_login_invites is already installed on production.\n";
        exit(0);
    }

    if (!in_array('--confirm', $argv, true)) {
        echo "PENDING: customer_qr_login_invites is missing on production.\n";
        echo "Rerun with --confirm to apply only database/schema/029_customer_qr_login.sql.\n";
        exit(2);
    }

    $schemaPath = $root . '/database/schema/029_customer_qr_login.sql';
    $sql = file_get_contents($schemaPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Migration 029 could not be read.');
    }
    $db->exec($sql);

    $check->execute(['customer_qr_login_invites']);
    if ((int)$check->fetchColumn() !== 1) {
        throw new RuntimeException('Migration completed without creating the expected table.');
    }
    echo "OK: migration 029 applied to production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

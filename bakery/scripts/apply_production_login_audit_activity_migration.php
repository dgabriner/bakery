<?php
/**
 * Apply only migration 036 to the configured production database.
 *
 * Usage:
 *   php scripts/apply_production_login_audit_activity_migration.php          # read-only status
 *   php scripts/apply_production_login_audit_activity_migration.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';
require_once $root . '/includes/schema_sql.php';

function prod_login_audit_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function prod_login_audit_migration_applied(PDO $db, string $id): bool
{
    $stmt = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    );
    if ((int)$stmt->fetchColumn() === 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);

    $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (strtolower($actual) !== 'bakerysf') {
        throw new RuntimeException('Refusing: connected database is not bakerysf.');
    }

    echo "Production: {$prod['name']}@{$prod['host']}\n";

    $auditReady = prod_login_audit_table_exists($db, 'login_audit');
    $activityReady = prod_login_audit_table_exists($db, 'login_audit_activity');
    $marked = prod_login_audit_migration_applied($db, '036_login_audit_activity');

    echo 'login_audit: ' . ($auditReady ? 'yes' : 'NO') . PHP_EOL;
    echo 'login_audit_activity: ' . ($activityReady ? 'yes' : 'NO') . PHP_EOL;
    echo '036 marked applied: ' . ($marked ? 'yes' : 'no') . PHP_EOL;

    if ($activityReady) {
        if (!$marked) {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    id VARCHAR(64) NOT NULL PRIMARY KEY,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')
                ->execute(['036_login_audit_activity']);
            echo "OK: login_audit_activity already installed; marked 036 applied.\n";
        } else {
            echo "OK: login_audit_activity is already installed on production.\n";
        }
        exit(0);
    }

    if (!$auditReady) {
        throw new RuntimeException('login_audit is missing on production; 036 cannot be applied until 027 exists.');
    }

    if (!in_array('--confirm', $argv, true)) {
        echo "PENDING: login_audit_activity is missing on production.\n";
        echo "Rerun with --confirm to apply only database/schema/036_login_audit_activity.sql.\n";
        exit(2);
    }

    $schemaPath = $root . '/database/schema/036_login_audit_activity.sql';
    if (!is_readable($schemaPath)) {
        throw new RuntimeException('Missing 036_login_audit_activity.sql');
    }
    bakery_run_sql_file($db, $schemaPath);

    if (!prod_login_audit_table_exists($db, 'login_audit_activity')) {
        throw new RuntimeException('Migration completed without creating login_audit_activity.');
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')
        ->execute(['036_login_audit_activity']);

    echo "OK: migration 036 applied to production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * Apply Live ops schema catch-up (037–047 / 051) to bakerysf.
 *
 * Usage:
 *   php scripts/apply_production_ops_catchup.php
 *   php scripts/apply_production_ops_catchup.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/hosted_migration_approval.php';

$confirm = in_array('--confirm', $argv, true);
$sqlFile = $root . '/database/schema/051_live_ops_catchup.sql';
$migrationId = '051_live_ops_catchup';
$historicalIds = [
    '037_route_closeout',
    '038_manager_exception_and_delivery_recovery',
    '041_sfb_studio_clock',
    '044_agent_homebase',
    '046_assignment_cancelled_status',
    '047_unique_dated_route_positions',
    $migrationId,
];

function ops_catchup_table_exists(PDO $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\''
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ops_catchup_column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ops_catchup_index_exists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function ops_catchup_enum_has(PDO $db, string $table, string $column, string $value): bool {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $type = strtolower((string)$stmt->fetchColumn());
    return strpos($type, "'" . strtolower($value) . "'") !== false;
}

function ops_catchup_migration_applied(PDO $db, string $id): bool {
    if (!ops_catchup_table_exists($db, 'schema_migrations')) {
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
    if (strtolower((string)$prod['name']) !== 'bakerysf') {
        throw new RuntimeException('Refusing: PROD_DB_NAME must be bakerysf.');
    }
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);
    $selected = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($selected !== 'bakerysf') {
        throw new RuntimeException('Refusing: connected database is not bakerysf.');
    }

    echo "Production: {$prod['name']}@{$prod['host']}\n\n";

    if (!is_readable($sqlFile)) {
        throw new RuntimeException('Missing ' . $sqlFile);
    }
    [$safe, $safeMessage] = bakery_hosted_migration_sql_safe((string)file_get_contents($sqlFile));
    if (!$safe) {
        throw new RuntimeException('Catch-up SQL refused: ' . $safeMessage);
    }

    $checks = [
        'movement_type has waste' => ops_catchup_enum_has($db, 'inventory_movements', 'movement_type', 'waste'),
        'driver_load_items.wasted_quantity' => ops_catchup_column_exists($db, 'driver_load_items', 'wasted_quantity'),
        'manager_exception_work' => ops_catchup_table_exists($db, 'manager_exception_work'),
        'delivery_recovery_cases' => ops_catchup_table_exists($db, 'delivery_recovery_cases'),
        'sfb_studio_settings' => ops_catchup_table_exists($db, 'sfb_studio_settings'),
        'agent_bugs' => ops_catchup_table_exists($db, 'agent_bugs'),
        'delivery_status has cancelled' => ops_catchup_enum_has($db, 'daily_order_assignments', 'delivery_status', 'cancelled'),
        'uq_assignment_driver_date_route_order' => ops_catchup_index_exists($db, 'daily_order_assignments', 'uq_assignment_driver_date_route_order'),
        '051 recorded' => ops_catchup_migration_applied($db, $migrationId),
    ];

    foreach ($checks as $label => $ok) {
        echo ($ok ? 'OK   ' : 'NEED ') . $label . "\n";
    }

    $pending = false;
    foreach ($checks as $ok) {
        if (!$ok) {
            $pending = true;
            break;
        }
    }
    if (!$pending) {
        echo "\nLive already has the ops catch-up.\n";
        exit(0);
    }

    if (!$confirm) {
        echo "\nRe-run with --confirm to apply 051_live_ops_catchup.sql to bakerysf.\n";
        exit(0);
    }

    echo "\nApplying {$migrationId}...\n";
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    foreach (bakery_parse_sql_file($sqlFile) as $statement) {
        $trim = ltrim($statement);
        $upper = strtoupper(substr($trim, 0, 64));
        if (stripos($upper, 'ADD COLUMN WASTED_QUANTITY') !== false
            && ops_catchup_column_exists($db, 'driver_load_items', 'wasted_quantity')) {
            echo "Skip existing driver_load_items.wasted_quantity\n";
            continue;
        }
        if (stripos($upper, 'ADD COLUMN RECONCILED_AT') !== false
            && ops_catchup_column_exists($db, 'driver_loads', 'reconciled_at')) {
            echo "Skip existing driver_loads.reconciled_*\n";
            continue;
        }
        if (stripos($upper, 'CREATE UNIQUE INDEX UQ_ASSIGNMENT_DRIVER_DATE_ROUTE_ORDER') !== false
            && ops_catchup_index_exists($db, 'daily_order_assignments', 'uq_assignment_driver_date_route_order')) {
            echo "Skip existing uq_assignment_driver_date_route_order\n";
            continue;
        }
        $db->exec($statement);
    }
    foreach ($historicalIds as $id) {
        $mark = $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)');
        $mark->execute([$id]);
    }

    echo "Applied {$migrationId} and recorded historical migration IDs on Live.\n";
    echo "Refresh Staging Manager → Staging → Live to confirm Match.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * Apply SF Baker migrations (032–035) to the configured production database.
 *
 * Usage:
 *   php scripts/apply_production_sfb_migrations.php          # read-only status
 *   php scripts/apply_production_sfb_migrations.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';
require_once $root . '/includes/schema_sql.php';

$migrations = [
    '032_sf_baker' => [
        'file' => '032_sf_baker.sql',
        'tables' => ['sfb_batches', 'sfb_formulas'],
        'column' => ['customers', 'sf_baker_enabled'],
    ],
    '033_sfb_batch_formula_snapshots' => [
        'file' => '033_sfb_batch_formula_snapshots.sql',
        'tables' => ['sfb_batch_formula_snapshots', 'sfb_batch_formula_snapshot_lines'],
    ],
    '034_sfb_batch_messages' => [
        'file' => '034_sfb_batch_messages.sql',
        'tables' => ['sfb_batch_messages'],
    ],
    '035_sfb_community' => [
        'file' => '035_sfb_community.sql',
        'tables' => ['sfb_community_topics', 'sfb_community_replies', 'sfb_batch_shares'],
    ],
    '039_sfb_origin' => [
        'file' => '039_sfb_origin.sql',
        'tables' => [],
        'column' => ['customers', 'sfb_origin'],
        'extra_columns' => [
            ['sfb_community_topics', 'is_pinned'],
            ['sfb_community_topics', 'author_kind'],
            ['sfb_community_replies', 'author_kind'],
        ],
    ],
    '040_sfb_persona_profiles' => [
        'file' => '040_sfb_persona_profiles.sql',
        'tables' => ['sfb_persona_profiles'],
    ],
    '041_sfb_studio_clock' => [
        'file' => '041_sfb_studio_clock.sql',
        'tables' => ['sfb_studio_settings', 'sfb_studio_clock', 'sfb_studio_action_log'],
    ],
];

function prod_sfb_table_exists(PDO $db, $table) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function prod_sfb_column_exists(PDO $db, $table, $column) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function prod_sfb_migration_ready(PDO $db, array $migration) {
    foreach ($migration['tables'] as $table) {
        if (!prod_sfb_table_exists($db, $table)) {
            return false;
        }
    }
    if (!empty($migration['column'])) {
        [$table, $column] = $migration['column'];
        if (!prod_sfb_column_exists($db, $table, $column)) {
            return false;
        }
    }
    if (!empty($migration['extra_columns'])) {
        foreach ($migration['extra_columns'] as $pair) {
            [$table, $column] = $pair;
            if (prod_sfb_table_exists($db, $table) && !prod_sfb_column_exists($db, $table, $column)) {
                return false;
            }
        }
    }
    return true;
}

function prod_sfb_ensure_migrations_table(PDO $db) {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function prod_sfb_mark_migration(PDO $db, $id) {
    $stmt = $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)');
    $stmt->execute([$id]);
}

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);

    echo "Production: {$prod['name']}@{$prod['host']}\n\n";

    $pending = [];
    foreach ($migrations as $id => $migration) {
        $ready = prod_sfb_migration_ready($db, $migration);
        echo "{$id}: " . ($ready ? 'OK' : 'PENDING') . "\n";
        if (!$ready) {
            $pending[$id] = $migration;
        }
    }

    $adminReady = prod_sfb_migration_ready($db, $migrations['032_sf_baker'])
        && prod_sfb_migration_ready($db, $migrations['033_sfb_batch_formula_snapshots'])
        && prod_sfb_migration_ready($db, $migrations['034_sfb_batch_messages']);
    echo "\nadmin engagement ready: " . ($adminReady ? 'yes' : 'NO') . "\n";

    if ($pending === []) {
        echo "\nAll SF Baker migrations are already installed on production.\n";
        exit(0);
    }

    if (!in_array('--confirm', $argv, true)) {
        echo "\nPending migrations:\n";
        foreach (array_keys($pending) as $id) {
            echo "  - {$id} (database/schema/{$migrations[$id]['file']})\n";
        }
        echo "\nBackup production first, then rerun with --confirm to apply.\n";
        echo "  php scripts/backup_production.php --label=before_sfb_migrations\n";
        echo "  php scripts/apply_production_sfb_migrations.php --confirm\n";
        exit(2);
    }

    prod_sfb_ensure_migrations_table($db);

    foreach ($pending as $id => $migration) {
        if (!empty($migration['column'])) {
            [$table, $column] = $migration['column'];
            if (prod_sfb_table_exists($db, $table) && !prod_sfb_column_exists($db, $table, $column)) {
                echo "Adding {$table}.{$column}...\n";
                if ($column === 'sfb_origin') {
                    $db->exec(
                        "ALTER TABLE customers
                         ADD COLUMN sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'"
                    );
                } else {
                    $db->exec(
                        "ALTER TABLE {$table}
                         ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_enabled"
                    );
                }
            }
        }

        if ($id === '039_sfb_origin') {
            echo "Applying {$id} piecewise (SQL file is not idempotent)...\n";
            if (prod_sfb_table_exists($db, 'customers') && prod_sfb_column_exists($db, 'customers', 'sfb_origin')) {
                try {
                    $db->exec('CREATE INDEX idx_customers_sfb_origin ON customers (sfb_origin)');
                    echo "  Added idx_customers_sfb_origin\n";
                } catch (Throwable $e) {
                    echo "  Note: origin index skipped (" . $e->getMessage() . ")\n";
                }
                $db->exec(
                    "UPDATE customers SET sfb_origin = 'synthetic' WHERE name IN ('Customer1', 'Customer2')"
                );
                echo "  Tagged Customer1/Customer2 as synthetic\n";
            }
            if (prod_sfb_table_exists($db, 'sfb_community_topics')) {
                try {
                    $db->exec(
                        "ALTER TABLE sfb_community_topics
                         MODIFY COLUMN category ENUM(
                            'starter','formula','fermentation','shaping_baking','general',
                            'failures','flours_mills','weekend_schedule'
                         ) NOT NULL DEFAULT 'general'"
                    );
                    echo "  Extended community category enum\n";
                } catch (Throwable $e) {
                    echo "  Note: category enum update skipped (" . $e->getMessage() . ")\n";
                }
                if (!prod_sfb_column_exists($db, 'sfb_community_topics', 'is_pinned')) {
                    $db->exec(
                        'ALTER TABLE sfb_community_topics
                         ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_locked'
                    );
                    echo "  Added sfb_community_topics.is_pinned\n";
                }
                if (!prod_sfb_column_exists($db, 'sfb_community_topics', 'author_kind')) {
                    $db->exec(
                        "ALTER TABLE sfb_community_topics
                         ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id"
                    );
                    echo "  Added sfb_community_topics.author_kind\n";
                }
                if (!prod_sfb_column_exists($db, 'sfb_community_topics', 'author_user_id')) {
                    $db->exec(
                        'ALTER TABLE sfb_community_topics
                         ADD COLUMN author_user_id INT NULL DEFAULT NULL AFTER author_kind'
                    );
                    echo "  Added sfb_community_topics.author_user_id\n";
                }
                try {
                    $db->exec('ALTER TABLE sfb_community_topics MODIFY COLUMN author_customer_id INT NULL DEFAULT NULL');
                } catch (Throwable $e) {
                    echo "  Note: topics.author_customer_id nullability skipped (" . $e->getMessage() . ")\n";
                }
            }
            if (prod_sfb_table_exists($db, 'sfb_community_replies')) {
                if (!prod_sfb_column_exists($db, 'sfb_community_replies', 'author_kind')) {
                    $db->exec(
                        "ALTER TABLE sfb_community_replies
                         ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id"
                    );
                    echo "  Added sfb_community_replies.author_kind\n";
                }
                if (!prod_sfb_column_exists($db, 'sfb_community_replies', 'author_user_id')) {
                    $db->exec(
                        'ALTER TABLE sfb_community_replies
                         ADD COLUMN author_user_id INT NULL DEFAULT NULL AFTER author_kind'
                    );
                    echo "  Added sfb_community_replies.author_user_id\n";
                }
                try {
                    $db->exec('ALTER TABLE sfb_community_replies MODIFY COLUMN author_customer_id INT NULL DEFAULT NULL');
                } catch (Throwable $e) {
                    echo "  Note: replies.author_customer_id nullability skipped (" . $e->getMessage() . ")\n";
                }
            }
            prod_sfb_mark_migration($db, $id);
            if (!prod_sfb_migration_ready($db, $migration)) {
                throw new RuntimeException("Migration {$id} finished but readiness check still failed.");
            }
            echo "  OK\n";
            continue;
        }

        $schemaPath = $root . '/database/schema/' . $migration['file'];
        echo "Applying {$id} from {$migration['file']}...\n";
        bakery_run_sql_file($db, $schemaPath);
        prod_sfb_mark_migration($db, $id);

        if (!prod_sfb_migration_ready($db, $migration)) {
            throw new RuntimeException("Migration {$id} finished but readiness check still failed.");
        }
        echo "  OK\n";
    }

    echo "\nOK: SF Baker migrations applied to production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

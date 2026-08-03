<?php
/**
 * Apply idempotent post-baseline schema migrations (003+).
 * Tracks applied migrations in schema_migrations.
 *
 * Usage:
 *   C:\php\php.exe scripts/run_migrations.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
    bakery_load_env_file($envPath);
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

function bakery_run_sql_file(PDO $db, $path) {
    if (!is_readable($path)) {
        throw new RuntimeException("SQL file not readable: {$path}");
    }
    $sql = file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (strpos($trim, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    $statements = [];
    $current = '';
    $inString = false;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $ch = $buf[$i];
        if ($ch === "'" && ($i === 0 || $buf[$i - 1] !== '\\')) {
            $inString = !$inString;
            $current .= $ch;
            continue;
        }
        if ($ch === ';' && !$inString) {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
}

function bakery_column_exists(PDO $db, $table, $column) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_fk_exists(PDO $db, $table, $constraintName) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt->execute([$table, $constraintName]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_ensure_migrations_table(PDO $db) {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function bakery_migration_applied(PDO $db, $id) {
    $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

function bakery_mark_migration(PDO $db, $id) {
    $stmt = $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)');
    $stmt->execute([$id]);
}

try {
    $db = check_mysql_connection();
    bakery_ensure_migrations_table($db);

    $migrationsDir = $root . '/database/schema';

    // 003 — weekday normalize
    if (!bakery_migration_applied($db, '003_weekday_normalize')) {
        echo "Applying migration 003_weekday_normalize...\n";
        bakery_run_sql_file($db, $migrationsDir . '/003_weekday_normalize.sql');
        bakery_mark_migration($db, '003_weekday_normalize');
        echo "  OK\n";
    } else {
        echo "Skip 003_weekday_normalize (already applied)\n";
    }

    // 004 — zone_id column + backfill
    if (!bakery_migration_applied($db, '004_zone_id')) {
        echo "Applying migration 004_zone_id...\n";
        if (!table_exists($db, 'zones')) {
            echo "  Note: zones table missing — skipping zone_id (run baseline first)\n";
        } else {
            if (!bakery_column_exists($db, 'customers', 'zone_id')) {
                $db->exec(
                    'ALTER TABLE customers
                     ADD COLUMN zone_id INT NULL AFTER zone,
                     ADD KEY idx_customers_zone_id (zone_id)'
                );
                echo "  Added customers.zone_id column\n";
            }
            bakery_run_sql_file($db, $migrationsDir . '/004_zone_id.sql');
            if (!bakery_fk_exists($db, 'customers', 'fk_customers_zone_id')) {
                try {
                    $db->exec(
                        'ALTER TABLE customers
                         ADD CONSTRAINT fk_customers_zone_id
                         FOREIGN KEY (zone_id) REFERENCES zones(id)
                         ON DELETE SET NULL ON UPDATE CASCADE'
                    );
                    echo "  Added fk_customers_zone_id\n";
                } catch (Throwable $e) {
                    echo "  Note: FK not added (" . $e->getMessage() . ")\n";
                }
            }
        }
        bakery_mark_migration($db, '004_zone_id');
        echo "  OK\n";
    } else {
        echo "Skip 004_zone_id (already applied)\n";
    }

    // 005 — ingredient inventory columns
    if (!bakery_migration_applied($db, '005_inventory')) {
        echo "Applying migration 005_inventory...\n";
        if (!table_exists($db, 'ingredients')) {
            echo "  Note: ingredients table missing — skipping inventory columns\n";
        } else {
            if (!bakery_column_exists($db, 'ingredients', 'quantity_on_hand')) {
                $db->exec(
                    'ALTER TABLE ingredients
                     ADD COLUMN quantity_on_hand DECIMAL(12,3) NULL DEFAULT NULL AFTER unit,
                     ADD COLUMN reorder_level DECIMAL(12,3) NULL DEFAULT NULL AFTER quantity_on_hand,
                     ADD COLUMN supplier_name VARCHAR(255) NULL DEFAULT NULL AFTER reorder_level'
                );
                echo "  Added inventory columns to ingredients\n";
            }
            bakery_run_sql_file($db, $migrationsDir . '/005_inventory.sql');
        }
        bakery_mark_migration($db, '005_inventory');
        echo "  OK\n";
    } else {
        echo "Skip 005_inventory (already applied)\n";
    }

    // 006 — driver archive columns
    if (!bakery_migration_applied($db, '006_driver_archive')) {
        echo "Applying migration 006_driver_archive...\n";
        if (!table_exists($db, 'drivers')) {
            echo "  Note: drivers table missing — skipping archive columns\n";
        } elseif (!bakery_column_exists($db, 'drivers', 'archived')) {
            bakery_run_sql_file($db, $migrationsDir . '/006_driver_archive.sql');
            echo "  Added drivers.archived columns\n";
        } else {
            echo "  drivers.archived already present\n";
        }
        bakery_mark_migration($db, '006_driver_archive');
        echo "  OK\n";
    } else {
        echo "Skip 006_driver_archive (already applied)\n";
    }

    // 008 — 4-digit login codes
    if (!bakery_migration_applied($db, '008_login_code')) {
        echo "Applying migration 008_login_code...\n";
        if (!table_exists($db, 'users')) {
            echo "  Note: users table missing — skipping login_code\n";
        } else {
            if (!bakery_column_exists($db, 'users', 'login_code')) {
                $db->exec(
                    'ALTER TABLE users
                     ADD COLUMN login_code CHAR(4) NULL AFTER password_hash'
                );
                echo "  Added users.login_code column\n";
            } else {
                echo "  users.login_code already present\n";
            }
            try {
                $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_login_code (login_code)');
                echo "  Added uq_users_login_code\n";
            } catch (Throwable $e) {
                echo "  Note: unique key already present or not added (" . $e->getMessage() . ")\n";
            }
        }
        bakery_mark_migration($db, '008_login_code');
        echo "  OK\n";
    } else {
        echo "Skip 008_login_code (already applied)\n";
    }

    // 009 — finished-goods inventory, driver loads, and actual delivered quantities
    if (!bakery_migration_applied($db, '009_finished_goods_inventory')) {
        echo "Applying migration 009_finished_goods_inventory...\n";
        if (!bakery_column_exists($db, 'daily_order_items', 'delivered_quantity')) {
            $db->exec(
                'ALTER TABLE daily_order_items
                 ADD COLUMN delivered_quantity INT NULL DEFAULT NULL AFTER quantity'
            );
            echo "  Added daily_order_items.delivered_quantity\n";
        }
        bakery_run_sql_file($db, $migrationsDir . '/009_finished_goods_inventory.sql');
        bakery_mark_migration($db, '009_finished_goods_inventory');
        echo "  OK\n";
    } else {
        echo "Skip 009_finished_goods_inventory (already applied)\n";
    }

    // 010 — standard quick-add quantities by Pan Dulce dough type
    if (!bakery_migration_applied($db, '010_pan_dulce_quantity_standards')) {
        echo "Applying migration 010_pan_dulce_quantity_standards...\n";
        bakery_run_sql_file($db, $migrationsDir . '/010_pan_dulce_quantity_standards.sql');
        bakery_mark_migration($db, '010_pan_dulce_quantity_standards');
        echo "  OK\n";
    } else {
        echo "Skip 010_pan_dulce_quantity_standards (already applied)\n";
    }

    // 011 — baker product-line visibility
    if (!bakery_migration_applied($db, '011_baker_product_lines')) {
        echo "Applying migration 011_baker_product_lines...\n";
        bakery_run_sql_file($db, $migrationsDir . '/010_baker_product_lines.sql');
        bakery_mark_migration($db, '011_baker_product_lines');
        echo "  OK\n";
    } else {
        echo "Skip 011_baker_product_lines (already applied)\n";
    }

    // 012 — product-level Pan Dulce standard quantities
    if (!bakery_migration_applied($db, '012_pan_dulce_product_quantity_standards')) {
        echo "Applying migration 012_pan_dulce_product_quantity_standards...\n";
        bakery_run_sql_file($db, $migrationsDir . '/012_pan_dulce_product_quantity_standards.sql');
        bakery_mark_migration($db, '012_pan_dulce_product_quantity_standards');
        echo "  OK\n";
    } else {
        echo "Skip 012_pan_dulce_product_quantity_standards (already applied)\n";
    }

    // 013 — driver delivery reconciliation for invoice/payment handoff
    if (!bakery_migration_applied($db, '013_delivery_confirmation')) {
        echo "Applying migration 013_delivery_confirmation...\n";
        if (!bakery_column_exists($db, 'daily_orders', 'delivered_pieces')) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN delivered_pieces INT NULL DEFAULT NULL AFTER total_amount');
            echo "  Added daily_orders.delivered_pieces\n";
        }
        if (!bakery_column_exists($db, 'daily_orders', 'credits_taken_back')) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN credits_taken_back INT NOT NULL DEFAULT 0 AFTER delivered_pieces');
            echo "  Added daily_orders.credits_taken_back\n";
        }
        bakery_mark_migration($db, '013_delivery_confirmation');
        echo "  OK\n";
    } else {
        echo "Skip 013_delivery_confirmation (already applied)\n";
    }

    // 014 — preserve the priced order basis for reloadable delivery invoices
    if (!bakery_migration_applied($db, '014_delivery_invoice_snapshot')) {
        echo "Applying migration 014_delivery_invoice_snapshot...\n";
        if (!bakery_column_exists($db, 'daily_orders', 'delivery_order_total')) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN delivery_order_total DECIMAL(10,2) NULL DEFAULT NULL AFTER total_amount');
            echo "  Added daily_orders.delivery_order_total\n";
        }
        if (!bakery_column_exists($db, 'daily_orders', 'delivery_pricing_label')) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN delivery_pricing_label VARCHAR(50) NULL DEFAULT NULL AFTER delivery_order_total');
            echo "  Added daily_orders.delivery_pricing_label\n";
        }
        if (!bakery_column_exists($db, 'daily_orders', 'delivery_confirmed_at')) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN delivery_confirmed_at DATETIME NULL DEFAULT NULL AFTER delivery_pricing_label');
            echo "  Added daily_orders.delivery_confirmed_at\n";
        }
        bakery_mark_migration($db, '014_delivery_invoice_snapshot');
        echo "  OK\n";
    } else {
        echo "Skip 014_delivery_invoice_snapshot (already applied)\n";
    }

    // 015 — saved weekly finished-goods targets for the Production Center
    if (!bakery_migration_applied($db, '015_production_center_plans')) {
        echo "Applying migration 015_production_center_plans...\n";
        bakery_run_sql_file($db, $migrationsDir . '/015_production_center_plans.sql');
        bakery_mark_migration($db, '015_production_center_plans');
        echo "  OK\n";
    } else {
        echo "Skip 015_production_center_plans (already applied)\n";
    }

    echo "Migrations complete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

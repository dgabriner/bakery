<?php
/**
 * Apply idempotent post-baseline schema migrations (003+).
 * Tracks applied migrations in schema_migrations.
 *
 * Usage:
 *   C:\php\php.exe scripts/run_migrations.php
 *   C:\php\php.exe scripts/run_migrations.php --mode=dreamhost-stage
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$hostedStageRoot = rtrim((string)getenv('BAKERY_HOSTED_STAGE_ROOT'), '/');
if ($hostedStageRoot !== '' && $hostedStageRoot !== '/home/bakeryOS/staging.sourflour.org') {
    fwrite(STDERR, "Refusing unexpected hosted Staging application root.\n");
    exit(1);
}
$root = $hostedStageRoot !== '' ? $hostedStageRoot : dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$requestedMigrationDb = '';
$migrationMode = 'local';
foreach ($argv as $arg) {
    if (strpos($arg, '--database=') === 0) {
        $requestedMigrationDb = strtolower(trim(substr($arg, 11)));
    }
    if (strpos($arg, '--mode=') === 0) {
        $migrationMode = strtolower(trim(substr($arg, 7)));
    }
}

if ($migrationMode === 'dreamhost-stage' || $migrationMode === 'hosted-stage') {
    if ($requestedMigrationDb !== '' && $requestedMigrationDb !== 'bakerysoftware') {
        fwrite(STDERR, "Refusing: --mode=dreamhost-stage only targets bakerysoftware.\n");
        exit(1);
    }
    bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
    $stagingEnv = $root . DIRECTORY_SEPARATOR
        . ($migrationMode === 'hosted-stage' ? '.env' : '.env.staging.dreamhost');
    if (!is_readable($stagingEnv)) {
        fwrite(STDERR, "Missing hosted Staging database environment\n");
        exit(1);
    }
    bakery_load_env_file($stagingEnv, true);
    putenv('APP_ENV=staging');
    $_ENV['APP_ENV'] = 'staging';
    $_SERVER['APP_ENV'] = 'staging';
    putenv('USE_PROD_DB=false');
    $_ENV['USE_PROD_DB'] = 'false';
    $_SERVER['USE_PROD_DB'] = 'false';
} else {
    if ($migrationMode !== 'local' && $migrationMode !== '') {
        fwrite(STDERR, "Unknown --mode={$migrationMode}. Use local, dreamhost-stage, or hosted-stage.\n");
        exit(1);
    }
    $envPath = $root . DIRECTORY_SEPARATOR . '.env';
    if (is_readable($envPath)) {
        bakery_load_env_file($envPath);
    }
    if ($requestedMigrationDb !== '') {
        putenv('DB_NAME=' . $requestedMigrationDb);
        $_ENV['DB_NAME'] = $requestedMigrationDb;
        $_SERVER['DB_NAME'] = $requestedMigrationDb;
    }
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/schema_sql.php';

function bakery_column_exists(PDO $db, $table, $column) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_schema_table_exists(PDO $db, $table) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_login_audit_context_ready(PDO $db) {
    if (!table_exists($db, 'login_audit')) {
        return false;
    }

    foreach ([
        'credential_method', 'credential_fingerprint', 'credential_suffix',
        'request_method', 'request_uri', 'referer', 'accept_language',
        'forwarded_for', 'server_protocol', 'server_port', 'session_id_hash',
        'last_page_path', 'last_page_at', 'page_views_count',
    ] as $column) {
        if (!bakery_column_exists($db, 'login_audit', $column)) {
            return false;
        }
    }

    return true;
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
    if ($migrationMode === 'dreamhost-stage' || $migrationMode === 'hosted-stage') {
        bakery_assert_dreamhost_staging_target($db);
    } else {
        $migrationTarget = strtolower((string)(defined('DB_NAME') ? DB_NAME : ''));
        if ($migrationTarget === 'bakerysf_refresh_local' || $migrationTarget === 'bakerysf_stage_local') {
            bakery_assert_local_connection($db, [$migrationTarget]);
        } else {
            bakery_assert_local_test_target($db);
        }
    }
    bakery_ensure_migrations_table($db);

    $migrationsDir = $root . '/database/schema';
    $newMigrationsDir = $migrationMode === 'hosted-stage'
        ? dirname($root) . '/.sourflour-migration-source'
        : $migrationsDir;
    if ($migrationMode === 'hosted-stage' && !is_dir($newMigrationsDir)) {
        throw new RuntimeException('Private hosted Staging migration source is missing.');
    }

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

    // 016 — customer lifecycle and lead-to-customer conversion linkage
    if (!bakery_migration_applied($db, '016_customer_lifecycle')) {
        echo "Applying migration 016_customer_lifecycle...\n";
        if (!bakery_column_exists($db, 'customers', 'is_active')) {
            $db->exec(
                'ALTER TABLE customers
                 ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER default_pan_dulce_price,
                 ADD COLUMN inactive_at TIMESTAMP NULL DEFAULT NULL AFTER is_active,
                 ADD COLUMN inactive_reason VARCHAR(255) NULL DEFAULT NULL AFTER inactive_at,
                 ADD KEY idx_customers_is_active (is_active)'
            );
            echo "  Added customer lifecycle columns\n";
        }
        if (!bakery_column_exists($db, 'leads', 'customer_id')) {
            $db->exec('ALTER TABLE leads ADD COLUMN customer_id INT NULL AFTER status, ADD KEY idx_leads_customer_id (customer_id)');
            echo "  Added leads.customer_id\n";
        }
        if (!bakery_fk_exists($db, 'leads', 'fk_leads_customer_id')) {
            $db->exec(
                'ALTER TABLE leads ADD CONSTRAINT fk_leads_customer_id
                 FOREIGN KEY (customer_id) REFERENCES customers(id)
                 ON DELETE SET NULL ON UPDATE CASCADE'
            );
            echo "  Linked leads to customers\n";
        }
        bakery_mark_migration($db, '016_customer_lifecycle');
        echo "  OK\n";
    } else {
        echo "Skip 016_customer_lifecycle (already applied)\n";
    }

    // 017 — ingredient package size and unit cost for mobile inventory & ordering
    if (!bakery_migration_applied($db, '017_ingredient_purchasing')) {
        echo "Applying migration 017_ingredient_purchasing...\n";
        if (!table_exists($db, 'ingredients')) {
            echo "  Note: ingredients table missing — skipping purchasing columns\n";
        } else {
            if (!bakery_column_exists($db, 'ingredients', 'package_size')) {
                $db->exec(
                    'ALTER TABLE ingredients
                     ADD COLUMN package_size DECIMAL(12,3) NULL DEFAULT NULL AFTER supplier_name,
                     ADD COLUMN unit_cost DECIMAL(10,2) NULL DEFAULT NULL AFTER package_size'
                );
                echo "  Added package_size and unit_cost to ingredients\n";
            }
            bakery_run_sql_file($db, $migrationsDir . '/017_ingredient_purchasing.sql');
        }
        bakery_mark_migration($db, '017_ingredient_purchasing');
        echo "  OK\n";
    } else {
        echo "Skip 017_ingredient_purchasing (already applied)\n";
    }

    // 018 — customer portal, pricing tiers, week pauses, product images
    if (!bakery_migration_applied($db, '018_customer_portal')) {
        echo "Applying migration 018_customer_portal...\n";
        bakery_run_sql_file($db, $migrationsDir . '/018_customer_portal.sql');
        bakery_mark_migration($db, '018_customer_portal');
        echo "  OK\n";
    } else {
        echo "Skip 018_customer_portal (already applied)\n";
    }

    // 019 — optional notes on daily_order_assignments (older route tables)
    if (!bakery_migration_applied($db, '019_daily_order_assignment_notes')) {
        echo "Applying migration 019_daily_order_assignment_notes...\n";
        if (!table_exists($db, 'daily_order_assignments')) {
            echo "  Note: daily_order_assignments table missing — skipping notes column\n";
        } elseif (!bakery_column_exists($db, 'daily_order_assignments', 'notes')) {
            $db->exec(
                'ALTER TABLE daily_order_assignments
                 ADD COLUMN notes TEXT NULL AFTER delivery_status'
            );
            echo "  Added daily_order_assignments.notes\n";
        } else {
            echo "  daily_order_assignments.notes already present\n";
        }
        bakery_mark_migration($db, '019_daily_order_assignment_notes');
        echo "  OK\n";
    } else {
        echo "Skip 019_daily_order_assignment_notes (already applied)\n";
    }

    // 020 — COD vs signature payment collection
    if (!bakery_migration_applied($db, '020_cod_payment_collection')) {
        echo "Applying migration 020_cod_payment_collection...\n";
        if (!table_exists($db, 'customers')) {
            echo "  Note: customers table missing — skipping payment_collection\n";
        } elseif (!bakery_column_exists($db, 'customers', 'payment_collection')) {
            $db->exec(
                "ALTER TABLE customers
                 ADD COLUMN payment_collection ENUM('cod', 'signature') NOT NULL DEFAULT 'cod'
                 AFTER pricing_tier"
            );
            echo "  Added customers.payment_collection\n";
        } else {
            echo "  customers.payment_collection already present\n";
        }
        if (!table_exists($db, 'daily_orders')) {
            echo "  Note: daily_orders table missing — skipping amount_collected\n";
        } elseif (!bakery_column_exists($db, 'daily_orders', 'amount_collected')) {
            $db->exec(
                'ALTER TABLE daily_orders
                 ADD COLUMN amount_collected DECIMAL(10,2) NULL DEFAULT NULL
                 AFTER delivery_confirmed_at'
            );
            echo "  Added daily_orders.amount_collected\n";
        } else {
            echo "  daily_orders.amount_collected already present\n";
        }
        bakery_mark_migration($db, '020_cod_payment_collection');
        echo "  OK\n";
    } else {
        echo "Skip 020_cod_payment_collection (already applied)\n";
    }

    // 021 — operating day closeout
    if (!bakery_migration_applied($db, '021_operating_day_closeout')) {
        echo "Applying migration 021_operating_day_closeout...\n";
        if (is_readable($migrationsDir . '/021_operating_day_closeout.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/021_operating_day_closeout.sql');
            echo "  Applied operating_day_closeouts table\n";
        } else {
            echo "  Note: 021_operating_day_closeout.sql missing — skipping\n";
        }
        bakery_mark_migration($db, '021_operating_day_closeout');
        echo "  OK\n";
    } else {
        echo "Skip 021_operating_day_closeout (already applied)\n";
    }

    // 022 — billing center (audit, statements, exports)
    if (!bakery_migration_applied($db, '022_billing_center')) {
        echo "Applying migration 022_billing_center...\n";
        if (!table_exists($db, 'customers') || !table_exists($db, 'daily_orders')) {
            echo "  Note: core tables missing — skipping billing_center migration\n";
        } elseif (is_readable($migrationsDir . '/022_billing_center.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/022_billing_center.sql');
            echo "  Applied billing audit/statement/export tables\n";
        } else {
            echo "  Note: 022_billing_center.sql missing — skipping\n";
        }
        bakery_mark_migration($db, '022_billing_center');
        echo "  OK\n";
    } else {
        echo "Skip 022_billing_center (already applied)\n";
    }

    // 021 — operational timeline / audit events
    if (!bakery_migration_applied($db, '021_operational_events')) {
        echo "Applying migration 021_operational_events...\n";
        bakery_run_sql_file($db, $migrationsDir . '/021_operational_events.sql');
        bakery_mark_migration($db, '021_operational_events');
        echo "  OK\n";
    } else {
        echo "Skip 021_operational_events (already applied)\n";
    }

    // 023 — optional standard batch dough reference on dough_types
    if (!bakery_migration_applied($db, '023_dough_type_batch_reference')) {
        echo "Applying migration 023_dough_type_batch_reference...\n";
        if (!table_exists($db, 'dough_types')) {
            echo "  Note: dough_types table missing — skipping batch reference column\n";
        } elseif (!bakery_column_exists($db, 'dough_types', 'standard_batch_dough_grams')) {
            if (is_readable($migrationsDir . '/023_dough_type_batch_reference.sql')) {
                bakery_run_sql_file($db, $migrationsDir . '/023_dough_type_batch_reference.sql');
            } else {
                $db->exec(
                    'ALTER TABLE dough_types
                     ADD COLUMN standard_batch_dough_grams DECIMAL(12,3) NULL DEFAULT NULL
                     AFTER product_line_id'
                );
            }
            echo "  Added dough_types.standard_batch_dough_grams\n";
        } else {
            echo "  dough_types.standard_batch_dough_grams already present\n";
        }
        bakery_mark_migration($db, '023_dough_type_batch_reference');
        echo "  OK\n";
    } else {
        echo "Skip 023_dough_type_batch_reference (already applied)\n";
    }

    // 024 — customer order power tools (skips, date-range pauses, change requests)
    if (!bakery_migration_applied($db, '024_customer_order_power_tools')) {
        echo "Applying migration 024_customer_order_power_tools...\n";
        bakery_run_sql_file($db, $migrationsDir . '/024_customer_order_power_tools.sql');
        bakery_mark_migration($db, '024_customer_order_power_tools');
        echo "  OK\n";
    } else {
        echo "Skip 024_customer_order_power_tools (already applied)\n";
    }

    // 025 — customer account preferences (parallel 025; file was previously only
    // applied at runtime by includes/customer_account.php, which left fresh
    // databases without columns that text_comms.php queries unguarded).
    if (!bakery_migration_applied($db, '025_customer_account_preferences')) {
        echo "Applying migration 025_customer_account_preferences...\n";
        if (!table_exists($db, 'customers')) {
            echo "  Note: customers table missing — skipping account preference columns\n";
        } else {
            $accountColumns = [
                'delivery_instructions' => "ALTER TABLE customers ADD COLUMN delivery_instructions TEXT NULL COMMENT 'Customer-facing delivery/receiving notes for drivers'",
                'ordering_contact_name' => 'ALTER TABLE customers ADD COLUMN ordering_contact_name VARCHAR(100) NULL DEFAULT NULL',
                'ordering_contact_phone' => 'ALTER TABLE customers ADD COLUMN ordering_contact_phone VARCHAR(20) NULL DEFAULT NULL',
                'ordering_contact_email' => 'ALTER TABLE customers ADD COLUMN ordering_contact_email VARCHAR(100) NULL DEFAULT NULL',
                'delivery_contact_name' => "ALTER TABLE customers ADD COLUMN delivery_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Day-of-delivery contact'",
                'delivery_contact_phone' => "ALTER TABLE customers ADD COLUMN delivery_contact_phone VARCHAR(20) NULL DEFAULT NULL COMMENT 'Day-of-delivery phone'",
                'billing_contact_name' => "ALTER TABLE customers ADD COLUMN billing_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Accounts payable contact'",
                'billing_contact_email' => 'ALTER TABLE customers ADD COLUMN billing_contact_email VARCHAR(100) NULL DEFAULT NULL',
                'billing_contact_phone' => 'ALTER TABLE customers ADD COLUMN billing_contact_phone VARCHAR(20) NULL DEFAULT NULL',
            ];
            foreach ($accountColumns as $col => $sql) {
                if (!bakery_column_exists($db, 'customers', $col)) {
                    $db->exec($sql);
                    echo "  Added customers.{$col}\n";
                }
            }
        }
        bakery_mark_migration($db, '025_customer_account_preferences');
        echo "  OK\n";
    } else {
        echo "Skip 025_customer_account_preferences (already applied)\n";
    }

    // 025 — customer notifications
    if (!bakery_migration_applied($db, '025_customer_notifications')) {
        echo "Applying migration 025_customer_notifications...\n";
        if (is_readable($migrationsDir . '/025_customer_notifications.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/025_customer_notifications.sql');
        }
        bakery_mark_migration($db, '025_customer_notifications');
        echo "  OK\n";
    } else {
        echo "Skip 025_customer_notifications (already applied)\n";
    }

    // 026 — customer delivery issues
    if (!bakery_migration_applied($db, '026_customer_delivery_issues')) {
        echo "Applying migration 026_customer_delivery_issues...\n";
        if (is_readable($migrationsDir . '/026_customer_delivery_issues.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/026_customer_delivery_issues.sql');
        }
        bakery_mark_migration($db, '026_customer_delivery_issues');
        echo "  OK\n";
    } else {
        echo "Skip 026_customer_delivery_issues (already applied)\n";
    }

    // 027 — login, device, optional location, and session-duration audit
    if (!bakery_migration_applied($db, '027_login_audit')) {
        echo "Applying migration 027_login_audit...\n";
        if (is_readable($migrationsDir . '/027_login_audit.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/027_login_audit.sql');
        }
        bakery_mark_migration($db, '027_login_audit');
        echo "  OK\n";
    } else {
        echo "Skip 027_login_audit (already applied)\n";
    }

    // 028 — additional request, credential, session, and client context
    if (!bakery_migration_applied($db, '028_login_audit_context')) {
        echo "Applying migration 028_login_audit_context...\n";
        if (bakery_login_audit_context_ready($db)) {
            echo "  Context columns already present - marking migration applied\n";
        } elseif (is_readable($migrationsDir . '/028_login_audit_context.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/028_login_audit_context.sql');
        }
        bakery_mark_migration($db, '028_login_audit_context');
        echo "  OK\n";
    } else {
        echo "Skip 028_login_audit_context (already applied)\n";
    }

    // 029 — short-lived customer portal QR invitations
    if (!bakery_migration_applied($db, '029_customer_qr_login')) {
        echo "Applying migration 029_customer_qr_login...\n";
        bakery_run_sql_file($db, $migrationsDir . '/029_customer_qr_login.sql');
        bakery_mark_migration($db, '029_customer_qr_login');
        echo "  OK\n";
    } else {
        echo "Skip 029_customer_qr_login (already applied)\n";
    }

    // 030 — pack list check-off progress (shared per delivery date)
    if (!bakery_migration_applied($db, '030_pack_progress')) {
        echo "Applying migration 030_pack_progress...\n";
        if (is_readable($migrationsDir . '/030_pack_progress.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/030_pack_progress.sql');
        }
        bakery_mark_migration($db, '030_pack_progress');
        echo "  OK\n";
    } else {
        echo "Skip 030_pack_progress (already applied)\n";
    }

    // 031 — per-date manager demand confirmations ("Tomorrow, confirmed")
    if (!bakery_migration_applied($db, '031_demand_confirmations')) {
        echo "Applying migration 031_demand_confirmations...\n";
        bakery_run_sql_file($db, $migrationsDir . '/031_demand_confirmations.sql');
        bakery_mark_migration($db, '031_demand_confirmations');
        echo "  OK\n";
    } else {
        echo "Skip 031_demand_confirmations (already applied)\n";
    }

    // 032 — SF Baker module (starters, formulas, batches, turns, temps, photos)
    if (!table_exists($db, 'customers')) {
        echo "  Note: customers table missing — skipping sf_baker column check\n";
    } elseif (!bakery_column_exists($db, 'customers', 'sf_baker_enabled')) {
        echo "Applying customers.sf_baker_enabled (032_sf_baker)...\n";
        $db->exec(
            'ALTER TABLE customers
             ADD COLUMN sf_baker_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_enabled'
        );
        echo "  Added customers.sf_baker_enabled\n";
    }
    if (!bakery_migration_applied($db, '032_sf_baker')) {
        echo "Applying migration 032_sf_baker...\n";
        bakery_run_sql_file($db, $migrationsDir . '/032_sf_baker.sql');
        bakery_mark_migration($db, '032_sf_baker');
        echo "  OK\n";
    } elseif (!table_exists($db, 'sfb_batches') && is_readable($migrationsDir . '/032_sf_baker.sql')) {
        echo "Applying migration 032_sf_baker (tables missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/032_sf_baker.sql');
        echo "  OK\n";
    } else {
        echo "Skip 032_sf_baker (already applied)\n";
    }

    // 033 — immutable formula snapshots for SF Baker batch history
    if (!bakery_migration_applied($db, '033_sfb_batch_formula_snapshots')) {
        echo "Applying migration 033_sfb_batch_formula_snapshots...\n";
        bakery_run_sql_file($db, $migrationsDir . '/033_sfb_batch_formula_snapshots.sql');
        bakery_mark_migration($db, '033_sfb_batch_formula_snapshots');
        echo "  OK\n";
    } elseif ((!table_exists($db, 'sfb_batch_formula_snapshots') || !table_exists($db, 'sfb_batch_formula_snapshot_lines'))
        && is_readable($migrationsDir . '/033_sfb_batch_formula_snapshots.sql')) {
        echo "Applying migration 033_sfb_batch_formula_snapshots (tables missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/033_sfb_batch_formula_snapshots.sql');
        echo "  OK\n";
    } else {
        echo "Skip 033_sfb_batch_formula_snapshots (already applied)\n";
    }

    // 034 — baker/admin conversation threads on each SF Baker batch
    if (!bakery_migration_applied($db, '034_sfb_batch_messages')) {
        echo "Applying migration 034_sfb_batch_messages...\n";
        bakery_run_sql_file($db, $migrationsDir . '/034_sfb_batch_messages.sql');
        bakery_mark_migration($db, '034_sfb_batch_messages');
        echo "  OK\n";
    } elseif (!table_exists($db, 'sfb_batch_messages')
        && is_readable($migrationsDir . '/034_sfb_batch_messages.sql')) {
        echo "Applying migration 034_sfb_batch_messages (table missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/034_sfb_batch_messages.sql');
        echo "  OK\n";
    } else {
        echo "Skip 034_sfb_batch_messages (already applied)\n";
    }

    // 035 — opt-in community forum and public-within-SF-Baker batch cards
    if (!bakery_migration_applied($db, '035_sfb_community')) {
        echo "Applying migration 035_sfb_community...\n";
        bakery_run_sql_file($db, $migrationsDir . '/035_sfb_community.sql');
        bakery_mark_migration($db, '035_sfb_community');
        echo "  OK\n";
    } elseif ((!table_exists($db, 'sfb_community_topics') || !table_exists($db, 'sfb_community_replies') || !table_exists($db, 'sfb_batch_shares'))
        && is_readable($migrationsDir . '/035_sfb_community.sql')) {
        echo "Applying migration 035_sfb_community (tables missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/035_sfb_community.sql');
        echo "  OK\n";
    } else {
        echo "Skip 035_sfb_community (already applied)\n";
    }

    // 036 — per-session navigation history for the Login History investigator
    $activityTableReady = bakery_schema_table_exists($db, 'login_audit_activity');
    if (!bakery_migration_applied($db, '036_login_audit_activity')) {
        echo "Applying migration 036_login_audit_activity...\n";
        if (!bakery_schema_table_exists($db, 'login_audit')) {
            echo "  Note: login_audit table missing — skipping activity timeline\n";
        } elseif (!is_readable($migrationsDir . '/036_login_audit_activity.sql')) {
            throw new RuntimeException('Missing 036_login_audit_activity.sql');
        } else {
            bakery_run_sql_file($db, $migrationsDir . '/036_login_audit_activity.sql');
            bakery_mark_migration($db, '036_login_audit_activity');
            echo "  OK\n";
        }
    } elseif (!$activityTableReady
        && bakery_schema_table_exists($db, 'login_audit')
        && is_readable($migrationsDir . '/036_login_audit_activity.sql')) {
        echo "Applying migration 036_login_audit_activity (table missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/036_login_audit_activity.sql');
        echo "  OK\n";
    } else {
        echo "Skip 036_login_audit_activity (already applied)\n";
    }

    // 037 — route closeout: waste/delivery movements + returned/wasted load lines
    if (!bakery_migration_applied($db, '037_route_closeout')) {
        echo "Applying migration 037_route_closeout...\n";
        if (!table_exists($db, 'inventory_movements') || !table_exists($db, 'driver_load_items')) {
            echo "  Note: finished-goods inventory tables missing — skipping route closeout\n";
        } else {
            if (!bakery_column_exists($db, 'driver_load_items', 'wasted_quantity')) {
                $db->exec(
                    'ALTER TABLE driver_load_items
                     ADD COLUMN wasted_quantity INT NOT NULL DEFAULT 0 AFTER returned_quantity'
                );
                echo "  Added driver_load_items.wasted_quantity\n";
            }
            if (!bakery_column_exists($db, 'driver_loads', 'reconciled_at')) {
                $db->exec(
                    'ALTER TABLE driver_loads
                     ADD COLUMN reconciled_at TIMESTAMP NULL DEFAULT NULL AFTER status,
                     ADD COLUMN reconciled_by_user_id INT NULL DEFAULT NULL AFTER reconciled_at'
                );
                echo "  Added driver_loads.reconciled_at columns\n";
            }
            try {
                $db->exec(
                    "ALTER TABLE inventory_movements
                     MODIFY COLUMN movement_type
                     ENUM('production','count','load','load_correction','return','waste','delivery') NOT NULL"
                );
                echo "  Extended inventory_movements.movement_type with waste + delivery\n";
            } catch (Throwable $e) {
                echo "  Note: movement_type enum update skipped (" . $e->getMessage() . ")\n";
            }
        }
        bakery_mark_migration($db, '037_route_closeout');
        echo "  OK\n";
    } else {
        echo "Skip 037_route_closeout (already applied)\n";
    }

    // 038 — manager exception ownership and failed-stop recovery workflow.
    if (!bakery_migration_applied($db, '038_manager_exception_and_delivery_recovery')) {
        echo "Applying migration 038_manager_exception_and_delivery_recovery...\n";
        if (is_readable($migrationsDir . '/038_manager_exception_and_delivery_recovery.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/038_manager_exception_and_delivery_recovery.sql');
        } else {
            throw new RuntimeException('Missing 038_manager_exception_and_delivery_recovery.sql');
        }
        bakery_mark_migration($db, '038_manager_exception_and_delivery_recovery');
        echo "  OK\n";
    } else {
        echo "Skip 038_manager_exception_and_delivery_recovery (already applied)\n";
    }

    // 039 — real vs synthetic SF Baker origin + community contract columns
    if (!bakery_migration_applied($db, '039_sfb_origin')) {
        echo "Applying migration 039_sfb_origin...\n";
        if (table_exists($db, 'customers') && !bakery_column_exists($db, 'customers', 'sfb_origin')) {
            $after = bakery_column_exists($db, 'customers', 'sf_baker_enabled')
                ? ' AFTER sf_baker_enabled'
                : '';
            $db->exec(
                "ALTER TABLE customers
                 ADD COLUMN sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'{$after}"
            );
            echo "  Added customers.sfb_origin\n";
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('customers', 'sfb_origin');
            }
        }
        if (table_exists($db, 'customers') && bakery_column_exists($db, 'customers', 'sfb_origin')) {
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
        if (table_exists($db, 'sfb_community_topics')) {
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
            if (!bakery_column_exists($db, 'sfb_community_topics', 'is_pinned')) {
                $db->exec(
                    'ALTER TABLE sfb_community_topics
                     ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_locked'
                );
                echo "  Added sfb_community_topics.is_pinned\n";
            }
            if (!bakery_column_exists($db, 'sfb_community_topics', 'author_kind')) {
                $db->exec(
                    "ALTER TABLE sfb_community_topics
                     ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id"
                );
                echo "  Added sfb_community_topics.author_kind\n";
            }
            if (!bakery_column_exists($db, 'sfb_community_topics', 'author_user_id')) {
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
        if (table_exists($db, 'sfb_community_replies')) {
            if (!bakery_column_exists($db, 'sfb_community_replies', 'author_kind')) {
                $db->exec(
                    "ALTER TABLE sfb_community_replies
                     ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id"
                );
                echo "  Added sfb_community_replies.author_kind\n";
            }
            if (!bakery_column_exists($db, 'sfb_community_replies', 'author_user_id')) {
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
        bakery_mark_migration($db, '039_sfb_origin');
        echo "  OK\n";
    } else {
        echo "Skip 039_sfb_origin (already applied)\n";
    }

    // 040 — synthetic studio persona profiles
    if (!bakery_migration_applied($db, '040_sfb_persona_profiles')) {
        echo "Applying migration 040_sfb_persona_profiles...\n";
        if (is_readable($migrationsDir . '/040_sfb_persona_profiles.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/040_sfb_persona_profiles.sql');
        } else {
            throw new RuntimeException('Missing 040_sfb_persona_profiles.sql');
        }
        bakery_mark_migration($db, '040_sfb_persona_profiles');
        echo "  OK\n";
    } else {
        echo "Skip 040_sfb_persona_profiles (already applied)\n";
    }

    // 041 — synthetic studio clock, pace, and action log
    if (!bakery_migration_applied($db, '041_sfb_studio_clock')) {
        echo "Applying migration 041_sfb_studio_clock...\n";
        if (is_readable($migrationsDir . '/041_sfb_studio_clock.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/041_sfb_studio_clock.sql');
        } else {
            throw new RuntimeException('Missing 041_sfb_studio_clock.sql');
        }
        bakery_mark_migration($db, '041_sfb_studio_clock');
        echo "  OK\n";
    } else {
        echo "Skip 041_sfb_studio_clock (already applied)\n";
    }

    // 042 — phone + PIN self-serve accounts for the customer baking portal
    if (!bakery_migration_applied($db, '042_customer_phone_pin_signup')) {
        echo "Applying migration 042_customer_phone_pin_signup...\n";
        if (!table_exists($db, 'customers')) {
            throw new RuntimeException('customers table is required for phone/PIN signup');
        }
        if (!bakery_column_exists($db, 'customers', 'portal_phone_key')) {
            $db->exec(
                'ALTER TABLE customers
                 ADD COLUMN portal_phone_key CHAR(10) NULL DEFAULT NULL AFTER portal_phone'
            );
            echo "  Added customers.portal_phone_key\n";
        }
        if (!bakery_column_exists($db, 'customers', 'portal_code_hash')) {
            $db->exec(
                'ALTER TABLE customers
                 ADD COLUMN portal_code_hash VARCHAR(255) NULL DEFAULT NULL AFTER portal_code'
            );
            echo "  Added customers.portal_code_hash\n";
        }
        try {
            $db->exec('CREATE UNIQUE INDEX uq_customers_portal_phone_key ON customers (portal_phone_key)');
            echo "  Added uq_customers_portal_phone_key\n";
        } catch (Throwable $e) {
            echo "  Note: phone key index already exists or was not added (" . $e->getMessage() . ")\n";
        }
        bakery_mark_migration($db, '042_customer_phone_pin_signup');
        echo "  OK\n";
    } else {
        echo "Skip 042_customer_phone_pin_signup (already applied)\n";
    }

    // 043 — a 4-digit portal code is a unique returning-customer login
    if (!bakery_migration_applied($db, '043_unique_customer_portal_codes')) {
        echo "Applying migration 043_unique_customer_portal_codes...\n";
        try {
            $db->exec('CREATE UNIQUE INDEX uq_customers_portal_code ON customers (portal_code)');
            echo "  Added uq_customers_portal_code\n";
        } catch (Throwable $e) {
            echo "  Note: portal-code index already exists (" . $e->getMessage() . ")\n";
        }
        bakery_mark_migration($db, '043_unique_customer_portal_codes');
        echo "  OK\n";
    } else {
        echo "Skip 043_unique_customer_portal_codes (already applied)\n";
    }

    // 044 — Agent Learning Studio / Homebase
    if (!bakery_migration_applied($db, '044_agent_homebase')) {
        echo "Applying migration 044_agent_homebase...\n";
        if (is_readable($migrationsDir . '/044_agent_homebase.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/044_agent_homebase.sql');
        } else {
            throw new RuntimeException('Missing 044_agent_homebase.sql');
        }
        require_once $root . '/includes/agent_homebase.php';
        bakery_agent_homebase_seed($db);
        bakery_mark_migration($db, '044_agent_homebase');
        echo "  OK\n";
    } else {
        echo "Skip 044_agent_homebase (already applied)\n";
        require_once $root . '/includes/agent_homebase.php';
        if (bakery_agent_homebase_ready($db)) {
            bakery_agent_homebase_seed($db);
            echo "  Refreshed Agent Homebase curriculum\n";
        }
    }

    // 045 — Driver Assistant role and dated route pairings
    if (!bakery_migration_applied($db, '045_driver_assistant')) {
        echo "Applying migration 045_driver_assistant...\n";
        if (is_readable($migrationsDir . '/045_driver_assistant.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/045_driver_assistant.sql');
        } else {
            throw new RuntimeException('Missing 045_driver_assistant.sql');
        }
        bakery_mark_migration($db, '045_driver_assistant');
        echo "  OK\n";
    } else {
        echo "Skip 045_driver_assistant (already applied)\n";
    }

    // 046 — cancelled delivery status + historical skipped-stop repair
    $assignmentStatusReady = false;
    if (table_exists($db, 'daily_order_assignments')) {
        $statusTypeStmt = $db->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $statusTypeStmt->execute(['daily_order_assignments', 'delivery_status']);
        $statusColumnType = strtolower((string)$statusTypeStmt->fetchColumn());
        $assignmentStatusReady = strpos($statusColumnType, "'cancelled'") !== false
            && strpos($statusColumnType, "'rescheduled'") !== false;
    }
    if (!bakery_migration_applied($db, '046_assignment_cancelled_status') || !$assignmentStatusReady) {
        echo "Applying migration 046_assignment_cancelled_status...\n";
        if (!table_exists($db, 'daily_order_assignments')) {
            throw new RuntimeException('daily_order_assignments table is required for cancelled-stop migration');
        }
        if (is_readable($migrationsDir . '/046_assignment_cancelled_status.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/046_assignment_cancelled_status.sql');
        } else {
            throw new RuntimeException('Missing 046_assignment_cancelled_status.sql');
        }
        bakery_mark_migration($db, '046_assignment_cancelled_status');
        echo "  OK\n";
    } else {
        echo "Skip 046_assignment_cancelled_status (already applied)\n";
    }

    // 047 — one unambiguous route position per driver and delivery date
    $routePositionIndexReady = false;
    if (table_exists($db, 'daily_order_assignments')) {
        $routeIndexStmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?'
        );
        $routeIndexStmt->execute([
            'daily_order_assignments',
            'uq_assignment_driver_date_route_order',
        ]);
        $routePositionIndexReady = (int)$routeIndexStmt->fetchColumn() === 3;
    }
    if (!bakery_migration_applied($db, '047_unique_dated_route_positions') && $routePositionIndexReady) {
        bakery_mark_migration($db, '047_unique_dated_route_positions');
        echo "Skip 047_unique_dated_route_positions (baseline already enforces it)\n";
    } elseif (!bakery_migration_applied($db, '047_unique_dated_route_positions') || !$routePositionIndexReady) {
        echo "Applying migration 047_unique_dated_route_positions...\n";
        if (!table_exists($db, 'daily_order_assignments')) {
            throw new RuntimeException('daily_order_assignments table is required for route-position migration');
        }
        if (is_readable($migrationsDir . '/047_unique_dated_route_positions.sql')) {
            bakery_run_sql_file($db, $migrationsDir . '/047_unique_dated_route_positions.sql');
        } else {
            throw new RuntimeException('Missing 047_unique_dated_route_positions.sql');
        }
        bakery_mark_migration($db, '047_unique_dated_route_positions');
        echo "  OK\n";
    } else {
        echo "Skip 047_unique_dated_route_positions (already applied)\n";
    }

    // 048 — per-date production plan commits (plan → baker ritual)
    if (!bakery_migration_applied($db, '048_production_plan_commits')) {
        echo "Applying migration 048_production_plan_commits...\n";
        bakery_run_sql_file($db, $migrationsDir . '/048_production_plan_commits.sql');
        bakery_mark_migration($db, '048_production_plan_commits');
        echo "  OK\n";
    } elseif ((!table_exists($db, 'production_plan_commits') || !table_exists($db, 'production_plan_commit_items'))
        && is_readable($migrationsDir . '/048_production_plan_commits.sql')) {
        echo "Applying migration 048_production_plan_commits (tables missing despite applied flag)...\n";
        bakery_run_sql_file($db, $migrationsDir . '/048_production_plan_commits.sql');
        echo "  OK\n";
    } else {
        echo "Skip 048_production_plan_commits (already applied)\n";
    }

    // 049 — canonical invoice send columns + outbox
    $invoiceSendReady = table_exists($db, 'billing_invoice_sends')
        && bakery_column_exists($db, 'daily_orders', 'invoice_sent_at');
    if (!bakery_migration_applied($db, '049_invoice_send') || !$invoiceSendReady) {
        echo "Applying migration 049_invoice_send...\n";
        if (!table_exists($db, 'daily_orders')) {
            throw new RuntimeException('daily_orders table is required for invoice send migration');
        }
        foreach ([
            'invoice_sent_at' => 'DATETIME NULL DEFAULT NULL',
            'invoice_sent_to_email' => 'VARCHAR(255) NULL DEFAULT NULL',
            'invoice_sent_by_user_id' => 'INT NULL DEFAULT NULL',
            'invoice_send_channel' => 'VARCHAR(16) NULL DEFAULT NULL',
        ] as $column => $definition) {
            if (!bakery_column_exists($db, 'daily_orders', $column)) {
                $db->exec('ALTER TABLE daily_orders ADD COLUMN `' . $column . '` ' . $definition);
                echo "  Added daily_orders.{$column}\n";
            }
        }
        if (!table_exists($db, 'billing_invoice_sends')) {
            if (is_readable($migrationsDir . '/049_invoice_send.sql')) {
                bakery_run_sql_file($db, $migrationsDir . '/049_invoice_send.sql');
            } else {
                $db->exec(
                    'CREATE TABLE billing_invoice_sends (
                        id INT NOT NULL AUTO_INCREMENT,
                        daily_order_id INT NOT NULL,
                        invoice_number VARCHAR(40) NOT NULL,
                        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        sent_by_user_id INT NULL DEFAULT NULL,
                        sent_to_email VARCHAR(255) NULL DEFAULT NULL,
                        channel VARCHAR(16) NOT NULL DEFAULT \'log\',
                        status VARCHAR(16) NOT NULL DEFAULT \'logged\',
                        PRIMARY KEY (id),
                        KEY idx_billing_invoice_sends_order (daily_order_id),
                        KEY idx_billing_invoice_sends_sent (sent_at)
                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            }
            echo "  Created billing_invoice_sends\n";
        }
        bakery_mark_migration($db, '049_invoice_send');
        echo "  OK\n";
    } else {
        echo "Skip 049_invoice_send (already applied)\n";
    }

    // 050+ — self-contained additive SQL migrations. New migrations must be
    // safe to run verbatim on Staging and are later approved separately for Live.
    // 051 is a Live catch-up of 037–047; Staging that already has those objects
    // only records the ledger id.
    require_once $root . '/includes/hosted_migration_runtime.php';
    require_once $root . '/includes/schema_migration_numbers.php';
    $duplicatePrefixes = bakery_schema_unexpected_duplicate_prefixes();
    if ($duplicatePrefixes !== []) {
        $parts = [];
        foreach ($duplicatePrefixes as $prefix => $ids) {
            $parts[] = $prefix . ' => ' . implode(', ', $ids);
        }
        throw new RuntimeException(
            'New schema files reused a migration number. Use php scripts/next_schema_migration.php --name=slug. Collisions: '
            . implode('; ', $parts)
        );
    }
    foreach (glob($newMigrationsDir . '/[0-9][0-9][0-9]_*.sql') ?: [] as $migrationPath) {
        $file = basename($migrationPath, '.sql');
        $number = (int)substr($file, 0, 3);
        if ($number < 50 || bakery_migration_applied($db, $file)) {
            continue;
        }
        if ($file === '051_live_ops_catchup'
            && bakery_schema_table_exists($db, 'agent_bugs')
            && bakery_column_exists($db, 'driver_load_items', 'wasted_quantity')) {
            echo "Recording migration {$file} (Staging already has catch-up objects)...\n";
            bakery_mark_migration($db, $file);
            echo "  OK\n";
            continue;
        }
        $migrationSql = (string)file_get_contents($migrationPath);
        if (($supersededBy = bakery_hosted_migration_superseded_by($migrationSql)) !== null) {
            echo "Recording migration {$file} (superseded by {$supersededBy})...\n";
            bakery_mark_migration($db, $file);
            echo "  OK\n";
            continue;
        }
        if ($number >= 55) {
            [$hostedSafe, $hostedMessage] = bakery_hosted_migration_sql_safe($migrationSql);
            if (!$hostedSafe) {
                throw new RuntimeException("Migration {$file} is not portable to the hosted Live gate: {$hostedMessage}");
            }
        }
        echo "Applying migration {$file}...\n";
        foreach (bakery_parse_sql_file($migrationPath) as $statement) {
            bakery_hosted_migration_exec_statement($db, $statement);
        }
        bakery_mark_migration($db, $file);
        echo "  OK\n";
    }

    echo "Migrations complete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

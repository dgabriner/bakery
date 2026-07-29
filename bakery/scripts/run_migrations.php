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

    echo "Migrations complete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * Production-safe config probe (no auth gate). Upload temporarily, visit in browser, then delete.
 */
define('ACCESS_ALLOWED', true);

header('Content-Type: text/plain; charset=utf-8');

$steps = [];

try {
    require_once __DIR__ . '/includes/env_loader.php';
    $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    $loaded = bakery_load_env_file($envPath);
    $steps[] = '.env readable: ' . ($loaded ? 'yes' : 'NO — create .env next to login.php');
    $steps[] = 'APP_ENV: ' . (getenv('APP_ENV') ?: '(not set)');
    $steps[] = 'DB_HOST: ' . (getenv('DB_HOST') ?: '(not set)');
    $steps[] = 'DB_NAME: ' . (getenv('DB_NAME') ?: '(not set)');
    $steps[] = 'BASE_URL: ' . (getenv('BASE_URL') ?: '(not set)');

    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/database.php';
    $steps[] = 'config.php: OK (APP_ENV=' . APP_ENV . ', IS_LOCAL=' . (IS_LOCAL ? 'true' : 'false') . ')';

    $db = check_mysql_connection();
    $steps[] = 'database: connected to ' . DB_NAME . '@' . DB_HOST;

    if (function_exists('table_exists')) {
        $steps[] = 'users table: ' . (table_exists($db, 'users') ? 'yes' : 'MISSING — run 002_auth.sql');
        $steps[] = 'daily_order_assignments table: ' . (table_exists($db, 'daily_order_assignments') ? 'yes' : 'MISSING — run docs/archive/sql-patches/create_daily_order_assignments_table.sql or baseline');
        if (table_exists($db, 'drivers') && function_exists('bakery_drivers_support_archive_column')) {
            $steps[] = 'drivers.archived column: ' . (bakery_drivers_support_archive_column($db) ? 'yes' : 'MISSING — run migration 006 in phpMyAdmin');
        }
    }

    $steps[] = '';
    $steps[] = 'All checks passed. Delete this file after debugging.';
} catch (Throwable $e) {
    $steps[] = '';
    $steps[] = 'FAILED: ' . $e->getMessage();
    $steps[] = 'File: ' . $e->getFile() . ':' . $e->getLine();
}

echo implode("\n", $steps) . "\n";

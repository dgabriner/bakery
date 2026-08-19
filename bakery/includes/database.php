<?php
/**
 * Database Connection and Utility Functions
 * 
 * This file provides centralized database connection management and common
 * database utility functions to reduce code duplication across the application.
 * 
 * @package BakeryManagement
 * @version 1.0
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Establishes a MySQL database connection with proper error handling
 * 
 * @return PDO Database connection object
 * @throws Exception If connection fails
 */
function check_mysql_connection() {
    try {
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ];
        if (defined('USE_PROD_DB') && USE_PROD_DB) {
            // Remote DreamHost from local — bounded waits when the driver supports them.
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 15;
            }
            if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_READ_TIMEOUT] = 120;
            }
            if (defined('PDO::MYSQL_ATTR_COMPRESS')) {
                $options[PDO::MYSQL_ATTR_COMPRESS] = true;
            }
            // Reuse TCP handshakes across PHP built-in server requests when possible.
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            $options
        );
        return $db;
    } catch (PDOException $e) {
        throw new Exception("Database Connection Error: " . $e->getMessage());
    }
}

/**
 * Safely get count from a table with error handling
 * 
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param string $where Optional WHERE clause
 * @return int Count of records
 */
function safe_table_count($db, $table, $where = '') {
    try {
        $query = "SELECT COUNT(*) FROM `$table`";
        if ($where) {
            $query .= " WHERE $where";
        }
        return $db->query($query)->fetchColumn();
    } catch (Exception $e) {
        error_log("Table count error for $table: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check if a table exists in the database
 * 
 * @param PDO $db Database connection
 * @param string $table Table name
 * @return bool True if table exists
 */
function &bakery_table_exists_cache() {
    static $cache = [];
    return $cache;
}

function table_exists($db, $table) {
    $cache = &bakery_table_exists_cache();
    $key = (string)$table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$key]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
        return $cache[$key];
    } catch (Exception $e) {
        error_log("Table exists check error for $table: " . $e->getMessage());
        $cache[$key] = false;
        return false;
    }
}

/** Clear a cached table_exists result after runtime schema creation. */
function bakery_forget_table_exists($table) {
    $cache = &bakery_table_exists_cache();
    unset($cache[(string)$table]);
}

/**
 * Check if a column exists on a table.
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param string $column Column name
 * @return bool True if column exists
 */
function &bakery_schema_column_cache() {
    static $cache = [];
    return $cache;
}

function column_exists($db, $table, $column) {
    $cache = &bakery_schema_column_cache();
    $key = (string)$table . '.' . (string)$column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $safeTable = str_replace('`', '``', (string)$table);
        $safeColumn = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$column);
        $query = "SHOW COLUMNS FROM `{$safeTable}` LIKE " . $db->quote($safeColumn);
        $cache[$key] = $db->query($query)->fetchColumn() !== false;
        return $cache[$key];
    } catch (Exception $e) {
        error_log("Column exists check error for {$table}.{$column}: " . $e->getMessage());
        $cache[$key] = false;
        return false;
    }
}

/** Clear a cached column_exists result (after runtime ALTER). */
function bakery_forget_column_exists($table, $column) {
    $cache = &bakery_schema_column_cache();
    unset($cache[(string)$table . '.' . (string)$column]);
}

/**
 * Get business statistics with proper error handling
 * 
 * @param PDO $db Database connection
 * @return array Array of statistics
 */
function get_business_statistics($db) {
    $stats = [
        // Core metrics
        'total_products' => safe_table_count($db, 'products'),
        'total_customers' => safe_table_count($db, 'customers'),
        'total_orders' => safe_table_count($db, 'orders'),
        'total_ingredients' => safe_table_count($db, 'ingredients'),
        
        // Activity metrics
        'recent_orders' => safe_table_count($db, 'orders', "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'pending_orders' => safe_table_count($db, 'orders', "status = 'pending'"),
        'active_leads' => safe_table_count($db, 'leads', "status IN ('new', 'contacted', 'interested', 'qualified')"),
        
        // Customer insights
        'customers_with_zones' => safe_table_count($db, 'customers', "zone IS NOT NULL AND zone != ''"),
        'customers_with_phone' => safe_table_count($db, 'customers', "phone IS NOT NULL AND phone != ''"),
        'customers_with_email' => safe_table_count($db, 'customers', "email IS NOT NULL AND email != ''"),
        
        // Production metrics
        'total_formulas' => safe_table_count($db, 'formulas'),
        'total_dough_types' => safe_table_count($db, 'dough_types'),
        'production_schedules' => safe_table_count($db, 'production_schedules', "scheduled_date >= CURDATE()"),
        
        // Route metrics
        'customers_with_standing_orders' => 0,
        'total_standing_routes' => 0
    ];
    
    // Handle complex queries separately
    try {
        $stats['customers_with_standing_orders'] = $db->query("SELECT COUNT(DISTINCT customer_id) FROM standing_orders")->fetchColumn();
    } catch (Exception $e) {
        error_log("Standing orders count error: " . $e->getMessage());
    }
    
    try {
        $stats['total_standing_routes'] = $db->query("SELECT COUNT(DISTINCT day_of_week) FROM standing_routes")->fetchColumn();
    } catch (Exception $e) {
        error_log("Standing routes count error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Validate and sanitize database input
 * 
 * @param mixed $value Input value
 * @param string $type Expected type (string, int, email, etc.)
 * @return mixed Sanitized value or null
 */
function sanitize_db_input($value, $type = 'string') {
    if (empty($value) && $value !== '0') {
        return null;
    }
    
    switch ($type) {
        case 'int':
            return filter_var($value, FILTER_VALIDATE_INT);
        case 'float':
            return filter_var($value, FILTER_VALIDATE_FLOAT);
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL);
        case 'string':
        default:
            return trim(strip_tags($value));
    }
}

/**
 * Execute a prepared statement with proper error handling
 * 
 * @param PDO $db Database connection
 * @param string $query SQL query with placeholders
 * @param array $params Parameters for the query
 * @return PDOStatement|false Statement object or false on failure
 */
function safe_execute($db, $query, $params = []) {
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (Exception $e) {
        error_log("Database execution error: " . $e->getMessage() . " Query: " . $query);
        return false;
    }
}

// Initialize database connection for web requests.
// CLI scripts should call check_mysql_connection() explicitly.
if (PHP_SAPI !== 'cli') {
    try {
        if (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: connecting ' . DB_NAME . '@' . DB_HOST);
        }
        $db = check_mysql_connection();
        if (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: connected');
        }
        require_once __DIR__ . '/common_functions.php';
        if (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: common_functions loaded');
        }
        require_once __DIR__ . '/auth.php';
        if (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: auth.php loaded');
        }
        if (!defined('BAKERY_SKIP_REQUEST_SECURITY') || BAKERY_SKIP_REQUEST_SECURITY !== true) {
            if (function_exists('bakery_page_probe_step')) {
                bakery_page_probe_step('db: running auth gate');
            }
            bakery_enforce_request_security($db);
            if (function_exists('bakery_page_probe_step')) {
                bakery_page_probe_step('db: auth gate passed');
            }
        } elseif (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: auth gate skipped (probe/diagnostic)');
        }
    } catch (Exception $e) {
        if (function_exists('bakery_page_probe_step')) {
            bakery_page_probe_step('db: CONNECTION FAILED — ' . $e->getMessage());
            bakery_page_probe_finish('STOPPED — database error');
            exit;
        }
        error_log("Database connection failed: " . $e->getMessage());
        $detail = '';
        if (defined('IS_LOCAL') && IS_LOCAL) {
            $mode = (defined('USE_PROD_DB') && USE_PROD_DB) ? 'USE_PROD_DB=true (production)' : 'local MariaDB';
            $detail = '<br><small style="font-weight:normal">'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                . '<br>Mode: ' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8')
                . ' — target ' . htmlspecialchars((defined('DB_NAME') ? DB_NAME : '?') . '@' . (defined('DB_HOST') ? DB_HOST : '?'), ENT_QUOTES, 'UTF-8')
                . '<br>CLI: php scripts/diag_db_connect.php</small>';
        }
        die('<div class="error"><strong>Connection Error:</strong> Unable to connect to database. Please try again later.' . $detail . '</div>');
    }
} elseif (file_exists(__DIR__ . '/common_functions.php')) {
    require_once __DIR__ . '/common_functions.php';
    if (file_exists(__DIR__ . '/auth.php')) {
        require_once __DIR__ . '/auth.php';
    }
}
 

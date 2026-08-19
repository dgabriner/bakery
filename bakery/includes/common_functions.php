<?php
/**
 * Common Utility Functions
 * 
 * This file contains reusable functions that are used across multiple pages
 * to reduce code duplication and improve maintainability.
 * 
 * @package BakeryManagement
 * @version 1.0
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * JSON safe to embed inside <script> tags (prevents </script> breakage from customer data).
 */
function bakery_json_for_html($data, $default = 'null')
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    return $json === false ? $default : $json;
}

/** True when bakerysf_local still has the fictional demo fixture set. */
function bakery_local_using_demo_fixtures($db) {
    if (!($db instanceof PDO) || !defined('IS_LOCAL') || !IS_LOCAL) {
        return false;
    }
    if (defined('USE_PROD_DB') && USE_PROD_DB) {
        return false;
    }
    if (!function_exists('table_exists') || !table_exists($db, 'customers')) {
        return false;
    }
    try {
        $demo = (int)$db->query("SELECT COUNT(*) FROM customers WHERE name LIKE 'Demo %'")->fetchColumn();
        $total = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        return $total > 0 && $demo === $total;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 
 * @param PDO $db Database connection
 * @param array $config Configuration array with handlers
 * @return array Result with success/error information
 */
function handle_form_submission($db, $config) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
        return ['success' => false, 'error' => 'Invalid request'];
    }
    
    $action = $_POST['action'];
    
    if (!isset($config['handlers'][$action])) {
        return ['success' => false, 'error' => 'Invalid action'];
    }
    
    try {
        return $config['handlers'][$action]($db, $_POST);
    } catch (Exception $e) {
        error_log("Form submission error ($action): " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get success message based on action type
 * 
 * @param string $action The action that was performed
 * @param string $entity The entity type (customer, product, etc.)
 * @return string Success message
 */
function get_success_message($action, $entity = 'item') {
    $messages = [
        'created' => ucfirst($entity) . ' created successfully!',
        'updated' => ucfirst($entity) . ' updated successfully!',
        'deleted' => ucfirst($entity) . ' deleted successfully!',
        'bulk_created' => ucfirst($entity) . 's created successfully!',
        'batch_updated' => ucfirst($entity) . 's updated successfully!'
    ];
    
    return $messages[$action] ?? ucfirst($action) . ' completed successfully!';
}

/**
 * Validate required fields in form data
 * 
 * @param array $data Form data
 * @param array $required Required field names
 * @return array|null Array of missing fields or null if all present
 */
function validate_required_fields($data, $required) {
    $missing = [];
    foreach ($required as $field) {
        if (empty($data[$field]) && $data[$field] !== '0') {
            $missing[] = $field;
        }
    }
    return empty($missing) ? null : $missing;
}

/**
 * Safely redirect to prevent header injection
 * 
 * @param string $location Relative URL to redirect to
 * @param string $success Optional success parameter
 */
function safe_redirect($location, $success = null) {
    $url = $location;
    if ($success) {
        $url .= (strpos($location, '?') !== false ? '&' : '?') . 'success=' . urlencode($success);
    }
    
    // Prevent header injection
    $url = filter_var($url, FILTER_SANITIZE_URL);
    header("Location: $url");
    exit;
}

/**
 * Generate CRUD operation handlers for common database operations
 * 
 * @param string $table Table name
 * @param array $fields Field configuration
 * @return array Array of handler functions
 */
function generate_crud_handlers($table, $fields) {
    return [
        'create' => function($db, $data) use ($table, $fields) {
            $fieldNames = array_keys($fields);
            $placeholders = array_fill(0, count($fieldNames), '?');
            
            $query = "INSERT INTO `$table` (`" . implode('`, `', $fieldNames) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            
            $values = [];
            foreach ($fieldNames as $field) {
                $values[] = sanitize_db_input($data[$field] ?? null, $fields[$field]['type'] ?? 'string');
            }
            
            $stmt = safe_execute($db, $query, $values);
            if ($stmt) {
                return ['success' => true, 'id' => $db->lastInsertId()];
            }
            return ['success' => false, 'error' => 'Failed to create record'];
        },
        
        'update' => function($db, $data) use ($table, $fields) {
            if (!isset($data['id'])) {
                return ['success' => false, 'error' => 'ID required for update'];
            }
            
            $fieldNames = array_keys($fields);
            $setParts = array_map(function($field) { return "`$field` = ?"; }, $fieldNames);
            
            $query = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $values = [];
            foreach ($fieldNames as $field) {
                $values[] = sanitize_db_input($data[$field] ?? null, $fields[$field]['type'] ?? 'string');
            }
            $values[] = (int)$data['id'];
            
            $stmt = safe_execute($db, $query, $values);
            if ($stmt) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => 'Failed to update record'];
        },
        
        'delete' => function($db, $data) use ($table) {
            if (!isset($data['id'])) {
                return ['success' => false, 'error' => 'ID required for deletion'];
            }
            
            $stmt = safe_execute($db, "DELETE FROM `$table` WHERE id = ?", [(int)$data['id']]);
            if ($stmt) {
                return ['success' => true];
            }
            return ['success' => false, 'error' => 'Failed to delete record'];
        }
    ];
}

/**
 * Create a standardized data table with sorting and pagination
 * 
 * @param array $data Table data
 * @param array $columns Column configuration
 * @param array $options Additional options (pagination, etc.)
 * @return string HTML table
 */
function create_data_table($data, $columns, $options = []) {
    $html = '<div class="table-container">';
    
    // Add search if enabled
    if ($options['searchable'] ?? false) {
        $html .= '<div class="table-search">
            <input type="text" id="tableSearch" placeholder="Search..." class="search-input">
        </div>';
    }
    
    $html .= '<table class="data-table">';
    
    // Table header
    $html .= '<thead><tr>';
    foreach ($columns as $key => $column) {
        $sortable = $column['sortable'] ?? false;
        $label = $column['label'] ?? ucfirst(str_replace('_', ' ', $key));
        
        if ($sortable) {
            $html .= "<th class=\"sortable\" data-sort=\"$key\">$label <span class=\"sort-indicator\">⇅</span></th>";
        } else {
            $html .= "<th>$label</th>";
        }
    }
    
    if ($options['actions'] ?? false) {
        $html .= '<th>Actions</th>';
    }
    
    $html .= '</tr></thead>';
    
    // Table body
    $html .= '<tbody>';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($columns as $key => $column) {
            $value = $row[$key] ?? '';
            
            // Apply formatting if specified
            if (isset($column['format'])) {
                $value = $column['format']($value, $row);
            } else {
                $value = htmlspecialchars($value);
            }
            
            $html .= "<td>$value</td>";
        }
        
        // Add action buttons if enabled
        if ($options['actions'] ?? false) {
            $html .= '<td class="actions">';
            $html .= '<button class="btn-small btn-primary" onclick="editRecord(' . $row['id'] . ')">' . htmlspecialchars(bakery_t('common.edit')) . '</button>';
            $html .= '<button class="btn-small btn-danger" onclick="deleteRecord(' . $row['id'] . ')">' . htmlspecialchars(bakery_t('common.delete')) . '</button>';
            $html .= '</td>';
        }
        
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    
    // Add pagination if enabled
    if ($options['paginated'] ?? false) {
        $html .= '<div class="pagination">
            <button id="prevPage" class="btn-secondary">' . htmlspecialchars(bakery_t('ui.previous')) . '</button>
            <span id="pageInfo">' . htmlspecialchars(bakery_t('ui.page', ['num' => '1'])) . '</span>
            <button id="nextPage" class="btn-secondary">' . htmlspecialchars(bakery_t('ui.next')) . '</button>
        </div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Generate form HTML with proper validation and styling
 * 
 * @param array $fields Field definitions
 * @param array $values Current values (for editing)
 * @param array $options Form options
 * @return string HTML form
 */
function generate_form($fields, $values = [], $options = []) {
    $formId = $options['id'] ?? 'mainForm';
    $action = $options['action'] ?? '';
    
    $html = "<form id=\"$formId\" method=\"POST\">";
    
    if ($action) {
        $html .= "<input type=\"hidden\" name=\"action\" value=\"$action\">";
    }
    
    if (isset($values['id'])) {
        $html .= "<input type=\"hidden\" name=\"id\" value=\"" . htmlspecialchars($values['id']) . "\">";
    }
    
    foreach ($fields as $name => $field) {
        $value = $values[$name] ?? '';
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $name));
        $required = $field['required'] ?? false;
        $placeholder = $field['placeholder'] ?? '';
        
        $html .= '<div class="form-group">';
        $html .= "<label for=\"$name\">$label" . ($required ? ' *' : '') . "</label>";
        
        switch ($type) {
            case 'textarea':
                $html .= "<textarea id=\"$name\" name=\"$name\" placeholder=\"$placeholder\"" . 
                        ($required ? ' required' : '') . ">" . htmlspecialchars($value) . "</textarea>";
                break;
                
            case 'select':
                $html .= "<select id=\"$name\" name=\"$name\"" . ($required ? ' required' : '') . ">";
                if (!$required) {
                    $html .= "<option value=\"\">" . htmlspecialchars(bakery_t('ui.select')) . "</option>";
                }
                foreach ($field['options'] as $optValue => $optLabel) {
                    $selected = ($value == $optValue) ? ' selected' : '';
                    $html .= "<option value=\"" . htmlspecialchars($optValue) . "\"$selected>" . 
                            htmlspecialchars($optLabel) . "</option>";
                }
                $html .= "</select>";
                break;
                
            default:
                $html .= "<input type=\"$type\" id=\"$name\" name=\"$name\" value=\"" . 
                        htmlspecialchars($value) . "\" placeholder=\"$placeholder\"" . 
                        ($required ? ' required' : '') . ">";
        }
        
        $html .= '</div>';
    }
    
    $submitText = $options['submit_text'] ?? 'Save';
    $html .= "<div class=\"form-actions\">";
    $html .= "<button type=\"submit\" class=\"btn-primary\">$submitText</button>";
    $html .= "<button type=\"button\" class=\"btn-secondary\" onclick=\"hideForm()\">" . htmlspecialchars(bakery_t('common.cancel')) . "</button>";
    $html .= "</div>";
    
    $html .= '</form>';
    
    return $html;
}

/**
 * Log user actions for audit trail
 * 
 * @param PDO $db Database connection
 * @param string $action Action performed
 * @param string $entity Entity affected
 * @param int $entityId ID of the entity
 * @param string $details Additional details
 */
function log_user_action($db, $action, $entity, $entityId = null, $details = null, $userId = null) {
    if (!function_exists('bakery_operational_log_user_action')) {
        require_once __DIR__ . '/operational_timeline.php';
    }
    bakery_operational_log_user_action($db, $action, $entity, $entityId, $details);
}

/**
 * Cache expensive operations to improve performance
 * 
 * @param string $key Cache key
 * @param callable $callback Function to execute if cache miss
 * @param int $ttl Time to live in seconds
 * @return mixed Cached or fresh data
 */
function cache_operation($key, $callback, $ttl = 300) {
    $cacheFile = sys_get_temp_dir() . '/bakery_cache_' . md5($key);
    
    // Check if cache exists and is valid
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return unserialize(file_get_contents($cacheFile));
    }
    
    // Cache miss - execute callback and cache result
    $result = $callback();
    file_put_contents($cacheFile, serialize($result));
    
    return $result;
}

/**
 * Canonical standing-order weekday: 1 = Monday through 7 = Sunday.
 * Matches standing_orders, production, and local fixtures.
 */
function bakery_standing_day_from_date($date) {
    return (int)date('N', strtotime($date));
}

/**
 * Normalize UI/API day input to canonical 1-7 (legacy Sunday = 0 maps to 7).
 */
function bakery_normalize_standing_day($day) {
    $day = (int)$day;
    return $day === 0 ? 7 : $day;
}

/**
 * Values to match in standing_orders for a canonical day (Sunday includes legacy 0).
 *
 * @return int[]
 */
function bakery_standing_day_match_values($canonicalDay) {
    $canonicalDay = bakery_normalize_standing_day($canonicalDay);
    return $canonicalDay === 7 ? [0, 7] : [$canonicalDay];
}

/**
 * Build an IN (...) SQL fragment and bound values for standing_orders day filters.
 *
 * @return array{sql: string, values: int[]}
 */
function bakery_standing_day_in_clause($canonicalDay) {
    $values = bakery_standing_day_match_values($canonicalDay);
    return [
        'sql' => 'IN (' . implode(',', array_fill(0, count($values), '?')) . ')',
        'values' => $values,
    ];
}

/**
 * Resolve zones.id from a text zone name (null if unknown or empty).
 */
function bakery_zone_id_for_name(PDO $db, $zoneName) {
    if ($zoneName === null || $zoneName === '') {
        return null;
    }
    if (!table_exists($db, 'zones')) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM zones WHERE name = ? LIMIT 1');
    $stmt->execute([(string)$zoneName]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * SQL fragment: join customers to zones via zone_id with name fallback.
 */
function bakery_customer_zone_join_sql() {
    return 'LEFT JOIN zones z ON (c.zone_id IS NOT NULL AND c.zone_id = z.id) OR (c.zone_id IS NULL AND c.zone = z.name)';
}

/**
 * Whether migration 005 inventory columns exist on ingredients.
 */
function bakery_ingredients_inventory_ready(PDO $db) {
    if (!table_exists($db, 'ingredients')) {
        return false;
    }
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME IN (?, ?, ?)'
    );
    $stmt->execute(['ingredients', 'quantity_on_hand', 'reorder_level', 'supplier_name']);
    $ready = (int)$stmt->fetchColumn() === 3;
    return $ready;
}

/**
 * True when an ingredient row is at or below its reorder level.
 * Requires reorder_level to be set (non-null).
 */
function bakery_ingredient_is_low_stock(array $ingredient) {
    if (!array_key_exists('reorder_level', $ingredient) || $ingredient['reorder_level'] === null || $ingredient['reorder_level'] === '') {
        return false;
    }
    $reorder = (float)$ingredient['reorder_level'];
    $qty = ($ingredient['quantity_on_hand'] === null || $ingredient['quantity_on_hand'] === '')
        ? 0.0
        : (float)$ingredient['quantity_on_hand'];
    return $qty <= $reorder;
}

/**
 * Ingredients at or below reorder level (quantity_on_hand <= reorder_level).
 * For future production integration (see production.php). Returns [] when
 * migration 005 columns are not present.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_low_stock_ingredients(PDO $db) {
    if (!bakery_ingredients_inventory_ready($db)) {
        return [];
    }
    $stmt = $db->query(
        'SELECT id, name, unit, quantity_on_hand, reorder_level, supplier_name
         FROM ingredients
         WHERE reorder_level IS NOT NULL
           AND COALESCE(quantity_on_hand, 0) <= reorder_level
         ORDER BY name'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Whether migration 017 purchasing columns exist on ingredients.
 */
function bakery_ingredients_purchasing_ready(PDO $db) {
    if (!table_exists($db, 'ingredients')) {
        return false;
    }
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME IN (?, ?)'
    );
    $stmt->execute(['ingredients', 'package_size', 'unit_cost']);
    $ready = (int)$stmt->fetchColumn() === 2;
    return $ready;
}

/**
 * Format an ingredient's package label, e.g. "50 lb bag" or "25 kg".
 */
function bakery_ingredient_package_label(array $ingredient) {
    $size = $ingredient['package_size'] ?? null;
    $unit = trim((string)($ingredient['unit'] ?? ''));
    if ($size === null || $size === '') {
        return $unit !== '' ? $unit : null;
    }
    $formatted = rtrim(rtrim(number_format((float)$size, 3, '.', ''), '0'), '.');
    return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
}

/**
 * Resolve dashboard date from input or ?date=; defaults to today (Y-m-d).
 */
function bakery_dashboard_resolve_date($input = null) {
    if ($input === null && isset($_GET['date'])) {
        $input = $_GET['date'];
    }
    if ($input === null || $input === '') {
        return date('Y-m-d');
    }
    $input = trim((string)$input);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) || strtotime($input) === false) {
        return date('Y-m-d');
    }
    return $input;
}

/**
 * Daily ops snapshot for a calendar date (uses canonical Sunday=7 weekday helpers).
 *
 * @return array{
 *   date: string,
 *   weekday: int,
 *   daily_order_count: int,
 *   customers_with_orders: int,
 *   assignments_pending: int,
 *   assignments_delivered: int,
 *   standing_order_lines: int,
 *   unassigned_orders: int
 * }
 */
function bakery_dashboard_ops_snapshot(PDO $db, $date) {
    $snapshot = [
        'date' => $date,
        'weekday' => bakery_standing_day_from_date($date),
        'daily_order_count' => 0,
        'customers_with_orders' => 0,
        'assignments_pending' => 0,
        'assignments_delivered' => 0,
        'standing_order_lines' => 0,
        'unassigned_orders' => 0,
    ];

    if (table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE order_date = ?');
        $stmt->execute([$date]);
        $snapshot['daily_order_count'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(DISTINCT customer_id) FROM daily_orders WHERE order_date = ?');
        $stmt->execute([$date]);
        $snapshot['customers_with_orders'] = (int)$stmt->fetchColumn();
    }

    if (table_exists($db, 'daily_order_assignments')) {
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN delivery_status IN ('pending', 'in_transit') THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered
            FROM daily_order_assignments
            WHERE delivery_date = ?
        ");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        $snapshot['assignments_pending'] = (int)($row['pending'] ?? 0);
        $snapshot['assignments_delivered'] = (int)($row['delivered'] ?? 0);

        if (table_exists($db, 'daily_orders')) {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM daily_orders do
                WHERE do.order_date = ?
                AND NOT EXISTS (
                    SELECT 1 FROM daily_order_assignments doa
                    WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
                )
            ");
            $stmt->execute([$date, $date]);
            $snapshot['unassigned_orders'] = (int)$stmt->fetchColumn();
        }
    }

    if (table_exists($db, 'standing_orders')) {
        $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($date));
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM standing_orders
            WHERE quantity > 0 AND day_of_week {$dayClause['sql']}
        ");
        $stmt->execute($dayClause['values']);
        $snapshot['standing_order_lines'] = (int)$stmt->fetchColumn();
    }

    return $snapshot;
}

/**
 * Daily order counts for the last N days ending on $endDate (for mini chart).
 *
 * @return array<int, array{date: string, count: int, label: string, is_today: bool}>
 */
function bakery_dashboard_orders_by_day(PDO $db, $endDate, $days = 7) {
    $days = max(1, (int)$days);
    $result = [];
    $counts = [];

    if (table_exists($db, 'daily_orders')) {
        $startDate = date('Y-m-d', strtotime($endDate . ' -' . ($days - 1) . ' days'));
        $stmt = $db->prepare('
            SELECT order_date, COUNT(*) AS cnt
            FROM daily_orders
            WHERE order_date BETWEEN ? AND ?
            GROUP BY order_date
        ');
        $stmt->execute([$startDate, $endDate]);
        while ($row = $stmt->fetch()) {
            $counts[$row['order_date']] = (int)$row['cnt'];
        }
    }

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($endDate . " -$i days"));
        $result[] = [
            'date' => $d,
            'count' => $counts[$d] ?? 0,
            'label' => date('D', strtotime($d)),
            'is_today' => ($d === $endDate),
        ];
    }

    return $result;
}

/**
 * Driver-scoped dashboard: today's assignments for one driver.
 *
 * @return array{
 *   assignments: array,
 *   pending: int,
 *   delivered: int,
 *   total: int
 * }
 */
function bakery_dashboard_driver_view(PDO $db, $driverId, $date) {
    $view = [
        'assignments' => [],
        'pending' => 0,
        'delivered' => 0,
        'total' => 0,
    ];

    if ($driverId <= 0 || !table_exists($db, 'daily_order_assignments') || !table_exists($db, 'daily_orders')) {
        return $view;
    }

    $stmt = $db->prepare("
        SELECT
            doa.delivery_status,
            doa.route_order,
            doa.scheduled_delivery_time,
            c.name AS customer_name,
            c.address AS customer_address,
            c.zone,
            do.id AS daily_order_id
        FROM daily_order_assignments doa
        JOIN daily_orders do ON do.id = doa.daily_order_id
        JOIN customers c ON do.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.order_date = ?
        ORDER BY doa.route_order, c.name
    ");
    $stmt->execute([$driverId, $date, $date]);
    $view['assignments'] = $stmt->fetchAll();

    foreach ($view['assignments'] as $assignment) {
        $view['total']++;
        if ($assignment['delivery_status'] === 'delivered') {
            $view['delivered']++;
        } elseif (in_array($assignment['delivery_status'], ['pending', 'in_transit'], true)) {
            $view['pending']++;
        }
    }

    return $view;
}

/**
 * Ensure drivers.archived column exists (runtime migration for existing DBs).
 */
function bakery_ensure_drivers_archived_column(PDO $db): void
{
    static $checked = false;
    if ($checked || !table_exists($db, 'drivers')) {
        return;
    }

    try {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'archived');
        $col = $db->query('SHOW COLUMNS FROM drivers LIKE ' . $db->quote($safe))->fetch();
        if (!$col) {
            $db->exec('ALTER TABLE drivers ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER name');
            $db->exec('ALTER TABLE drivers ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER archived');
        }
    } catch (Exception $e) {
        error_log('Driver archive column migration: ' . $e->getMessage());
    }

    $checked = true;
}

/**
 * Whether drivers.archived is present (shared hosts may block ALTER at runtime).
 */
function bakery_drivers_support_archive_column(PDO $db): bool
{
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    if (!table_exists($db, 'drivers')) {
        $supported = false;
        return false;
    }

    bakery_ensure_drivers_archived_column($db);
    $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'archived');
    $col = $db->query('SHOW COLUMNS FROM drivers LIKE ' . $db->quote($safe))->fetch();
    $supported = (bool)$col;
    return $supported;
}

/**
 * Fetch drivers for dropdowns and management UI.
 *
 * @return array<int, array{id:int, name:string, archived?:int, archived_at?:string|null}>
 */
function bakery_get_drivers(PDO $db, bool $includeArchived = false): array
{
    if (!table_exists($db, 'drivers')) {
        return [];
    }

    if (bakery_drivers_support_archive_column($db)) {
        $sql = 'SELECT id, name, archived, archived_at FROM drivers';
        if (!$includeArchived) {
            $sql .= ' WHERE archived = 0 ORDER BY name ASC';
        } else {
            $sql .= ' ORDER BY archived ASC, name ASC';
        }
        return $db->query($sql)->fetchAll();
    }

    return $db->query('SELECT id, name FROM drivers ORDER BY name ASC')->fetchAll();
}

/**
 * Fetch a single driver row, including archived drivers.
 */
function bakery_get_driver_by_id(PDO $db, int $driverId): ?array
{
    if ($driverId <= 0 || !table_exists($db, 'drivers')) {
        return null;
    }

    if (bakery_drivers_support_archive_column($db)) {
        $stmt = $db->prepare('SELECT id, name, archived, archived_at FROM drivers WHERE id = ?');
    } else {
        $stmt = $db->prepare('SELECT id, name FROM drivers WHERE id = ?');
    }
    $stmt->execute([$driverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Ensure standing routes can remember the preferred stop order.
 * Existing route rows remain valid with a NULL order and continue to use
 * the normal customer-name fallback until a route is saved from a day.
 */
function bakery_ensure_standing_routes_order_column(PDO $db): void
{
    static $checked = false;
    if ($checked || !table_exists($db, 'standing_routes')) {
        return;
    }

    try {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'route_order');
        $col = $db->query('SHOW COLUMNS FROM standing_routes LIKE ' . $db->quote($safe))->fetch();
        if (!$col) {
            $db->exec('ALTER TABLE standing_routes ADD COLUMN route_order INT NULL DEFAULT NULL AFTER customer_id');
        }
    } catch (Exception $e) {
        error_log('Standing route order column migration: ' . $e->getMessage());
    }

    $checked = true;
}

/**
 * Standard starter formula templates for empty dough formulas.
 *
 * @return array<string, array{label:string, description:string, ingredients:array<int, array{keywords:array<int, string>, percentage:float}>}>
 */
function bakery_formula_starter_templates(): array
{
    return [
        'sourdough' => [
            'label' => 'Sourdough basics',
            'description' => 'Flour, water, salt, and starter for a classic levain dough.',
            'ingredients' => [
                ['keywords' => ['flour', 'harina'], 'percentage' => 100.0],
                ['keywords' => ['water', 'agua'], 'percentage' => 75.0],
                ['keywords' => ['salt', 'sal'], 'percentage' => 2.2],
                ['keywords' => ['starter', 'levain', 'masa madre'], 'percentage' => 20.0],
            ],
        ],
        'sweet' => [
            'label' => 'Sweet dough basics',
            'description' => 'Flour, water, sugar, and salt for pan dulce-style dough.',
            'ingredients' => [
                ['keywords' => ['flour', 'harina'], 'percentage' => 100.0],
                ['keywords' => ['water', 'agua'], 'percentage' => 55.0],
                ['keywords' => ['sugar', 'azucar', 'azúcar'], 'percentage' => 15.0],
                ['keywords' => ['salt', 'sal'], 'percentage' => 1.8],
            ],
        ],
        'white' => [
            'label' => 'White bread basics',
            'description' => 'Simple flour, water, and salt for sandwich loaves.',
            'ingredients' => [
                ['keywords' => ['flour', 'harina'], 'percentage' => 100.0],
                ['keywords' => ['water', 'agua'], 'percentage' => 65.0],
                ['keywords' => ['salt', 'sal'], 'percentage' => 2.0],
            ],
        ],
    ];
}

/**
 * Common ingredient suggestions shown as quick-add chips.
 *
 * @return array<int, array{keywords:array<int, string>, percentage:float, label:string}>
 */
function bakery_formula_common_suggestions(): array
{
    return [
        ['keywords' => ['flour', 'harina'], 'percentage' => 100.0, 'label' => 'Flour'],
        ['keywords' => ['water', 'agua'], 'percentage' => 75.0, 'label' => 'Water'],
        ['keywords' => ['salt', 'sal'], 'percentage' => 2.0, 'label' => 'Salt'],
        ['keywords' => ['starter', 'levain', 'masa madre'], 'percentage' => 20.0, 'label' => 'Starter'],
        ['keywords' => ['sugar', 'azucar', 'azúcar'], 'percentage' => 15.0, 'label' => 'Sugar'],
        ['keywords' => ['yeast', 'levadura'], 'percentage' => 1.0, 'label' => 'Yeast'],
        ['keywords' => ['butter', 'mantequilla'], 'percentage' => 5.0, 'label' => 'Butter'],
        ['keywords' => ['oil', 'aceite'], 'percentage' => 3.0, 'label' => 'Oil'],
    ];
}

/**
 * Match a catalogue ingredient by keyword list (case-insensitive substring).
 */
function bakery_match_ingredient_by_keywords(array $ingredients, array $keywords, array $exclude_ids = []): ?array
{
    foreach ($ingredients as $ingredient) {
        $id = (int)($ingredient['id'] ?? 0);
        if ($id <= 0 || in_array($id, $exclude_ids, true)) {
            continue;
        }
        $name = strtolower((string)($ingredient['name'] ?? ''));
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($name, strtolower($keyword))) {
                return $ingredient;
            }
        }
    }
    return null;
}

/**
 * Resolve a starter template to ingredients available in the catalogue.
 *
 * @return array<int, array{ingredient_id:int, name:string, unit:string, percentage:float}>
 */
function bakery_formula_resolve_starter(array $ingredients, string $template_key, array $exclude_ids = []): array
{
    $templates = bakery_formula_starter_templates();
    if (!isset($templates[$template_key])) {
        return [];
    }

    $resolved = [];
    $used = $exclude_ids;
    foreach ($templates[$template_key]['ingredients'] as $item) {
        $match = bakery_match_ingredient_by_keywords($ingredients, $item['keywords'], $used);
        if (!$match) {
            continue;
        }
        $resolved[] = [
            'ingredient_id' => (int)$match['id'],
            'name' => (string)$match['name'],
            'unit' => (string)($match['unit'] ?? ''),
            'percentage' => (float)$item['percentage'],
        ];
        $used[] = (int)$match['id'];
    }
    return $resolved;
}

/**
 * Suggest common ingredients not yet in a formula.
 *
 * @return array<int, array{ingredient_id:int, name:string, percentage:float, label:string}>
 */
function bakery_formula_suggest_missing(array $all_ingredients, array $used_ids): array
{
    $suggestions = [];
    foreach (bakery_formula_common_suggestions() as $suggestion) {
        $match = bakery_match_ingredient_by_keywords($all_ingredients, $suggestion['keywords'], $used_ids);
        if (!$match) {
            continue;
        }
        $suggestions[] = [
            'ingredient_id' => (int)$match['id'],
            'name' => (string)$match['name'],
            'percentage' => (float)$suggestion['percentage'],
            'label' => (string)$suggestion['label'],
        ];
    }
    return $suggestions;
}

/**
 * Insert formula ingredients, skipping duplicates already in the formula.
 */
function bakery_formula_apply_ingredients(PDO $db, int $dough_type_id, array $items): int
{
    if ($dough_type_id <= 0 || empty($items)) {
        return 0;
    }

    $added = 0;
    $stmt = $db->prepare(
        'INSERT IGNORE INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?)'
    );
    foreach ($items as $item) {
        $ingredient_id = (int)($item['ingredient_id'] ?? 0);
        if ($ingredient_id <= 0) {
            continue;
        }
        $stmt->execute([$dough_type_id, $ingredient_id, $item['percentage']]);
        if ($stmt->rowCount() > 0) {
            $added++;
        }
    }
    return $added;
}

if (is_readable(__DIR__ . '/sfb_origin.php')) {
    require_once __DIR__ . '/sfb_origin.php';
}

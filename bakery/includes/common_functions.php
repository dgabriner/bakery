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
 * Process POST form submission with CSRF protection and validation
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
            $html .= '<button class="btn-small btn-primary" onclick="editRecord(' . $row['id'] . ')">Edit</button>';
            $html .= '<button class="btn-small btn-danger" onclick="deleteRecord(' . $row['id'] . ')">Delete</button>';
            $html .= '</td>';
        }
        
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    
    // Add pagination if enabled
    if ($options['paginated'] ?? false) {
        $html .= '<div class="pagination">
            <button id="prevPage" class="btn-secondary">Previous</button>
            <span id="pageInfo">Page 1</span>
            <button id="nextPage" class="btn-secondary">Next</button>
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
                    $html .= "<option value=\"\">-- Select --</option>";
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
    $html .= "<button type=\"button\" class=\"btn-secondary\" onclick=\"hideForm()\">Cancel</button>";
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
function log_user_action($db, $action, $entity, $entityId = null, $details = null) {
    try {
        $query = "INSERT INTO audit_log (action, entity, entity_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        safe_execute($db, $query, [$action, $entity, $entityId, $details]);
    } catch (Exception $e) {
        // Silently fail - audit logging shouldn't break the application
        error_log("Audit log error: " . $e->getMessage());
    }
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
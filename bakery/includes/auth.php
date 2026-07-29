<?php
/**
 * Authentication, authorization, and CSRF (Checkpoint 0D).
 *
 * Roles: administrator, manager, driver (extensible via permissions tables).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

define('BAKERY_SESSION_IDLE_SECONDS', 8 * 60 * 60); // 8 hours idle
define('BAKERY_SESSION_ABSOLUTE_SECONDS', 12 * 60 * 60);

/**
 * Scripts reachable without login.
 */
function bakery_public_scripts() {
    return [
        'login.php',
        'health_local.php',
    ];
}

/**
 * Diagnostics / dangerous tools — administrator only after login.
 */
function bakery_diagnostic_scripts() {
    return [
        'test.php',
        'test_php.php',
        'test_simple.php',
        'test_table.php',
        'test_display.php',
        'test_ajax.php',
        'test_ajax_json.php',
        'test_ajax_photos.php',
        'test_photo_display.php',
        'test_db.php',
        'test_driver_assignment.php',
        'test_bread_distribution_performance.php',
        'test_order_details.php',
        'test_oauth_email.php',
        'db_test.php',
        'debug.php',
        'simple-debug.php',
        'debug_order_details.php',
        'debug_driver_assignment.php',
        'debug_driver_interface.php',
        'debug_invoice.php',
        'debug_photo_upload.php',
        'table_debug.php',
        'check_photo_db.php',
        'find_photo_ids.php',
        'run_sql_setup.php',
        'setup_directories.php',
        'oauth_setup.php',
        'simple_performance_test.php',
        'test_email.php',
    ];
}

/**
 * Driver-only pages (managers/admins also allowed).
 */
function bakery_driver_scripts() {
    return [
        'driver.php',
        'driver_list.php',
        'complete_delivery.php',
        'get_customer_order_details.php',
        'global_gps_handler.php',
        'call_headquarters.php',
    ];
}

function bakery_current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['user_id'],
        'email' => $_SESSION['user_email'] ?? '',
        'display_name' => $_SESSION['user_display_name'] ?? '',
        'role_slug' => $_SESSION['user_role_slug'] ?? '',
        'driver_id' => isset($_SESSION['user_driver_id']) ? (int)$_SESSION['user_driver_id'] : null,
    ];
}

function bakery_user_has_role($roles) {
    $user = bakery_current_user();
    if (!$user) {
        return false;
    }
    $roles = (array)$roles;
    return in_array($user['role_slug'], $roles, true);
}

function bakery_user_has_permission(PDO $db, $permissionSlug) {
    $user = bakery_current_user();
    if (!$user) {
        return false;
    }
    if ($user['role_slug'] === 'administrator') {
        return true;
    }
    $stmt = $db->prepare(
        "SELECT 1
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE u.id = ? AND p.slug = ?
         LIMIT 1"
    );
    $stmt->execute([$user['id'], $permissionSlug]);
    return (bool)$stmt->fetchColumn();
}

function bakery_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bakery_csrf_field() {
    $token = htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function bakery_request_csrf_token() {
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return (string)$token;
}

function bakery_verify_csrf() {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $provided = bakery_request_csrf_token();
    if ($sessionToken === '' || $provided === '' || !hash_equals($sessionToken, $provided)) {
        return false;
    }
    return true;
}

function bakery_require_csrf() {
    if (!bakery_verify_csrf()) {
        if (is_ajax_request() || bakery_wants_json()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token']);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: invalid or missing CSRF token.\n";
        exit;
    }
}

function bakery_wants_json() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $uri = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        return true;
    }
    // Known JSON endpoints
    $jsonScripts = [
        'complete_delivery.php',
        'get_customer_order_details.php',
        'global_gps_handler.php',
        'get_driver_orders.php',
    ];
    return in_array(basename($uri), $jsonScripts, true);
}

function bakery_touch_session() {
    $now = time();
    if (!isset($_SESSION['auth_login_at'])) {
        return;
    }
    if (($now - (int)$_SESSION['auth_login_at']) > BAKERY_SESSION_ABSOLUTE_SECONDS) {
        bakery_logout();
        return;
    }
    if (isset($_SESSION['auth_last_activity']) &&
        ($now - (int)$_SESSION['auth_last_activity']) > BAKERY_SESSION_IDLE_SECONDS) {
        bakery_logout();
        return;
    }
    $_SESSION['auth_last_activity'] = $now;
}

function bakery_login(PDO $db, $email, $password) {
    $email = strtolower(trim($email));
    $stmt = $db->prepare(
        "SELECT u.*, r.slug AS role_slug
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['user_email'] = $row['email'];
    $_SESSION['user_display_name'] = $row['display_name'];
    $_SESSION['user_role_slug'] = $row['role_slug'];
    $_SESSION['user_driver_id'] = $row['driver_id'] !== null ? (int)$row['driver_id'] : null;
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    // Refresh CSRF after login
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $upd = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $upd->execute([(int)$row['id']]);

    return true;
}

function bakery_logout() {
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies') && session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            (bool)$params['secure'], (bool)$params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function bakery_require_login() {
    bakery_touch_session();
    if (bakery_current_user()) {
        return;
    }
    if (is_ajax_request() || bakery_wants_json()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '/bakery/index.php';
    header('Location: ' . BASE_URL . 'login.php?next=' . urlencode($next));
    exit;
}

function bakery_require_role($roles) {
    bakery_require_login();
    if (!bakery_user_has_role($roles)) {
        http_response_code(403);
        if (is_ajax_request() || bakery_wants_json()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: your role cannot access this resource.\n";
        exit;
    }
}

/**
 * Enforce auth + CSRF for web requests after DB is ready.
 */
function bakery_enforce_request_security(PDO $db = null) {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = basename($_SERVER['PHP_SELF'] ?? '');

    if (in_array($script, bakery_public_scripts(), true)) {
        return;
    }

    bakery_require_login();

    if (in_array($script, bakery_diagnostic_scripts(), true)) {
        bakery_require_role(['administrator']);
    } elseif (in_array($script, bakery_driver_scripts(), true)) {
        bakery_require_role(['administrator', 'manager', 'driver']);
    } else {
        // Default ops UI: manager + administrator. Drivers stay on driver scripts.
        bakery_require_role(['administrator', 'manager']);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        bakery_require_csrf();
    }
}

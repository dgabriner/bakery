<?php
/**
 * Authentication, authorization, and CSRF (Checkpoint 0D).
 *
 * Roles: administrator, manager, driver, baker (extensible via permissions tables).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

define('BAKERY_SESSION_IDLE_SECONDS', 8 * 60 * 60); // 8 hours idle
define('BAKERY_SESSION_ABSOLUTE_SECONDS', 12 * 60 * 60);

/** Fixed baker auto-login credentials (used by baker.php). */
define('BAKERY_BAKER_EMAIL', 'juan.carlos@sourflour.local');
define('BAKERY_BAKER_CODE', '1234');
define('BAKERY_BAKER_DISPLAY_NAME', 'Juan Carlos Hernandez');
define('BAKERY_NIKO_EMAIL', 'niko@sourflour.local');
define('BAKERY_NIKO_CODE', '2468');
define('BAKERY_NIKO_DISPLAY_NAME', 'Niko');

/** Durable admin code login (danny@sourflour.org). */
define('BAKERY_ADMIN_EMAIL', 'danny@sourflour.org');
define('BAKERY_ADMIN_CODE', '9741');
define('BAKERY_ADMIN_DISPLAY_NAME', 'Danny');

/**
 * Scripts reachable without login.
 */
function bakery_public_scripts() {
    return [
        'login.php',
        'logout.php',
        'baker.php',
        'health_local.php',
        'health_prod.php',
        'health_driver.php',
        'health_deploy.php',
        'ping.php',
        'trace_driver_list.php',
        'driver_pages_probe.php',
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
        'index.php',
        'driver.php',
        'driver_list.php',
        'complete_delivery.php',
        'upload_driver_photo.php',
        'get_customer_order_details.php',
        'get_driver_orders.php',
        'global_gps_handler.php',
        'call_headquarters.php',
    ];
}

/**
 * Baker pages (managers/admins also allowed). Bakers receive only the
 * day-of production schedule and the packing checklist; weekly planning and
 * inventory remain management workflows.
 */
function bakery_baker_scripts() {
    return [
        'production.php',
        'pack_list.php',
    ];
}

/**
 * Normalize a 4-digit login code. Returns '' when invalid.
 */
function bakery_normalize_login_code($code) {
    $digits = preg_replace('/\D/', '', (string)$code);
    if ($digits === null || !preg_match('/^\d{4}$/', $digits)) {
        return '';
    }
    return $digits;
}

/**
 * Ensure users.login_code exists (idempotent).
 */
function bakery_ensure_login_code_column(PDO $db) {
    static $done = false;
    if ($done || !table_exists($db, 'users')) {
        return;
    }
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'login_code'");
    if (!$stmt || !$stmt->fetch()) {
        $db->exec('ALTER TABLE users ADD COLUMN login_code CHAR(4) NULL AFTER password_hash');
        try {
            $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_login_code (login_code)');
        } catch (Throwable $e) {
            // Unique key may already exist under another name.
        }
    }
    $done = true;
}

/**
 * Find or create a drivers row by exact name.
 */
function bakery_ensure_driver_named(PDO $db, $name) {
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM drivers WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $ins = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $ins->execute([$name]);
    return (int)$db->lastInsertId();
}

/**
 * Create or update a login user identified by email (idempotent).
 */
function bakery_upsert_code_user(PDO $db, array $user) {
    bakery_ensure_login_code_column($db);

    $email = strtolower(trim((string)($user['email'] ?? '')));
    $name = trim((string)($user['display_name'] ?? ''));
    $roleSlug = trim((string)($user['role'] ?? ''));
    $code = bakery_normalize_login_code($user['code'] ?? '');
    $driverId = array_key_exists('driver_id', $user) ? $user['driver_id'] : null;
    if ($driverId !== null) {
        $driverId = (int)$driverId;
        if ($driverId <= 0) {
            $driverId = null;
        }
    }

    if ($email === '' || $name === '' || $roleSlug === '' || $code === '') {
        return false;
    }

    $roleStmt = $db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
    $roleStmt->execute([$roleSlug]);
    $roleId = $roleStmt->fetchColumn();
    if (!$roleId) {
        return false;
    }

    // Codes must be unique across active users (other than this email).
    $clash = $db->prepare(
        'SELECT id FROM users WHERE login_code = ? AND LOWER(email) <> LOWER(?) LIMIT 1'
    );
    $clash->execute([$code, $email]);
    if ($clash->fetchColumn()) {
        return false;
    }

    $hash = password_hash($code, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           password_hash = VALUES(password_hash),
           login_code = VALUES(login_code),
           display_name = VALUES(display_name),
           role_id = VALUES(role_id),
           driver_id = VALUES(driver_id),
           is_active = 1"
    );
    $stmt->execute([$email, $hash, $code, $name, (int)$roleId, $driverId]);
    return true;
}

/**
 * Ensure baker role and Juan Carlos baker user exist (idempotent).
 */
function bakery_ensure_baker_user(PDO $db) {
    $db->exec(
        "INSERT INTO roles (slug, name, description) VALUES
         ('baker', 'Baker', 'Production and pack list only')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)"
    );

    $permStmt = $db->prepare('SELECT id FROM permissions WHERE slug = ? LIMIT 1');
    $permStmt->execute(['ops.manage']);
    $permId = $permStmt->fetchColumn();
    if ($permId) {
        $roleStmt = $db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
        $roleStmt->execute(['baker']);
        $roleId = $roleStmt->fetchColumn();
        if ($roleId) {
            $link = $db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE role_id = role_id'
            );
            $link->execute([(int)$roleId, (int)$permId]);
        }
    }

    // Migrate legacy baker email key if present.
    bakery_ensure_login_code_column($db);
    $legacy = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $legacy->execute(['baker']);
    $legacyId = $legacy->fetchColumn();
    if ($legacyId) {
        $taken = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $taken->execute([BAKERY_BAKER_EMAIL, (int)$legacyId]);
        if (!$taken->fetchColumn()) {
            $upd = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
            $upd->execute([BAKERY_BAKER_EMAIL, (int)$legacyId]);
        }
    }

    $ok = bakery_upsert_code_user($db, [
        'email' => BAKERY_BAKER_EMAIL,
        'display_name' => BAKERY_BAKER_DISPLAY_NAME,
        'role' => 'baker',
        'code' => BAKERY_BAKER_CODE,
        'driver_id' => null,
    ]);
    bakery_ensure_niko_user($db);
    bakery_ensure_baker_product_assignments($db);
    return $ok;
}

function bakery_ensure_niko_user(PDO $db) {
    return bakery_upsert_code_user($db, [
        'email' => BAKERY_NIKO_EMAIL,
        'display_name' => BAKERY_NIKO_DISPLAY_NAME,
        'role' => 'baker',
        'code' => BAKERY_NIKO_CODE,
        'driver_id' => null,
    ]);
}

/** Keep baker visibility assignments aligned with the named product lines. */
function bakery_ensure_baker_product_assignments(PDO $db) {
    if (!table_exists($db, 'baker_product_lines') || !table_exists($db, 'product_lines')) {
        return;
    }
    $stmt = $db->prepare(
        "INSERT IGNORE INTO baker_product_lines (baker_user_id, product_line_id)
         SELECT u.id, pl.id
         FROM users u CROSS JOIN product_lines pl
         WHERE LOWER(u.email) = ? AND pl.name IN (?, ?)"
    );
    $stmt->execute([BAKERY_BAKER_EMAIL, 'Sour Flour', 'Traditional']);
    $stmt = $db->prepare(
        "INSERT IGNORE INTO baker_product_lines (baker_user_id, product_line_id)
         SELECT u.id, pl.id
         FROM users u CROSS JOIN product_lines pl
         WHERE LOWER(u.email) = ? AND pl.name = ?"
    );
    $stmt->execute([BAKERY_NIKO_EMAIL, 'Pan Dulce']);
}

/** Return assigned product IDs for the signed-in baker; null means not a baker/not migrated. */
function bakery_baker_product_ids(PDO $db) {
    $user = bakery_current_user();
    if (!$user || ($user['role_slug'] ?? '') !== 'baker' || !table_exists($db, 'baker_product_lines')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT DISTINCT p.id
         FROM products p
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN baker_product_lines bpl ON bpl.product_line_id = dt.product_line_id
         WHERE bpl.baker_user_id = ?'
    );
    $stmt->execute([(int)$user['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Ensure primary staff code logins (admin, baker, drivers).
 */
function bakery_ensure_staff_code_users(PDO $db) {
    bakery_ensure_login_code_column($db);
    bakery_ensure_baker_user($db);

    bakery_upsert_code_user($db, [
        'email' => BAKERY_ADMIN_EMAIL,
        'display_name' => BAKERY_ADMIN_DISPLAY_NAME,
        'role' => 'administrator',
        'code' => BAKERY_ADMIN_CODE,
        'driver_id' => null,
    ]);

    $sergioId = bakery_ensure_driver_named($db, 'Sergio');
    bakery_upsert_code_user($db, [
        'email' => 'sergio@sourflour.local',
        'display_name' => 'Sergio',
        'role' => 'driver',
        'code' => '1111',
        'driver_id' => $sergioId,
    ]);

    $lauraId = bakery_ensure_driver_named($db, 'Laura');
    bakery_upsert_code_user($db, [
        'email' => 'laura@sourflour.local',
        'display_name' => 'Laura',
        'role' => 'driver',
        'code' => '7286',
        'driver_id' => $lauraId,
    ]);

    return true;
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
        'upload_driver_photo.php',
        'get_customer_order_details.php',
        'global_gps_handler.php',
        'get_driver_orders.php',
        'auto_push_api.php',
        'generate_invoice_simple.php',
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

function bakery_login(PDO $db, $code) {
    bakery_ensure_login_code_column($db);

    $code = bakery_normalize_login_code($code);
    if ($code === '') {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT u.*, r.slug AS role_slug
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.login_code = ? AND u.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
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

    // Drivers land on their own route identity.
    if ($row['role_slug'] === 'driver' && $row['driver_id'] !== null) {
        $driverName = '';
        $nameStmt = $db->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
        $nameStmt->execute([(int)$row['driver_id']]);
        $driverName = (string)($nameStmt->fetchColumn() ?: $row['display_name']);
        bakery_set_selected_driver((int)$row['driver_id'], $driverName);
    }

    return true;
}

/** Cookie name for remembered on-route driver selection (not auth identity). */
define('BAKERY_SELECTED_DRIVER_COOKIE', 'bakery_selected_driver_id');

/**
 * Remembered driver for the route UI (session + cookie), falling back to linked user.driver_id.
 */
function bakery_get_selected_driver_id() {
    if (!empty($_SESSION['selected_driver_id'])) {
        return (int)$_SESSION['selected_driver_id'];
    }
    if (!empty($_COOKIE[BAKERY_SELECTED_DRIVER_COOKIE])) {
        $fromCookie = (int)$_COOKIE[BAKERY_SELECTED_DRIVER_COOKIE];
        if ($fromCookie > 0) {
            $_SESSION['selected_driver_id'] = $fromCookie;
            return $fromCookie;
        }
    }
    $user = bakery_current_user();
    if ($user && !empty($user['driver_id'])) {
        return (int)$user['driver_id'];
    }
    return 0;
}

function bakery_get_selected_driver_name() {
    if (!empty($_SESSION['selected_driver_name'])) {
        return (string)$_SESSION['selected_driver_name'];
    }
    if (!empty($_COOKIE['bakery_selected_driver_name'])) {
        $name = (string)$_COOKIE['bakery_selected_driver_name'];
        $_SESSION['selected_driver_name'] = $name;
        return $name;
    }
    return '';
}

/**
 * Persist who is driving for the route UI (survives reloads; cleared on logout).
 */
function bakery_set_selected_driver($driverId, $driverName = '') {
    $driverId = (int)$driverId;
    $cookiePath = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    $secure = function_exists('isHTTPS') ? isHTTPS() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if ($driverId > 0) {
        $_SESSION['selected_driver_id'] = $driverId;
        if ($driverName !== '') {
            $_SESSION['selected_driver_name'] = $driverName;
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            $expires = time() + (365 * 24 * 60 * 60);
            setcookie(BAKERY_SELECTED_DRIVER_COOKIE, (string)$driverId, [
                'expires' => $expires,
                'path' => $cookiePath,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if ($driverName !== '') {
                setcookie('bakery_selected_driver_name', $driverName, [
                    'expires' => $expires,
                    'path' => $cookiePath,
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        return;
    }

    unset($_SESSION['selected_driver_id'], $_SESSION['selected_driver_name']);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie(BAKERY_SELECTED_DRIVER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie('bakery_selected_driver_name', '', [
            'expires' => time() - 3600,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function bakery_logout() {
    $cookiePath = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    $secure = function_exists('isHTTPS') ? isHTTPS() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie(BAKERY_SELECTED_DRIVER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie('bakery_selected_driver_name', '', [
            'expires' => time() - 3600,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

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
    $next = $_SERVER['REQUEST_URI'] ?? (defined('BASE_URL') ? BASE_URL . 'index.php' : '/bakery/index.php');
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

    $isDiagnostic = in_array($script, bakery_diagnostic_scripts(), true);
    $isDriverScript = in_array($script, bakery_driver_scripts(), true);
    $isBakerScript = in_array($script, bakery_baker_scripts(), true);

    if ($isDiagnostic) {
        bakery_require_role(['administrator']);
    } elseif ($isBakerScript && $isDriverScript) {
        // Overlap (index.php): admins, managers, drivers, and bakers.
        bakery_require_role(['administrator', 'manager', 'driver', 'baker']);
    } elseif ($isBakerScript) {
        bakery_require_role(['administrator', 'manager', 'baker']);
    } elseif ($isDriverScript) {
        bakery_require_role(['administrator', 'manager', 'driver']);
    } else {
        // Default ops UI: manager + administrator. Drivers/bakers stay on their scripts.
        bakery_require_role(['administrator', 'manager']);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        bakery_require_csrf();
    }
}

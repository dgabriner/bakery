<?php
/**
 * Authentication, authorization, and CSRF (Checkpoint 0D).
 *
 * Roles: administrator, manager, driver, driver_assistant, baker (extensible via permissions tables).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/login_audit.php';

// Bakery work often spans invoicing, dispatch, and a later photo at the next stop.
// Keep a signed-in session through that operational window instead of making a
// driver or manager re-enter a code mid-route. The lower-level PHP session store
// is kept for the same duration in config.php.
define('BAKERY_SESSION_IDLE_SECONDS', 30 * 24 * 60 * 60); // 30 days idle
define('BAKERY_SESSION_ABSOLUTE_SECONDS', 90 * 24 * 60 * 60); // 90 days maximum
define('BAKERY_DRIVER_SESSION_IDLE_SECONDS', 60 * 24 * 60 * 60); // 60 days idle
define('BAKERY_DRIVER_SESSION_ABSOLUTE_SECONDS', 180 * 24 * 60 * 60); // 180 days maximum
define('BAKERY_DRIVER_TRUST_COOKIE', 'bakery_driver_trusted_device');
define('BAKERY_DRIVER_TRUST_SECONDS', 400 * 24 * 60 * 60); // Browser-supported rolling maximum

/** Fixed baker auto-login credentials (used by baker.php). */
define('BAKERY_BAKER_EMAIL', trim((string)($_ENV['BAKERY_BAKER_EMAIL'] ?? getenv('BAKERY_BAKER_EMAIL') ?: '')));
define('BAKERY_BAKER_CODE', bakery_normalize_login_code($_ENV['BAKERY_BAKER_CODE'] ?? getenv('BAKERY_BAKER_CODE') ?: ''));
define('BAKERY_BAKER_DISPLAY_NAME', trim((string)($_ENV['BAKERY_BAKER_DISPLAY_NAME'] ?? getenv('BAKERY_BAKER_DISPLAY_NAME') ?: 'Baker')));
define('BAKERY_NIKO_EMAIL', trim((string)($_ENV['BAKERY_NIKO_EMAIL'] ?? getenv('BAKERY_NIKO_EMAIL') ?: '')));
define('BAKERY_NIKO_CODE', bakery_normalize_login_code($_ENV['BAKERY_NIKO_CODE'] ?? getenv('BAKERY_NIKO_CODE') ?: ''));
define('BAKERY_NIKO_DISPLAY_NAME', trim((string)($_ENV['BAKERY_NIKO_DISPLAY_NAME'] ?? getenv('BAKERY_NIKO_DISPLAY_NAME') ?: 'Baker')));
define('BAKERY_ADMIN_EMAIL', trim((string)($_ENV['BAKERY_ADMIN_EMAIL'] ?? getenv('BAKERY_ADMIN_EMAIL') ?: '')));
define('BAKERY_ADMIN_CODE', bakery_normalize_login_code($_ENV['BAKERY_ADMIN_CODE'] ?? getenv('BAKERY_ADMIN_CODE') ?: ''));
define('BAKERY_ADMIN_DISPLAY_NAME', trim((string)($_ENV['BAKERY_ADMIN_DISPLAY_NAME'] ?? getenv('BAKERY_ADMIN_DISPLAY_NAME') ?: 'Administrator')));

/**
 * Scripts reachable without login.
 */
function bakery_public_scripts() {
    return [
        'login.php',
        'logout.php',
        'guias.php',
        'health_local.php',
        'customer_login.php',
        'customer_qr_login.php',
        'customer_portal_login.php',
        'customer_portal_logout.php',
        'login_audit_api.php',
        // Sender-signed webhooks: no staff session exists; each endpoint
        // validates its own provider signature before touching anything.
        'square_webhook.php',
        'twilio_webhook.php',
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
        'driver_stops.php',
        'pack_list.php',
        'driver_list.php',
        'route_closeout.php',
        'complete_delivery.php',
        'driver_session_ping.php',
        'upload_driver_photo.php',
        'get_customer_order_details.php',
        'get_driver_orders.php',
        'global_gps_handler.php',
        'call_headquarters.php',
        'qr_login.php',
    ];
}

/** Roles that work a driver route (the assistant works their paired driver's route). */
function bakery_driver_route_roles(): array {
    return ['driver', 'driver_assistant'];
}

function bakery_is_driver_route_role($role): bool {
    return in_array((string)$role, bakery_driver_route_roles(), true);
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

/** Durable IP-based throttle backed by the login audit table. */
function bakery_login_attempt_allowed(PDO $db, string $authType): bool
{
    if (!function_exists('bakery_login_audit_ready') || !bakery_login_audit_ready($db)) {
        return false; // Fail closed until the durable audit table is installed.
    }
    $ip = substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 64);
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_audit
             WHERE auth_type = ? AND outcome = 'failure' AND ip_address = ?
               AND login_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$authType === 'customer' ? 'customer' : 'staff', $ip]);
        return (int)$stmt->fetchColumn() < 5;
    } catch (Throwable $e) {
        error_log('Login throttle lookup failed: ' . $e->getMessage());
        return false;
    }
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
    if (!IS_LOCAL || BAKERY_BAKER_EMAIL === '' || BAKERY_BAKER_CODE === '') {
        return false;
    }
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
    if (!IS_LOCAL || BAKERY_NIKO_EMAIL === '' || BAKERY_NIKO_CODE === '') {
        return false;
    }
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
    if (!IS_LOCAL) {
        return false;
    }
    bakery_ensure_login_code_column($db);
    bakery_ensure_baker_user($db);

    if (BAKERY_ADMIN_EMAIL !== '' && BAKERY_ADMIN_CODE !== '') {
        bakery_upsert_code_user($db, [
            'email' => BAKERY_ADMIN_EMAIL,
            'display_name' => BAKERY_ADMIN_DISPLAY_NAME,
            'role' => 'administrator',
            'code' => BAKERY_ADMIN_CODE,
            'driver_id' => null,
        ]);
    }

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

/**
 * Resolve the route identity a route worker may use on one operating date.
 * A Driver Assistant follows a dated pairing when present, otherwise their
 * default linked driver. The route itself stays owned by that driver record.
 */
function bakery_route_worker_driver_id(PDO $db, ?array $user, string $date): int {
    if (!$user || !bakery_is_driver_route_role($user['role_slug'] ?? '')) {
        return 0;
    }

    $defaultDriverId = (int)($user['driver_id'] ?? 0);
    if (($user['role_slug'] ?? '') !== 'driver_assistant' || $defaultDriverId <= 0) {
        return $defaultDriverId;
    }

    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date
        || !function_exists('table_exists') || !table_exists($db, 'driver_assistant_assignments')) {
        return $defaultDriverId;
    }

    $stmt = $db->prepare(
        'SELECT driver_id FROM driver_assistant_assignments
         WHERE assistant_user_id = ? AND delivery_date = ? LIMIT 1'
    );
    $stmt->execute([(int)$user['id'], $date]);
    $datedDriverId = (int)$stmt->fetchColumn();
    return $datedDriverId > 0 ? $datedDriverId : $defaultDriverId;
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
        'customer_routes.php',
        'customer_overview.php',
        'customer_schedule.php',
        'drivers.php',
        'standing_routes.php',
        'driver_overview.php',
        'upload_product_photo.php',
        'customer_portal_api.php',
        'route_manager.php',
        'staff_alerts_api.php',
    ];
    return in_array(basename($uri), $jsonScripts, true);
}

function bakery_touch_session() {
    $now = time();
    if (!isset($_SESSION['auth_login_at'])) {
        return;
    }
    $isDriver = bakery_is_driver_route_role($_SESSION['user_role_slug'] ?? '');
    $absoluteSeconds = $isDriver ? BAKERY_DRIVER_SESSION_ABSOLUTE_SECONDS : BAKERY_SESSION_ABSOLUTE_SECONDS;
    $idleSeconds = $isDriver ? BAKERY_DRIVER_SESSION_IDLE_SECONDS : BAKERY_SESSION_IDLE_SECONDS;
    if (($now - (int)$_SESSION['auth_login_at']) > $absoluteSeconds) {
        // A trusted driver phone can immediately rebuild a fresh PHP session.
        bakery_logout(false);
        return;
    }
    if (isset($_SESSION['auth_last_activity']) &&
        ($now - (int)$_SESSION['auth_last_activity']) > $idleSeconds) {
        bakery_logout(false);
        return;
    }
    $_SESSION['auth_last_activity'] = $now;
}

function bakery_driver_trusted_devices_ready(PDO $db): bool {
    return function_exists('table_exists') && table_exists($db, 'driver_trusted_devices');
}

function bakery_driver_trust_cookie_options(int $expires): array {
    $cookiePath = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    $secure = function_exists('isHTTPS')
        ? isHTTPS()
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
        'expires' => $expires,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function bakery_set_driver_trust_cookie(string $token): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie(BAKERY_DRIVER_TRUST_COOKIE, $token, bakery_driver_trust_cookie_options(
        time() + BAKERY_DRIVER_TRUST_SECONDS
    ));
}

function bakery_clear_driver_trust_cookie(): void {
    unset($_COOKIE[BAKERY_DRIVER_TRUST_COOKIE]);
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie(BAKERY_DRIVER_TRUST_COOKIE, '', bakery_driver_trust_cookie_options(time() - 3600));
}

function bakery_driver_trust_token_from_cookie(): string {
    $token = trim((string)($_COOKIE[BAKERY_DRIVER_TRUST_COOKIE] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ? $token : '';
}

/** Create or replace the trusted-phone credential after a route-worker code login. */
function bakery_issue_driver_trusted_device(PDO $db, array $user): string {
    if (!bakery_is_driver_route_role($user['role_slug'] ?? '')
        || (int)($user['id'] ?? 0) <= 0
        || !bakery_driver_trusted_devices_ready($db)) {
        return '';
    }

    $oldToken = bakery_driver_trust_token_from_cookie();
    if ($oldToken !== '') {
        $db->prepare('UPDATE driver_trusted_devices SET revoked_at = NOW() WHERE token_hash = ?')
            ->execute([hash('sha256', $oldToken)]);
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $stmt = $db->prepare(
        'INSERT INTO driver_trusted_devices
         (user_id, token_hash, last_used_at, expires_at, user_agent)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 400 DAY), ?)'
    );
    $stmt->execute([(int)$user['id'], hash('sha256', $token), $userAgent !== '' ? $userAgent : null]);
    bakery_set_driver_trust_cookie($token);
    return $token;
}

function bakery_populate_staff_session(array $row): void {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['user_email'] = (string)$row['email'];
    $_SESSION['user_display_name'] = (string)$row['display_name'];
    $_SESSION['user_role_slug'] = (string)$row['role_slug'];
    $_SESSION['user_driver_id'] = $row['driver_id'] !== null ? (int)$row['driver_id'] : null;
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Rebuild a missing PHP session from a valid driver-only trusted-phone cookie. */
function bakery_restore_driver_trusted_device(PDO $db): bool {
    if (bakery_current_user() || !bakery_driver_trusted_devices_ready($db)) {
        return bakery_current_user() !== null;
    }
    $token = bakery_driver_trust_token_from_cookie();
    if ($token === '') {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT u.*, r.slug AS role_slug
         FROM driver_trusted_devices d
         JOIN users u ON u.id = d.user_id
         JOIN roles r ON r.id = u.role_id
         WHERE d.token_hash = ?
           AND d.revoked_at IS NULL
           AND d.expires_at > NOW()
           AND u.is_active = 1
           AND u.driver_id IS NOT NULL
           AND r.slug IN ('driver', 'driver_assistant')
         LIMIT 1"
    );
    $tokenHash = hash('sha256', $token);
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) {
        bakery_clear_driver_trust_cookie();
        return false;
    }

    bakery_populate_staff_session($row);
    $db->prepare(
        'UPDATE driver_trusted_devices
         SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 400 DAY)
         WHERE token_hash = ? AND revoked_at IS NULL'
    )->execute([$tokenHash]);
    bakery_set_driver_trust_cookie($token);
    $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);

    bakery_login_audit_start($db, 'staff', [
        'user_id' => (int)$row['id'],
        'principal' => (string)$row['email'],
    ]);
    if (function_exists('bakery_apply_locale_default_for_user')) {
        bakery_apply_locale_default_for_user($row['role_slug'] ?? null, false);
    }
    $routeDriverId = bakery_route_worker_driver_id($db, bakery_current_user(), date('Y-m-d'));
    $nameStmt = $db->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
    $nameStmt->execute([$routeDriverId]);
    bakery_set_selected_driver($routeDriverId, (string)($nameStmt->fetchColumn() ?: $row['display_name']));
    return true;
}

function bakery_revoke_current_driver_trusted_device(PDO $db): void {
    $token = bakery_driver_trust_token_from_cookie();
    if ($token !== '' && bakery_driver_trusted_devices_ready($db)) {
        $db->prepare('UPDATE driver_trusted_devices SET revoked_at = NOW() WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
    bakery_clear_driver_trust_cookie();
}

/** Quietly enroll an already-authenticated route worker after this feature ships. */
function bakery_ensure_current_driver_trusted_device(PDO $db): void {
    $user = bakery_current_user();
    if (!$user || !bakery_is_driver_route_role($user['role_slug'] ?? '')
        || bakery_driver_trust_token_from_cookie() !== '') {
        return;
    }
    try {
        bakery_issue_driver_trusted_device($db, $user);
    } catch (Throwable $e) {
        // Route access must keep working even if durable trust cannot be saved.
        error_log('Trusted driver auto-enrollment error: ' . $e->getMessage());
    }
}

function bakery_login(PDO $db, $code) {
    bakery_ensure_login_code_column($db);

    if (!bakery_login_attempt_allowed($db, 'staff')) {
        return false;
    }

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

    bakery_populate_staff_session($row);

    $upd = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $upd->execute([(int)$row['id']]);

    bakery_login_audit_start($db, 'staff', [
        'user_id' => (int)$row['id'],
        'principal' => (string)$row['email'],
        'credential_code' => $code,
    ]);

    if (function_exists('bakery_apply_locale_default_for_user')) {
        bakery_apply_locale_default_for_user($row['role_slug'] ?? null, false);
    }

    try {
        bakery_issue_driver_trusted_device($db, $row);
    } catch (Throwable $e) {
        // A trust-record failure must never turn a valid driver code into a
        // failed login. The normal PHP session remains available as fallback.
        error_log('Trusted driver device issue error: ' . $e->getMessage());
    }

    // Route workers land on their own or paired route identity.
    if (bakery_is_driver_route_role($row['role_slug'] ?? '') && $row['driver_id'] !== null) {
        $routeDriverId = bakery_route_worker_driver_id($db, bakery_current_user(), date('Y-m-d'));
        $driverName = '';
        $nameStmt = $db->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
        $nameStmt->execute([$routeDriverId]);
        $driverName = (string)($nameStmt->fetchColumn() ?: $row['display_name']);
        bakery_set_selected_driver($routeDriverId, $driverName);
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

function bakery_logout($forgetTrustedDevice = true) {
    global $db;
    if ($forgetTrustedDevice && $db instanceof PDO) {
        try {
            bakery_revoke_current_driver_trusted_device($db);
        } catch (Throwable $e) {
            error_log('Trusted driver logout error: ' . $e->getMessage());
            bakery_clear_driver_trust_cookie();
        }
    } elseif ($forgetTrustedDevice) {
        bakery_clear_driver_trust_cookie();
    }
    if (bakery_login_audit_current_id()) {
        try {
            $auditDb = ($db instanceof PDO) ? $db : (function_exists('check_mysql_connection') ? check_mysql_connection() : null);
            if ($auditDb instanceof PDO) {
                bakery_login_audit_close($auditDb);
            }
        } catch (Throwable $e) {
            error_log('Login audit logout error: ' . $e->getMessage());
        }
    }
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
    $user = bakery_current_user();
    if (!$user && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO
        && bakery_restore_driver_trusted_device($GLOBALS['db'])) {
        $user = bakery_current_user();
    }
    if ($user) {
        if (PHP_SAPI !== 'cli' && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            bakery_ensure_current_driver_trusted_device($GLOBALS['db']);
        }
        // Adopt sessions that were already open when login telemetry was deployed.
        if (!bakery_login_audit_current_id() && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            bakery_login_audit_start($GLOBALS['db'], 'staff', [
                'user_id' => (int)$user['id'],
                'principal' => (string)$user['email'],
                'login_at' => $_SESSION['auth_login_at'] ?? null,
            ]);
        }
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

    require_once __DIR__ . '/customer_portal.php';
    require_once __DIR__ . '/sf_baker.php';

    $script = basename($_SERVER['PHP_SELF'] ?? '');

    // A driver who opens the login bookmark after PHP session cleanup should
    // still land directly on My Route from the trusted phone credential.
    if ($script === 'login.php' && $db instanceof PDO && !bakery_current_user()) {
        bakery_restore_driver_trusted_device($db);
    }

    if (in_array($script, bakery_public_scripts(), true)) {
        return;
    }

    $communityScripts = function_exists('bakery_sfb_community_scripts')
        ? bakery_sfb_community_scripts()
        : ['sfb_community.php', 'sfb_community_topic.php', 'sfb_shared_batch.php'];
    if (in_array($script, $communityScripts, true)) {
        $staffOk = bakery_current_user() && bakery_user_has_role(['administrator']);
        if (!$staffOk) {
            bakery_require_portal_login($db);
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            bakery_require_csrf();
        }
        return;
    }

    $portalScripts = array_values(array_unique(array_merge(
        bakery_customer_portal_scripts(),
        bakery_sfb_portal_scripts()
    )));

    // Canonical invoice: portal customers or Billing Center staff (not drivers).
    if ($script === 'customer_invoice.php') {
        $staffOk = bakery_current_user() && bakery_user_has_role(['administrator', 'manager']);
        if (!$staffOk) {
            bakery_require_portal_login($db);
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            bakery_require_csrf();
        }
        return;
    }

    // Customer portal uses phone + passcode session (not staff login).
    // Any customer_portal_*.php page is portal territory by naming convention,
    // so new portal pages cannot silently fall through to the staff login gate.
    $isPortalScript = in_array($script, $portalScripts, true)
        || strpos($script, 'customer_portal_') === 0;
    if ($isPortalScript) {
        bakery_require_portal_login($db);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            bakery_require_csrf();
        }
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
        bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
    } else {
        // Default ops UI: manager + administrator. Drivers/bakers stay on their scripts.
        bakery_require_role(['administrator', 'manager']);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        bakery_require_csrf();
    }
}

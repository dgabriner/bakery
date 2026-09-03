<?php
/**
 * Cashier role + Sarita product-photo access (bakerysf_test only).
 * Usage: php tests/run_cashier_role_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';
putenv('APP_ENV=local');
$_ENV['APP_ENV'] = 'local';
$_SERVER['APP_ENV'] = 'local';

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$passed = 0;
$failed = 0;
function c_assert($ok, $message) {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function cashier_apply_sql_file(PDO $db, $path) {
    $sql = (string)file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (Throwable $e) {
            // Idempotent re-runs may hit duplicate column / table races.
            if (stripos($e->getMessage(), 'Duplicate') === false
                && stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
}

// Minimal drivers table so 002_auth FK to drivers succeeds on an empty test DB.
$db->exec(
    "CREATE TABLE IF NOT EXISTS drivers (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

cashier_apply_sql_file($db, $root . '/database/schema/002_auth.sql');
cashier_apply_sql_file($db, $root . '/database/schema/008_login_code.sql');
cashier_apply_sql_file($db, $root . '/database/schema/007_baker_role.sql');
cashier_apply_sql_file($db, $root . '/database/schema/074_cashier_role.sql');
bakery_ensure_login_code_column($db);

// Minimal login_audit so bakery_login_attempt_allowed does not fail closed.
$db->exec(
    "CREATE TABLE IF NOT EXISTS login_audit (
        id BIGINT NOT NULL AUTO_INCREMENT,
        auth_type ENUM('staff', 'customer') NOT NULL,
        user_id INT NULL DEFAULT NULL,
        customer_id INT NULL DEFAULT NULL,
        principal VARCHAR(255) NOT NULL,
        outcome ENUM('success', 'failure') NOT NULL,
        failure_reason VARCHAR(100) NULL DEFAULT NULL,
        login_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_login_audit_throttle (auth_type, outcome, ip_address, login_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('cashier-test-' . bin2hex(random_bytes(4)));
    session_start();
}
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';


c_assert(in_array('product_photos.php', bakery_cashier_scripts(), true), 'product_photos is a cashier script');
c_assert(in_array('upload_product_photo.php', bakery_cashier_scripts(), true), 'upload_product_photo is a cashier script');
c_assert(!in_array('products.php', bakery_cashier_scripts(), true), 'products.php is not a cashier script');
c_assert(!in_array('daily_orders.php', bakery_cashier_scripts(), true), 'daily_orders.php is not a cashier script');

$role = $db->query("SELECT id, name FROM roles WHERE slug = 'cashier' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
c_assert(!empty($role['id']), 'cashier role exists after migration 074');

c_assert(bakery_ensure_sarita_cashier($db) === true, 'ensure Sarita cashier succeeds');
$row = $db->query(
    "SELECT u.display_name, u.login_code, u.is_active, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.email = 'sarita@sourflour.local' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
c_assert($row && $row['display_name'] === 'Sarita', 'Sarita display name');
c_assert($row && $row['login_code'] === '8989', 'Sarita code is 8989');
c_assert($row && $row['role_slug'] === 'cashier', 'Sarita role is cashier');
c_assert($row && (int)$row['is_active'] === 1, 'Sarita is active');

$_SESSION = [];
c_assert(bakery_login($db, '8989') === true, 'Sarita logs in with 8989');
$user = bakery_current_user();
c_assert($user && $user['role_slug'] === 'cashier', 'session role is cashier');
c_assert($user && $user['display_name'] === 'Sarita', 'session display name is Sarita');
c_assert(bakery_user_has_role(['cashier']), 'bakery_user_has_role recognizes cashier');
c_assert(bakery_user_has_permission($db, 'ops.manage'), 'cashier has ops.manage');

bakery_logout();
$_SESSION = [];

echo $failed === 0
    ? "Cashier role tests passed ({$passed})\n"
    : "Cashier role tests finished with {$failed} failure(s), {$passed} pass(es)\n";
exit($failed === 0 ? 0 : 1);

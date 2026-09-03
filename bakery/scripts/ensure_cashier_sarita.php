<?php
/**
 * Ensure cashier role + Sarita (code 8989) exist.
 *
 * Usage: php scripts/ensure_cashier_sarita.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = rtrim((string)getenv('BAKERY_HOSTED_STAGE_ROOT'), '/');
if ($root === '') {
    $root = dirname(__DIR__);
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

$db = check_mysql_connection();
if (!bakery_ensure_sarita_cashier($db)) {
    fwrite(STDERR, "Failed to ensure Sarita cashier (missing roles table or code collision)\n");
    exit(1);
}

$check = $db->prepare(
    "SELECT u.id, u.email, u.display_name, u.login_code, u.is_active, r.slug AS role_slug
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.email = ? LIMIT 1"
);
$check->execute(['sarita@sourflour.local']);
$user = $check->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "Sarita row missing after ensure\n");
    exit(1);
}

echo "OK cashier id={$user['id']} name={$user['display_name']} role={$user['role_slug']} code={$user['login_code']} active={$user['is_active']}\n";

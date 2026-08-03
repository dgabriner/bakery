<?php
/**
 * One-shot: create or update an app login user with a 4-digit code.
 * Usage: C:\php\php.exe bakery/scripts/create_user_once.php code display_name [role] [email] [driver_id]
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

$code = $argv[1] ?? '';
$displayName = $argv[2] ?? '';
$roleSlug = $argv[3] ?? 'administrator';
$email = $argv[4] ?? '';
$driverId = isset($argv[5]) ? (int)$argv[5] : null;

if ($code === '' || $displayName === '') {
    fwrite(STDERR, "Usage: php create_user_once.php code display_name [role] [email] [driver_id]\n");
    exit(1);
}

$code = bakery_normalize_login_code($code);
if ($code === '') {
    fwrite(STDERR, "Code must be exactly 4 digits\n");
    exit(1);
}

if ($email === '') {
    $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($displayName));
    $slug = trim($slug, '.') ?: 'user';
    $email = $slug . '@sourflour.local';
}

$db = check_mysql_connection();

if (!table_exists($db, 'users') || !table_exists($db, 'roles')) {
    $authSchema = $root . '/database/schema/002_auth.sql';
    $sql = file_get_contents($authSchema);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
        if ($statement !== '') {
            $db->exec($statement);
        }
    }
    echo "Ensured auth schema\n";
}

bakery_ensure_login_code_column($db);

if (!bakery_upsert_code_user($db, [
    'email' => $email,
    'display_name' => $displayName,
    'role' => $roleSlug,
    'code' => $code,
    'driver_id' => $driverId,
])) {
    fwrite(STDERR, "Failed to create/update user (missing role or code collision)\n");
    exit(1);
}

$check = $db->prepare('SELECT id, email, display_name, login_code, is_active FROM users WHERE email = ?');
$check->execute([$email]);
$user = $check->fetch(PDO::FETCH_ASSOC);

echo "OK user id={$user['id']} email={$user['email']} name={$user['display_name']} role={$roleSlug} active={$user['is_active']} code_set=1\n";
echo "Login at BASE_URL login.php with the 4-digit code.\n";

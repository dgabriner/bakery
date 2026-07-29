<?php
/**
 * One-shot: create or update an app login user.
 * Usage: C:\php\php.exe bakery/scripts/create_user_once.php email password [display_name] [role]
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
$displayName = $argv[3] ?? 'Danny';
$roleSlug = $argv[4] ?? 'administrator';

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php create_user_once.php email password [display_name] [role]\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters\n");
    exit(1);
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

$roleStmt = $db->prepare('SELECT id FROM roles WHERE slug = ?');
$roleStmt->execute([$roleSlug]);
$roleId = $roleStmt->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "Missing role: {$roleSlug}\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO users (email, password_hash, display_name, role_id, is_active)
     VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
       password_hash = VALUES(password_hash),
       display_name = VALUES(display_name),
       role_id = VALUES(role_id),
       is_active = 1"
);
$stmt->execute([$email, $hash, $displayName, $roleId]);

$check = $db->prepare('SELECT id, email, display_name, is_active FROM users WHERE email = ?');
$check->execute([$email]);
$user = $check->fetch(PDO::FETCH_ASSOC);

echo "OK user id={$user['id']} email={$user['email']} name={$user['display_name']} role={$roleSlug} active={$user['is_active']}\n";
echo "Password hash stored (password not echoed). Login at BASE_URL login.php\n";

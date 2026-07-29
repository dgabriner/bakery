<?php
define('ACCESS_ALLOWED', true);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

$email = $argv[1] ?? 'danny@sourflour.org';
$password = $argv[2] ?? '';

$db = check_mysql_connection();
echo "DB_OK name=" . DB_NAME . " host=" . DB_HOST . "\n";

$all = $db->query('SELECT id, email, display_name, role_id, is_active, LENGTH(password_hash) AS hash_len, LEFT(password_hash, 7) AS hash_prefix FROM users')->fetchAll(PDO::FETCH_ASSOC);
echo "users=" . count($all) . "\n";
foreach ($all as $u) {
    echo "  id={$u['id']} email={$u['email']} active={$u['is_active']} hash_len={$u['hash_len']} prefix={$u['hash_prefix']}\n";
}

$stmt = $db->prepare('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE LOWER(u.email) = LOWER(?)');
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "LOOKUP_MISS for {$email}\n";
    exit(1);
}
echo "LOOKUP_OK id={$row['id']} role={$row['role_slug']} active={$row['is_active']}\n";

if ($password === '') {
    echo "No password arg — skip verify\n";
    exit(0);
}

$ok = password_verify($password, $row['password_hash']);
echo "password_verify=" . ($ok ? 'YES' : 'NO') . "\n";
$login = bakery_login($db, $email, $password);
echo "bakery_login=" . ($login ? 'YES' : 'NO') . "\n";
if ($login) {
    $cu = bakery_current_user();
    echo "session_user=" . ($cu['email'] ?? 'null') . " role=" . ($cu['role_slug'] ?? '') . "\n";
}

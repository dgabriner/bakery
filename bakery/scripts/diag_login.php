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

$emailOrCode = $argv[1] ?? 'danny@sourflour.org';
$codeArg = $argv[2] ?? '';

$db = check_mysql_connection();
bakery_ensure_login_code_column($db);
echo "DB_OK name=" . DB_NAME . " host=" . DB_HOST . "\n";

$all = $db->query(
    'SELECT id, email, display_name, role_id, is_active, login_code,
            LENGTH(password_hash) AS hash_len, LEFT(password_hash, 7) AS hash_prefix
     FROM users'
)->fetchAll(PDO::FETCH_ASSOC);
echo "users=" . count($all) . "\n";
foreach ($all as $u) {
    echo "  id={$u['id']} email={$u['email']} name={$u['display_name']} active={$u['is_active']} code_set=" . ($u['login_code'] ? '1' : '0') . " hash_len={$u['hash_len']}\n";
}

$code = bakery_normalize_login_code($codeArg !== '' ? $codeArg : $emailOrCode);
if ($code !== '') {
    $stmt = $db->prepare(
        'SELECT u.*, r.slug AS role_slug
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.login_code = ?'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "LOOKUP_MISS for code\n";
        exit(1);
    }
    echo "LOOKUP_OK id={$row['id']} email={$row['email']} role={$row['role_slug']} active={$row['is_active']}\n";
    $login = bakery_login($db, $code);
    echo "bakery_login=" . ($login ? 'YES' : 'NO') . "\n";
    if ($login) {
        $cu = bakery_current_user();
        echo "session_user=" . ($cu['email'] ?? 'null') . " role=" . ($cu['role_slug'] ?? '') . "\n";
    }
    exit($login ? 0 : 1);
}

$stmt = $db->prepare(
    'SELECT u.*, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE LOWER(u.email) = LOWER(?)'
);
$stmt->execute([$emailOrCode]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "LOOKUP_MISS for {$emailOrCode}\n";
    exit(1);
}
echo "LOOKUP_OK id={$row['id']} role={$row['role_slug']} active={$row['is_active']} code_set=" . (!empty($row['login_code']) ? '1' : '0') . "\n";
echo "No code arg — skip login verify\n";
exit(0);

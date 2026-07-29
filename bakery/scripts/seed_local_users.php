<?php
/**
 * Seed local-only users for Checkpoint 0D.
 * Passwords are nonproduction fixtures documented in LOCAL_SETUP.md.
 *
 * Usage: C:\php\php.exe bakery/scripts/seed_local_users.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: seed only allowed when APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();

function bakery_apply_sql_file(PDO $db, $path) {
    $sql = file_get_contents($path);
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
}

$authSchema = $root . '/database/schema/002_auth.sql';
bakery_apply_sql_file($db, $authSchema);
echo "Ensured auth schema/roles/permissions\n";

$seeds = [
    [
        'email' => 'admin@local.test',
        'name' => 'Local Admin',
        'role' => 'administrator',
        'password' => 'LocalAdmin!234',
        'driver_id' => null,
    ],
    [
        'email' => 'manager@local.test',
        'name' => 'Local Manager',
        'role' => 'manager',
        'password' => 'LocalManager!234',
        'driver_id' => null,
    ],
    [
        'email' => 'driver@local.test',
        'name' => 'Local Driver',
        'role' => 'driver',
        'password' => 'LocalDriver!234',
        'driver_id' => 1,
    ],
];

foreach ($seeds as $seed) {
    $roleId = $db->prepare('SELECT id FROM roles WHERE slug = ?');
    $roleId->execute([$seed['role']]);
    $rid = $roleId->fetchColumn();
    if (!$rid) {
        fwrite(STDERR, "Missing role {$seed['role']}\n");
        exit(1);
    }
    $hash = password_hash($seed['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO users (email, password_hash, display_name, role_id, driver_id, is_active)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           password_hash = VALUES(password_hash),
           display_name = VALUES(display_name),
           role_id = VALUES(role_id),
           driver_id = VALUES(driver_id),
           is_active = 1"
    );
    $stmt->execute([
        $seed['email'],
        $hash,
        $seed['name'],
        $rid,
        $seed['driver_id'],
    ]);
    echo "Seeded {$seed['email']} ({$seed['role']})\n";
}

// Durable operator login (danny@sourflour.org by default) from LOCAL_ADMIN_* env
$ensure = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_local_admin.php';
if (is_readable($ensure)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensure), $ensureCode);
    if ($ensureCode !== 0) {
        echo "Skipped durable admin (set LOCAL_ADMIN_PASSWORD in .env or .env.production.pull).\n";
    }
}

echo "Done.\n";

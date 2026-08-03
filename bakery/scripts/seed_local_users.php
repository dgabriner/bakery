<?php
/**
 * Seed local-only users with 4-digit login codes.
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
require_once $root . '/includes/auth.php';

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

$bakerRoleSchema = $root . '/database/schema/007_baker_role.sql';
if (is_readable($bakerRoleSchema)) {
    bakery_apply_sql_file($db, $bakerRoleSchema);
    echo "Ensured baker role\n";
}

bakery_ensure_login_code_column($db);

$seeds = [
    [
        'email' => 'admin@local.test',
        'display_name' => 'Local Admin',
        'role' => 'administrator',
        'code' => '9001',
        'driver_id' => null,
    ],
    [
        'email' => 'manager@local.test',
        'display_name' => 'Local Manager',
        'role' => 'manager',
        'code' => '9002',
        'driver_id' => null,
    ],
    [
        'email' => 'driver@local.test',
        'display_name' => 'Local Driver',
        'role' => 'driver',
        'code' => '9003',
        'driver_id' => 1,
    ],
];

foreach ($seeds as $seed) {
    if (!bakery_upsert_code_user($db, $seed)) {
        fwrite(STDERR, "Failed seeding {$seed['email']}\n");
        exit(1);
    }
    echo "Seeded {$seed['email']} ({$seed['role']}) code={$seed['code']}\n";
}

bakery_ensure_staff_code_users($db);
echo "Ensured staff code users (Danny/Juan Carlos/Sergio/Laura)\n";

// Durable operator login override from LOCAL_ADMIN_* env when set
$ensure = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure_local_admin.php';
if (is_readable($ensure)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensure), $ensureCode);
    if ($ensureCode !== 0) {
        echo "Staff admin defaults kept (set LOCAL_ADMIN_CODE in .env to override).\n";
    }
}

echo "Done.\n";

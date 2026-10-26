<?php
/**
 * Staff role change updates users.role_id only, with the same audit row
 * the staff save writes, and a confirm step that names the old and new role.
 *
 * Usage: php tests/run_staff_role_change_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);
if (strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) !== 'bakerysf_test') {
    fwrite(STDERR, "Refusing: staff role tests require bakerysf_test\n");
    exit(1);
}

require_once $root . '/includes/auth.php';
require_once $root . '/includes/users_admin.php';
require_once $root . '/includes/hosted_migration_runtime.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_role_slug'] = 'administrator';

if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}

if (!table_exists($db, 'field_audit')) {
    $db->exec((string)file_get_contents($root . '/database/schema/098_audit_log.sql'));
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('field_audit');
    }
}

function staff_role_free_code(PDO $db): string
{
    $stmt = $db->prepare('SELECT id FROM users WHERE login_code = ? LIMIT 1');
    for ($try = 0; $try < 40; $try++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('No free staff code');
}

function staff_role_row(PDO $db, int $id): array
{
    $stmt = $db->prepare(
        'SELECT email, password_hash, login_code, display_name, role_id, driver_id, is_active
         FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Missing staff row');
    }
    return $row;
}

function staff_role_audit(PDO $db, int $id): array
{
    $stmt = $db->prepare(
        'SELECT action, field, old_value, new_value
         FROM field_audit
         WHERE entity_type = ? AND entity_id = ?
         ORDER BY id'
    );
    $stmt->execute(['user', $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stamp = 'role' . bin2hex(random_bytes(3));
$userId = 0;
$managerId = 0;
$driverId = 0;
$page = (string)file_get_contents($root . '/users.php');
$admin = (string)file_get_contents($root . '/includes/users_admin.php');

try {
    assert_true(strpos($page, 'value="change_role_review"') !== false, 'Staff page reviews a role change before saving');
    assert_true(strpos($page, 'value="change_role">') !== false, 'Staff page confirms the role change in a second action');
    assert_true(strpos($page, 'users.change_role_confirm') !== false, 'confirm copy comes from the language catalog');
    assert_true(strpos($page, 'users.change_role_driver_rule') !== false, 'Staff page calls out the non-driver driver-link rule');
    assert_true(strpos($page, 'change_role=') !== false, 'each staff row has a change role action');
    assert_true(strpos($admin, 'UPDATE users SET role_id = ? WHERE id = ?') !== false, 'a driver role update writes only role_id');
    assert_true(strpos($admin, 'UPDATE users SET role_id = ?, driver_id = NULL WHERE id = ?') !== false, 'a non-driver role clears driver_id and nothing else');
    assert_true(strpos($admin, "bakery_audit_write(\$db, 'user', \$id, 'update', 'role'") !== false, 'role change writes the same audit row as the staff save');

    $sales = $db->query("SELECT id, name FROM roles WHERE slug = 'sales' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $driverRole = $db->query("SELECT id, name FROM roles WHERE slug = 'driver' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assistant = $db->query("SELECT id, name FROM roles WHERE slug = 'driver_assistant' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $manager = $db->query("SELECT id, name FROM roles WHERE slug = 'manager' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($sales) && is_array($driverRole) && is_array($assistant) && is_array($manager), 'driver, assistant, manager, and sales roles exist');

    $db->prepare('INSERT INTO drivers (name) VALUES (?)')->execute(['Role change ' . $stamp]);
    $driverId = (int)$db->lastInsertId();
    $code = staff_role_free_code($db);
    $created = bakery_users_save($db, [
        'action' => 'create',
        'display_name' => 'Role Change ' . $stamp,
        'role_slug' => 'driver',
        'login_code' => $code,
        'email' => $stamp . '@example.test',
        'driver_id' => $driverId,
        'is_active' => '1',
    ]);
    $userId = (int)$created['id'];
    $before = staff_role_row($db, $userId);
    assert_true((int)$before['role_id'] === (int)$driverRole['id'], 'new staff user starts as driver');
    assert_true((int)$before['driver_id'] === $driverId, 'driver link is stored');

    $kept = bakery_users_change_role($db, $userId, 'driver_assistant');
    $afterAssistant = staff_role_row($db, $userId);
    assert_true($kept['old_role'] === (string)$driverRole['name'], 'change reports the old role name');
    assert_true($kept['new_role'] === (string)$assistant['name'], 'change reports the new role name');
    assert_true($kept['driver_cleared'] === false, 'moving between driver roles keeps the driver link');
    assert_true((int)$afterAssistant['role_id'] === (int)$assistant['id'], 'role_id is the assistant role');
    assert_true((int)$afterAssistant['driver_id'] === $driverId, 'driver_id is unchanged for a driver role');
    assert_true($afterAssistant['password_hash'] === $before['password_hash'], 'password hash is unchanged');
    assert_true($afterAssistant['login_code'] === $before['login_code'], 'login code is unchanged');
    assert_true($afterAssistant['email'] === $before['email'], 'email is unchanged');
    assert_true($afterAssistant['display_name'] === $before['display_name'], 'name is unchanged');
    assert_true((int)$afterAssistant['is_active'] === (int)$before['is_active'], 'active flag is unchanged');

    $db->prepare('UPDATE users SET is_active = 0, email = ? WHERE id = ?')->execute([$stamp . '-kept@example.test', $userId]);
    $beforeSales = staff_role_row($db, $userId);
    $cleared = bakery_users_change_role($db, $userId, 'sales');
    $afterSales = staff_role_row($db, $userId);
    assert_true($cleared['driver_cleared'] === true, 'a non-driver role reports that the driver link will be cleared');
    assert_true($cleared['old_role'] === (string)$assistant['name'], 'sales change names the previous role');
    assert_true($cleared['new_role'] === (string)$sales['name'], 'sales change names the new role');
    assert_true((int)$afterSales['role_id'] === (int)$sales['id'], 'role_id is the sales role');
    assert_true($afterSales['driver_id'] === null, 'non-driver role clears driver_id');
    assert_true($afterSales['password_hash'] === $beforeSales['password_hash'], 'password hash stays through the sales change');
    assert_true($afterSales['login_code'] === $beforeSales['login_code'], 'login code stays through the sales change');
    assert_true($afterSales['email'] === $beforeSales['email'], 'email stays through the sales change');
    assert_true($afterSales['display_name'] === $beforeSales['display_name'], 'name stays through the sales change');
    assert_true((int)$afterSales['is_active'] === 0, 'active flag stays inactive');

    $preview = bakery_users_role_change_preview($db, $userId, 'manager');
    assert_true($preview['same'] === false, 'preview of a different role is not a no-op');
    assert_true($preview['old_role'] === (string)$sales['name'], 'preview shows the old role');
    assert_true($preview['new_role'] === (string)$manager['name'], 'preview shows the new role');
    assert_true($preview['driver_will_clear'] === false, 'preview does not clear a driver link that is already empty');
    $confirm = bakery_t('users.change_role_confirm', [
        'name' => $preview['display_name'],
        'old_role' => $preview['old_role'],
        'new_role' => $preview['new_role'],
    ]);
    assert_true(strpos($confirm, (string)$preview['old_role']) !== false, 'confirm text includes the old role');
    assert_true(strpos($confirm, (string)$preview['new_role']) !== false, 'confirm text includes the new role');
    assert_true(strpos($confirm, (string)$preview['display_name']) !== false, 'confirm text includes the staff name');
    assert_true(staff_role_row($db, $userId)['role_id'] === $afterSales['role_id'], 'preview does not write the role');

    $auditBeforeSame = staff_role_audit($db, $userId);
    $sameThrew = false;
    try {
        bakery_users_change_role($db, $userId, 'sales');
    } catch (Exception $e) {
        $sameThrew = $e->getMessage() === bakery_t('users.change_role_same');
    }
    assert_true($sameThrew, 'choosing the current role does not save');
    assert_true(count(staff_role_audit($db, $userId)) === count($auditBeforeSame), 'a same-role confirm writes no audit row');

    $invalidThrew = false;
    try {
        bakery_users_change_role($db, $userId, 'not-a-role');
    } catch (Exception $e) {
        $invalidThrew = $e->getMessage() === bakery_t('users.change_role_invalid');
    }
    assert_true($invalidThrew, 'an unknown role is refused');

    $roleAudits = [];
    foreach (staff_role_audit($db, $userId) as $row) {
        if ($row['action'] === 'update' && $row['field'] === 'role') {
            $roleAudits[] = $row;
        }
    }
    assert_true(count($roleAudits) === 2, 'each real role change writes one role audit row');
    assert_true($roleAudits[0]['old_value'] === (string)$driverRole['name'], 'first audit row stores the old role name');
    assert_true($roleAudits[0]['new_value'] === (string)$assistant['name'], 'first audit row stores the new role name');
    assert_true($roleAudits[1]['old_value'] === (string)$assistant['name'], 'second audit row stores the old role name');
    assert_true($roleAudits[1]['new_value'] === (string)$sales['name'], 'second audit row stores the new role name');
    foreach (staff_role_audit($db, $userId) as $row) {
        if ($row['action'] === 'update' && $row['field'] !== 'role') {
            assert_true(false, 'role change does not audit other fields');
        }
    }

    $managerCode = staff_role_free_code($db);
    $managerCreated = bakery_users_save($db, [
        'action' => 'create',
        'display_name' => 'Manager Role ' . $stamp,
        'role_slug' => 'manager',
        'login_code' => $managerCode,
        'email' => $stamp . '-manager@example.test',
        'driver_id' => $driverId,
        'is_active' => '1',
    ]);
    $managerId = (int)$managerCreated['id'];
    $managerBefore = staff_role_row($db, $managerId);
    assert_true($managerBefore['driver_id'] === null, 'creating a manager already clears a posted driver link');
    $managerSaved = bakery_users_change_role($db, $managerId, 'sales');
    $managerAfter = staff_role_row($db, $managerId);
    assert_true($managerSaved['driver_cleared'] === false, 'clearing an empty driver link is not reported as a clear');
    assert_true((int)$managerAfter['role_id'] === (int)$sales['id'], 'manager becomes sales');
    assert_true($managerAfter['driver_id'] === null, 'manager to sales leaves driver_id empty');
    assert_true($managerAfter['password_hash'] === $managerBefore['password_hash'], 'manager password hash is unchanged');
    assert_true($managerAfter['login_code'] === $managerBefore['login_code'], 'manager login code is unchanged');
    assert_true($managerAfter['email'] === $managerBefore['email'], 'manager email is unchanged');
    assert_true($managerAfter['display_name'] === $managerBefore['display_name'], 'manager name is unchanged');
    assert_true((int)$managerAfter['is_active'] === 1, 'manager active flag is unchanged');
} finally {
    if ($userId > 0) {
        $db->prepare('DELETE FROM field_audit WHERE entity_type = ? AND entity_id = ?')->execute(['user', $userId]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    if ($managerId > 0) {
        $db->prepare('DELETE FROM field_audit WHERE entity_type = ? AND entity_id = ?')->execute(['user', $managerId]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
    if ($driverId > 0) {
        $db->prepare('DELETE FROM drivers WHERE id = ?')->execute([$driverId]);
    }
}

echo "\n" . (int)$GLOBALS['TEST_PASS'] . " passed, " . (int)$GLOBALS['TEST_FAIL'] . " failed\n";
exit(((int)$GLOBALS['TEST_FAIL']) > 0 ? 1 : 0);

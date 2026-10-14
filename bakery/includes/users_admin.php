<?php
/**
 * Staff user create, edit, activate, and code reveal.
 * Login codes are masked in the page and never written to the audit trail.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/audit_log.php';

function bakery_users_load(PDO $db, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT u.id, u.email, u.login_code, u.password_hash, u.display_name, u.driver_id,
                u.is_active, u.role_id, r.slug AS role_slug, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array{id:int,message:string}
 */
function bakery_users_save(PDO $db, array $input): array
{
    $action = (string)($input['action'] ?? '');
    if ($action !== 'create' && $action !== 'update') {
        throw new Exception('Invalid user.');
    }
    $id = (int)($input['id'] ?? 0);
    $displayName = trim((string)($input['display_name'] ?? ''));
    $roleSlug = trim((string)($input['role_slug'] ?? ''));
    $postedCode = bakery_normalize_login_code($input['login_code'] ?? '');
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $driverId = (int)($input['driver_id'] ?? 0);
    $isActive = isset($input['is_active']) ? 1 : 0;
    $hasBot = function_exists('column_exists') && column_exists($db, 'users', 'is_bot');
    $knownBot = function_exists('bakery_known_bot_display_names')
        && in_array($displayName, bakery_known_bot_display_names(), true);
    $isBot = ($knownBot || isset($input['is_bot'])) ? 1 : 0;
    $roleLabels = bakery_assignable_role_labels();

    if ($displayName === '') {
        throw new Exception('Name is required.');
    }
    if (!isset($roleLabels[$roleSlug])) {
        throw new Exception('Invalid user type.');
    }

    $existing = $action === 'update' ? bakery_users_load($db, $id) : null;
    if ($action === 'update' && !$existing) {
        throw new Exception('Invalid user.');
    }

    $codeChanged = false;
    if ($action === 'create') {
        if ($postedCode === '') {
            throw new Exception('Login code must be exactly 4 digits.');
        }
        $code = $postedCode;
        $codeChanged = true;
        $isActive = 1;
    } elseif ($postedCode === '') {
        $code = (string)$existing['login_code'];
    } else {
        $code = $postedCode;
        $codeChanged = $code !== (string)$existing['login_code'];
    }

    $roleStmt = $db->prepare('SELECT id, name FROM roles WHERE slug = ? LIMIT 1');
    $roleStmt->execute([$roleSlug]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        throw new Exception('Role not found.');
    }
    $roleId = (int)$role['id'];
    $roleName = (string)$role['name'];

    if (!bakery_is_driver_route_role($roleSlug)) {
        $driverId = 0;
    }
    $driverIdValue = $driverId > 0 ? $driverId : null;

    if ($email === '') {
        $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($displayName));
        $slug = trim((string)$slug, '.') ?: 'user';
        $email = $slug . '@sourflour.local';
    }

    if ($codeChanged || $action === 'create') {
        $clash = $db->prepare('SELECT id FROM users WHERE login_code = ? AND id <> ? LIMIT 1');
        $clash->execute([$code, $id]);
        if ($clash->fetchColumn()) {
            throw new Exception('That login code is already in use.');
        }
    }

    $emailClash = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
    $emailClash->execute([$email, $id]);
    if ($emailClash->fetchColumn()) {
        throw new Exception('That email is already in use.');
    }

    if ($action === 'create') {
        $hash = password_hash($code, PASSWORD_DEFAULT);
        if ($hasBot) {
            $stmt = $db->prepare(
                'INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active, is_bot)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([$email, $hash, $code, $displayName, $roleId, $driverIdValue, $isBot]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$email, $hash, $code, $displayName, $roleId, $driverIdValue]);
        }
        $id = (int)$db->lastInsertId();
        bakery_audit_write($db, 'user', $id, 'create', 'role', null, $roleName);
        bakery_audit_write($db, 'user', $id, 'create', 'is_active', null, '1');
        bakery_audit_write($db, 'user', $id, 'create', 'login_code', null, BAKERY_AUDIT_CODE_CHANGED);
        $message = 'User created.';
    } else {
        $hash = $codeChanged ? password_hash($code, PASSWORD_DEFAULT) : (string)$existing['password_hash'];
        $storedCode = $codeChanged ? $code : (string)$existing['login_code'];
        if ($hasBot) {
            $stmt = $db->prepare(
                'UPDATE users
                 SET email = ?, password_hash = ?, login_code = ?, display_name = ?,
                     role_id = ?, driver_id = ?, is_active = ?, is_bot = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $email,
                $hash,
                $storedCode,
                $displayName,
                $roleId,
                $driverIdValue,
                $isActive,
                $isBot,
                $id,
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE users
                 SET email = ?, password_hash = ?, login_code = ?, display_name = ?,
                     role_id = ?, driver_id = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $email,
                $hash,
                $storedCode,
                $displayName,
                $roleId,
                $driverIdValue,
                $isActive,
                $id,
            ]);
        }
        if ((string)$existing['role_name'] !== $roleName) {
            bakery_audit_write($db, 'user', $id, 'update', 'role', (string)$existing['role_name'], $roleName);
        }
        if ((int)$existing['is_active'] !== $isActive) {
            bakery_audit_write(
                $db,
                'user',
                $id,
                'update',
                'is_active',
                (string)(int)$existing['is_active'],
                (string)$isActive
            );
        }
        if ($codeChanged) {
            bakery_audit_write(
                $db,
                'user',
                $id,
                'update',
                'login_code',
                BAKERY_AUDIT_CODE_CHANGED,
                BAKERY_AUDIT_CODE_CHANGED
            );
        }
        $message = 'User updated.';
    }

    $pairingDate = trim((string)($input['pairing_date'] ?? ''));
    if ($roleSlug === 'driver_assistant' && $pairingDate !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $pairingDate);
        if (!$date || $date->format('Y-m-d') !== $pairingDate) {
            throw new Exception('Route pairing date must use YYYY-MM-DD.');
        }
        if ($driverIdValue === null) {
            throw new Exception('Choose the driver this assistant will pair with.');
        }
        if (!table_exists($db, 'driver_assistant_assignments')) {
            throw new Exception('Driver Assistant pairing is not installed yet. Run migration 045.');
        }
        $pair = $db->prepare(
            'INSERT INTO driver_assistant_assignments (assistant_user_id, driver_id, delivery_date)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id)'
        );
        $pair->execute([$id, $driverIdValue, $pairingDate]);
        $message .= ' Route pairing saved for ' . $pairingDate . '.';
    }

    return ['id' => $id, 'message' => $message];
}

function bakery_users_set_active(PDO $db, int $id, int $active, string $reason = 'admin'): string
{
    if ($id <= 0) {
        throw new Exception('Invalid user.');
    }
    $active = $active === 1 ? 1 : 0;
    if ($active === 0) {
        $current = bakery_current_user();
        if ($current && (int)$current['id'] === $id) {
            throw new Exception('You cannot deactivate your own account.');
        }
    }
    $existing = bakery_users_load($db, $id);
    if (!$existing) {
        throw new Exception('Invalid user.');
    }
    $stmt = $db->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $stmt->execute([$active, $id]);
    if ((int)$existing['is_active'] !== $active) {
        bakery_audit_write(
            $db,
            'user',
            $id,
            'update',
            'is_active',
            (string)(int)$existing['is_active'],
            (string)$active
        );
        if ($active === 0 && function_exists('bakery_record_operational_event')) {
            $reason = $reason === 'inactive_60_days' ? 'inactive_60_days' : 'admin';
            bakery_record_operational_event(
                $db,
                'user_deactivated',
                'Deactivated ' . (string)$existing['display_name'],
                [
                    'metadata' => [
                        'target_user_id' => $id,
                        'reason' => $reason,
                    ],
                ]
            );
        }
    }
    return $active === 1 ? 'User activated.' : 'User deactivated.';
}

/**
 * @return array{ok:bool,error?:string,code?:string}
 */
function bakery_users_reveal_code(PDO $db, int $id): array
{
    $actor = function_exists('bakery_current_user') ? bakery_current_user() : null;
    if (!$actor || ($actor['role_slug'] ?? '') !== 'administrator') {
        return ['ok' => false, 'error' => 'Forbidden'];
    }
    $existing = bakery_users_load($db, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    bakery_audit_write($db, 'user', $id, 'reveal', 'login_code', null, BAKERY_AUDIT_CODE_REVEALED);
    return ['ok' => true, 'code' => (string)$existing['login_code']];
}

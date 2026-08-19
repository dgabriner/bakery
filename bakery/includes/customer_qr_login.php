<?php
/** Short-lived QR invitations for customer portal activation and sign-in. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_portal.php';

define('BAKERY_CUSTOMER_QR_TTL_MINUTES', 30);

function bakery_customer_qr_schema_ready(PDO $db): bool {
    return function_exists('table_exists') && table_exists($db, 'customer_qr_login_invites');
}

/** Return the first unfinished stop assigned to the driver today. */
function bakery_customer_qr_current_stop(PDO $db, int $driverId): ?array {
    if ($driverId <= 0 || !table_exists($db, 'daily_order_assignments')) {
        return null;
    }
    $today = date('Y-m-d');
    $stmt = $db->prepare(
        "SELECT c.id, c.name, c.address, doa.route_order, doa.delivery_status
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.order_date = ?
           AND COALESCE(doa.delivery_status, 'pending') NOT IN ('delivered', 'cancelled') AND c.is_active = 1
         ORDER BY COALESCE(doa.route_order, 2147483647), c.name LIMIT 1"
    );
    $stmt->execute([$driverId, $today, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Create a random invitation. Only its SHA-256 digest is stored. */
function bakery_customer_qr_create_invite(PDO $db, int $customerId, array $actor): array {
    if (!bakery_customer_qr_schema_ready($db)) {
        throw new RuntimeException('Customer QR Login is not installed yet.');
    }
    $customerStmt = $db->prepare('SELECT id, name FROM customers WHERE id = ? AND is_active = 1 LIMIT 1');
    $customerStmt->execute([$customerId]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $token = bin2hex(random_bytes(24));
    $db->beginTransaction();
    try {
        $expire = $db->prepare('UPDATE customer_qr_login_invites SET expires_at = NOW() WHERE customer_id = ? AND used_at IS NULL AND expires_at > NOW()');
        $expire->execute([$customerId]);
        $insert = $db->prepare(
            'INSERT INTO customer_qr_login_invites
             (customer_id, token_hash, created_by_user_id, created_by_driver_id, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $insert->execute([
            $customerId,
            hash('sha256', $token),
            !empty($actor['id']) ? (int)$actor['id'] : null,
            !empty($actor['driver_id']) ? (int)$actor['driver_id'] : null,
            BAKERY_CUSTOMER_QR_TTL_MINUTES,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['token' => $token, 'customer_id' => (int)$customer['id'], 'customer_name' => (string)$customer['name'], 'expires_minutes' => BAKERY_CUSTOMER_QR_TTL_MINUTES];
}

function bakery_customer_qr_find_invite(PDO $db, string $token): ?array {
    if (!bakery_customer_qr_schema_ready($db) || !preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $stmt = $db->prepare(
        'SELECT i.id AS invite_id, i.customer_id, i.expires_at, c.name, c.portal_code, c.portal_enabled
         FROM customer_qr_login_invites i JOIN customers c ON c.id = i.customer_id
         WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND c.is_active = 1 LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_customer_portal_code_available(PDO $db, int $customerId, string $code): bool {
    $stmt = $db->prepare('SELECT id FROM customers WHERE portal_code = ? AND id <> ? AND is_active = 1 LIMIT 1 FOR UPDATE');
    $stmt->execute([$code, $customerId]);
    return !$stmt->fetchColumn();
}

/** Validate or create the invited customer's code, consume the invite, and sign in. */
function bakery_customer_qr_complete(PDO $db, string $token, string $code, string $confirmation = ''): array {
    $code = bakery_normalize_login_code($code);
    if ($code === '') return ['success' => false, 'error' => 'Enter exactly 4 numbers.'];

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT i.id AS invite_id, i.customer_id, c.name, c.portal_code
             FROM customer_qr_login_invites i JOIN customers c ON c.id = i.customer_id
             WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND c.is_active = 1
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $db->rollBack();
            return ['success' => false, 'error' => 'This QR login has expired. Ask for a new one.'];
        }

        $customerId = (int)$row['customer_id'];
        $existingCode = bakery_normalize_login_code($row['portal_code'] ?? '');
        if ($existingCode !== '') {
            if (!hash_equals($existingCode, $code)) {
                $db->rollBack();
                return ['success' => false, 'error' => 'That code does not match. Please try again.'];
            }
            $db->prepare('UPDATE customers SET portal_enabled = 1 WHERE id = ?')->execute([$customerId]);
        } else {
            $confirmation = bakery_normalize_login_code($confirmation);
            if ($confirmation === '' || !hash_equals($code, $confirmation)) {
                $db->rollBack();
                return ['success' => false, 'error' => 'The two codes need to match.'];
            }
            if (!bakery_customer_portal_code_available($db, $customerId, $code)) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Choose a different 4-digit code.'];
            }
            $save = $db->prepare("UPDATE customers SET portal_code = ?, portal_enabled = 1 WHERE id = ? AND (portal_code IS NULL OR portal_code = '')");
            $save->execute([$code, $customerId]);
            if ($save->rowCount() !== 1) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Your login changed. Scan a fresh QR code and try again.'];
            }
        }
        $db->prepare('UPDATE customer_qr_login_invites SET used_at = NOW() WHERE id = ?')->execute([(int)$row['invite_id']]);
        $db->commit();
        bakery_portal_start_session(['id' => $customerId, 'name' => (string)$row['name']], $code);
        return ['success' => true, 'customer_id' => $customerId, 'customer_name' => (string)$row['name']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

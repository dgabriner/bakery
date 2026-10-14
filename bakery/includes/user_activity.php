<?php
/**
 * Staff accounts with no sign-in or recorded action in 60 days.
 * Listing does not deactivate anyone. Deactivation is an audited admin action.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';

function bakery_users_inactive_days(): int
{
    return 60;
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_users_inactive_accounts(PDO $db, ?int $now = null): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'users')) {
        return [];
    }
    $now = $now ?? time();
    $cutoff = $now - (bakery_users_inactive_days() * 24 * 60 * 60);
    $auditReady = table_exists($db, 'login_audit');
    $eventsReady = table_exists($db, 'operational_events');
    $rows = $db->query(
        'SELECT u.id, u.display_name, u.email, u.is_active, u.created_at, u.last_login_at,
                r.slug AS role_slug, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1
         ORDER BY u.display_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $auditStmt = $auditReady
        ? $db->prepare(
            "SELECT MAX(COALESCE(last_seen_at, login_at))
             FROM login_audit
             WHERE user_id = ? AND outcome = 'success'"
        )
        : null;
    $eventStmt = $eventsReady
        ? $db->prepare('SELECT MAX(occurred_at) FROM operational_events WHERE actor_user_id = ?')
        : null;

    $inactive = [];
    foreach ($rows as $row) {
        $stamps = [];
        if (!empty($row['last_login_at'])) {
            $stamps[] = (string)$row['last_login_at'];
        }
        if ($auditStmt) {
            $auditStmt->execute([(int)$row['id']]);
            $auditAt = $auditStmt->fetchColumn();
            if ($auditAt) {
                $stamps[] = (string)$auditAt;
            }
        }
        if ($eventStmt) {
            $eventStmt->execute([(int)$row['id']]);
            $eventAt = $eventStmt->fetchColumn();
            if ($eventAt) {
                $stamps[] = (string)$eventAt;
            }
        }
        if (!$stamps && !empty($row['created_at'])) {
            $stamps[] = (string)$row['created_at'];
        }
        $last = null;
        $lastTs = null;
        foreach ($stamps as $stamp) {
            $ts = strtotime($stamp);
            if ($ts === false) {
                continue;
            }
            if ($lastTs === null || $ts > $lastTs) {
                $lastTs = $ts;
                $last = $stamp;
            }
        }
        if ($lastTs !== null && $lastTs > $cutoff) {
            continue;
        }
        $row['last_activity_at'] = $last;
        $inactive[] = $row;
    }
    return $inactive;
}

/**
 * @return array{ok:bool,error?:string}
 */
function bakery_users_deactivate_account(PDO $db, int $userId, string $reason = 'admin'): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    $current = function_exists('bakery_current_user') ? bakery_current_user() : null;
    if ($current && (int)$current['id'] === $userId) {
        return ['ok' => false, 'error' => 'You cannot deactivate your own account.'];
    }
    $stmt = $db->prepare('SELECT id, display_name, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    if ((int)$row['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'That account is already inactive.'];
    }
    $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    $reason = $reason === 'inactive_60_days' ? 'inactive_60_days' : 'admin';
    bakery_record_operational_event(
        $db,
        'user_deactivated',
        'Deactivated ' . (string)$row['display_name'],
        [
            'metadata' => [
                'target_user_id' => $userId,
                'reason' => $reason,
            ],
        ]
    );
    return ['ok' => true];
}

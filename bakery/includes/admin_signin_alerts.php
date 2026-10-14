<?php
/**
 * Flags an administrator sign-in from an IP or device not seen in 30 days.
 * The flag stays on the admin dashboard. Nothing is sent off the server.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_admin_signin_window_days(): int
{
    return 30;
}

function bakery_admin_signin_consider(PDO $db, int $auditId, int $userId): void
{
    if ($auditId <= 0 || $userId <= 0 || !function_exists('table_exists') || !table_exists($db, 'admin_signin_alerts')) {
        return;
    }
    $userStmt = $db->prepare(
        'SELECT u.display_name, r.slug AS role_slug
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || ($user['role_slug'] ?? '') !== 'administrator') {
        return;
    }

    $context = function_exists('bakery_login_audit_request_context')
        ? bakery_login_audit_request_context()
        : ['ip_address' => null, 'user_agent' => null];
    $ip = $context['ip_address'] ?? null;
    $ua = $context['user_agent'] ?? null;
    if (($ip === null || $ip === '') && ($ua === null || $ua === '')) {
        return;
    }

    $days = bakery_admin_signin_window_days();
    $ipSeen = false;
    if ($ip !== null && $ip !== '') {
        $ipStmt = $db->prepare(
            "SELECT COUNT(*) FROM login_audit
             WHERE user_id = ? AND outcome = 'success' AND id <> ?
               AND ip_address = ? AND login_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        $ipStmt->execute([$userId, $auditId, $ip]);
        $ipSeen = (int)$ipStmt->fetchColumn() > 0;
    }
    $deviceSeen = false;
    if ($ua !== null && $ua !== '') {
        $deviceStmt = $db->prepare(
            "SELECT COUNT(*) FROM login_audit
             WHERE user_id = ? AND outcome = 'success' AND id <> ?
               AND user_agent = ? AND login_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        $deviceStmt->execute([$userId, $auditId, $ua]);
        $deviceSeen = (int)$deviceStmt->fetchColumn() > 0;
    }
    $newIp = ($ip !== null && $ip !== '' && !$ipSeen);
    $newDevice = ($ua !== null && $ua !== '' && !$deviceSeen);
    if (!$newIp && !$newDevice) {
        return;
    }

    $parsed = function_exists('bakery_login_audit_parse_user_agent')
        ? bakery_login_audit_parse_user_agent((string)$ua)
        : ['browser' => '', 'operating_system' => '', 'device_type' => ''];
    $deviceLabel = trim($parsed['browser'] . ' / ' . $parsed['operating_system'] . ' / ' . $parsed['device_type'], ' /');
    $insert = $db->prepare(
        'INSERT INTO admin_signin_alerts
         (user_id, login_audit_id, ip_address, user_agent, device_label)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $userId,
        $auditId,
        $ip !== '' ? $ip : null,
        $ua !== '' ? substr((string)$ua, 0, 1000) : null,
        $deviceLabel !== '' ? substr($deviceLabel, 0, 160) : null,
    ]);
}

/** @return list<array<string,mixed>> */
function bakery_admin_signin_open_alerts(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'admin_signin_alerts')) {
        return [];
    }
    $stmt = $db->query(
        'SELECT a.id, a.user_id, a.ip_address, a.user_agent, a.device_label, a.created_at,
                u.display_name
         FROM admin_signin_alerts a
         JOIN users u ON u.id = a.user_id
         WHERE a.dismissed_at IS NULL
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 20'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return $rows ?: [];
}

function bakery_admin_signin_dismiss(PDO $db, int $alertId, int $actorUserId): bool
{
    if ($alertId <= 0 || !function_exists('table_exists') || !table_exists($db, 'admin_signin_alerts')) {
        return false;
    }
    $stmt = $db->prepare(
        'UPDATE admin_signin_alerts
         SET dismissed_at = NOW(), dismissed_by_user_id = ?
         WHERE id = ? AND dismissed_at IS NULL'
    );
    $stmt->execute([$actorUserId > 0 ? $actorUserId : null, $alertId]);
    if ($stmt->rowCount() < 1) {
        return false;
    }
    // Loaded here so login_audit.php can include this file without pulling the
    // timeline helper into every authenticated request.
    require_once __DIR__ . '/operational_timeline.php';
    bakery_record_operational_event(
        $db,
        'admin_signin_alert_dismissed',
        'Dismissed administrator new-device sign-in alert #' . $alertId,
        ['metadata' => ['alert_id' => $alertId]]
    );
    return true;
}

function bakery_admin_signin_alerts_html(PDO $db): string
{
    $alerts = bakery_admin_signin_open_alerts($db);
    if (!$alerts) {
        return '';
    }
    $html = '<section class="ops-alert ops-alert-warning" role="alert">';
    $html .= '<strong>' . htmlspecialchars(bakery_t('dashboard.admin_signin_title'), ENT_QUOTES, 'UTF-8') . '</strong>';
    $html .= '<ul class="ops-admin-signin-list">';
    foreach ($alerts as $alert) {
        $when = (string)($alert['created_at'] ?? '');
        $html .= '<li>';
        $html .= htmlspecialchars(bakery_t('dashboard.admin_signin_body', [
            'name' => (string)($alert['display_name'] ?? ''),
            'when' => $when,
            'ip' => (string)($alert['ip_address'] ?? ''),
            'device' => (string)($alert['device_label'] ?? ''),
        ]), ENT_QUOTES, 'UTF-8');
        $html .= '<form method="post" action="" style="display:inline;margin-left:0.6rem">';
        $html .= function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
        $html .= '<input type="hidden" name="action" value="dismiss_admin_signin_alert">';
        $html .= '<input type="hidden" name="alert_id" value="' . (int)$alert['id'] . '">';
        $html .= '<button type="submit">' . htmlspecialchars(bakery_t('dashboard.admin_signin_dismiss'), ENT_QUOTES, 'UTF-8') . '</button>';
        $html .= '</form></li>';
    }
    $html .= '</ul></section>';
    return $html;
}

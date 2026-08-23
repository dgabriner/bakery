<?php
/**
 * DreamHost cron: deliver critical/warning staff alerts as one email digest.
 *
 * Closes bug no-staff-alerts: the nav bell was pull-only; this pushes the same
 * live facts to every active administrator/manager without a new module.
 *
 * Unforced runs are production (bakerysf) or DreamHost staging.
 * Local one-shot: php scripts/staff_alert_digest.php --force
 * Test delivery to one inbox: php scripts/staff_alert_digest.php --force --to=you@example.org --json
 *
 * Silent when clean: zero critical/warning alerts means no email and exit 0.
 * MAIL_DRIVER=log records the digest to logs/mail.log instead of SMTP.
 *
 * DreamHost (daily, early morning):
 *   /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/staff_alert_digest.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/staff_alerts.php';
require_once $root . '/includes/customer_notifications.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/operational_timeline.php';

$force = in_array('--force', $argv, true);
$json = in_array('--json', $argv, true);

$toOverride = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--to=') === 0) {
        $toOverride = trim(substr($arg, strlen('--to=')));
    }
}

try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    bakery_demand_scheduler_assert_cli($db, $force);
    $today = date('Y-m-d');

    if ($toOverride !== '') {
        $recipients = [];
        foreach (explode(',', $toOverride) as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['email' => $email, 'name' => $email];
            }
        }
        if ($recipients === []) {
            error_log('staff_alert_digest: --to override contained no valid email addresses');
            fwrite(STDERR, "No valid addresses in --to\n");
            exit(1);
        }
    } else {
        $stmt = $db->query(
            "SELECT u.email, u.display_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.slug IN ('administrator', 'manager')
               AND u.email IS NOT NULL AND u.email <> ''
             ORDER BY u.id"
        );
        $recipients = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim((string)$row['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['email' => $email, 'name' => (string)$row['display_name']];
            }
        }
        if ($recipients === []) {
            error_log('staff_alert_digest: no active administrator/manager user has an email address');
            fwrite(STDERR, "No recipients configured\n");
            exit(1);
        }
    }

    $summary = bakery_staff_alerts_collect(
        $db,
        ['id' => 0, 'role_slug' => 'administrator', 'display_name' => 'Cron Digest'],
        $today
    );
    $emailable = [];
    foreach ($summary['alerts'] as $alert) {
        if (in_array((string)($alert['severity'] ?? ''), ['critical', 'warning'], true)) {
            $emailable[] = $alert;
        }
    }

    if ($emailable === []) {
        if ($json) {
            echo json_encode(['status' => 'clean', 'alerts' => 0, 'emailed' => false]) . "\n";
        } else {
            echo "clean: nothing needs attention, no digest sent\n";
        }
        exit(0);
    }

    $criticalCount = 0;
    $warningCount = 0;
    foreach ($emailable as $alert) {
        if (($alert['severity'] ?? '') === 'critical') {
            $criticalCount++;
        }
        if (($alert['severity'] ?? '') === 'warning') {
            $warningCount++;
        }
    }

    $subject = bakery_t('staff_alerts.digest_subject', ['count' => count($emailable)]);
    $lines = [bakery_t('staff_alerts.digest_heading'), ''];
    $grouped = [];
    foreach ($emailable as $alert) {
        $grouped[(string)($alert['date'] ?? '')][] = $alert;
    }
    foreach ($grouped as $date => $alertsForDate) {
        $label = (string)($alertsForDate[0]['day_label'] ?? $date);
        $lines[] = $label . ' (' . $date . ')';
        foreach ($alertsForDate as $alert) {
            $line = '- [' . strtoupper((string)($alert['severity'] ?? '')) . '] ' . trim((string)($alert['title'] ?? ''));
            $detail = trim((string)($alert['detail'] ?? ''));
            if ($detail !== '') {
                $line .= ' — ' . $detail;
            }
            if (isset($alert['count']) && $alert['count'] !== null && $alert['count'] !== '') {
                $line .= ' (' . $alert['count'] . ')';
            }
            $lines[] = $line;
            $href = trim((string)($alert['href'] ?? ''));
            if ($href !== '') {
                $url = strpos($href, 'http') === 0 || !defined('BASE_URL')
                    ? $href
                    : rtrim(BASE_URL, '/') . '/' . ltrim($href, '/');
                $lines[] = '  ' . $url;
            }
        }
        $lines[] = '';
    }
    $lines[] = bakery_t('staff_alerts.digest_footer', ['datetime' => date('Y-m-d H:i:s T')]);
    $body = implode("\n", $lines);

    $channel = bakery_customer_notification_email_ready() ? 'smtp' : 'log';
    $sentTo = [];
    foreach ($recipients as $recipient) {
        if ($channel === 'smtp') {
            try {
                bakery_customer_notification_send_email_message(
                    $recipient['email'],
                    $recipient['name'],
                    $subject,
                    $body
                );
            } catch (Throwable $e) {
                error_log('staff_alert_digest send to ' . $recipient['email'] . ': ' . $e->getMessage());
                continue;
            }
        } else {
            bakery_billing_append_mail_log(sprintf(
                "[%s] MAIL_DRIVER=log staff_alert_digest alerts=%d to=%s subject=%s\n",
                date('c'),
                count($emailable),
                $recipient['email'],
                $subject
            ));
        }
        $sentTo[] = $recipient['email'];
    }

    if ($sentTo === []) {
        fwrite(STDERR, "Digest delivery failed for every recipient\n");
        exit(1);
    }

    bakery_record_operational_event(
        $db,
        'staff_alert_digest_sent',
        sprintf(
            'Staff alert digest (%s) delivered to %s: %d critical / %d warning',
            $channel,
            implode(', ', $sentTo),
            $criticalCount,
            $warningCount
        ),
        ['operational_date' => $today]
    );

    $result = [
        'status' => 'sent',
        'db' => (string)$db->query('SELECT DATABASE()')->fetchColumn(),
        'alerts' => count($emailable),
        'critical' => $criticalCount,
        'warning' => $warningCount,
        'channel' => $channel,
        'recipients' => $sentTo,
    ];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo sprintf(
            "digest sent channel=%s alerts=%d critical=%d warning=%d recipients=%d db=%s\n",
            $channel,
            count($emailable),
            $criticalCount,
            $warningCount,
            count($sentTo),
            $result['db']
        );
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

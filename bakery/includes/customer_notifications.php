<?php
/**
 * Customer notification center — in-app updates tied to real operational events.
 *
 * Operational timeline (operational_events) = internal audit.
 * customer_notifications = customer-appropriate subset with deduplication.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/brand.php';

define('BAKERY_CN_ORDER_STANDING_CHANGED', 'order_standing_changed');
define('BAKERY_CN_ORDER_DAILY_CHANGED', 'order_daily_changed');
define('BAKERY_CN_ORDER_DELIVERY_SKIPPED', 'order_delivery_skipped');
define('BAKERY_CN_ORDER_PAUSE_SCHEDULED', 'order_pause_scheduled');
define('BAKERY_CN_SERVICE_CHANGE_REQUESTED', 'service_change_requested');
define('BAKERY_CN_DELIVERY_OUT_FOR_DELIVERY', 'delivery_out_for_delivery');
define('BAKERY_CN_DELIVERY_COMPLETED', 'delivery_completed');
define('BAKERY_CN_DELIVERY_QUANTITY_VARIANCE', 'delivery_quantity_variance');
define('BAKERY_CN_BILLING_INVOICE_AVAILABLE', 'billing_invoice_available');
define('BAKERY_CN_BILLING_STATEMENT_AVAILABLE', 'billing_statement_available');
define('BAKERY_CN_SERVICE_ISSUE_SUBMITTED', 'service_issue_submitted');
define('BAKERY_CN_SERVICE_ISSUE_RESOLVED', 'service_issue_resolved');

/** Event types eligible for optional email delivery. */
function bakery_customer_notification_email_event_types() {
    return [
        BAKERY_CN_ORDER_DAILY_CHANGED,
        BAKERY_CN_ORDER_STANDING_CHANGED,
        BAKERY_CN_DELIVERY_COMPLETED,
        BAKERY_CN_DELIVERY_QUANTITY_VARIANCE,
        BAKERY_CN_BILLING_INVOICE_AVAILABLE,
        BAKERY_CN_BILLING_STATEMENT_AVAILABLE,
    ];
}

function bakery_customer_notification_category_for_event($eventType) {
    $map = [
        BAKERY_CN_ORDER_STANDING_CHANGED => 'order',
        BAKERY_CN_ORDER_DAILY_CHANGED => 'order',
        BAKERY_CN_ORDER_DELIVERY_SKIPPED => 'order',
        BAKERY_CN_ORDER_PAUSE_SCHEDULED => 'order',
        BAKERY_CN_SERVICE_CHANGE_REQUESTED => 'order',
        BAKERY_CN_DELIVERY_OUT_FOR_DELIVERY => 'delivery',
        BAKERY_CN_DELIVERY_COMPLETED => 'delivery',
        BAKERY_CN_DELIVERY_QUANTITY_VARIANCE => 'delivery',
        BAKERY_CN_BILLING_INVOICE_AVAILABLE => 'billing',
        BAKERY_CN_BILLING_STATEMENT_AVAILABLE => 'billing',
        BAKERY_CN_SERVICE_ISSUE_SUBMITTED => 'delivery',
        BAKERY_CN_SERVICE_ISSUE_RESOLVED => 'delivery',
    ];
    return $map[$eventType] ?? 'order';
}

/** Ensure notification tables exist (idempotent). */
function bakery_customer_notifications_ensure_schema(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    if (!table_exists($db, 'customer_notifications')) {
        $path = dirname(__DIR__) . '/database/schema/025_customer_notifications.sql';
        if (is_readable($path)) {
            $sql = file_get_contents($path);
            if ($sql !== false) {
                foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $statement) {
                    if ($statement !== '') {
                        try {
                            $db->exec($statement);
                        } catch (Throwable $e) {
                            // Idempotent migration.
                        }
                    }
                }
            }
        }
    }
    $done = true;
}

function bakery_customer_notification_default_preferences() {
    return [
        'order_in_app' => true,
        'order_email' => true,
        'delivery_in_app' => true,
        'delivery_email' => false,
        'billing_in_app' => true,
        'billing_email' => true,
    ];
}

function bakery_customer_notification_preferences(PDO $db, $customerId) {
    bakery_customer_notifications_ensure_schema($db);
    $defaults = bakery_customer_notification_default_preferences();
    if (!table_exists($db, 'customer_notification_preferences')) {
        return $defaults;
    }
    $stmt = $db->prepare(
        'SELECT order_in_app, order_email, delivery_in_app, delivery_email, billing_in_app, billing_email
         FROM customer_notification_preferences WHERE customer_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $defaults;
    }
    return [
        'order_in_app' => (bool)(int)$row['order_in_app'],
        'order_email' => (bool)(int)$row['order_email'],
        'delivery_in_app' => (bool)(int)$row['delivery_in_app'],
        'delivery_email' => (bool)(int)$row['delivery_email'],
        'billing_in_app' => (bool)(int)$row['billing_in_app'],
        'billing_email' => (bool)(int)$row['billing_email'],
    ];
}

function bakery_customer_notification_save_preferences(PDO $db, $customerId, array $prefs) {
    bakery_customer_notifications_ensure_schema($db);
    if (!table_exists($db, 'customer_notification_preferences')) {
        throw new RuntimeException('Notification preferences table not available');
    }
    $current = bakery_customer_notification_preferences($db, $customerId);
    $merged = array_merge($current, array_intersect_key($prefs, $current));
    $stmt = $db->prepare(
        'INSERT INTO customer_notification_preferences
            (customer_id, order_in_app, order_email, delivery_in_app, delivery_email, billing_in_app, billing_email)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            order_in_app = VALUES(order_in_app),
            order_email = VALUES(order_email),
            delivery_in_app = VALUES(delivery_in_app),
            delivery_email = VALUES(delivery_email),
            billing_in_app = VALUES(billing_in_app),
            billing_email = VALUES(billing_email)'
    );
    $stmt->execute([
        (int)$customerId,
        $merged['order_in_app'] ? 1 : 0,
        $merged['order_email'] ? 1 : 0,
        $merged['delivery_in_app'] ? 1 : 0,
        $merged['delivery_email'] ? 1 : 0,
        $merged['billing_in_app'] ? 1 : 0,
        $merged['billing_email'] ? 1 : 0,
    ]);
    return $merged;
}

function bakery_customer_notification_channel_enabled(array $prefs, $category, $channel) {
    $key = $category . '_' . $channel;
    return !empty($prefs[$key]);
}

/**
 * Create a customer notification with deduplication.
 *
 * @return int|null Notification id when newly created, null when skipped/duplicate.
 */
function bakery_customer_notify(PDO $db, $customerId, $eventType, $title, $message, array $options = []) {
    bakery_customer_notifications_ensure_schema($db);
    if (!table_exists($db, 'customer_notifications')) {
        return null;
    }

    $customerId = (int)$customerId;
    if ($customerId <= 0 || trim((string)$title) === '' || trim((string)$message) === '') {
        return null;
    }

    $category = $options['category'] ?? bakery_customer_notification_category_for_event($eventType);
    $prefs = bakery_customer_notification_preferences($db, $customerId);
    $wantsInApp = bakery_customer_notification_channel_enabled($prefs, $category, 'in_app');
    $wantsEmail = bakery_customer_notification_channel_enabled($prefs, $category, 'email')
        && in_array($eventType, bakery_customer_notification_email_event_types(), true);

    if (!$wantsInApp && !$wantsEmail) {
        return null;
    }

    $dedupeKey = trim((string)($options['dedupe_key'] ?? ''));
    if ($dedupeKey === '') {
        $dedupeKey = $eventType . ':' . (int)($options['related_entity_id'] ?? 0);
    }

    $linkUrl = isset($options['link_url']) ? trim((string)$options['link_url']) : null;
    if ($linkUrl === '') {
        $linkUrl = null;
    }
    $relatedEntityType = isset($options['related_entity_type']) ? (string)$options['related_entity_type'] : null;
    $relatedEntityId = isset($options['related_entity_id']) ? (int)$options['related_entity_id'] : null;

    if (!$wantsInApp) {
        // Email-only path still needs a row for audit; mark as read immediately.
        $emailStatus = $wantsEmail ? 'pending' : 'skipped';
    } else {
        $emailStatus = $wantsEmail ? 'pending' : 'none';
    }

    $stmt = $db->prepare(
        'INSERT IGNORE INTO customer_notifications
            (customer_id, event_type, title, message, link_url, related_entity_type, related_entity_id,
             dedupe_key, email_status, read_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $readAt = $wantsInApp ? null : date('Y-m-d H:i:s');
    $stmt->execute([
        $customerId,
        $eventType,
        $title,
        $message,
        $linkUrl,
        $relatedEntityType,
        $relatedEntityId > 0 ? $relatedEntityId : null,
        $dedupeKey,
        $emailStatus,
        $readAt,
    ]);

    if ($stmt->rowCount() === 0) {
        return null;
    }

    $notificationId = (int)$db->lastInsertId();
    if ($notificationId > 0 && $emailStatus === 'pending') {
        bakery_customer_notification_try_send_email($db, $customerId, $notificationId);
    }

    return $notificationId;
}

function bakery_customer_notifications_unread_count(PDO $db, $customerId) {
    bakery_customer_notifications_ensure_schema($db);
    if (!table_exists($db, 'customer_notifications')) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM customer_notifications
         WHERE customer_id = ? AND read_at IS NULL'
    );
    $stmt->execute([(int)$customerId]);
    return (int)$stmt->fetchColumn();
}

function bakery_customer_notifications_list(PDO $db, $customerId, $limit = 50, $offset = 0) {
    bakery_customer_notifications_ensure_schema($db);
    if (!table_exists($db, 'customer_notifications')) {
        return [];
    }
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $stmt = $db->prepare(
        'SELECT id, event_type, title, message, link_url, related_entity_type, related_entity_id,
                read_at, created_at
         FROM customer_notifications
         WHERE customer_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute([(int)$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_read'] = $row['read_at'] !== null && $row['read_at'] !== '';
        $row['created_at_formatted'] = format_date($row['created_at'], 'M j, Y g:i A');
    }
    unset($row);
    return $rows;
}

function bakery_customer_notification_mark_read(PDO $db, $customerId, $notificationId) {
    bakery_customer_notifications_ensure_schema($db);
    $stmt = $db->prepare(
        'UPDATE customer_notifications SET read_at = NOW()
         WHERE id = ? AND customer_id = ? AND read_at IS NULL'
    );
    $stmt->execute([(int)$notificationId, (int)$customerId]);
    return $stmt->rowCount() > 0;
}

function bakery_customer_notifications_mark_all_read(PDO $db, $customerId) {
    bakery_customer_notifications_ensure_schema($db);
    $stmt = $db->prepare(
        'UPDATE customer_notifications SET read_at = NOW()
         WHERE customer_id = ? AND read_at IS NULL'
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->rowCount();
}

function bakery_customer_notification_get(PDO $db, $customerId, $notificationId) {
    bakery_customer_notifications_ensure_schema($db);
    $stmt = $db->prepare(
        'SELECT * FROM customer_notifications WHERE id = ? AND customer_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$notificationId, (int)$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** True when SMTP/OAuth mail is configured (not log-only). */
function bakery_customer_notification_email_ready() {
    if (function_exists('bakery_billing_email_ready')) {
        return bakery_billing_email_ready();
    }
    return defined('MAIL_DRIVER') && MAIL_DRIVER !== 'log'
        && defined('SMTP_HOST') && SMTP_HOST !== '';
}

function bakery_customer_notification_try_send_email(PDO $db, $customerId, $notificationId) {
    if (!bakery_customer_notification_email_ready()) {
        $db->prepare(
            "UPDATE customer_notifications SET email_status = 'skipped' WHERE id = ? AND customer_id = ?"
        )->execute([(int)$notificationId, (int)$customerId]);
        return false;
    }

    $notification = bakery_customer_notification_get($db, $customerId, $notificationId);
    if (!$notification || $notification['email_status'] !== 'pending') {
        return false;
    }

    $custStmt = $db->prepare('SELECT name, email FROM customers WHERE id = ? LIMIT 1');
    $custStmt->execute([(int)$customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    $email = trim((string)($customer['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $db->prepare(
            "UPDATE customer_notifications SET email_status = 'skipped' WHERE id = ?"
        )->execute([(int)$notificationId]);
        return false;
    }

    try {
        bakery_customer_notification_send_email_message(
            $email,
            (string)$customer['name'],
            (string)$notification['title'],
            (string)$notification['message'],
            $notification['link_url'] ? (string)$notification['link_url'] : null
        );
        $db->prepare(
            "UPDATE customer_notifications SET email_status = 'sent', email_sent_at = NOW() WHERE id = ?"
        )->execute([(int)$notificationId]);
        return true;
    } catch (Throwable $e) {
        error_log('customer notification email failed: ' . $e->getMessage());
        $db->prepare(
            "UPDATE customer_notifications SET email_status = 'failed' WHERE id = ?"
        )->execute([(int)$notificationId]);
        return false;
    }
}

/** Send a simple HTML notification email via existing PHPMailer config. */
function bakery_customer_notification_send_email_message($toEmail, $customerName, $subject, $body, $linkUrl = null) {
    if (!bakery_customer_notification_email_ready()) {
        throw new RuntimeException('Email is not configured');
    }

    require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
    require_once __DIR__ . '/email_config.php';

    $mailDriver = defined('MAIL_DRIVER') ? strtolower((string)MAIL_DRIVER) : 'smtp';
    if ($mailDriver === 'oauth') {
        $oauthBootstrap = __DIR__ . '/gmail_oauth.php';
        $oauthInterface = __DIR__ . '/../vendor/phpmailer/src/OAuthTokenProvider.php';
        if (is_readable($oauthBootstrap) && is_readable($oauthInterface)) {
            require_once $oauthBootstrap;
            if (class_exists('GmailOAuth', false) && GmailOAuth::isAuthorized()) {
                $html = bakery_customer_notification_email_html($customerName, $body, $linkUrl);
                return GmailOAuth::sendEmail($toEmail, $subject, $html, 'Sour Flour Bakery', []);
            }
        }
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    if (strtolower((string)SMTP_ENCRYPTION) === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif (strtolower((string)SMTP_ENCRYPTION) === 'tls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port = SMTP_PORT;
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($toEmail, $customerName);
    $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = bakery_customer_notification_email_html($customerName, $body, $linkUrl);
    $mail->AltBody = $body . ($linkUrl ? "\n\n" . $linkUrl : '');
    $mail->send();
    return true;
}

function bakery_customer_notification_email_html($customerName, $body, $linkUrl = null) {
    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $logoUrl = htmlspecialchars(bakery_sour_flour_logo_url(), ENT_QUOTES, 'UTF-8');
    $linkHtml = '';
    if ($linkUrl) {
        $href = htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8');
        if (strpos($linkUrl, 'http') !== 0 && defined('BASE_URL')) {
            $href = htmlspecialchars(rtrim(BASE_URL, '/') . '/' . ltrim($linkUrl, '/'), ENT_QUOTES, 'UTF-8');
        }
        $linkHtml = '<p style="margin-top:20px;"><a href="' . $href . '" style="color:#b75c3f;">View in customer portal</a></p>';
    }
    return '<div style="font-family:sans-serif;color:#33251f;max-width:560px;">'
        . '<p style="margin:0 0 20px;"><img src="' . $logoUrl . '" alt="Sour Flour" style="display:block;height:auto;max-width:180px;width:100%;"></p>'
        . '<p>Hello ' . $safeName . ',</p>'
        . '<p>' . $safeBody . '</p>'
        . $linkHtml
        . '</div>';
}

// ── Event-specific notification builders ─────────────────────────────────────

function bakery_customer_notify_standing_changed(PDO $db, array $customer, $dayLabel, $productName, $oldQty, $newQty, $dayOfWeek, $productId) {
    if ($oldQty === $newQty) {
        return null;
    }
    $title = bakery_t('portal.notify_standing_title', ['day' => $dayLabel]);
    $message = bakery_t('portal.notify_standing_message', [
        'day' => $dayLabel,
        'product' => $productName,
        'old' => (int)$oldQty,
        'new' => (int)$newQty,
    ]);
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_ORDER_STANDING_CHANGED, $title, $message, [
        'dedupe_key' => 'standing:' . (int)$customer['id'] . ':' . (int)$dayOfWeek . ':' . (int)$productId . ':' . (int)$newQty,
        'link_url' => 'customer_portal_regular.php',
        'related_entity_type' => 'standing_order',
        'related_entity_id' => (int)$productId,
    ]);
}

function bakery_customer_notify_daily_changed(PDO $db, array $customer, $date, $productName, $oldQty, $newQty, $dailyOrderId, $productId) {
    if ($oldQty === $newQty) {
        return null;
    }
    $dateLabel = format_date($date, 'l M j');
    $title = bakery_t('portal.notify_daily_title', ['date' => $dateLabel]);
    $message = bakery_t('portal.notify_daily_message', [
        'date' => $dateLabel,
        'product' => $productName,
        'old' => (int)$oldQty,
        'new' => (int)$newQty,
    ]);
    $link = function_exists('bakery_portal_delivery_url')
        ? bakery_portal_delivery_url($date, $dailyOrderId)
        : 'customer_portal_delivery.php?date=' . urlencode($date);
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_ORDER_DAILY_CHANGED, $title, $message, [
        'dedupe_key' => 'daily:' . (int)$dailyOrderId . ':' . (int)$productId . ':' . (int)$newQty,
        'link_url' => $link,
        'related_entity_type' => 'daily_order',
        'related_entity_id' => (int)$dailyOrderId,
    ]);
}

function bakery_customer_notify_delivery_skipped(PDO $db, array $customer, $date, $dailyOrderId = null) {
    $dateLabel = format_date($date, 'l M j');
    $title = bakery_t('portal.notify_skip_title', ['date' => $dateLabel]);
    $message = bakery_t('portal.notify_skip_message', ['date' => $dateLabel]);
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_ORDER_DELIVERY_SKIPPED, $title, $message, [
        'dedupe_key' => 'skip:' . (int)$customer['id'] . ':' . $date,
        'link_url' => 'customer_portal_calendar.php',
        'related_entity_type' => 'daily_order',
        'related_entity_id' => $dailyOrderId ? (int)$dailyOrderId : null,
    ]);
}

function bakery_customer_notify_pause_scheduled(PDO $db, array $customer, $pauseStart, $pauseEnd, $pauseId = null) {
    $startLabel = format_date($pauseStart, 'M j');
    $endLabel = format_date($pauseEnd, 'M j, Y');
    $title = bakery_t('portal.notify_pause_title');
    $message = bakery_t('portal.notify_pause_message', ['start' => $startLabel, 'end' => $endLabel]);
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_ORDER_PAUSE_SCHEDULED, $title, $message, [
        'dedupe_key' => 'pause:' . (int)$customer['id'] . ':' . $pauseStart . ':' . $pauseEnd,
        'link_url' => 'customer_portal_calendar.php',
        'related_entity_type' => 'delivery_pause',
        'related_entity_id' => $pauseId ? (int)$pauseId : null,
    ]);
}

function bakery_customer_notify_change_requested(PDO $db, array $customer, $date, $requestId = null) {
    $dateLabel = format_date($date, 'l M j');
    $title = bakery_t('portal.notify_change_request_title', ['date' => $dateLabel]);
    $message = bakery_t('portal.notify_change_request_message');
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_SERVICE_CHANGE_REQUESTED, $title, $message, [
        'dedupe_key' => 'change_request:' . (int)$customer['id'] . ':' . $date . ':' . (int)$requestId,
        'link_url' => 'customer_portal_delivery.php?date=' . urlencode($date),
        'related_entity_type' => 'change_request',
        'related_entity_id' => $requestId ? (int)$requestId : null,
    ]);
}

function bakery_customer_notify_out_for_delivery(PDO $db, $dailyOrderId) {
    $ctx = bakery_customer_notification_order_context($db, (int)$dailyOrderId);
    if (!$ctx) {
        return null;
    }
    if ($ctx['status'] !== 'out_for_delivery') {
        return null;
    }
    $dateLabel = format_date($ctx['order_date'], 'l M j');
    $title = bakery_t('portal.notify_out_for_delivery_title');
    $message = bakery_t('portal.notify_out_for_delivery_message', ['date' => $dateLabel]);
    $link = function_exists('bakery_portal_delivery_url')
        ? bakery_portal_delivery_url($ctx['order_date'], (int)$dailyOrderId)
        : 'customer_portal_delivery.php?id=' . (int)$dailyOrderId;
    return bakery_customer_notify($db, (int)$ctx['customer_id'], BAKERY_CN_DELIVERY_OUT_FOR_DELIVERY, $title, $message, [
        'dedupe_key' => 'out_for_delivery:' . (int)$dailyOrderId,
        'link_url' => $link,
        'related_entity_type' => 'daily_order',
        'related_entity_id' => (int)$dailyOrderId,
    ]);
}

function bakery_customer_notify_delivery_completed(PDO $db, $dailyOrderId) {
    $ctx = bakery_customer_notification_order_context($db, (int)$dailyOrderId);
    if (!$ctx || empty($ctx['delivery_confirmed_at'])) {
        return null;
    }

    $dateLabel = format_date($ctx['order_date'], 'l M j');
    $timeLabel = date('g:i A', strtotime($ctx['delivery_confirmed_at']));
    $link = function_exists('bakery_portal_delivery_url')
        ? bakery_portal_delivery_url($ctx['order_date'], (int)$dailyOrderId)
        : 'customer_portal_delivery.php?id=' . (int)$dailyOrderId;

    $orderedPieces = (int)($ctx['ordered_pieces'] ?? 0);
    $deliveredPieces = (int)($ctx['delivered_pieces'] ?? 0);
    $hasVariance = $orderedPieces > 0 && $deliveredPieces > 0 && $deliveredPieces !== $orderedPieces;

    if ($hasVariance) {
        $title = bakery_t('portal.notify_delivered_variance_title');
        $message = bakery_t('portal.notify_delivered_variance_message', ['date' => $dateLabel]);
        bakery_customer_notify($db, (int)$ctx['customer_id'], BAKERY_CN_DELIVERY_QUANTITY_VARIANCE, $title, $message, [
            'dedupe_key' => 'delivery_variance:' . (int)$dailyOrderId,
            'link_url' => $link,
            'related_entity_type' => 'daily_order',
            'related_entity_id' => (int)$dailyOrderId,
        ]);
    }

    $title = bakery_t('portal.notify_delivered_title');
    $message = bakery_t('portal.notify_delivered_message', [
        'date' => $dateLabel,
        'time' => $timeLabel,
    ]);
    return bakery_customer_notify($db, (int)$ctx['customer_id'], BAKERY_CN_DELIVERY_COMPLETED, $title, $message, [
        'dedupe_key' => 'delivery_completed:' . (int)$dailyOrderId,
        'link_url' => $link,
        'related_entity_type' => 'daily_order',
        'related_entity_id' => (int)$dailyOrderId,
    ]);
}

function bakery_customer_notify_invoice_available(PDO $db, $dailyOrderId) {
    $ctx = bakery_customer_notification_order_context($db, (int)$dailyOrderId);
    if (!$ctx || empty($ctx['delivery_confirmed_at'])) {
        return null;
    }
    $dateLabel = format_date($ctx['order_date'], 'M j, Y');
    $invoiceRef = function_exists('bakery_billing_invoice_number')
        ? bakery_billing_invoice_number((int)$dailyOrderId, $ctx['order_date'])
        : ('#' . (int)$dailyOrderId);
    $title = bakery_t('portal.notify_invoice_title');
    $message = bakery_t('portal.notify_invoice_message', ['date' => $dateLabel, 'invoice' => $invoiceRef]);
    return bakery_customer_notify($db, (int)$ctx['customer_id'], BAKERY_CN_BILLING_INVOICE_AVAILABLE, $title, $message, [
        'dedupe_key' => 'invoice:' . (int)$dailyOrderId,
        'link_url' => 'customer_invoice.php?daily_order_id=' . (int)$dailyOrderId,
        'related_entity_type' => 'daily_order',
        'related_entity_id' => (int)$dailyOrderId,
    ]);
}

function bakery_customer_notify_statement_available(PDO $db, $customerId, $statementId, $periodStart, $periodEnd) {
    $startLabel = format_date($periodStart, 'M j');
    $endLabel = format_date($periodEnd, 'M j, Y');
    $title = bakery_t('portal.notify_statement_title');
    $message = bakery_t('portal.notify_statement_message', ['start' => $startLabel, 'end' => $endLabel]);
    $link = 'customer_portal_statement.php?start_date=' . urlencode($periodStart)
        . '&end_date=' . urlencode($periodEnd);
    return bakery_customer_notify($db, (int)$customerId, BAKERY_CN_BILLING_STATEMENT_AVAILABLE, $title, $message, [
        'dedupe_key' => 'statement:' . (int)$statementId,
        'link_url' => $link,
        'related_entity_type' => 'billing_statement',
        'related_entity_id' => (int)$statementId,
    ]);
}

/** Notify all customers whose orders moved to out_for_delivery on a driver load. */
function bakery_customer_notify_out_for_delivery_batch(PDO $db, $driverId, $deliveryDate) {

    $stmt = $db->prepare(
        'SELECT do.id
         FROM daily_orders do
         INNER JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.status = ?'
    );
    $stmt->execute([(int)$driverId, $deliveryDate, 'out_for_delivery']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        bakery_customer_notify_out_for_delivery($db, (int)$orderId);
    }
}

function bakery_customer_notify_issue_submitted(PDO $db, array $customer, $issueId, $orderDate) {
    $dateLabel = format_date($orderDate, 'l M j');
    $title = bakery_t('portal.notify_issue_received_title', ['date' => $dateLabel]);
    $message = bakery_t('portal.notify_issue_received_message');
    $link = 'customer_portal_issue.php?id=' . (int)$issueId;
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_SERVICE_ISSUE_SUBMITTED, $title, $message, [
        'dedupe_key' => 'issue_submitted:' . (int)$issueId,
        'link_url' => $link,
        'related_entity_type' => 'delivery_issue',
        'related_entity_id' => (int)$issueId,
    ]);
}

function bakery_customer_notify_issue_resolved(PDO $db, array $customer, $issueId, $resolutionNote, $orderDate) {
    $dateLabel = format_date($orderDate, 'l M j');
    $title = bakery_t('portal.notify_issue_resolved_title', ['date' => $dateLabel]);
    $message = $resolutionNote !== '' ? $resolutionNote : bakery_t('portal.notify_issue_resolved_message');
    $link = 'customer_portal_issue.php?id=' . (int)$issueId;
    return bakery_customer_notify($db, (int)$customer['id'], BAKERY_CN_SERVICE_ISSUE_RESOLVED, $title, $message, [
        'dedupe_key' => 'issue_resolved:' . (int)$issueId,
        'link_url' => $link,
        'related_entity_type' => 'delivery_issue',
        'related_entity_id' => (int)$issueId,
    ]);
}

function bakery_customer_notification_order_context(PDO $db, $dailyOrderId) {
    $stmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status,
                do.delivery_confirmed_at, do.delivered_pieces,
                (SELECT COALESCE(SUM(quantity), 0) FROM daily_order_items WHERE daily_order_id = do.id) AS ordered_pieces
         FROM daily_orders do
         WHERE do.id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$dailyOrderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

<?php
/**
 * Customer portal AJAX API — standing orders, dated deliveries, pauses, reorder.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/portal_command_center.php';
require_once __DIR__ . '/includes/customer_order_mutations.php';
require_once __DIR__ . '/includes/customer_delivery_issues.php';
require_once __DIR__ . '/includes/customer_account.php';

header('Content-Type: application/json');

$customer = bakery_portal_customer($db);
if (!$customer) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in']);
    exit;
}

$customerId = (int)$customer['id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'save_order':
        case 'save_standing':
            $productId = (int)($_POST['product_id'] ?? 0);
            $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $result = bakery_customer_save_standing_line($db, $customer, $productId, $dayOfWeek, $quantity);
            echo json_encode([
                'success' => true,
                'result' => $result,
                'confirmation' => bakery_customer_format_confirmation($result, 'standing'),
            ]);
            break;

        case 'save_daily_item':
            $date = trim((string)($_POST['date'] ?? ''));
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $result = bakery_customer_save_daily_line($db, $customer, $date, $productId, $quantity);
            $result['date'] = $date;
            $context = bakery_portal_cmd_assert_delivery_date($db, $customerId, $date);
            echo json_encode([
                'success' => true,
                'result' => $result,
                'confirmation' => bakery_customer_format_confirmation($result, 'delivery'),
                'delivery' => bakery_portal_cmd_build_delivery_card($db, $customerId, $date, $context),
            ]);
            break;

        case 'apply_reorder':
            $sourceOrderId = (int)($_POST['source_order_id'] ?? 0);
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            $context = bakery_portal_cmd_apply_reorder($db, $customer, $sourceOrderId, $targetDate);
            $card = bakery_portal_cmd_build_delivery_card($db, $customerId, $targetDate, $context);
            echo json_encode(['success' => true, 'delivery' => $card]);
            break;

        case 'get_delivery':
            $date = trim((string)($_POST['date'] ?? ''));
            $context = bakery_portal_cmd_assert_delivery_date($db, $customerId, $date);
            echo json_encode([
                'success' => true,
                'delivery' => bakery_portal_cmd_build_delivery_card($db, $customerId, $date, $context),
            ]);
            break;

        case 'get_upcoming':
            echo json_encode([
                'success' => true,
                'deliveries' => bakery_portal_cmd_schedule_deliveries($db, $customerId, 42, 20),
            ]);
            break;

        case 'get_history':
            echo json_encode([
                'success' => true,
                'history' => bakery_portal_cmd_history_search($db, $customerId, [
                    'start_date' => $_POST['start_date'] ?? '',
                    'end_date' => $_POST['end_date'] ?? '',
                    'product_id' => (int)($_POST['product_id'] ?? 0),
                    'q' => $_POST['q'] ?? '',
                    'limit' => (int)($_POST['limit'] ?? 30),
                    'offset' => (int)($_POST['offset'] ?? 0),
                ]),
            ]);
            break;

        case 'get_notifications':
            require_once __DIR__ . '/includes/customer_notifications.php';
            echo json_encode([
                'success' => true,
                'notifications' => bakery_customer_notifications_list($db, $customerId, (int)($_POST['limit'] ?? 50), (int)($_POST['offset'] ?? 0)),
                'unread_count' => bakery_customer_notifications_unread_count($db, $customerId),
            ]);
            break;

        case 'mark_notification_read':
            require_once __DIR__ . '/includes/customer_notifications.php';
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            echo json_encode([
                'success' => bakery_customer_notification_mark_read($db, $customerId, $notificationId),
                'unread_count' => bakery_customer_notifications_unread_count($db, $customerId),
            ]);
            break;

        case 'mark_all_notifications_read':
            require_once __DIR__ . '/includes/customer_notifications.php';
            bakery_customer_notifications_mark_all_read($db, $customerId);
            echo json_encode([
                'success' => true,
                'unread_count' => 0,
            ]);
            break;

        case 'save_notification_preferences':
            require_once __DIR__ . '/includes/customer_notifications.php';
            $prefs = bakery_customer_notification_save_preferences($db, $customerId, [
                'order_in_app' => !empty($_POST['order_in_app']),
                'order_email' => !empty($_POST['order_email']),
                'delivery_in_app' => !empty($_POST['delivery_in_app']),
                'delivery_email' => !empty($_POST['delivery_email']),
                'billing_in_app' => !empty($_POST['billing_in_app']),
                'billing_email' => !empty($_POST['billing_email']),
            ]);
            echo json_encode(['success' => true, 'preferences' => $prefs]);
            break;

        case 'pause_week':
            $weekStart = bakery_week_start_monday($_POST['week_start'] ?? null);
            $note = trim((string)($_POST['note'] ?? ''));
            $stmt = $db->prepare(
                'INSERT INTO standing_order_pauses (customer_id, week_start, note)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note)'
            );
            $stmt->execute([$customerId, $weekStart, $note !== '' ? $note : null]);
            bakery_portal_record_event($db, BAKERY_OP_PORTAL_PAUSE_CREATED,
                $customer['name'] . ' paused week starting ' . $weekStart,
                $customerId,
                ['metadata' => ['week_start' => $weekStart, 'type' => 'week']]
            );
            require_once __DIR__ . '/includes/customer_notifications.php';
            $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
            bakery_customer_notify_pause_scheduled($db, $customer, $weekStart, $weekEnd);
            echo json_encode(['success' => true, 'week_start' => $weekStart]);
            break;

        case 'unpause_week':
            $weekStart = bakery_week_start_monday($_POST['week_start'] ?? null);
            $stmt = $db->prepare(
                'DELETE FROM standing_order_pauses WHERE customer_id = ? AND week_start = ?'
            );
            $stmt->execute([$customerId, $weekStart]);
            bakery_portal_record_event($db, BAKERY_OP_PORTAL_PAUSE_REMOVED,
                $customer['name'] . ' resumed week starting ' . $weekStart,
                $customerId,
                ['metadata' => ['week_start' => $weekStart, 'type' => 'week']]
            );
            echo json_encode(['success' => true]);
            break;

        case 'skip_delivery':
            $date = trim((string)($_POST['date'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            bakery_customer_skip_delivery($db, $customer, $date, $note);
            echo json_encode([
                'success' => true,
                'confirmation' => [
                    'title' => bakery_t('portal.confirm_skipped', ['date' => format_date($date)]),
                    'lines' => [bakery_t('portal.confirm_regular_unchanged')],
                ],
            ]);
            break;

        case 'unskip_delivery':
            $date = trim((string)($_POST['date'] ?? ''));
            bakery_customer_unskip_delivery($db, $customer, $date);
            echo json_encode([
                'success' => true,
                'confirmation' => [
                    'title' => bakery_t('portal.confirm_unskipped', ['date' => format_date($date)]),
                ],
            ]);
            break;

        case 'pause_range':
            $pauseStart = trim((string)($_POST['pause_start'] ?? ''));
            $pauseEnd = trim((string)($_POST['pause_end'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $result = bakery_customer_create_pause_range($db, $customer, $pauseStart, $pauseEnd, $note);
            echo json_encode([
                'success' => true,
                'pause' => $result,
                'confirmation' => [
                    'title' => bakery_t('portal.confirm_pause_range', [
                        'start' => format_date($pauseStart),
                        'end' => format_date($pauseEnd),
                    ]),
                    'lines' => [bakery_t('portal.confirm_regular_unchanged')],
                ],
            ]);
            break;

        case 'remove_pause_range':
            $pauseId = (int)($_POST['pause_id'] ?? 0);
            bakery_customer_remove_pause_range($db, $customer, $pauseId);
            echo json_encode([
                'success' => true,
                'confirmation' => ['title' => bakery_t('portal.confirm_pause_removed')],
            ]);
            break;

        case 'request_change':
            $date = trim((string)($_POST['date'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $details = [];
            if (!empty($_POST['product_id'])) {
                $details['product_id'] = (int)$_POST['product_id'];
            }
            if (isset($_POST['requested_quantity'])) {
                $details['requested_quantity'] = (int)$_POST['requested_quantity'];
            }
            $result = bakery_customer_request_change($db, $customer, $date, $message, $details);
            echo json_encode([
                'success' => true,
                'request_id' => $result['request_id'],
                'confirmation' => [
                    'title' => bakery_t('portal.confirm_change_requested', ['date' => format_date($date)]),
                    'lines' => [bakery_t('portal.confirm_change_request_note')],
                ],
            ]);
            break;

        case 'submit_issue':
            bakery_require_csrf();
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            $issue = bakery_delivery_issue_submit($db, $customer, $dailyOrderId, [
                'category' => $_POST['category'] ?? '',
                'product_id' => $_POST['product_id'] ?? null,
                'customer_reported_quantity' => $_POST['customer_reported_quantity'] ?? null,
                'description' => $_POST['description'] ?? '',
                'credit_requested' => !empty($_POST['credit_requested']),
            ]);
            echo json_encode([
                'success' => true,
                'issue' => $issue,
                'confirmation' => [
                    'title' => bakery_t('issue.confirm_submitted_title'),
                    'lines' => [bakery_t('issue.confirm_submitted_note')],
                ],
            ]);
            break;

        case 'get_orders':
            $ordersStmt = $db->prepare(
                'SELECT so.product_id,
                        CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS day_of_week,
                        so.quantity,
                        p.name AS product_name
                 FROM standing_orders so
                 JOIN products p ON p.id = so.product_id
                 WHERE so.customer_id = ?
                 ORDER BY day_of_week, p.name'
            );
            $ordersStmt->execute([$customerId]);
            echo json_encode(['success' => true, 'orders' => $ordersStmt->fetchAll()]);
            break;

        case 'get_account_profile':
            echo json_encode([
                'success' => true,
                'profile' => bakery_customer_account_load($db, $customerId),
            ]);
            break;

        case 'save_account_section':
            $section = trim((string)($_POST['section'] ?? ''));
            $fieldsRaw = $_POST['fields'] ?? '';
            $fields = is_array($fieldsRaw) ? $fieldsRaw : json_decode((string)$fieldsRaw, true);
            if (!is_array($fields)) {
                throw new InvalidArgumentException('Invalid form data');
            }
            $result = bakery_customer_account_update_section($db, $customer, $section, $fields);
            echo json_encode([
                'success' => true,
                'no_changes' => empty($result['changes']),
                'changes' => $result['changes'],
                'confirmation' => [
                    'title' => empty($result['changes'])
                        ? bakery_t('portal.account_no_changes')
                        : bakery_t('portal.account_saved'),
                ],
            ]);
            break;

        case 'request_account_change':
            $field = trim((string)($_POST['field'] ?? ''));
            $requestedValue = trim((string)($_POST['requested_value'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $result = bakery_customer_account_request_change($db, $customer, $field, $requestedValue, $message);
            echo json_encode([
                'success' => true,
                'request_id' => $result['request_id'],
                'confirmation' => [
                    'title' => bakery_t('portal.account_request_submitted'),
                    'lines' => [bakery_t('portal.confirm_change_request_note')],
                ],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

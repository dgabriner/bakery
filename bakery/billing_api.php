<?php
/**
 * Billing API — mark invoiced, record statements, trigger exports.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/billing.php';

bakery_require_role(['administrator', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

bakery_require_csrf();

$action = (string)($_POST['action'] ?? '');
$user = bakery_current_user();
$userId = isset($user['id']) ? (int)$user['id'] : null;
$redirect = (string)($_POST['redirect'] ?? 'billing_center.php');

try {
    switch ($action) {
        case 'mark_invoiced':
            $orderId = (int)($_POST['daily_order_id'] ?? 0);
            bakery_billing_mark_invoiced($db, $orderId, $userId);
            $redirect = $redirect ?: 'billing_center.php?panel=invoices&invoice_id=' . $orderId;
            safe_redirect($redirect, 'marked_invoiced');
            break;

        case 'bulk_mark_invoiced':
            $orderIds = $_POST['order_ids'] ?? [];
            if (!is_array($orderIds)) {
                $orderIds = [$orderIds];
            }
            $marked = 0;
            $skipped = 0;
            foreach ($orderIds as $rawId) {
                $orderId = (int)$rawId;
                if ($orderId <= 0) {
                    continue;
                }
                try {
                    bakery_billing_mark_invoiced($db, $orderId, $userId);
                    $marked++;
                } catch (Throwable $e) {
                    // Ineligible rows (unconfirmed delivery, already invoiced) are skipped, not fatal.
                    $skipped++;
                }
            }
            if ($marked === 0 && $skipped === 0) {
                throw new RuntimeException('No deliveries selected');
            }
            $msg = $marked . ' deliver' . ($marked === 1 ? 'y' : 'ies') . ' marked invoiced.';
            if ($skipped > 0) {
                $msg .= ' ' . $skipped . ' skipped (not confirmed or already invoiced).';
            }
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            safe_redirect(($redirect ?: 'billing_center.php?panel=invoices') . $sep . 'bulk_msg=' . urlencode($msg), 'marked_invoiced');
            break;

        case 'send_invoice':
            $orderId = (int)($_POST['daily_order_id'] ?? 0);
            $result = bakery_billing_send_invoice($db, $orderId, $userId);
            if (empty($result['ok'])) {
                $msg = 'Invoice marked invoiced but not sent: no billing email on file.';
                if (($result['reason'] ?? '') !== 'no_email') {
                    $msg = 'Invoice could not be sent.';
                }
            } elseif (($result['channel'] ?? '') === 'log') {
                $msg = 'Invoice ' . $result['invoice_number'] . ' recorded, not emailed (MAIL_DRIVER=log or SMTP missing).';
            } else {
                $msg = 'Invoice ' . $result['invoice_number'] . ' emailed to ' . $result['recipient'] . '.';
            }
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            safe_redirect(($redirect ?: 'billing_center.php?panel=invoices&invoice_id=' . $orderId) . $sep . 'bulk_msg=' . urlencode($msg), 'invoice_sent');
            break;

        case 'bulk_send_invoices':
            $orderIds = $_POST['order_ids'] ?? [];
            if (!is_array($orderIds)) {
                $orderIds = [$orderIds];
            }
            $batch = bakery_billing_send_invoices($db, $orderIds, $userId);
            if ($batch['sent'] === 0 && $batch['skipped'] === 0) {
                throw new RuntimeException('No deliveries selected');
            }
            $channel = bakery_billing_email_ready() ? 'smtp' : 'log';
            if ($channel === 'log') {
                $msg = $batch['sent'] . ' invoice' . ($batch['sent'] === 1 ? '' : 's') . ' recorded, not emailed.';
            } else {
                $msg = $batch['sent'] . ' invoice' . ($batch['sent'] === 1 ? '' : 's') . ' emailed.';
            }
            if ($batch['skipped'] > 0) {
                $msg .= ' ' . $batch['skipped'] . ' skipped (not confirmed, not sendable, or no billing email).';
            }
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            safe_redirect(($redirect ?: 'billing_center.php?panel=invoices') . $sep . 'bulk_msg=' . urlencode($msg), 'invoice_sent');
            break;

        case 'record_statement':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $statementDate = trim((string)($_POST['statement_date'] ?? date('Y-m-d')));
            $markSent = !empty($_POST['mark_sent']);
            $sentTo = trim((string)($_POST['sent_to_email'] ?? ''));

            $data = bakery_billing_statement_data($db, $customerId, $startDate, $endDate, $statementDate);
            $record = [
                'customer_id' => $customerId,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'statement_date' => $statementDate,
                'invoice_count' => $data['invoice_count'],
                'total_amount' => $data['total_amount'],
                'sent_at' => $markSent ? date('Y-m-d H:i:s') : null,
                'sent_by_user_id' => $markSent ? $userId : null,
                'sent_to_email' => $markSent && $sentTo !== '' ? $sentTo : null,
            ];
            bakery_billing_record_statement($db, $record, $userId);
            safe_redirect(
                'billing_center.php?panel=customer&customer_id=' . $customerId . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate),
                'statement_recorded'
            );
            break;

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    error_log('billing_api: ' . $e->getMessage());
    safe_redirect($redirect ?: 'billing_center.php', 'error');
}

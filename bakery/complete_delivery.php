<?php
/**
 * Delivery completion API — uses shared config/database (Checkpoint 0B).
 * Hardcoded production credentials removed.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

try {
    $db = check_mysql_connection();

    if (!isset($_POST['action'])) {
        throw new Exception('Action is required');
    }

    $action = $_POST['action'];

    switch ($action) {
        case 'mark_delivered':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            $stmt = $db->prepare(
                "UPDATE daily_order_assignments
                 SET delivery_status = 'delivered', actual_delivery_time = CURTIME()
                 WHERE daily_order_id = ?"
            );
            $stmt->execute([$dailyOrderId]);
            echo json_encode([
                'success' => true,
                'message' => 'Delivery marked as completed successfully'
            ]);
            break;

        case 'get_order_items':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];

            $orderStmt = $db->prepare(
                "SELECT do.id, c.name AS customer_name
                 FROM daily_orders do
                 JOIN customers c ON c.id = do.customer_id
                 WHERE do.id = ?"
            );
            $orderStmt->execute([$dailyOrderId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new Exception('Order not found');
            }

            $stmt = $db->prepare(
                "SELECT doi.id, doi.quantity, doi.unit_price, doi.line_total, p.name AS product_name
                 FROM daily_order_items doi
                 JOIN products p ON p.id = doi.product_id
                 WHERE doi.daily_order_id = ?
                 ORDER BY p.name"
            );
            $stmt->execute([$dailyOrderId]);
            $items = $stmt->fetchAll();

            $html = '<div style="padding: 10px;">';
            $html .= '<h3 style="margin-top:0;">Modify Order — ' . htmlspecialchars($order['customer_name']) . '</h3>';
            $html .= '<form id="modify-order-form">';
            if (empty($items)) {
                $html .= '<p>No items on this order.</p>';
            } else {
                foreach ($items as $item) {
                    $html .= '<div style="margin-bottom:12px;">';
                    $html .= '<label style="display:block;font-weight:600;">' .
                        htmlspecialchars($item['product_name']) .
                        ' <span style="font-weight:400;color:#666;">($' .
                        number_format((float)$item['unit_price'], 2) . ')</span></label>';
                    $html .= '<input type="number" min="0" step="1" name="quantity_' . (int)$item['id'] . '" value="' .
                        (int)$item['quantity'] . '" style="width:100%;padding:8px;">';
                    $html .= '</div>';
                }
            }
            $html .= '</form>';
            $html .= '<div style="display:flex;gap:8px;margin-top:16px;">';
            $html .= '<button type="button" onclick="saveModifiedOrder()" style="flex:1;padding:10px;background:#28a745;color:#fff;border:none;border-radius:4px;">Save & Deliver</button>';
            $html .= '<button type="button" onclick="closeCompleteDeliveryModal()" style="flex:1;padding:10px;background:#6c757d;color:#fff;border:none;border-radius:4px;">Cancel</button>';
            $html .= '</div></div>';

            echo json_encode(['success' => true, 'html' => $html, 'items' => $items]);
            break;

        case 'update_order_and_deliver':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            if (!isset($_POST['updates'])) {
                throw new Exception('Updates are required');
            }

            $dailyOrderId = (int)$_POST['daily_order_id'];
            $updates = json_decode($_POST['updates'], true);
            if (!is_array($updates)) {
                throw new Exception('Invalid updates payload');
            }

            $db->beginTransaction();

            foreach ($updates as $itemId => $quantity) {
                $itemId = (int)$itemId;
                $quantity = (int)$quantity;
                if ($quantity <= 0) {
                    $del = $db->prepare('DELETE FROM daily_order_items WHERE id = ? AND daily_order_id = ?');
                    $del->execute([$itemId, $dailyOrderId]);
                } else {
                    $upd = $db->prepare(
                        'UPDATE daily_order_items
                         SET quantity = ?, line_total = (? * unit_price)
                         WHERE id = ? AND daily_order_id = ?'
                    );
                    $upd->execute([$quantity, $quantity, $itemId, $dailyOrderId]);
                }
            }

            $sum = $db->prepare(
                'SELECT COALESCE(SUM(line_total), 0) FROM daily_order_items WHERE daily_order_id = ?'
            );
            $sum->execute([$dailyOrderId]);
            $total = $sum->fetchColumn();

            $tot = $db->prepare('UPDATE daily_orders SET total_amount = ? WHERE id = ?');
            $tot->execute([$total, $dailyOrderId]);

            $deliv = $db->prepare(
                "UPDATE daily_order_assignments
                 SET delivery_status = 'delivered', actual_delivery_time = CURTIME()
                 WHERE daily_order_id = ?"
            );
            $deliv->execute([$dailyOrderId]);

            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Order updated and delivery completed',
                'total' => $total
            ]);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

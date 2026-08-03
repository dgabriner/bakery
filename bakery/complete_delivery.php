<?php
/**
 * Delivery completion API — uses shared config/database (Checkpoint 0B).
 * Auth + CSRF enforced via includes/database.php (Checkpoint 0D).
 *
 * Unified delivery status on completion (both tables set to 'delivered'):
 * - daily_order_assignments.delivery_status: pending|in_transit|delivered|failed|cancelled
 * - daily_orders.status: pending|confirmed|in_production|ready|out_for_delivery|delivered|invoiced
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/product_inventory.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

/**
 * Mark assignment and parent daily_order as delivered in one transaction.
 * Safe to call when caller already holds an open transaction.
 */
function bakery_mark_delivery_delivered(PDO $db, int $dailyOrderId): void {
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        $assignmentStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'delivered', actual_delivery_time = CURTIME()
             WHERE daily_order_id = ?"
        );
        $assignmentStmt->execute([$dailyOrderId]);

        $orderStmt = $db->prepare(
            "UPDATE daily_orders SET status = 'delivered' WHERE id = ?"
        );
        $orderStmt->execute([$dailyOrderId]);

        // Preserve what was ordered and record the actual quantity separately.
        // A regular completion means the full order was delivered unless a driver
        // previously entered a shortfall through the adjustment action.
        $itemsStmt = $db->prepare(
            'UPDATE daily_order_items
             SET delivered_quantity = COALESCE(delivered_quantity, quantity)
             WHERE daily_order_id = ?'
        );
        $itemsStmt->execute([$dailyOrderId]);

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Exception $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_delivery_invoice(PDO $db, int $dailyOrderId): array {
    $orderStmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.total_amount,
                do.delivery_order_total, do.delivery_pricing_label,
                do.delivery_confirmed_at, do.delivered_pieces,
                do.credits_taken_back, c.name AS customer_name,
                c.address AS customer_address, c.phone AS customer_phone,
                c.default_pan_dulce_price
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found');
    }

    $itemStmt = $db->prepare(
        "SELECT doi.id, doi.product_id, doi.quantity, doi.delivered_quantity,
                doi.unit_price, doi.line_total, p.name AS product_name,
                p.price AS standard_price, pl.name AS product_line_name,
                dt.name AS dough_type_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ?
         ORDER BY pl.name, dt.name, p.name"
    );
    $itemStmt->execute([$dailyOrderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderedPieces = 0;
    $storedOrderTotal = 0.0;
    $hasPanDulce = false;
    $hasStorePrice = false;
    $hasStandardPrice = false;
    foreach ($items as &$item) {
        $quantity = (int)$item['quantity'];
        $unitPrice = round((float)$item['unit_price'], 2);
        $lineTotal = round((float)$item['line_total'], 2);
        $orderedPieces += $quantity;
        $storedOrderTotal += $lineTotal;
        $item['quantity'] = $quantity;
        $item['unit_price'] = $unitPrice;
        $item['line_total'] = $lineTotal;
        if (($item['product_line_name'] ?? '') === 'Pan Dulce') {
            $hasPanDulce = true;
            $storePrice = $order['default_pan_dulce_price'];
            if ($storePrice !== null && (float)$storePrice > 0 && abs($unitPrice - (float)$storePrice) < 0.005) {
                $hasStorePrice = true;
            } elseif (abs($unitPrice - (float)$item['standard_price']) < 0.005) {
                $hasStandardPrice = true;
            }
        }
    }
    unset($item);

    $pricingLabel = (string)($order['delivery_pricing_label'] ?? '');
    if ($pricingLabel === '') {
        if ($hasStorePrice && $hasStandardPrice) {
            $pricingLabel = 'Mixed Pan Dulce pricing';
        } elseif ($hasStorePrice) {
            $pricingLabel = 'Store price';
        } elseif ($hasPanDulce) {
            $pricingLabel = 'Standard price';
        } else {
            $pricingLabel = 'Order pricing';
        }
    }

    $orderTotal = $order['delivery_order_total'] !== null
        ? round((float)$order['delivery_order_total'], 2)
        : round($storedOrderTotal, 2);
    $averagePrice = $orderedPieces > 0 ? round($orderTotal / $orderedPieces, 4) : 0.0;

    return [
        'order' => $order,
        'items' => $items,
        'ordered_pieces' => $orderedPieces,
        'order_total' => $orderTotal,
        'average_price' => $averagePrice,
        'pricing_label' => $pricingLabel,
    ];
}

function bakery_delivery_summary(PDO $db, int $dailyOrderId): array {
    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    return [
        'ordered_pieces' => $invoice['ordered_pieces'],
        'order_total' => $invoice['order_total'],
        'average_price' => $invoice['average_price'],
        'pricing_label' => $invoice['pricing_label'],
    ];
}

try {
    $db = check_mysql_connection();

    if (!isset($_POST['action'])) {
        throw new Exception('Action is required');
    }

    $action = $_POST['action'];

    switch ($action) {
        case 'get_delivery_summary':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            $summary = bakery_delivery_summary($db, $dailyOrderId);
            $orderStmt = $db->prepare('SELECT delivered_pieces, credits_taken_back, total_amount FROM daily_orders WHERE id = ?');
            $orderStmt->execute([$dailyOrderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new Exception('Order not found');
            }
            echo json_encode([
                'success' => true,
                'ordered_pieces' => $summary['ordered_pieces'],
                'order_total' => $summary['order_total'],
                'average_price' => $summary['average_price'],
                'pricing_label' => $summary['pricing_label'],
                'delivered_pieces' => $order['delivered_pieces'] === null ? $summary['ordered_pieces'] : (int)$order['delivered_pieces'],
                'credits_taken_back' => (int)$order['credits_taken_back'],
                'saved_total' => (float)$order['total_amount'],
                'is_saved' => $order['delivery_confirmed_at'] !== null,
                'items' => bakery_delivery_invoice($db, $dailyOrderId)['items'],
            ]);
            break;

        case 'get_delivery_invoice':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $invoice = bakery_delivery_invoice($db, (int)$_POST['daily_order_id']);
            echo json_encode([
                'success' => true,
                'invoice' => [
                    'daily_order_id' => (int)$invoice['order']['id'],
                    'date' => $invoice['order']['order_date'],
                    'customer_name' => $invoice['order']['customer_name'],
                    'customer_address' => $invoice['order']['customer_address'],
                    'status' => $invoice['order']['status'],
                    'ordered_pieces' => $invoice['ordered_pieces'],
                    'delivered_pieces' => $invoice['order']['delivered_pieces'] === null ? $invoice['ordered_pieces'] : (int)$invoice['order']['delivered_pieces'],
                    'credits_taken_back' => (int)$invoice['order']['credits_taken_back'],
                    'billable_pieces' => max(0, (int)($invoice['order']['delivered_pieces'] ?? $invoice['ordered_pieces']) - (int)$invoice['order']['credits_taken_back']),
                    'price_per_piece' => $invoice['average_price'],
                    'order_total' => $invoice['order_total'],
                    'total' => (float)$invoice['order']['total_amount'],
                    'pricing_label' => $invoice['pricing_label'],
                    'confirmed_at' => $invoice['order']['delivery_confirmed_at'],
                    'items' => $invoice['items'],
                ],
            ]);
            break;

        case 'confirm_delivery':
            if (!isset($_POST['daily_order_id'], $_POST['delivered_pieces'], $_POST['credits_taken_back'])) {
                throw new Exception('Delivery pieces and credits are required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            $deliveredPieces = filter_var($_POST['delivered_pieces'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $creditsTakenBack = filter_var($_POST['credits_taken_back'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($deliveredPieces === false || $creditsTakenBack === false) {
                throw new Exception('Enter whole numbers of pieces and credits');
            }
            if ($creditsTakenBack > $deliveredPieces) {
                throw new Exception('Credits taken back cannot exceed pieces delivered');
            }

            $summary = bakery_delivery_summary($db, $dailyOrderId);
            $billablePieces = $deliveredPieces - $creditsTakenBack;
            $pricePerPiece = $summary['average_price'];
            $total = round($billablePieces * $pricePerPiece, 2);

            $db->beginTransaction();
            $tot = $db->prepare(
                'UPDATE daily_orders
                 SET delivered_pieces = ?, credits_taken_back = ?, total_amount = ?,
                     delivery_order_total = COALESCE(delivery_order_total, ?),
                     delivery_pricing_label = COALESCE(delivery_pricing_label, ?),
                     delivery_confirmed_at = NOW()
                 WHERE id = ?'
            );
            $tot->execute([$deliveredPieces, $creditsTakenBack, $total, $summary['order_total'], $summary['pricing_label'], $dailyOrderId]);
            if ($tot->rowCount() === 0) {
                throw new Exception('Could not save the delivery invoice');
            }
            bakery_mark_delivery_delivered($db, $dailyOrderId);
            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Delivery confirmed.',
                'delivered_pieces' => $deliveredPieces,
                'credits_taken_back' => $creditsTakenBack,
                'billable_pieces' => $billablePieces,
                'price_per_piece' => $pricePerPiece,
                'total' => $total,
            ]);
            break;

        case 'mark_delivered':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_mark_delivery_delivered($db, $dailyOrderId);
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
                if ($quantity < 0) {
                    throw new Exception('Delivered quantity cannot be negative');
                }
                $upd = $db->prepare(
                    'UPDATE daily_order_items
                     SET delivered_quantity = ?, line_total = (? * unit_price)
                     WHERE id = ? AND daily_order_id = ?'
                );
                $upd->execute([$quantity, $quantity, $itemId, $dailyOrderId]);
            }

            $sum = $db->prepare(
                'SELECT COALESCE(SUM(COALESCE(delivered_quantity, quantity) * unit_price), 0) FROM daily_order_items WHERE daily_order_id = ?'
            );
            $sum->execute([$dailyOrderId]);
            $total = $sum->fetchColumn();

            $tot = $db->prepare('UPDATE daily_orders SET total_amount = ? WHERE id = ?');
            $tot->execute([$total, $dailyOrderId]);

            bakery_mark_delivery_delivered($db, $dailyOrderId);

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

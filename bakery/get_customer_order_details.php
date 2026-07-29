<?php
// Security check
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    if (!isset($_POST['daily_order_id'])) {
        throw new Exception('Daily order ID is required');
    }
    
    $dailyOrderId = (int)$_POST['daily_order_id'];
    
    // Get order details
    $stmt = $db->prepare("
        SELECT 
            do.id as daily_order_id,
            do.total_amount,
            c.name as customer_name,
            c.address as customer_address
        FROM daily_orders do
        INNER JOIN customers c ON do.customer_id = c.id
        WHERE do.id = ?
    ");
    $stmt->execute([$dailyOrderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    // Get order items grouped by product line and dough type
    $stmt = $db->prepare("
        SELECT 
            doi.id as order_item_id,
            doi.quantity,
            doi.unit_price,
            doi.line_total as total_price,
            p.name as product_name,
            p.description as product_description,
            pl.name as product_line_name,
            dt.name as dough_type_name
        FROM daily_order_items doi
        INNER JOIN products p ON doi.product_id = p.id
        INNER JOIN dough_types dt ON p.dough_type_id = dt.id
        INNER JOIN product_lines pl ON dt.product_line_id = pl.id
        WHERE doi.daily_order_id = ?
        ORDER BY pl.name, dt.name, p.name
    ");
    $stmt->execute([$dailyOrderId]);
    $items = $stmt->fetchAll();
    
    // Group items by product line and dough type
    $groupedItems = [];
    foreach ($items as $item) {
        $productLine = $item['product_line_name'];
        $doughType = $item['dough_type_name'];
        
        if (!isset($groupedItems[$productLine])) {
            $groupedItems[$productLine] = [];
        }
        
        if (!isset($groupedItems[$productLine][$doughType])) {
            $groupedItems[$productLine][$doughType] = [];
        }
        
        $groupedItems[$productLine][$doughType][] = $item;
    }
    
    // Generate HTML
    $html = '<div class="order-details-content">';
    
    if (empty($groupedItems)) {
        $html .= '<div style="text-align: center; color: #6c757d; font-style: italic; padding: 20px;">No products found for this order.</div>';
    } else {
        foreach ($groupedItems as $productLine => $doughTypes) {
            $html .= '<div class="product-line-group">';
            $html .= '<div class="product-line-header">' . htmlspecialchars($productLine) . '</div>';
            
            foreach ($doughTypes as $doughType => $products) {
                foreach ($products as $product) {
                    $html .= '<div class="product-item">';
                    $html .= '<div class="product-info">';
                    $html .= '<div class="product-name">' . htmlspecialchars($product['product_name']) . '</div>';
                    $html .= '<div class="product-meta">' . htmlspecialchars($doughType) . ' • $' . number_format($product['unit_price'], 2) . ' each</div>';
                    $html .= '</div>';
                    $html .= '<div class="product-quantity">' . $product['quantity'] . '</div>';
                    $html .= '<div class="product-total">$' . number_format($product['total_price'], 2) . '</div>';
                    $html .= '</div>';
                }
            }
            
            $html .= '</div>';
        }
    }
    
    $html .= '</div>';
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'order' => $order
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 
<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Check if we have the required parameters
if (!isset($_GET['customer_id']) || !isset($_GET['start_date']) || !isset($_GET['end_date'])) {
    die('Missing required parameters');
}

$customerId = (int)$_GET['customer_id'];
$startDate = $_GET['start_date'];
$endDate = $_GET['end_date'];
$emailOnly = isset($_GET['email_only']) && $_GET['email_only'] === '1';

try {
    // Get customer information
    $stmt = $db->prepare("
        SELECT c.*, z.name as zone_name 
        FROM customers c 
        LEFT JOIN zones z ON c.zone = z.name 
        WHERE c.id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // Get all orders for the period
    $stmt = $db->prepare("
        SELECT do.*, DATE_FORMAT(do.order_date, '%M %d, %Y') as formatted_date
        FROM daily_orders do
        WHERE do.customer_id = ? AND do.order_date BETWEEN ? AND ?
        ORDER BY do.order_date
    ");
    $stmt->execute([$customerId, $startDate, $endDate]);
    $orders = $stmt->fetchAll();
    
    if (empty($orders)) {
        throw new Exception('No orders found for this period');
    }
    
    // Get order items for all orders
    $orderIds = array_column($orders, 'id');
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    
    $stmt = $db->prepare("
        SELECT 
            doi.*,
            p.name as product_name,
            p.price as unit_price,
            dt.name as dough_type_name
        FROM daily_order_items doi
        JOIN products p ON doi.product_id = p.id
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        WHERE doi.daily_order_id IN ($placeholders)
        ORDER BY doi.daily_order_id, p.name
    ");
    $stmt->execute($orderIds);
    $allItems = $stmt->fetchAll();
    
    // Group items by order
    $orderItems = [];
    foreach ($allItems as $item) {
        $orderItems[$item['daily_order_id']][] = $item;
    }
    
    // Calculate totals
    $totalAmount = array_sum(array_column($orders, 'total_amount'));
    $totalOrders = count($orders);
    
    // Generate invoice number
    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($customerId, 4, '0', STR_PAD_LEFT) . '-' . date('md', strtotime($endDate));
    
    // Create the PDF
    require_once 'vendor/tcpdf/tcpdf.php';
    
    class InvoicePDF extends TCPDF {
        public function Header() {
            // Logo
            $this->SetFont('helvetica', 'B', 20);
            $this->SetTextColor(40, 62, 80);
            $this->Cell(0, 15, 'Sour Flour Bakery', 0, 1, 'L');
            $this->SetFont('helvetica', '', 10);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 5, 'Artisan Breads & Pastries', 0, 1, 'L');
            $this->Ln(10);
        }
        
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 10, 'Thank you for your business! | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
    
    $pdf = new InvoicePDF();
    $pdf->SetCreator('Sour Flour Bakery');
    $pdf->SetAuthor('Sour Flour Bakery');
    $pdf->SetTitle('Invoice ' . $invoiceNumber);
    $pdf->SetSubject('Customer Invoice');
    
    $pdf->AddPage();
    
    // Invoice header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(40, 62, 80);
    $pdf->Cell(0, 10, 'INVOICE', 0, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(73, 80, 87);
    $pdf->Cell(0, 5, 'Invoice #: ' . $invoiceNumber, 0, 1, 'R');
    $pdf->Cell(0, 5, 'Date: ' . date('F j, Y'), 0, 1, 'R');
    $pdf->Cell(0, 5, 'Period: ' . date('F j, Y', strtotime($startDate)) . ' - ' . date('F j, Y', strtotime($endDate)), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    // Customer information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(40, 62, 80);
    $pdf->Cell(0, 8, 'Bill To:', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(73, 80, 87);
    $pdf->Cell(0, 5, htmlspecialchars($customer['name']), 0, 1, 'L');
    if ($customer['address']) {
        $pdf->Cell(0, 5, htmlspecialchars($customer['address']), 0, 1, 'L');
    }
    if ($customer['phone']) {
        $pdf->Cell(0, 5, 'Phone: ' . htmlspecialchars($customer['phone']), 0, 1, 'L');
    }
    if ($customer['email']) {
        $pdf->Cell(0, 5, 'Email: ' . htmlspecialchars($customer['email']), 0, 1, 'L');
    }
    
    $pdf->Ln(15);
    
    // Orders table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(248, 249, 250);
    $pdf->SetTextColor(40, 62, 80);
    $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(80, 8, 'Product', 1, 0, 'L', true);
    $pdf->Cell(20, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Unit Price', 1, 0, 'R', true);
    $pdf->Cell(25, 8, 'Total', 1, 1, 'R', true);
    
    // Orders data
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(73, 80, 87);
    
    foreach ($orders as $order) {
        $firstItem = true;
        $orderDate = date('m/d/Y', strtotime($order['order_date']));
        
        if (isset($orderItems[$order['id']])) {
            foreach ($orderItems[$order['id']] as $item) {
                $pdf->Cell(25, 6, $firstItem ? $orderDate : '', 1, 0, 'C');
                $pdf->Cell(80, 6, htmlspecialchars($item['product_name']), 1, 0, 'L');
                $pdf->Cell(20, 6, $item['quantity'], 1, 0, 'C');
                $pdf->Cell(25, 6, '$' . number_format($item['unit_price'], 2), 1, 0, 'R');
                $pdf->Cell(25, 6, '$' . number_format($item['line_total'], 2), 1, 1, 'R');
                $firstItem = false;
            }
        } else {
            // Order with no items (shouldn't happen but handle gracefully)
            $pdf->Cell(25, 6, $orderDate, 1, 0, 'C');
            $pdf->Cell(80, 6, 'Order Total', 1, 0, 'L');
            $pdf->Cell(20, 6, '-', 1, 0, 'C');
            $pdf->Cell(25, 6, '-', 1, 0, 'R');
            $pdf->Cell(25, 6, '$' . number_format($order['total_amount'], 2), 1, 1, 'R');
        }
    }
    
    // Total row
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(233, 236, 239);
    $pdf->Cell(150, 8, 'TOTAL (' . $totalOrders . ' orders)', 1, 0, 'R', true);
    $pdf->Cell(25, 8, '$' . number_format($totalAmount, 2), 1, 1, 'R', true);
    
    $pdf->Ln(10);
    
    // Notes section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 5, 'Payment Terms: Net 30 days', 0, 1, 'L');
    $pdf->Cell(0, 5, 'Thank you for choosing Sour Flour Bakery!', 0, 1, 'L');
    
    // Output the PDF
    $filename = 'invoice_' . $invoiceNumber . '.pdf';
    
    if ($emailOnly) {
        // Save to temp file for email
        $tempFile = sys_get_temp_dir() . '/' . $filename;
        $pdf->Output($tempFile, 'F');
        
        // Send email
        $success = sendInvoiceEmail($customer, $tempFile, $filename, $invoiceNumber, $startDate, $endDate, $totalAmount);
        
        // Clean up temp file
        unlink($tempFile);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Invoice emailed successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
    } else {
        // Download PDF
        $pdf->Output($filename, 'D');
    }
    
} catch (Exception $e) {
    if ($emailOnly) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        die('Error generating invoice: ' . $e->getMessage());
    }
}

function sendInvoiceEmail($customer, $pdfFile, $filename, $invoiceNumber, $startDate, $endDate, $totalAmount) {
    // Include the new email utility class
    require_once 'includes/email_utils.php';
    
    // Use the new EmailUtils class with proper SMTP authentication and PDF attachment
    return EmailUtils::sendInvoiceEmail($customer, $invoiceNumber, $startDate, $endDate, $totalAmount, null, $pdfFile, $filename);
}
?> 
<?php
/**
 * Customer delivery issue / service resolution workflow.
 *
 * Issues are claims tied to a delivery — they do not overwrite fulfillment records.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_delivery.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/customer_notifications.php';

/** Ensure issue table exists (idempotent). */
function bakery_delivery_issues_ensure_schema(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    if (!table_exists($db, 'customer_delivery_issues')) {
        $path = dirname(__DIR__) . '/database/schema/026_customer_delivery_issues.sql';
        if (is_readable($path) && function_exists('bakery_run_sql_file')) {
            bakery_run_sql_file($db, $path);
        } elseif (is_readable($path)) {
            $sql = file_get_contents($path);
            if ($sql !== false) {
                foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $statement) {
                    if ($statement !== '') {
                        try {
                            $db->exec($statement);
                        } catch (Throwable $e) {
                            // Idempotent.
                        }
                    }
                }
            }
        }
    }
    $done = true;
}

/** @return array<string, array{label_key:string,needs_product:bool}> */
function bakery_delivery_issue_categories() {
    return [
        'missing_quantity' => ['label_key' => 'issue.cat_missing_quantity', 'needs_product' => true],
        'wrong_product' => ['label_key' => 'issue.cat_wrong_product', 'needs_product' => true],
        'damaged' => ['label_key' => 'issue.cat_damaged', 'needs_product' => true],
        'quality' => ['label_key' => 'issue.cat_quality', 'needs_product' => true],
        'delivery_problem' => ['label_key' => 'issue.cat_delivery_problem', 'needs_product' => false],
        'billing' => ['label_key' => 'issue.cat_billing', 'needs_product' => false],
        'other' => ['label_key' => 'issue.cat_other', 'needs_product' => false],
    ];
}

function bakery_delivery_issue_category_label($category) {
    $cats = bakery_delivery_issue_categories();
    if (!isset($cats[$category])) {
        return ucfirst(str_replace('_', ' ', (string)$category));
    }
    return bakery_t($cats[$category]['label_key']);
}

/** @return array<string, array{label_key:string,tone:string}> */
function bakery_delivery_issue_status_meta() {
    return [
        'submitted' => ['label_key' => 'issue.status_submitted', 'tone' => 'info'],
        'under_review' => ['label_key' => 'issue.status_under_review', 'tone' => 'warn'],
        'resolved' => ['label_key' => 'issue.status_resolved', 'tone' => 'ok'],
        'closed' => ['label_key' => 'issue.status_closed', 'tone' => 'muted'],
    ];
}

function bakery_delivery_issue_status_label($status) {
    $meta = bakery_delivery_issue_status_meta();
    return bakery_t($meta[$status]['label_key'] ?? 'issue.status_submitted');
}

function bakery_delivery_issue_assert_ownership(PDO $db, int $customerId, int $issueId) {
    bakery_delivery_issues_ensure_schema($db);
    if ($customerId <= 0 || $issueId <= 0) {
        throw new InvalidArgumentException('Invalid issue reference');
    }
    $stmt = $db->prepare(
        'SELECT ci.*, p.name AS product_name, c.name AS customer_name
         FROM customer_delivery_issues ci
         JOIN customers c ON c.id = ci.customer_id
         LEFT JOIN products p ON p.id = ci.product_id
         WHERE ci.id = ? AND ci.customer_id = ?
         LIMIT 1'
    );
    $stmt->execute([$issueId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Issue not found');
    }
    return $row;
}

/** Issues for one delivery (customer-scoped). */
function bakery_delivery_issues_for_delivery(PDO $db, int $customerId, int $dailyOrderId) {
    bakery_delivery_issues_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_issues')) {
        return [];
    }
    bakery_customer_delivery_assert_ownership($db, $customerId, $dailyOrderId);
    $stmt = $db->prepare(
        'SELECT ci.*, p.name AS product_name
         FROM customer_delivery_issues ci
         LEFT JOIN products p ON p.id = ci.product_id
         WHERE ci.customer_id = ? AND ci.daily_order_id = ?
         ORDER BY ci.created_at DESC'
    );
    $stmt->execute([$customerId, $dailyOrderId]);
    return array_map('bakery_delivery_issue_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function bakery_delivery_issue_format_row(array $row) {
    $status = (string)($row['status'] ?? 'submitted');
    $meta = bakery_delivery_issue_status_meta();
    return [
        'id' => (int)$row['id'],
        'customer_id' => (int)$row['customer_id'],
        'daily_order_id' => (int)$row['daily_order_id'],
        'order_date' => (string)$row['order_date'],
        'product_id' => $row['product_id'] !== null ? (int)$row['product_id'] : null,
        'product_name' => $row['product_name'] ?? null,
        'category' => (string)$row['category'],
        'category_label' => bakery_delivery_issue_category_label((string)$row['category']),
        'ordered_quantity' => $row['ordered_quantity'] !== null ? (int)$row['ordered_quantity'] : null,
        'driver_delivered_quantity' => $row['driver_delivered_quantity'] !== null ? (int)$row['driver_delivered_quantity'] : null,
        'customer_reported_quantity' => $row['customer_reported_quantity'] !== null ? (int)$row['customer_reported_quantity'] : null,
        'description' => (string)$row['description'],
        'status' => $status,
        'status_label' => bakery_delivery_issue_status_label($status),
        'status_tone' => $meta[$status]['tone'] ?? 'info',
        'credit_recommendation' => (string)($row['credit_recommendation'] ?? 'none'),
        'credit_pieces' => $row['credit_pieces'] !== null ? (int)$row['credit_pieces'] : null,
        'resolution_note' => $row['resolution_note'] ?? null,
        'created_at' => (string)$row['created_at'],
        'created_at_label' => format_date($row['created_at'], 'M j, Y g:i A'),
        'resolved_at' => $row['resolved_at'] ?? null,
        'resolved_at_label' => !empty($row['resolved_at']) ? format_date($row['resolved_at'], 'M j, Y g:i A') : null,
    ];
}

/**
 * Submit a delivery issue from the customer portal.
 *
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function bakery_delivery_issue_submit(PDO $db, array $customer, int $dailyOrderId, array $input) {
    bakery_delivery_issues_ensure_schema($db);
    $customerId = (int)$customer['id'];
    $order = bakery_customer_delivery_assert_ownership($db, $customerId, $dailyOrderId);

    if (empty($order['delivery_confirmed_at'])) {
        throw new InvalidArgumentException(bakery_t('issue.error_not_delivered'));
    }

    $category = trim((string)($input['category'] ?? ''));
    $cats = bakery_delivery_issue_categories();
    if (!isset($cats[$category])) {
        throw new InvalidArgumentException(bakery_t('issue.error_invalid_category'));
    }

    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException(bakery_t('issue.error_description_required'));
    }
    if (strlen($description) > 2000) {
        throw new InvalidArgumentException(bakery_t('issue.error_description_too_long'));
    }

    $productId = isset($input['product_id']) ? (int)$input['product_id'] : null;
    $orderedQty = null;
    $driverQty = null;
    $reportedQty = isset($input['customer_reported_quantity']) && $input['customer_reported_quantity'] !== ''
        ? (int)$input['customer_reported_quantity']
        : null;

    if ($cats[$category]['needs_product'] || $productId > 0) {
        if ($productId <= 0) {
            throw new InvalidArgumentException(bakery_t('issue.error_product_required'));
        }
        $items = bakery_customer_delivery_items($db, $dailyOrderId);
        $matched = null;
        foreach ($items as $item) {
            if ((int)$item['product_id'] === $productId) {
                $matched = $item;
                break;
            }
        }
        if (!$matched) {
            throw new InvalidArgumentException(bakery_t('issue.error_product_not_on_delivery'));
        }
        $orderedQty = (int)$matched['quantity'];
        $driverQty = $matched['delivered_quantity'] !== null ? (int)$matched['delivered_quantity'] : null;
    }

    if ($reportedQty !== null && $reportedQty < 0) {
        throw new InvalidArgumentException(bakery_t('issue.error_invalid_quantity'));
    }

    $creditRequested = !empty($input['credit_requested']);
    $creditRecommendation = $creditRequested ? 'requested' : 'none';
    $creditPieces = null;
    if ($creditRequested && $reportedQty !== null && $driverQty !== null && $driverQty > $reportedQty) {
        $creditPieces = $driverQty - $reportedQty;
    }

    $stmt = $db->prepare(
        'INSERT INTO customer_delivery_issues
            (customer_id, daily_order_id, order_date, product_id, category,
             ordered_quantity, driver_delivered_quantity, customer_reported_quantity,
             description, status, credit_recommendation, credit_pieces)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $customerId,
        $dailyOrderId,
        (string)$order['order_date'],
        $productId > 0 ? $productId : null,
        $category,
        $orderedQty,
        $driverQty,
        $reportedQty,
        $description,
        'submitted',
        $creditRecommendation,
        $creditPieces,
    ]);
    $issueId = (int)$db->lastInsertId();

    $productName = '';
    if ($productId > 0) {
        $pStmt = $db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
        $pStmt->execute([$productId]);
        $productName = (string)($pStmt->fetchColumn() ?: '');
    }

    $summary = $customer['name'] . ' reported a delivery issue (' . bakery_delivery_issue_category_label($category) . ')';
    bakery_record_operational_event($db, BAKERY_OP_PORTAL_ISSUE_SUBMITTED, $summary, [
        'actor_role' => 'customer_portal',
        'customer_id' => $customerId,
        'daily_order_id' => $dailyOrderId,
        'operational_date' => (string)$order['order_date'],
        'metadata' => [
            'issue_id' => $issueId,
            'category' => $category,
            'product_id' => $productId > 0 ? $productId : null,
            'product_name' => $productName !== '' ? $productName : null,
            'ordered_quantity' => $orderedQty,
            'driver_delivered_quantity' => $driverQty,
            'customer_reported_quantity' => $reportedQty,
            'credit_recommendation' => $creditRecommendation,
            'credit_pieces' => $creditPieces,
        ],
    ]);

    if (function_exists('bakery_customer_notify_issue_submitted')) {
        bakery_customer_notify_issue_submitted($db, $customer, $issueId, (string)$order['order_date']);
    }

    $issue = bakery_delivery_issue_assert_ownership($db, $customerId, $issueId);
    return bakery_delivery_issue_format_row($issue);
}

/** Manager queue: open issues. */
function bakery_delivery_issues_manager_queue(PDO $db, array $filters = []) {
    bakery_delivery_issues_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_issues')) {
        return [];
    }

    $status = trim((string)($filters['status'] ?? 'open'));
    $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
    $customerId = max(0, (int)($filters['customer_id'] ?? 0));

    $where = ['1=1'];
    $params = [];

    if ($status === 'open') {
        $where[] = "ci.status IN ('submitted', 'under_review')";
    } elseif ($status !== 'all') {
        $where[] = 'ci.status = ?';
        $params[] = $status;
    }

    if ($customerId > 0) {
        $where[] = 'ci.customer_id = ?';
        $params[] = $customerId;
    }

    $sql = 'SELECT ci.*, c.name AS customer_name, p.name AS product_name,
                   do.delivery_confirmed_at, do.delivered_pieces, do.credits_taken_back
            FROM customer_delivery_issues ci
            JOIN customers c ON c.id = ci.customer_id
            JOIN daily_orders do ON do.id = ci.daily_order_id
            LEFT JOIN products p ON p.id = ci.product_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE ci.status WHEN \'submitted\' THEN 0 WHEN \'under_review\' THEN 1 ELSE 2 END,
                ci.created_at ASC
            LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $formatted = bakery_delivery_issue_format_row($row);
        $formatted['customer_name'] = (string)$row['customer_name'];
        $formatted['invoice_number'] = function_exists('bakery_billing_invoice_number')
            ? bakery_billing_invoice_number((int)$row['daily_order_id'], (string)$row['order_date'])
            : null;
        $formatted['delivery_url'] = BASE_URL . 'complete_delivery.php?order_id=' . (int)$row['daily_order_id'];
        $formatted['customer_url'] = BASE_URL . 'customer_record.php?customer_id=' . (int)$row['customer_id'];
        $formatted['invoice_url'] = BASE_URL . 'billing_center.php?panel=invoices&invoice_id=' . (int)$row['daily_order_id'];
        $formatted['timeline_url'] = BASE_URL . 'operational_timeline.php?daily_order_id=' . (int)$row['daily_order_id'];
        $results[] = $formatted;
    }
    return $results;
}

function bakery_delivery_issues_open_count(PDO $db) {
    bakery_delivery_issues_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_issues')) {
        return 0;
    }
    $stmt = $db->query(
        "SELECT COUNT(*) FROM customer_delivery_issues WHERE status IN ('submitted', 'under_review')"
    );
    return (int)$stmt->fetchColumn();
}

function bakery_delivery_issue_get_manager(PDO $db, int $issueId) {
    bakery_delivery_issues_ensure_schema($db);
    $stmt = $db->prepare(
        'SELECT ci.*, c.name AS customer_name, p.name AS product_name,
                do.delivery_confirmed_at, do.delivered_pieces, do.credits_taken_back,
                do.delivery_order_total
         FROM customer_delivery_issues ci
         JOIN customers c ON c.id = ci.customer_id
         JOIN daily_orders do ON do.id = ci.daily_order_id
         LEFT JOIN products p ON p.id = ci.product_id
         WHERE ci.id = ?
         LIMIT 1'
    );
    $stmt->execute([$issueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Issue not found');
    }
    $formatted = bakery_delivery_issue_format_row($row);
    $formatted['customer_name'] = (string)$row['customer_name'];
    $formatted['internal_note'] = $row['internal_note'] ?? null;
    $formatted['invoice_number'] = function_exists('bakery_billing_invoice_number')
        ? bakery_billing_invoice_number((int)$row['daily_order_id'], (string)$row['order_date'])
        : null;
    $formatted['delivery_confirmed_at'] = $row['delivery_confirmed_at'] ?? null;
    $formatted['delivery_order_total'] = $row['delivery_order_total'] ?? null;
    $formatted['delivery_url'] = BASE_URL . 'complete_delivery.php?order_id=' . (int)$row['daily_order_id'];
    $formatted['customer_url'] = BASE_URL . 'customer_record.php?customer_id=' . (int)$row['customer_id'];
    $formatted['invoice_url'] = BASE_URL . 'billing_center.php?panel=invoices&invoice_id=' . (int)$row['daily_order_id'];
    $formatted['timeline_url'] = BASE_URL . 'operational_timeline.php?daily_order_id=' . (int)$row['daily_order_id'];
    return $formatted;
}

/** Manager marks issue under review. */
function bakery_delivery_issue_start_review(PDO $db, int $issueId, ?int $userId = null) {
    bakery_delivery_issues_ensure_schema($db);
    $issue = bakery_delivery_issue_get_manager($db, $issueId);
    if ($issue['status'] !== 'submitted') {
        return $issue;
    }

    $stmt = $db->prepare(
        "UPDATE customer_delivery_issues
         SET status = 'under_review', assigned_to_user_id = COALESCE(?, assigned_to_user_id)
         WHERE id = ? AND status = 'submitted'"
    );
    $stmt->execute([$userId, $issueId]);

    bakery_record_operational_event($db, BAKERY_OP_PORTAL_ISSUE_REVIEW_STARTED,
        'Issue #' . $issueId . ' under review',
        [
            'customer_id' => (int)$issue['customer_id'],
            'daily_order_id' => (int)$issue['daily_order_id'],
            'operational_date' => (string)$issue['order_date'],
            'metadata' => ['issue_id' => $issueId],
        ]
    );

    return bakery_delivery_issue_get_manager($db, $issueId);
}

/**
 * Resolve a delivery issue. Does not modify delivery/invoice records.
 *
 * @param array<string, mixed> $input
 */
function bakery_delivery_issue_resolve(PDO $db, int $issueId, array $input, ?int $userId = null) {
    bakery_delivery_issues_ensure_schema($db);
    $issue = bakery_delivery_issue_get_manager($db, $issueId);

    $status = trim((string)($input['status'] ?? 'resolved'));
    if (!in_array($status, ['resolved', 'closed'], true)) {
        throw new InvalidArgumentException('Invalid resolution status');
    }

    $resolutionNote = trim((string)($input['resolution_note'] ?? ''));
    if ($resolutionNote === '') {
        throw new InvalidArgumentException(bakery_t('issue.error_resolution_required'));
    }

    $internalNote = trim((string)($input['internal_note'] ?? ''));
    $creditRec = trim((string)($input['credit_recommendation'] ?? $issue['credit_recommendation']));
    if (!in_array($creditRec, ['none', 'requested', 'recommended'], true)) {
        $creditRec = 'none';
    }
    $creditPieces = isset($input['credit_pieces']) && $input['credit_pieces'] !== ''
        ? max(0, (int)$input['credit_pieces'])
        : ($issue['credit_pieces'] ?? null);

    $stmt = $db->prepare(
        'UPDATE customer_delivery_issues
         SET status = ?, resolution_note = ?, internal_note = ?,
             credit_recommendation = ?, credit_pieces = ?,
             resolved_by_user_id = ?, resolved_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $status,
        $resolutionNote,
        $internalNote !== '' ? $internalNote : null,
        $creditRec,
        $creditPieces,
        $userId,
        $issueId,
    ]);

    bakery_record_operational_event($db, BAKERY_OP_PORTAL_ISSUE_RESOLVED,
        'Issue #' . $issueId . ' ' . $status,
        [
            'customer_id' => (int)$issue['customer_id'],
            'daily_order_id' => (int)$issue['daily_order_id'],
            'operational_date' => (string)$issue['order_date'],
            'metadata' => [
                'issue_id' => $issueId,
                'status' => $status,
                'credit_recommendation' => $creditRec,
                'credit_pieces' => $creditPieces,
                'billing_note' => $creditRec !== 'none'
                    ? 'Credit adjustment requires manual billing action — delivery record unchanged.'
                    : null,
            ],
        ]
    );

    if (function_exists('bakery_customer_notify_issue_resolved')) {
        $custStmt = $db->prepare('SELECT id, name FROM customers WHERE id = ? LIMIT 1');
        $custStmt->execute([(int)$issue['customer_id']]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            bakery_customer_notify_issue_resolved($db, $customer, $issueId, $resolutionNote, (string)$issue['order_date']);
        }
    }

    return bakery_delivery_issue_get_manager($db, $issueId);
}

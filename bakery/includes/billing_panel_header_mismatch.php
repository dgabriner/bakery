<?php
/**
 * Read-only manager list of invoices whose stored header differs from lines.
 * Read only. Historical invoice rows stay as booked.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$mismatchRows = bakery_billing_header_mismatch_rows($db);
?>
<style>
@media (max-width: 720px) {
    #header-mismatch-report .bc-table thead { display: none; }
    #header-mismatch-report .bc-table,
    #header-mismatch-report .bc-table tbody,
    #header-mismatch-report .bc-table tr,
    #header-mismatch-report .bc-table td { display: block; width: auto; }
    #header-mismatch-report .bc-table tr {
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 0;
    }
    #header-mismatch-report .bc-table td { padding: 2px 0; text-align: left; }
    #header-mismatch-report .bc-table td::before {
        content: attr(data-label);
        display: block;
        color: #64748b;
        font-size: .72rem;
        font-weight: 700;
    }
}
</style>
<section class="bc-panel" id="header-mismatch-report">
    <h2 style="margin:0 0 8px;font-size:1.1rem"><?php echo htmlspecialchars(bakery_t('billing.mismatch_title')); ?></h2>
    <p class="bc-note"><?php echo htmlspecialchars(bakery_t('billing.mismatch_note')); ?></p>
    <?php if ($mismatchRows === []): ?>
        <p><?php echo htmlspecialchars(bakery_t('billing.mismatch_empty')); ?></p>
    <?php else: ?>
        <table class="bc-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(bakery_t('billing.mismatch_invoice')); ?></th>
                    <th><?php echo htmlspecialchars(bakery_t('billing.mismatch_date')); ?></th>
                    <th><?php echo htmlspecialchars(bakery_t('billing.mismatch_customer')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.mismatch_stored')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.mismatch_lines')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.mismatch_diff')); ?></th>
                    <th><?php echo htmlspecialchars(bakery_t('billing.mismatch_reason')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mismatchRows as $row): ?>
                <?php
                $stored = (float)$row['stored_header_amount'];
                $lines = (float)$row['line_header_amount'];
                $diff = round($lines - $stored, 2);
                $reasonLabels = [];
                foreach ((array)($row['reasons'] ?? []) as $reasonCode) {
                    $reasonKey = 'billing.reason_' . (string)$reasonCode;
                    $reasonLabel = bakery_t($reasonKey);
                    $reasonLabels[] = $reasonLabel === $reasonKey ? (string)$reasonCode : $reasonLabel;
                }
                $reasonText = implode(', ', $reasonLabels);
                ?>
                <tr>
                    <td data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_invoice')); ?>">
                        <a href="billing_center.php?panel=invoices&amp;range=custom&amp;start_date=<?php echo urlencode((string)$row['order_date']); ?>&amp;end_date=<?php echo urlencode((string)$row['order_date']); ?>&amp;invoice_id=<?php echo (int)$row['id']; ?>&amp;queue=all&amp;collection=all">
                            <?php echo htmlspecialchars((string)$row['invoice_number']); ?>
                        </a>
                    </td>
                    <td data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_date')); ?>"><?php echo htmlspecialchars((string)$row['order_date']); ?></td>
                    <td data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_customer')); ?>"><?php echo htmlspecialchars((string)$row['customer_name']); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_stored')); ?>">$<?php echo number_format($stored, 2); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_lines')); ?>">$<?php echo number_format($lines, 2); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_diff')); ?>">$<?php echo number_format($diff, 2); ?></td>
                    <td data-label="<?php echo htmlspecialchars(bakery_t('billing.mismatch_reason')); ?>"><?php echo htmlspecialchars($reasonText); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

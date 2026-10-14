<?php
/**
 * Daily COD cash by driver. Expected versus collected.
 * Read only. Invoice and check accounts stay on the invoice list.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$codDate = trim((string)($_GET['cod_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $codDate)) {
    $codDate = date('Y-m-d');
}
$codRows = bakery_billing_cod_driver_day($db, $codDate);
$codExpected = 0.0;
$codCollected = 0.0;
foreach ($codRows as $codRow) {
    $codExpected += (float)$codRow['expected'];
    $codCollected += (float)$codRow['collected'];
}
$codExpected = round($codExpected, 2);
$codCollected = round($codCollected, 2);
?>
<style>
@media (max-width: 720px) {
    #cod-day-report .bc-table thead { display: none; }
    #cod-day-report .bc-table,
    #cod-day-report .bc-table tbody,
    #cod-day-report .bc-table tr,
    #cod-day-report .bc-table td { display: block; width: auto; }
    #cod-day-report .bc-table tr {
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 0;
    }
    #cod-day-report .bc-table td { padding: 2px 0; text-align: left; }
    #cod-day-report .bc-table td::before {
        content: attr(data-label);
        display: block;
        color: #64748b;
        font-size: .72rem;
        font-weight: 700;
    }
}
</style>
<section class="bc-panel" id="cod-day-report">
    <h2 style="margin:0 0 8px;font-size:1.1rem"><?php echo htmlspecialchars(bakery_t('billing.cod_day_title')); ?></h2>
    <p class="bc-note"><?php echo htmlspecialchars(bakery_t('billing.cod_day_note')); ?></p>
    <form class="bc-form" method="get">
        <input type="hidden" name="panel" value="cod">
        <label><?php echo htmlspecialchars(bakery_t('billing.cod_day_date')); ?>
            <input type="date" name="cod_date" value="<?php echo htmlspecialchars($codDate); ?>">
        </label>
        <button class="bc-btn bc-btn-primary" type="submit"><?php echo htmlspecialchars(bakery_t('billing.cod_day_show')); ?></button>
    </form>
    <div class="bc-grid">
        <div class="bc-metric"><strong>$<?php echo number_format($codExpected, 2); ?></strong><span><?php echo htmlspecialchars(bakery_t('billing.cod_expected')); ?></span></div>
        <div class="bc-metric"><strong>$<?php echo number_format($codCollected, 2); ?></strong><span><?php echo htmlspecialchars(bakery_t('billing.cod_collected')); ?></span></div>
        <div class="bc-metric"><strong>$<?php echo number_format(round($codExpected - $codCollected, 2), 2); ?></strong><span><?php echo htmlspecialchars(bakery_t('billing.cod_gap')); ?></span></div>
    </div>
    <?php if ($codRows === []): ?>
        <p><?php echo htmlspecialchars(bakery_t('billing.cod_day_empty')); ?></p>
    <?php else: ?>
        <table class="bc-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(bakery_t('billing.cod_driver')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.cod_stops')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.cod_expected')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.cod_collected')); ?></th>
                    <th class="num"><?php echo htmlspecialchars(bakery_t('billing.cod_gap')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($codRows as $row): ?>
                <?php $gap = round((float)$row['expected'] - (float)$row['collected'], 2); ?>
                <tr>
                    <td data-label="<?php echo htmlspecialchars(bakery_t('billing.cod_driver')); ?>"><?php echo htmlspecialchars((string)$row['driver_name']); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.cod_stops')); ?>"><?php echo (int)$row['stop_count']; ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.cod_expected')); ?>">$<?php echo number_format((float)$row['expected'], 2); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.cod_collected')); ?>">$<?php echo number_format((float)$row['collected'], 2); ?></td>
                    <td class="num" data-label="<?php echo htmlspecialchars(bakery_t('billing.cod_gap')); ?>">$<?php echo number_format($gap, 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

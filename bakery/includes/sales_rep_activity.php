<?php
/**
 * Manager and administrator list of what each sales rep logged
 * in the last 7 days. Sales Home does not include this file.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$repLate = (int)($repActivity['late_count'] ?? 0);
$repRows = (isset($repActivity['reps']) && is_array($repActivity['reps'])) ? $repActivity['reps'] : [];
$repKindKeys = [
    'visit' => 'leads.kind.visit',
    'call' => 'leads.kind.call',
    'stage' => 'leads.kind.stage',
    'note' => 'leads.kind.note',
];
?>
<section class="sales-rep-activity">
    <h2>
        <?php echo bakery_sales_leads_h(bakery_t('leads.rep_activity_title')); ?>
        <span class="sales-rep-activity__late" data-sales-a-overdue-count="<?php echo $repLate; ?>"><?php echo bakery_sales_leads_h(bakery_t('leads.rep_activity_late', ['count' => (string)$repLate])); ?></span>
    </h2>
    <?php if ($repRows === []): ?>
        <p class="sales-rep-activity__none"><?php echo bakery_sales_leads_h(bakery_t('leads.rep_activity_none')); ?></p>
    <?php else: ?>
        <ul class="sales-rep-activity__reps">
            <?php foreach ($repRows as $rep): ?>
                <?php
                $repCount = (int)($rep['count'] ?? 0);
                $repItems = (isset($rep['items']) && is_array($rep['items'])) ? $rep['items'] : [];
                ?>
                <li class="sales-rep-activity__rep<?php echo $repCount === 0 ? ' is-idle' : ''; ?>" data-rep-count="<?php echo $repCount; ?>">
                    <span class="sales-rep-activity__name"><?php echo bakery_sales_leads_h((string)($rep['name'] ?? '')); ?></span>
                    <span class="sales-rep-activity__count"><?php echo $repCount; ?></span>
                    <?php if ($repItems === []): ?>
                        <span class="sales-rep-activity__none"><?php echo bakery_sales_leads_h(bakery_t('leads.rep_activity_none')); ?></span>
                    <?php else: ?>
                        <ul class="sales-rep-activity__items">
                            <?php foreach ($repItems as $item): ?>
                                <?php
                                $kind = (string)($item['kind'] ?? 'note');
                                $labelKey = $repKindKeys[$kind] ?? 'leads.kind.note';
                                $leadKey = (string)($item['lead_key'] ?? '');
                                $href = BASE_URL . 'leads.php?lead=' . rawurlencode($leadKey);
                                ?>
                                <li>
                                    <span class="sales-kind"><?php echo bakery_sales_leads_h(bakery_t($labelKey)); ?></span>
                                    <span><?php echo bakery_sales_leads_h((string)($item['date'] ?? '')); ?></span>
                                    <a href="<?php echo bakery_sales_leads_h($href); ?>"><?php echo bakery_sales_leads_h((string)($item['lead_name'] ?? '')); ?></a>
                                    <span><?php echo bakery_sales_leads_h((string)($item['summary'] ?? '')); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

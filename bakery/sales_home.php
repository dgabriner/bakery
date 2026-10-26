<?php
/**
 * Sales Home. One phone-first screen for the sales role.
 * Administrators and managers may open it. Sales lands here.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sales_leads.php';

bakery_sales_leads_require_access();

$ready = isset($db) && $db instanceof PDO && bakery_sales_leads_ready($db);
$today = date('Y-m-d');
$query = trim((string)($_GET['q'] ?? ''));
$counts = [
    'New' => 0,
    'Researched' => 0,
    'Contacted' => 0,
    'Sampling' => 0,
    'Proposal' => 0,
    'Won' => 0,
];
$followups = ['overdue' => [], 'today' => []];
$search = ['leads' => [], 'customers' => []];
if ($ready) {
    $counts = bakery_sales_home_counts($db);
    $followups = bakery_sales_home_followups($db, $today);
    if ($query !== '') {
        $search = bakery_sales_home_search($db, $query);
    }
}

$stageLinks = [
    'New' => 'leads.php?view=leads&stage=New',
    'Researched' => 'leads.php?view=leads&stage=Researched',
    'Contacted' => 'leads.php?view=prospects&stage=Contacted',
    'Sampling' => 'leads.php?view=prospects&stage=Sampling',
    'Proposal' => 'leads.php?view=prospects&stage=Proposal',
    'Won' => 'leads.php?view=won',
];

$page_title = bakery_t('page.sales_home');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$leadPublicId = static function (array $row): string {
    $external = trim((string)($row['external_id'] ?? ''));
    return $external !== '' ? $external : (string)(int)($row['id'] ?? 0);
};
?>
<div class="sales-home" data-sales-home="1">
    <h1><?php echo bakery_sales_leads_h(bakery_t('page.sales_home')); ?></h1>
    <p class="sales-home__intro"><?php echo bakery_sales_leads_h(bakery_t('sales_home.intro')); ?></p>

    <?php if (!$ready): ?>
        <p class="sales-home__empty" role="alert"><?php echo bakery_sales_leads_h(bakery_t('leads.schema_missing')); ?></p>
    <?php else: ?>
        <form class="sales-home__search" method="get" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>sales_home.php">
            <label for="sales-home-q"><?php echo bakery_sales_leads_h(bakery_t('sales_home.search')); ?></label>
            <input id="sales-home-q" type="search" name="q" value="<?php echo bakery_sales_leads_h($query); ?>" autocomplete="off" enterkeyhint="search">
            <button type="submit"><?php echo bakery_sales_leads_h(bakery_t('sales_home.search_button')); ?></button>
        </form>

        <?php if ($query !== ''): ?>
            <section class="sales-home__results">
                <h2><?php echo bakery_sales_leads_h(bakery_t('sales_home.lead_results')); ?></h2>
                <?php if ($search['leads'] === []): ?>
                    <p class="sales-home__empty"><?php echo bakery_sales_leads_h(bakery_t('sales_home.empty')); ?></p>
                <?php else: ?>
                    <ul class="sales-home__hits">
                        <?php foreach ($search['leads'] as $hit): ?>
                            <?php $publicId = $leadPublicId($hit); ?>
                            <li>
                                <a href="<?php echo bakery_sales_leads_h(BASE_URL . 'leads.php?lead=' . rawurlencode($publicId)); ?>">
                                    <?php echo bakery_sales_leads_h((string)$hit['customer_name']); ?>
                                    <span><?php echo bakery_sales_leads_h((string)($hit['stage'] ?? '')); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <h2><?php echo bakery_sales_leads_h(bakery_t('sales_home.customer_results')); ?></h2>
                <?php if ($search['customers'] === []): ?>
                    <p class="sales-home__empty"><?php echo bakery_sales_leads_h(bakery_t('sales_home.empty')); ?></p>
                <?php else: ?>
                    <ul class="sales-home__hits">
                        <?php foreach ($search['customers'] as $hit): ?>
                            <li>
                                <a href="<?php echo bakery_sales_leads_h(BASE_URL . 'customer_record.php?customer_id=' . (int)$hit['id']); ?>">
                                    <?php echo bakery_sales_leads_h((string)$hit['name']); ?>
                                    <span><?php echo bakery_sales_leads_h(trim((string)($hit['zone'] ?? ''))); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <h2><?php echo bakery_sales_leads_h(bakery_t('sales_home.counts')); ?></h2>
        <div class="sales-home__counts">
            <?php foreach ($stageLinks as $stage => $href): ?>
                <a class="sales-home__count" href="<?php echo bakery_sales_leads_h(BASE_URL . $href); ?>">
                    <span class="sales-home__count-n"><?php echo (int)$counts[$stage]; ?></span>
                    <span class="sales-home__count-l"><?php echo bakery_sales_leads_h(bakery_t('leads.stage.' . $stage)); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php foreach (['overdue' => 'sales_home.overdue', 'today' => 'sales_home.today'] as $bucket => $labelKey): ?>
            <section class="sales-home__followups">
                <h2>
                    <?php echo bakery_sales_leads_h(bakery_t($labelKey)); ?>
                    <span><?php echo count($followups[$bucket]); ?></span>
                </h2>
                <?php if ($followups[$bucket] === []): ?>
                    <p class="sales-home__empty"><?php echo bakery_sales_leads_h(bakery_t('sales_home.no_followups')); ?></p>
                <?php else: ?>
                    <ul class="sales-home__hits">
                        <?php foreach ($followups[$bucket] as $hit): ?>
                            <?php $publicId = $leadPublicId($hit); ?>
                            <li>
                                <a href="<?php echo bakery_sales_leads_h(BASE_URL . 'leads.php?lead=' . rawurlencode($publicId) . '&view=followups'); ?>">
                                    <?php echo bakery_sales_leads_h((string)$hit['customer_name']); ?>
                                    <span><?php echo bakery_sales_leads_h(trim((string)($hit['next_action_date'] ?? '') . ' ' . (string)($hit['next_action'] ?? ''))); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <p class="sales-home__more"><a href="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?view=followups"><?php echo bakery_sales_leads_h(bakery_t('leads.view_followups')); ?></a></p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Sales Home. Add a lead, search, and the pipeline.
 * Administrators and managers may open it. Sales lands here.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sales_leads.php';

bakery_sales_leads_require_access();

$ready = isset($db) && $db instanceof PDO && bakery_sales_leads_ready($db);
$salesToday = date('Y-m-d');
$queryText = trim((string)($_GET['q'] ?? ''));
$pipelineCounts = array_fill_keys(bakery_sales_leads_stages(), 0);
$pipelineRows = [];
$searchHits = ['leads' => [], 'customers' => []];
if ($ready) {
    $pipelineCounts = bakery_sales_home_counts($db);
    $pipelineRows = bakery_sales_pipeline_list($db, ['due' => '1'], $salesToday);
    if ($queryText !== '') {
        $searchHits = bakery_sales_home_search($db, $queryText);
    }
}
$activeStage = '';
$activeDue = true;
$activeFocus = '';
$activeZone = '';
$zones = [];
$salesPublicId = static function (array $row): string {
    $external = trim((string)($row['external_id'] ?? ''));
    return $external !== '' ? $external : (string)(int)($row['id'] ?? 0);
};
$salesText = static function (string $value): string {
    return bakery_sales_leads_h($value);
};

$page_title = bakery_t('page.sales_home');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<div class="sales-home" data-sales-home="1">
    <h1><?php echo $salesText(bakery_t('page.sales_home')); ?></h1>
    <p class="sales-home__intro"><?php echo $salesText(bakery_t('sales_home.intro')); ?></p>
    <?php if (!$ready): ?>
        <p class="sales-home__empty" role="alert"><?php echo $salesText(bakery_t('leads.schema_missing')); ?></p>
    <?php else: ?>
        <a class="sales-home__add" data-sales-add="1" href="<?php echo $salesText(BASE_URL . 'leads.php?lead=new'); ?>"><?php echo $salesText(bakery_t('sales_home.add')); ?></a>
        <form class="sales-home__search" method="get" action="<?php echo $salesText(BASE_URL); ?>sales_home.php">
            <label for="sales-home-q"><?php echo $salesText(bakery_t('sales_home.search')); ?></label>
            <input id="sales-home-q" type="search" name="q" value="<?php echo $salesText($queryText); ?>" autocomplete="off" enterkeyhint="search">
            <button type="submit"><?php echo $salesText(bakery_t('sales_home.search_button')); ?></button>
        </form>
        <?php if ($queryText !== ''): ?>
            <section class="sales-home__results">
                <h2><?php echo $salesText(bakery_t('sales_home.lead_results')); ?></h2>
                <?php if ($searchHits['leads'] === []): ?>
                    <p class="sales-home__empty"><?php echo $salesText(bakery_t('sales_home.empty')); ?></p>
                <?php else: ?>
                    <ul class="sales-home__hits">
                        <?php foreach ($searchHits['leads'] as $hit): ?>
                            <li>
                                <a href="<?php echo $salesText(BASE_URL . 'leads.php?lead=' . rawurlencode($salesPublicId($hit))); ?>">
                                    <?php echo $salesText((string)$hit['customer_name']); ?>
                                    <span><?php echo $salesText(bakery_t('leads.stage.' . (string)($hit['stage'] ?? ''))); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <h2><?php echo $salesText(bakery_t('sales_home.customer_results')); ?></h2>
                <?php if ($searchHits['customers'] === []): ?>
                    <p class="sales-home__empty"><?php echo $salesText(bakery_t('sales_home.empty')); ?></p>
                <?php else: ?>
                    <ul class="sales-home__hits">
                        <?php foreach ($searchHits['customers'] as $hit): ?>
                            <li>
                                <a href="<?php echo $salesText(BASE_URL . 'customer_record.php?customer_id=' . (int)$hit['id']); ?>">
                                    <?php echo $salesText((string)$hit['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <section data-sales-pipeline="1">
            <h2><?php echo $salesText(bakery_t('sales_home.pipeline')); ?></h2>
            <div class="sales-pipe__tabs">
                <?php foreach (bakery_sales_leads_stages() as $stage): ?>
                    <a href="<?php echo $salesText(BASE_URL . 'leads.php?view=pipeline&stage=' . rawurlencode($stage)); ?>">
                        <?php echo $salesText(bakery_t('leads.stage.' . $stage)); ?>
                        <span><?php echo (int)($pipelineCounts[$stage] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <h2><?php echo $salesText(bakery_t('sales_home.needs_action')); ?></h2>
            <?php if ($pipelineRows === []): ?>
                <p class="sales-home__empty"><?php echo $salesText(bakery_t('sales_home.no_followups')); ?></p>
            <?php else: ?>
                <ul class="sales-pipe__list">
                    <?php foreach ($pipelineRows as $item): ?>
                        <?php
                        $href = BASE_URL . 'leads.php?lead=' . rawurlencode($salesPublicId($item));
                        $stageName = (string)($item['stage'] ?? '');
                        $when = substr((string)($item['next_action_date'] ?? ''), 0, 10);
                        ?>
                        <li class="sales-pipe__row is-due">
                            <a href="<?php echo $salesText($href); ?>">
                                <strong><?php echo $salesText((string)($item['customer_name'] ?? '')); ?></strong>
                                <span><?php echo $salesText($stageName !== '' ? bakery_t('leads.stage.' . $stageName) : ''); ?></span>
                                <span><?php echo $salesText($when); ?></span>
                                <span><?php echo $salesText((string)($item['last_note'] ?? '')); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="sales-home__more"><a href="<?php echo $salesText(BASE_URL); ?>leads.php?view=pipeline"><?php echo $salesText(bakery_t('pipeline.all')); ?></a></p>
        </section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

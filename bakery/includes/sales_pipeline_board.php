<?php
/**
 * Stage counts and pipeline rows for the sales workspace.
 * Expects $pipelineRows, $pipelineCounts, $salesToday, $salesPublicId, $salesText.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$activeStage = (string)($activeStage ?? '');
$activeDue = !empty($activeDue);
$activeFocus = (string)($activeFocus ?? '');
$activeZone = (string)($activeZone ?? '');
$queryText = trim((string)($queryText ?? ''));
$zones = isset($zones) && is_array($zones) ? $zones : [];
$searchHits = ($queryText !== '' && isset($db) && $db instanceof PDO) ? bakery_sales_home_search($db, $queryText) : ['leads' => [], 'customers' => []];
$boardAction = BASE_URL . 'leads.php';
?>
<div data-sales-pipeline="1">
    <a class="sales-home__add" data-sales-add="1" href="<?php echo $salesText(BASE_URL . 'leads.php?lead=new'); ?>"><?php echo $salesText(bakery_t('sales_home.add')); ?></a>
    <form class="sales-home__search" method="get" action="<?php echo $salesText($boardAction); ?>">
        <input type="hidden" name="view" value="pipeline">
        <label for="sales-pipeline-q"><?php echo $salesText(bakery_t('sales_home.search')); ?></label>
        <input id="sales-pipeline-q" type="search" name="q" value="<?php echo $salesText($queryText); ?>" autocomplete="off" enterkeyhint="search">
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

    <h2><?php echo $salesText(bakery_t('sales_home.pipeline')); ?></h2>
    <div class="sales-pipe__tabs" role="tablist">
        <?php
        $allHref = 'leads.php?view=pipeline';
        $dueHref = 'leads.php?view=pipeline&due=1';
        ?>
        <a class="<?php echo $activeStage === '' && !$activeDue && $activeFocus === '' ? 'is-active' : ''; ?>" href="<?php echo $salesText(BASE_URL . $allHref); ?>"><?php echo $salesText(bakery_t('pipeline.all')); ?></a>
        <a class="<?php echo $activeDue ? 'is-active' : ''; ?>" href="<?php echo $salesText(BASE_URL . $dueHref); ?>"><?php echo $salesText(bakery_t('pipeline.due')); ?></a>
        <?php foreach (bakery_sales_leads_stages() as $stage): ?>
            <a class="<?php echo $activeStage === $stage ? 'is-active' : ''; ?>" href="<?php echo $salesText(BASE_URL . 'leads.php?view=pipeline&stage=' . rawurlencode($stage)); ?>">
                <?php echo $salesText(bakery_t('leads.stage.' . $stage)); ?>
                <span><?php echo (int)($pipelineCounts[$stage] ?? 0); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($activeFocus === 'leads' || $activeFocus === 'prospects'): ?>
        <p class="sales-home__meta"><?php echo $salesText(bakery_t($activeFocus === 'leads' ? 'leads.view_leads_help' : 'leads.view_prospects_help')); ?></p>
    <?php endif; ?>
    <?php if ($zones !== []): ?>
        <form class="sales-home__search" method="get" action="<?php echo $salesText($boardAction); ?>">
            <input type="hidden" name="view" value="pipeline">
            <?php if ($activeStage !== ''): ?>
                <input type="hidden" name="stage" value="<?php echo $salesText($activeStage); ?>">
            <?php endif; ?>
            <?php if ($activeDue): ?>
                <input type="hidden" name="due" value="1">
            <?php endif; ?>
            <?php if ($activeFocus !== ''): ?>
                <input type="hidden" name="focus" value="<?php echo $salesText($activeFocus); ?>">
            <?php endif; ?>
            <label for="sales-pipeline-zone"><?php echo $salesText(bakery_t('pipeline.zone')); ?>
                <select id="sales-pipeline-zone" name="zone">
                    <option value=""><?php echo $salesText(bakery_t('leads.zone_any')); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo $activeZone === (string)$zone['id'] ? 'selected' : ''; ?>><?php echo $salesText((string)$zone['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit"><?php echo $salesText(bakery_t('leads.filter_apply')); ?></button>
        </form>
    <?php endif; ?>

    <?php if ($pipelineRows === []): ?>
        <p class="sales-home__empty"><?php echo $salesText(bakery_t('leads.empty')); ?></p>
    <?php else: ?>
        <ul class="sales-pipe__list">
            <?php foreach ($pipelineRows as $item): ?>
                <?php
                $href = BASE_URL . 'leads.php?lead=' . rawurlencode($salesPublicId($item));
                $stageName = (string)($item['stage'] ?? '');
                $stageLabel = $stageName !== '' ? bakery_t('leads.stage.' . $stageName) : '';
                $when = substr((string)($item['next_action_date'] ?? ''), 0, 10);
                $bucket = bakery_sales_leads_followup_bucket($item, $salesToday);
                ?>
                <li class="sales-pipe__row<?php echo $bucket === 'overdue' || $bucket === 'today' ? ' is-due' : ''; ?>">
                    <a href="<?php echo $salesText($href); ?>">
                        <strong><?php echo $salesText((string)($item['customer_name'] ?? '')); ?></strong>
                        <span><?php echo $salesText($stageLabel); ?></span>
                        <span><?php echo $salesText($when); ?></span>
                        <span><?php echo $salesText((string)($item['last_note'] ?? '')); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

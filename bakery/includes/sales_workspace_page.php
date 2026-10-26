<?php
/**
 * Sales-only lead workspace. Managers and administrators stay on leads.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$salesToday = date('Y-m-d');
$salesPublicId = static function (array $row): string {
    $external = trim((string)($row['external_id'] ?? ''));
    return $external !== '' ? $external : (string)(int)($row['id'] ?? 0);
};
$salesText = static function (string $value): string {
    return bakery_sales_leads_h($value);
};
?>
<div class="sales-home" data-sales-workspace="1">
<?php if (!$ready): ?>
    <p class="sales-home__empty" role="alert"><?php echo $salesText(bakery_t('leads.schema_missing')); ?></p>
<?php elseif ($editing): ?>
    <?php
    $detail = is_array($lead) ? $lead : [];
    $phoneValue = trim((string)($detail['phone'] ?? ''));
    if ($phoneValue === '') {
        $parsedPhone = bakery_sales_leads_parse_phone((string)($detail['contact_channel'] ?? ''));
        $phoneValue = $parsedPhone !== null ? $parsedPhone : '';
    }
    $emailValue = trim((string)($detail['email'] ?? ''));
    if ($emailValue === '') {
        $parsedEmail = bakery_sales_leads_parse_email((string)($detail['contact_channel'] ?? ''));
        $emailValue = $parsedEmail !== null ? $parsedEmail : '';
    }
    $detailName = (string)($detail['customer_name'] ?? '');
    $detailAddress = trim((string)($detail['address'] ?? ''));
    $detailNeighborhood = trim((string)($detail['neighborhood'] ?? ''));
    $detailZone = (string)($detail['zone_id'] ?? '');
    $detailStage = (string)($detail['stage'] ?? 'New');
    if (!in_array($detailStage, bakery_sales_leads_stages(), true)) {
        $detailStage = 'New';
    }
    $detailNext = trim((string)($detail['next_action'] ?? ''));
    $detailNextDate = substr((string)($detail['next_action_date'] ?? ''), 0, 10);
    $detailNotes = (string)($detail['notes'] ?? '');
    $detailCuisine = trim((string)($detail['cuisine'] ?? ''));
    $detailWebsite = trim((string)($detail['website'] ?? ''));
    $backHref = BASE_URL . 'leads.php?view=pipeline';
    ?>
    <div data-sales-detail="1">
        <p><a class="sales-home__back" href="<?php echo $salesText($backHref); ?>"><?php echo $salesText(bakery_t('leads.back')); ?></a></p>
        <h1><?php echo $salesText($detailName !== '' ? $detailName : bakery_t('leads.new_title')); ?></h1>
        <?php if ($notice !== ''): ?>
            <p class="sales-home__banner" role="status"><?php echo $salesText($notice); ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="sales-home__empty" role="alert"><?php echo $salesText($error); ?></p>
        <?php endif; ?>
        <?php if ($duplicateOthers !== []): ?>
            <p class="sales-leads-duplicate" role="status"><?php echo $salesText(bakery_t('leads.duplicate_flag')); ?></p>
        <?php endif; ?>
        <a class="sales-home__add" data-sales-add="1" href="<?php echo $salesText(BASE_URL . 'leads.php?lead=new'); ?>"><?php echo $salesText(bakery_t('sales_home.add')); ?></a>
        <form class="sales-home__form" method="post" action="<?php echo $salesText(BASE_URL . 'leads.php?lead=' . rawurlencode($leadKey !== '' ? $leadKey : 'new')); ?>">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="internal_id" value="<?php echo (int)($detail['id'] ?? 0); ?>">
            <label><?php echo $salesText(bakery_t('leads.field_name')); ?>
                <input type="text" name="name" required maxlength="255" value="<?php echo $salesText($detailName); ?>">
            </label>
            <label><?php echo $salesText(bakery_t('sales_detail.phone')); ?>
                <input type="tel" name="phone" value="<?php echo $salesText($phoneValue); ?>" autocomplete="tel">
            </label>
            <label><?php echo $salesText(bakery_t('sales_detail.email')); ?>
                <input type="email" name="email" value="<?php echo $salesText($emailValue); ?>" autocomplete="email">
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_address')); ?>
                <input type="text" name="address" value="<?php echo $salesText($detailAddress); ?>">
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_neighborhood')); ?>
                <input type="text" name="neighborhood" maxlength="120" value="<?php echo $salesText($detailNeighborhood); ?>">
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_zone')); ?>
                <select name="zone_id">
                    <option value=""><?php echo $salesText(bakery_t('leads.zone_none')); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo (string)$detailZone === (string)$zone['id'] ? 'selected' : ''; ?>><?php echo $salesText((string)$zone['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_stage')); ?>
                <select name="stage">
                    <?php foreach (bakery_sales_leads_stages() as $stage): ?>
                        <option value="<?php echo $salesText($stage); ?>" <?php echo $detailStage === $stage ? 'selected' : ''; ?>><?php echo $salesText(bakery_t('leads.stage.' . $stage)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_next_action')); ?>
                <input type="text" name="next_action" maxlength="255" value="<?php echo $salesText($detailNext); ?>">
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_next_action_date')); ?>
                <input type="date" name="next_action_date" value="<?php echo $salesText($detailNextDate); ?>">
            </label>
            <label><?php echo $salesText(bakery_t('leads.field_notes')); ?>
                <textarea name="notes" rows="4"><?php echo $salesText($detailNotes); ?></textarea>
            </label>
            <?php if ($detailCuisine !== ''): ?>
                <p class="sales-home__meta"><?php echo $salesText(bakery_t('leads.field_cuisine')); ?>: <?php echo $salesText($detailCuisine); ?></p>
            <?php endif; ?>
            <?php if ($detailWebsite !== ''): ?>
                <p class="sales-home__meta"><?php echo $salesText(bakery_t('leads.field_website')); ?>: <?php echo $salesText($detailWebsite); ?></p>
            <?php endif; ?>
            <button type="submit"><?php echo $salesText(bakery_t('sales_detail.save')); ?></button>
        </form>
        <?php if (is_array($lead)): ?>
            <?php $activity = bakery_sales_leads_activity($db, (int)$lead['id']); ?>
            <?php if ($activity !== []): ?>
                <h2><?php echo $salesText(bakery_t('leads.activity')); ?></h2>
                <ul class="sales-home__hits">
                    <?php foreach ($activity as $entry): ?>
                        <?php $noteText = trim((string)($entry['note'] ?? '')); ?>
                        <?php if ($noteText === '' && trim((string)($entry['stage_to'] ?? '')) === ''): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <li>
                            <span><?php echo $salesText((string)($entry['activity_date'] ?? '')); ?></span>
                            <?php echo $salesText($noteText !== '' ? $noteText : (string)($entry['stage_to'] ?? '')); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php $contacts = bakery_sales_leads_contacts($db, (int)$lead['id']); ?>
            <?php if ($contacts !== []): ?>
                <h2><?php echo $salesText(bakery_t('leads.earlier_contacts')); ?></h2>
                <ul class="sales-home__hits">
                    <?php foreach ($contacts as $contact): ?>
                        <li>
                            <span><?php echo $salesText((string)($contact['contact_date'] ?? '')); ?></span>
                            <?php echo $salesText(trim((string)($contact['contact_mode'] ?? '') . ' ' . (string)($contact['comment'] ?? ''))); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form class="sales-home__form" method="post" action="<?php echo $salesText(BASE_URL . 'leads.php?lead=' . rawurlencode($leadKey)); ?>">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="internal_id" value="<?php echo (int)$lead['id']; ?>">
                <label><?php echo $salesText(bakery_t('leads.activity_note')); ?>
                    <textarea name="note" rows="3" required></textarea>
                </label>
                <input type="hidden" name="activity_date" value="<?php echo $salesText($salesToday); ?>">
                <button type="submit"><?php echo $salesText(bakery_t('leads.activity_add')); ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
    $pipelineFilters = bakery_sales_pipeline_query_filters($_GET);
    $pipelineRows = bakery_sales_pipeline_list($db, $pipelineFilters, $salesToday);
    $pipelineCounts = bakery_sales_home_counts($db);
    $activeStage = (string)($pipelineFilters['stage'] ?? '');
    $activeDue = (string)($_GET['due'] ?? '') === '1';
    $activeFocus = (string)($_GET['focus'] ?? '');
    $activeZone = (string)($pipelineFilters['zone'] ?? '');
    $queryText = trim((string)($_GET['q'] ?? ''));
    require __DIR__ . '/sales_pipeline_board.php';
    ?>
<?php endif; ?>
</div>

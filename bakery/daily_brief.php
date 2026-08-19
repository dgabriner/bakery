<?php
/**
 * Daily Bakery Brief — one-page operational shift handoff.
 *
 * Manager/administrator only (default auth gate). Deterministic from live data.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/daily_brief.php';

$user = bakery_current_user();
$role = $user ? (string)($user['role_slug'] ?? 'manager') : 'manager';

$today = date('Y-m-d');
$selectedDate = bakery_dashboard_resolve_date();
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$autoPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';

$page_title = bakery_t('page.daily_brief');
$briefError = null;
$brief = null;

try {
    $brief = bakery_daily_brief_build($db, $selectedDate, ['role' => $role]);
} catch (Throwable $e) {
    error_log('daily brief page: ' . $e->getMessage());
    $briefError = bakery_dashboard_safe_error_message($e);
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

$formatScale = static function ($value): string {
    if ($value === null) {
        return '—';
    }
    return number_format((int)$value);
};

$relationLabel = static function (string $relation): string {
    switch ($relation) {
        case 'today':
            return bakery_t('common.today');
        case 'tomorrow':
            return bakery_t('brief.tomorrow');
        case 'past':
            return bakery_t('brief.past_date');
        case 'future':
            return bakery_t('brief.future_date');
        default:
            return bakery_t('common.selected_day');
    }
};
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('css/daily_brief.css'); ?>">

<div class="dbrief-page" id="dailyBriefPage">
    <?php if ($briefError && !$brief): ?>
        <div class="dbrief-alert dbrief-alert--danger" role="alert">
            <?php echo htmlspecialchars($briefError); ?>
        </div>
    <?php elseif ($brief): ?>
        <header class="dbrief-header no-print">
            <div class="dbrief-header__main">
                <p class="dbrief-eyebrow"><?php bakery_te('brief.eyebrow'); ?></p>
                <h1><?php bakery_te('brief.title'); ?></h1>
                <p class="dbrief-subtitle"><?php bakery_te('brief.subtitle'); ?></p>
            </div>
            <div class="dbrief-header__actions">
                <button type="button" class="dbrief-btn dbrief-btn--primary" onclick="window.print()">
                    <?php bakery_te('brief.print'); ?>
                </button>
                <a class="dbrief-btn" href="<?php echo htmlspecialchars($brief['links']['daily_orders']); ?>">
                    <?php bakery_te('nav.item.daily_orders'); ?>
                </a>
                <a class="dbrief-btn" href="<?php echo htmlspecialchars($brief['links']['daily_run'] ?? BASE_URL . 'daily_run.php?date=' . urlencode($selectedDate)); ?>">
                    <?php bakery_te('brief.view_daily_run'); ?>
                </a>
                <a class="dbrief-btn" href="<?php echo htmlspecialchars(BASE_URL); ?>index.php?date=<?php echo urlencode($selectedDate); ?>">
                    <?php bakery_te('brief.view_dashboard'); ?>
                </a>
            </div>
        </header>

        <nav class="dbrief-date-nav no-print" aria-label="<?php bakery_te('dashboard.date_nav_aria'); ?>">
            <a href="?date=<?php echo urlencode($prevDate); ?>"><?php bakery_te('common.prev'); ?></a>
            <?php if ($selectedDate !== $today): ?>
                <a href="?date=<?php echo urlencode($today); ?>"><?php bakery_te('common.today'); ?></a>
            <?php endif; ?>
            <a href="?date=<?php echo urlencode($nextDate); ?>"><?php bakery_te('common.next'); ?></a>
            <form class="dbrief-date-jump" method="get" action="">
                <label class="dbrief-sr-only" for="dbrief-date-input"><?php bakery_te('common.operating_date'); ?></label>
                <input id="dbrief-date-input" type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <button type="submit"><?php bakery_te('common.go'); ?></button>
            </form>
        </nav>

        <article class="dbrief-document" aria-label="<?php bakery_te('brief.document_aria'); ?>">
            <header class="dbrief-doc-header">
                <div class="dbrief-doc-header__row">
                    <div>
                        <h2 class="dbrief-doc-title"><?php bakery_te('brief.doc_title'); ?></h2>
                        <p class="dbrief-doc-date"><?php echo htmlspecialchars($brief['date_display']); ?></p>
                    </div>
                    <div class="dbrief-doc-meta">
                        <span class="dbrief-meta-item">
                            <span class="dbrief-meta-label"><?php bakery_te('brief.generated'); ?></span>
                            <?php echo htmlspecialchars($brief['current_time']); ?>
                        </span>
                        <span class="dbrief-meta-item">
                            <span class="dbrief-pill dbrief-pill--<?php echo htmlspecialchars($brief['date_relation']); ?>">
                                <?php echo htmlspecialchars($relationLabel($brief['date_relation'])); ?>
                            </span>
                        </span>
                        <span class="dbrief-meta-item">
                            <span class="dbrief-meta-label"><?php bakery_te('brief.mode'); ?></span>
                            <?php echo htmlspecialchars($brief['mode_label']); ?>
                        </span>
                        <span class="dbrief-meta-item">
                            <span class="dbrief-meta-label"><?php bakery_te('brief.run_status'); ?></span>
                            <span class="dbrief-run dbrief-run--<?php echo htmlspecialchars($brief['run_status']['tone']); ?>">
                                <?php echo htmlspecialchars($brief['run_status']['label']); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </header>

            <?php if (!empty($brief['demand_error'])): ?>
                <div class="dbrief-alert dbrief-alert--danger" role="alert">
                    <?php echo htmlspecialchars($brief['demand_error']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($brief['section_errors'])): ?>
                <div class="dbrief-alert dbrief-alert--warning" role="alert">
                    <?php bakery_te('daily_run.partial_data'); ?>
                </div>
            <?php endif; ?>

            <?php if ($brief['is_normal_day']): ?>
                <p class="dbrief-normal-note"><?php bakery_te('brief.normal_day'); ?></p>
            <?php endif; ?>

            <section class="dbrief-section dbrief-scale">
                <h3><?php bakery_te('brief.scale_heading'); ?></h3>
                <dl class="dbrief-scale-grid">
                    <div>
                        <dt><?php bakery_te('brief.scale_customers'); ?></dt>
                        <dd><?php echo $formatScale($brief['scale']['customer_deliveries']); ?></dd>
                    </div>
                    <div>
                        <dt><?php bakery_te('brief.scale_units'); ?></dt>
                        <dd><?php echo $formatScale($brief['scale']['committed_units']); ?></dd>
                    </div>
                    <div>
                        <dt><?php bakery_te('brief.scale_products'); ?></dt>
                        <dd><?php echo $formatScale($brief['scale']['products']); ?></dd>
                    </div>
                    <div>
                        <dt><?php bakery_te('brief.scale_drivers'); ?></dt>
                        <dd><?php echo $formatScale($brief['scale']['drivers']); ?></dd>
                    </div>
                    <?php if ($brief['mode'] === 'handoff' && $brief['scale']['delivered_stops'] !== null): ?>
                    <div>
                        <dt><?php bakery_te('brief.scale_delivered'); ?></dt>
                        <dd><?php echo $formatScale($brief['scale']['delivered_stops']); ?></dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if ($brief['important_changes'] !== [] || $brief['exceptions'] !== []): ?>
            <section class="dbrief-section dbrief-changes">
                <h3><?php bakery_te('brief.changes_heading'); ?></h3>
                <?php if ($brief['important_changes'] === [] && $brief['exceptions'] === []): ?>
                    <p class="dbrief-empty"><?php bakery_te('brief.no_changes'); ?></p>
                <?php else: ?>
                    <ul class="dbrief-change-list">
                        <?php foreach ($brief['important_changes'] as $change): ?>
                            <li class="dbrief-change dbrief-change--<?php echo htmlspecialchars($change['severity']); ?>">
                                <strong><?php echo htmlspecialchars($change['title']); ?></strong>
                                <span><?php echo htmlspecialchars($change['detail']); ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php
                        $shownExceptionTitles = array_column($brief['important_changes'], 'title');
                        foreach ($brief['exceptions'] as $ex):
                            if (in_array($ex['title'], $shownExceptionTitles, true)) {
                                continue;
                            }
                            if (($ex['severity'] ?? '') === 'info') {
                                continue;
                            }
                        ?>
                            <li class="dbrief-change dbrief-change--<?php echo htmlspecialchars($ex['severity']); ?>">
                                <strong><?php echo htmlspecialchars($ex['title']); ?></strong>
                                <span><?php echo htmlspecialchars($ex['detail']); ?></span>
                                <?php if (!empty($ex['href'])): ?>
                                    <a class="dbrief-resolve-link" href="<?php echo htmlspecialchars($ex['href']); ?>">
                                        <?php echo htmlspecialchars($ex['action'] ?? 'Resolve'); ?> →
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="dbrief-section dbrief-production">
                <div class="dbrief-section-head">
                    <h3><?php bakery_te('brief.production_heading'); ?></h3>
                    <a class="dbrief-section-link no-print" href="<?php echo htmlspecialchars($brief['production']['href_production']); ?>">
                        <?php bakery_te('brief.open_production'); ?> →
                    </a>
                </div>
                <p class="dbrief-lead"><?php echo htmlspecialchars($brief['production']['summary']); ?>
                    <span class="dbrief-muted">(<?php echo htmlspecialchars($brief['production']['source']); ?>)</span>
                </p>
                <?php if (!empty($brief['production']['shortages'])): ?>
                    <ul class="dbrief-bullets dbrief-bullets--warn">
                        <?php foreach (array_slice($brief['production']['shortages'], 0, 6) as $short): ?>
                            <li><?php echo htmlspecialchars($short); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($brief['production']['highlights'])): ?>
                    <table class="dbrief-table">
                        <thead>
                            <tr>
                                <th><?php bakery_te('brief.col_product'); ?></th>
                                <th class="dbrief-num"><?php bakery_te('brief.col_required'); ?></th>
                                <?php if (!empty($brief['inventory_ready'])): ?>
                                <th class="dbrief-num"><?php bakery_te('brief.col_produced'); ?></th>
                                <th class="dbrief-num"><?php bakery_te('brief.col_remaining'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brief['production']['highlights'] as $row): ?>
                                <tr<?php echo !empty($row['short']) ? ' class="dbrief-row--warn"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                    <td class="dbrief-num"><?php echo number_format((int)$row['required']); ?></td>
                                    <?php if (isset($row['produced'])): ?>
                                    <td class="dbrief-num"><?php echo number_format((int)$row['produced']); ?></td>
                                    <td class="dbrief-num"><?php echo number_format((int)$row['remaining']); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <?php if ($brief['ingredient_alerts']['available'] && !empty($brief['ingredient_alerts']['exceptions'])): ?>
            <section class="dbrief-section dbrief-ingredients">
                <div class="dbrief-section-head">
                    <h3><?php bakery_te('brief.ingredient_heading'); ?></h3>
                    <a class="dbrief-section-link no-print" href="<?php echo htmlspecialchars($brief['ingredient_alerts']['href']); ?>">
                        <?php bakery_te('brief.open_ingredients'); ?> →
                    </a>
                </div>
                <ul class="dbrief-bullets dbrief-bullets--warn">
                    <?php foreach ($brief['ingredient_alerts']['exceptions'] as $ex): ?>
                        <li>
                            <?php if ($ex['product_name'] !== ''): ?>
                                <strong><?php echo htmlspecialchars($ex['product_name']); ?>:</strong>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($ex['message']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($brief['customer_notes'] !== []): ?>
            <section class="dbrief-section dbrief-notes">
                <h3><?php bakery_te('brief.notes_heading'); ?></h3>
                <ul class="dbrief-note-list">
                    <?php foreach ($brief['customer_notes'] as $note): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($note['customer_name']); ?></strong>
                            <span class="dbrief-note-source"><?php echo htmlspecialchars($note['source']); ?></span>
                            <p><?php echo htmlspecialchars($note['note']); ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <section class="dbrief-section dbrief-drivers">
                <div class="dbrief-section-head">
                    <h3><?php bakery_te('brief.drivers_heading'); ?></h3>
                    <span class="dbrief-section-links no-print">
                        <a class="dbrief-section-link" href="<?php echo htmlspecialchars($brief['drivers']['href_assignment']); ?>"><?php bakery_te('nav.item.driver_assignment'); ?></a>
                        <a class="dbrief-section-link" href="<?php echo htmlspecialchars($brief['drivers']['href_route']); ?>"><?php bakery_te('nav.item.daily_route'); ?></a>
                        <a class="dbrief-section-link" href="<?php echo htmlspecialchars($brief['drivers']['href_load']); ?>"><?php bakery_te('nav.item.driver_load'); ?></a>
                    </span>
                </div>
                <p class="dbrief-lead"><?php echo htmlspecialchars($brief['drivers']['summary']); ?></p>
                <?php if ($brief['drivers']['unassigned'] !== null && $brief['drivers']['unassigned'] > 0): ?>
                    <p class="dbrief-callout dbrief-callout--warn">
                        <?php echo htmlspecialchars(bakery_t('brief.unassigned_orders', ['count' => (int)$brief['drivers']['unassigned']])); ?>
                    </p>
                <?php endif; ?>
                <?php if ($brief['drivers']['routes'] !== []): ?>
                    <table class="dbrief-table">
                        <thead>
                            <tr>
                                <th><?php bakery_te('brief.col_driver'); ?></th>
                                <th class="dbrief-num"><?php bakery_te('brief.col_stops'); ?></th>
                                <th class="dbrief-num"><?php bakery_te('brief.col_units'); ?></th>
                                <th><?php bakery_te('brief.col_status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brief['drivers']['routes'] as $route): ?>
                                <?php
                                $statusParts = [];
                                if ($route['delivered_stops'] > 0) {
                                    $statusParts[] = $route['delivered_stops'] . ' delivered';
                                }
                                if ($route['open_stops'] > 0) {
                                    $statusParts[] = $route['open_stops'] . ' open';
                                }
                                if ($route['load_status'] === 'loaded') {
                                    $statusParts[] = 'loaded';
                                } elseif ($route['load_status'] === 'partial') {
                                    $statusParts[] = 'partial load';
                                } elseif ($route['load_status'] === 'not_loaded') {
                                    $statusParts[] = 'not loaded';
                                }
                                if ($route['issues'] !== []) {
                                    $statusParts = array_merge($statusParts, $route['issues']);
                                }
                                ?>
                                <tr<?php echo $route['issues'] !== [] ? ' class="dbrief-row--warn"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($route['driver_name']); ?></td>
                                    <td class="dbrief-num"><?php echo number_format($route['stop_count']); ?></td>
                                    <td class="dbrief-num"><?php echo number_format($route['units']); ?></td>
                                    <td><?php echo htmlspecialchars($statusParts !== [] ? implode(' · ', $statusParts) : '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="dbrief-empty"><?php bakery_te('brief.no_routes'); ?></p>
                <?php endif; ?>
            </section>

            <?php if ($brief['mode'] === 'handoff' && $brief['handoff']): ?>
            <section class="dbrief-section dbrief-handoff">
                <h3><?php bakery_te('brief.handoff_heading'); ?></h3>
                <div class="dbrief-handoff-grid">
                    <?php if ($brief['handoff']['completed'] !== []): ?>
                    <div>
                        <h4><?php bakery_te('brief.completed'); ?></h4>
                        <ul class="dbrief-bullets">
                            <?php foreach ($brief['handoff']['completed'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <?php if ($brief['handoff']['outstanding'] !== []): ?>
                    <div>
                        <h4><?php bakery_te('brief.outstanding'); ?></h4>
                        <ul class="dbrief-bullets dbrief-bullets--warn">
                            <?php foreach ($brief['handoff']['outstanding'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <?php if ($brief['handoff']['recent'] !== []): ?>
                    <div>
                        <h4><?php bakery_te('brief.recent'); ?></h4>
                        <ul class="dbrief-bullets">
                            <?php foreach ($brief['handoff']['recent'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <footer class="dbrief-doc-footer">
                <p class="dbrief-muted">
                    <?php echo htmlspecialchars(bakery_t('brief.footer', [
                        'date' => $selectedDate,
                        'mode' => $brief['mode_label'],
                    ])); ?>
                </p>
                <p class="dbrief-muted no-print">
                    <?php bakery_te('brief.footer_links'); ?>
                    <a href="<?php echo htmlspecialchars($brief['links']['production_center']); ?>"><?php bakery_te('nav.item.production_center'); ?></a> ·
                    <a href="<?php echo htmlspecialchars($brief['links']['pack']); ?>"><?php bakery_te('nav.item.pack_list'); ?></a> ·
                    <a href="<?php echo htmlspecialchars($brief['links']['invoice']); ?>"><?php bakery_te('nav.item.invoice_center'); ?></a>
                    <?php if ($brief['ingredient_alerts']['available']): ?> ·
                    <a href="<?php echo htmlspecialchars($brief['ingredient_alerts']['href']); ?>"><?php bakery_te('page.ingredient_requirements'); ?></a>
                    <?php endif; ?>
                </p>
            </footer>
        </article>
    <?php endif; ?>
</div>

<?php if ($autoPrint && $brief): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

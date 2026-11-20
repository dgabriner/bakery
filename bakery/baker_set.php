<?php
/**
 * Set the day. Bakers and managers edit. Other staff can open it to look.
 * Autosave writes production_plan_items. Done commits the bake list.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/baker_set.php';

bakery_require_role(bakery_baker_set_view_roles());

$defaultDate = bakery_baker_set_default_date();
$selectedDate = bakery_staff_selected_date($_GET['date'] ?? '', $defaultDate);
$limit = bakery_baker_set_limit($db);
$canEdit = bakery_baker_set_can_edit();
$canUnlock = bakery_baker_set_can_unlock();
$wantsJson = (function_exists('is_ajax_request') && is_ajax_request())
    || (function_exists('bakery_wants_json') && bakery_wants_json());
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if (str_contains($contentType, 'application/json')) {
    $wantsJson = true;
}

if (($_GET['ajax'] ?? '') === 'revision') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'revision' => bakery_baker_set_revision($db, $selectedDate),
        'locked' => bakery_baker_set_is_locked($db, $selectedDate),
    ]);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $payload = $_POST;
    if ($wantsJson) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $action = trim((string) ($payload['action'] ?? ''));
    $postDate = bakery_staff_selected_date($payload['date'] ?? '', $selectedDate);
    try {
        $result = bakery_baker_set_apply($db, $action, $payload);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result);
            exit;
        }
        $productId = (int) ($result['patch']['product_id'] ?? $result['product_id'] ?? $payload['product_id'] ?? 0);
        $hash = $productId > 0 ? '#p-' . $productId : '';
        header('Location: ' . BASE_URL . 'baker_set.php?date=' . rawurlencode($postDate) . '&notice=saved' . $hash);
        exit;
    } catch (Throwable $e) {
        $key = bakery_baker_set_error_key($e);
        if ($wantsJson) {
            $conflict = str_starts_with($e->getMessage(), 'production_plan_conflict:');
            $status = $conflict ? 409 : ($key === 'baker_set.error_forbidden' ? 403 : 422);
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            if ($conflict) {
                echo json_encode(bakery_baker_set_conflict_payload($e, $db, $postDate));
                exit;
            }
            echo json_encode([
                'ok' => false,
                'error' => bakery_t($key, ['weekday' => '']),
                'error_key' => $key,
                'stale' => $key === 'baker_set.error_conflict',
            ]);
            exit;
        }
        header('Location: ' . BASE_URL . 'baker_set.php?date=' . rawurlencode($postDate) . '&notice=error&error_key=' . rawurlencode($key));
        exit;
    }
}

$sheet = bakery_baker_set_sheet($db, $selectedDate, $limit);
$locked = !empty($sheet['locked']);
$live = $canEdit && !$locked;
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchHits = $live && $searchQuery !== '' ? bakery_baker_set_search($db, $searchQuery, $limit) : [];
$days = bakery_day_names();
$weekday = (int) date('N', strtotime($selectedDate));
$dayName = $days[$weekday] ?? '';
$bakeDate = bakery_production_cadence_bake_date_for_delivery(BAKERY_PRODUCTION_CADENCE_DAILY, $selectedDate);
$bakeLabel = '';
if ($bakeDate !== null && function_exists('bakery_localized_date_label')) {
    $bakeObject = DateTime::createFromFormat('!Y-m-d', $bakeDate);
    if ($bakeObject instanceof DateTime) {
        $bakeLabel = bakery_localized_date_label($bakeObject, false);
    }
}
$notice = (string) ($_GET['notice'] ?? '');
$errorKey = (string) ($_GET['error_key'] ?? '');
$statusText = '';
if ($notice === 'saved') {
    $statusText = bakery_t('baker_set.saved');
} elseif ($notice === 'error' && $errorKey !== '') {
    $statusText = bakery_t($errorKey, ['weekday' => $dayName]);
}
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$showSegments = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker', 'manager', 'administrator']);
$isBakerOnly = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker']) && !bakery_user_has_role(['manager', 'administrator']);

$page_title = bakery_t('page.baker_set');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/baker_set.css'); ?>">

<div class="bs-screen"
     data-baker-set
     data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
     data-revision="<?php echo htmlspecialchars((string) $sheet['revision'], ENT_QUOTES, 'UTF-8'); ?>"
     data-live="<?php echo $live ? '1' : '0'; ?>"
     data-saved="<?php echo htmlspecialchars(bakery_t('baker_set.saved'), ENT_QUOTES, 'UTF-8'); ?>"
     data-saving="<?php echo htmlspecialchars(bakery_t('baker_set.saving'), ENT_QUOTES, 'UTF-8'); ?>"
     data-queued="<?php echo htmlspecialchars(bakery_t('baker_set.queued'), ENT_QUOTES, 'UTF-8'); ?>"
     data-stale="<?php echo htmlspecialchars(bakery_t('baker_set.stale'), ENT_QUOTES, 'UTF-8'); ?>"
     data-error="<?php echo htmlspecialchars(bakery_t('baker_set.error_generic'), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($showSegments) {
        bakery_kitchen_segments_render('set', $selectedDate);
    } ?>
    <header class="bs-header">
        <h1 class="bs-title"><?php bakery_te('baker_set.title'); ?></h1>
        <p class="bs-lead"><?php bakery_te('baker_set.lead'); ?></p>
        <form method="get" action="baker_set.php" class="bs-date-form">
            <label class="bs-date-label" for="bs-date"><?php bakery_te('baker_set.sell_day'); ?></label>
            <div class="bs-date-row">
                <a class="bs-date-jump" href="baker_set.php?date=<?php echo htmlspecialchars($prevDate, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('common.prev'); ?></a>
                <input type="date" name="date" id="bs-date" class="bs-date-input" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()">
                <a class="bs-date-jump" href="baker_set.php?date=<?php echo htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('common.next'); ?></a>
            </div>
            <p class="bs-date-context">
                <strong><?php echo htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="bs-chip"><?php echo htmlspecialchars(bakery_t('baker_set.lines', ['count' => (string) (int) $sheet['real_order_lines']]), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($bakeLabel !== ''): ?>
                    <span><?php echo htmlspecialchars(bakery_t('baker_set.bake_for', ['bake' => $bakeLabel]), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </p>
        </form>
        <?php if (!$isBakerOnly): ?>
            <p class="bs-links">
                <a href="baker_mix.php?date=<?php echo rawurlencode($selectedDate); ?>"><?php bakery_te('baker_set.open_mix'); ?></a>
                <a href="production.php?date=<?php echo rawurlencode($selectedDate); ?>"><?php bakery_te('baker_set.open_production'); ?></a>
            </p>
        <?php endif; ?>
    </header>

    <?php if (!$canEdit): ?>
        <p class="bs-banner" role="status"><?php bakery_te('baker_set.view_only'); ?></p>
    <?php elseif ($locked): ?>
        <p class="bs-banner bs-banner--locked" role="status"><?php bakery_te('baker_set.locked_hint'); ?></p>
    <?php endif; ?>

    <div id="bs-stale" class="bs-banner bs-banner--stale" role="alert" hidden>
        <span><?php bakery_te('baker_set.stale'); ?></span>
        <a href="baker_set.php?date=<?php echo rawurlencode($selectedDate); ?>"><?php bakery_te('baker_set.reload'); ?></a>
    </div>

    <?php if ($live): ?>
        <form method="get" action="baker_set.php" class="bs-search" role="search">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="bs-q"><?php bakery_te('baker_set.search_label'); ?></label>
            <input type="search" id="bs-q" name="q" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(bakery_t('baker_set.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" enterkeyhint="search" autocomplete="off">
        </form>
        <?php if ($searchQuery !== ''): ?>
            <ul class="bs-hits">
                <?php if ($searchHits === []): ?>
                    <li><?php bakery_te('baker_set.no_results'); ?></li>
                <?php endif; ?>
                <?php foreach ($searchHits as $hit): ?>
                    <li>
                        <form method="post" action="baker_set.php">
                            <?php echo bakery_csrf_field(); ?>
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="product_id" value="<?php echo (int) $hit['product_id']; ?>">
                            <input type="hidden" name="revision" value="<?php echo htmlspecialchars((string) $sheet['revision'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="action" value="add">
                                <?php bakery_te('baker_set.add'); ?>
                                <?php echo htmlspecialchars((string) $hit['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($hit['weight_grams']): ?>
                                    <span><?php echo htmlspecialchars(bakery_t('baker_set.piece_weight', ['grams' => (string) (int) $hit['weight_grams']]), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($sheet['batches'] === []): ?>
        <p class="bs-empty"><?php bakery_te('baker_set.empty'); ?></p>
    <?php endif; ?>

    <?php foreach ($sheet['batches'] as $batch):
        $card = bakery_baker_set_dough_card($batch);
        $doughId = (int) ($batch['dough_type_id'] ?? 0);
        ?>
        <section class="bs-dough" data-dough="<?php echo $doughId; ?>" aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.dough_aria', ['dough' => $card['name']]), ENT_QUOTES, 'UTF-8'); ?>">
            <header class="bs-dough__head">
                <h2><?php echo htmlspecialchars($card['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="bs-dough__kg" data-dough-kg><?php echo htmlspecialchars($card['kg'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="bs-dough__batches" data-dough-batches><?php echo htmlspecialchars($card['batches'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php foreach ($card['notes'] as $note): ?>
                    <p class="bs-dough__note"><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
                <?php if ($card['missing']): ?>
                    <p class="bs-dough__note"><?php bakery_te('baker_set.missing_weight'); ?></p>
                <?php endif; ?>
            </header>
            <?php if ($card['ingredients'] !== []): ?>
                <ul class="bs-pull" data-dough-pull aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.ingredients'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($card['ingredients'] as $ingredient): ?>
                        <li><span><?php echo htmlspecialchars($ingredient['name'], ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo htmlspecialchars($ingredient['grams'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ((float) ($batch['formula']['total_percentage'] ?? 0) <= 0): ?>
                <p class="bs-dough__note"><?php bakery_te('baker_set.no_formula'); ?></p>
            <?php endif; ?>

            <div class="bs-rows">
                <?php foreach ($batch['products'] as $product):
                    $productId = (int) $product['product_id'];
                    $planned = (int) $product['planned_quantity'];
                    $demand = (int) ($product['demand_quantity'] ?? 0);
                    $hasPlan = !empty($product['has_plan']);
                    $balance = (string) ($product['balance'] ?? 'match');
                    $balanceText = bakery_baker_set_balance_text($balance, $planned, $demand);
                    $weight = $product['weight_grams'];
                    $weightAttr = ($weight === null || $weight === '') ? '' : (string) (int) $weight;
                    $name = (string) $product['product_name'];
                    ?>
                    <article class="bs-row" id="p-<?php echo $productId; ?>" data-product="<?php echo $productId; ?>" data-weight="<?php echo htmlspecialchars($weightAttr, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="bs-row__copy">
                            <h3><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="bs-row__meta">
                                <?php echo htmlspecialchars(bakery_t('baker_set.demand', ['count' => (string) $demand]), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($weightAttr !== ''): ?>
                                    · <?php echo htmlspecialchars(bakery_t('baker_set.piece_weight', ['grams' => $weightAttr]), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </p>
                            <p class="bs-balance" data-balance data-kind="<?php echo htmlspecialchars($balance, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($balanceText, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <?php if ($live): ?>
                            <form method="post" action="baker_set.php" class="bs-stepper">
                                <?php echo bakery_csrf_field(); ?>
                                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                                <input type="hidden" name="expected_has_plan" value="<?php echo $hasPlan ? '1' : '0'; ?>">
                                <input type="hidden" name="expected_quantity" value="<?php echo $hasPlan ? $planned : 0; ?>">
                                <input type="hidden" name="revision" value="<?php echo htmlspecialchars((string) $sheet['revision'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" name="action" value="step-down" aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.step_down', ['product' => $name]), ENT_QUOTES, 'UTF-8'); ?>">−</button>
                                <input type="text"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       name="quantity"
                                       value="<?php echo $planned; ?>"
                                       enterkeyhint="done"
                                       autocomplete="off"
                                       autocapitalize="off"
                                       spellcheck="false"
                                       aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.quantity', ['product' => $name]), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" name="action" value="step-up" aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.step_up', ['product' => $name]), ENT_QUOTES, 'UTF-8'); ?>">+</button>
                            </form>
                        <?php else: ?>
                            <p class="bs-qty-read"><?php echo number_format($planned); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="bs-bar" role="region" aria-label="<?php echo htmlspecialchars(bakery_t('baker_set.status_aria'), ENT_QUOTES, 'UTF-8'); ?>">
        <p class="bs-status" role="status" aria-live="polite">
            <span id="bs-status-text"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span>
        </p>
        <div class="bs-bar__actions">
            <?php if ($live): ?>
                <form method="post" action="baker_set.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="revision" value="<?php echo htmlspecialchars((string) $sheet['revision'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="action" value="undo" <?php echo empty($sheet['can_undo']) ? 'disabled' : ''; ?>><?php bakery_te('baker_set.undo'); ?></button>
                </form>
                <form method="post" action="baker_set.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="action" value="copy"><?php echo htmlspecialchars(bakery_t('baker_set.copy_last', ['weekday' => $dayName]), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
                <form method="post" action="baker_set.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="action" value="reset"><?php bakery_te('baker_set.reset'); ?></button>
                </form>
                <form method="post" action="baker_set.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="bs-done" name="action" value="lock"><?php bakery_te('baker_set.lock'); ?></button>
                </form>
            <?php elseif ($locked && $canUnlock): ?>
                <form method="post" action="baker_set.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="action" value="unlock"><?php bakery_te('baker_set.unlock'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?php echo bakery_asset_href('includes/baker_set.js'); ?>" defer></script>
<?php
require_once 'includes/footer.php';

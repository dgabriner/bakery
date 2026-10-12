<?php
/**
 * Upload Zazie's weekly order sheet and apply it to dated orders.
 * Administrator and manager. Writes only on confirm POST.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/zazie_weekly_sheet.php';

bakery_require_role(['administrator', 'manager']);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$formError = null;
if ($method === 'POST') {
    bakery_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'preview') {
            $file = $_FILES['sheet'] ?? null;
            if (!is_array($file)) {
                throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
            }
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                throw new BakeryZazieWeeklyException('zazie_weekly.file_too_large');
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
            }
            $week = trim((string)($_POST['week_start'] ?? ''));
            bakery_zazie_weekly_begin_preview($db, $tmp, (string)($file['name'] ?? ''), $week !== '' ? $week : null, $_SESSION);
        } elseif ($action === 'save_mapping') {
            $sheetName = trim((string)($_POST['sheet_name'] ?? ''));
            bakery_zazie_weekly_save_mapping($db, $sheetName, (int)($_POST['product_id'] ?? 0));
            $_SESSION['zazie_weekly_flash'] = [
                'key' => 'zazie_weekly.mapped',
                'params' => ['name' => $sheetName],
            ];
        } elseif ($action === 'confirm') {
            $result = bakery_zazie_weekly_confirm($db, (string)($_POST['preview_token'] ?? ''), $_SESSION);
            if ((int)$result['applied'] === 0) {
                $_SESSION['zazie_weekly_flash'] = ['key' => 'zazie_weekly.applied_none', 'params' => []];
            } elseif ((int)$result['applied'] === 1) {
                $_SESSION['zazie_weekly_flash'] = ['key' => 'zazie_weekly.applied_one', 'params' => []];
            } else {
                $_SESSION['zazie_weekly_flash'] = [
                    'key' => 'zazie_weekly.applied_many',
                    'params' => ['count' => (int)$result['applied']],
                ];
            }
        } else {
            throw new BakeryZazieWeeklyException('zazie_weekly.bad_action');
        }
        header('Location: ' . BASE_URL . 'zazie_weekly_sheet.php');
        exit;
    } catch (BakeryZazieWeeklyException $exception) {
        $formError = $exception;
    } catch (Throwable $exception) {
        error_log('Zazie weekly sheet: ' . $exception->getMessage());
        $formError = new BakeryZazieWeeklyException('zazie_weekly.apply_failed');
    }
}

$flash = $_SESSION['zazie_weekly_flash'] ?? null;
unset($_SESSION['zazie_weekly_flash']);

$ready = bakery_zazie_weekly_ready($db);
$customer = $ready ? bakery_zazie_weekly_customer($db) : null;
$products = $ready ? bakery_zazie_weekly_products($db) : [];
$mappings = $ready ? bakery_zazie_weekly_mapping_rows($db) : [];
$preview = null;
if ($ready && $formError === null) {
    try {
        $preview = bakery_zazie_weekly_session_preview($db, $_SESSION);
    } catch (BakeryZazieWeeklyException $exception) {
        $formError = $exception;
    }
}

$page_title = bakery_t('zazie_weekly.page_title');
header('Cache-Control: no-store');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

/**
 * @param list<array{id:int,name:string}> $products
 */
function bakery_zazie_weekly_date_heading(string $ymd): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$date) {
        return $ymd;
    }
    $keys = [
        1 => 'day.mon_short',
        2 => 'day.tue_short',
        3 => 'day.wed_short',
        4 => 'day.thu_short',
        5 => 'day.fri_short',
        6 => 'day.sat_short',
        7 => 'day.sun_short',
    ];
    $dow = (int)$date->format('N');
    return bakery_t($keys[$dow] ?? 'day.mon_short') . ' ' . $date->format('j');
}
?>
<div class="container zazie-sheet-page">
    <header class="zazie-sheet-header">
        <p class="zazie-sheet-kicker"><a href="daily_orders.php"><?php echo htmlspecialchars(bakery_t('nav.item.daily_orders')); ?></a></p>
        <h1><?php echo htmlspecialchars(bakery_t('zazie_weekly.heading')); ?></h1>
        <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.intro')); ?></p>
        <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.format_help')); ?></p>
    </header>

    <?php if (is_array($flash) && isset($flash['key'])): ?>
        <p class="zazie-sheet-flash" role="status"><?php echo htmlspecialchars(bakery_t((string)$flash['key'], (array)($flash['params'] ?? []))); ?></p>
    <?php endif; ?>
    <?php if ($formError instanceof BakeryZazieWeeklyException): ?>
        <p class="zazie-sheet-error" role="alert"><?php echo htmlspecialchars(bakery_t($formError->messageKey, $formError->params)); ?></p>
    <?php endif; ?>

    <?php if (!$ready): ?>
        <p class="zazie-sheet-error" role="alert"><?php echo htmlspecialchars(bakery_t('zazie_weekly.schema_missing')); ?></p>
    <?php elseif (!$customer): ?>
        <p class="zazie-sheet-error" role="alert"><?php echo htmlspecialchars(bakery_t('zazie_weekly.missing_customer')); ?></p>
    <?php else: ?>
        <p class="zazie-sheet-customer"><span><?php echo htmlspecialchars(bakery_t('zazie_weekly.customer_label')); ?></span> <?php echo htmlspecialchars((string)$customer['name']); ?></p>
        <form class="zazie-sheet-upload" method="post" enctype="multipart/form-data" action="zazie_weekly_sheet.php">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="preview">
            <label class="zazie-sheet-field">
                <span><?php echo htmlspecialchars(bakery_t('zazie_weekly.file_label')); ?></span>
                <input type="file" name="sheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
            </label>
            <label class="zazie-sheet-field">
                <span><?php echo htmlspecialchars(bakery_t('zazie_weekly.week_label')); ?></span>
                <input type="date" name="week_start">
                <small><?php echo htmlspecialchars(bakery_t('zazie_weekly.week_help')); ?></small>
            </label>
            <button class="btn btn-primary" type="submit"><?php echo htmlspecialchars(bakery_t('zazie_weekly.preview_button')); ?></button>
        </form>
    <?php endif; ?>

    <?php if (is_array($preview)): ?>
        <?php
        $dates = $preview['dates'];
        $rangeStart = $dates !== [] ? min($dates) : '';
        $rangeEnd = $dates !== [] ? max($dates) : '';
        $counts = $preview['counts'];
        $blocked = $preview['errors'] !== [];
        ?>
        <section class="zazie-sheet-preview" aria-labelledby="zazie-sheet-preview-title">
            <h2 id="zazie-sheet-preview-title"><?php echo htmlspecialchars(bakery_t('zazie_weekly.preview_heading')); ?></h2>
            <?php if ($rangeStart !== ''): ?>
                <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.week_range', ['start' => $rangeStart, 'end' => $rangeEnd])); ?></p>
                <p><a href="daily_orders.php?date=<?php echo htmlspecialchars($rangeStart); ?>"><?php echo htmlspecialchars(bakery_t('zazie_weekly.open_orders')); ?></a></p>
            <?php endif; ?>
            <p class="zazie-sheet-counts"><?php echo htmlspecialchars(bakery_t('zazie_weekly.count_summary', [
                'new' => (int)$counts['new'],
                'changed' => (int)$counts['changed'],
                'unchanged' => (int)$counts['unchanged'],
                'unmapped' => (int)$counts['unmapped'],
            ])); ?></p>
            <?php foreach ($preview['errors'] as $error): ?>
                <p class="zazie-sheet-error" role="alert"><?php echo htmlspecialchars(bakery_t((string)$error['key'], (array)$error['params'])); ?></p>
            <?php endforeach; ?>
            <?php if ((int)$counts['unmapped'] > 0): ?>
                <h3><?php echo htmlspecialchars(bakery_t('zazie_weekly.unmapped_heading')); ?></h3>
                <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.unmapped_help')); ?></p>
            <?php endif; ?>
            <div class="zazie-sheet-scroll">
                <table class="zazie-sheet-table">
                    <caption class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.preview_heading')); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo htmlspecialchars(bakery_t('zazie_weekly.col_product')); ?></th>
                            <?php foreach ($dates as $date): ?>
                                <th scope="col"><?php echo htmlspecialchars(bakery_zazie_weekly_date_heading((string)$date)); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['lines'] as $line): ?>
                        <tr>
                            <th scope="row">
                                <?php echo htmlspecialchars((string)$line['sheet_name']); ?>
                                <?php if (!empty($line['unmapped'])): ?>
                                    <span class="zazie-sheet-badge is-unmapped"><?php echo htmlspecialchars(bakery_t('zazie_weekly.status_unmapped')); ?></span>
                                    <form method="post" action="zazie_weekly_sheet.php" class="zazie-sheet-map">
                                        <?php echo bakery_csrf_field(); ?>
                                        <input type="hidden" name="action" value="save_mapping">
                                        <input type="hidden" name="sheet_name" value="<?php echo htmlspecialchars((string)$line['sheet_name']); ?>">
                                        <label>
                                            <span class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.map_product')); ?></span>
                                            <select name="product_id" required>
                                                <option value=""><?php echo htmlspecialchars(bakery_t('zazie_weekly.map_choose')); ?></option>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <button class="btn btn-secondary btn-small" type="submit"><?php echo htmlspecialchars(bakery_t('zazie_weekly.map_save')); ?></button>
                                    </form>
                                <?php elseif (!empty($line['product_name']) && (string)$line['product_name'] !== (string)$line['sheet_name']): ?>
                                    <span class="zazie-sheet-mapped-name"><?php echo htmlspecialchars((string)$line['product_name']); ?></span>
                                <?php endif; ?>
                            </th>
                            <?php foreach ($dates as $date): ?>
                                <?php
                                $cell = $line['cells'][$date] ?? ['sheet_qty' => 0, 'existing_qty' => 0, 'status' => 'unchanged'];
                                $sheetQty = (int)$cell['sheet_qty'];
                                $existingQty = (int)$cell['existing_qty'];
                                $status = (string)$cell['status'];
                                if (!in_array($status, ['new', 'changed', 'unchanged', 'unmapped'], true)) {
                                    $status = 'unchanged';
                                }
                                $blank = $sheetQty === 0 && $existingQty === 0;
                                ?>
                                <td class="num<?php echo $blank ? '' : ' is-' . htmlspecialchars($status); ?>">
                                    <?php if ($blank): ?>
                                    <?php elseif ($status === 'new'): ?>
                                        <span class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.status_new')); ?></span>
                                        <?php echo $sheetQty; ?>
                                    <?php elseif ($status === 'changed'): ?>
                                        <span class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.status_changed')); ?></span>
                                        <?php echo htmlspecialchars(bakery_t('zazie_weekly.was_now', ['old' => $existingQty, 'new' => $sheetQty])); ?>
                                    <?php elseif ($status === 'unmapped'): ?>
                                        <?php echo $sheetQty > 0 ? $sheetQty : ''; ?>
                                    <?php else: ?>
                                        <span class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.status_unchanged')); ?></span>
                                        <?php echo $sheetQty; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" action="zazie_weekly_sheet.php" class="zazie-sheet-confirm">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="preview_token" value="<?php echo htmlspecialchars((string)$preview['token']); ?>">
                <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.confirm_help')); ?></p>
                <button class="btn btn-primary" type="submit"<?php echo $blocked ? ' disabled' : ''; ?>><?php echo htmlspecialchars(bakery_t('zazie_weekly.confirm_button')); ?></button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($ready): ?>
        <section class="zazie-sheet-saved" aria-labelledby="zazie-sheet-saved-title">
            <h2 id="zazie-sheet-saved-title"><?php echo htmlspecialchars(bakery_t('zazie_weekly.saved_heading')); ?></h2>
            <?php if ($mappings === []): ?>
                <p><?php echo htmlspecialchars(bakery_t('zazie_weekly.saved_empty')); ?></p>
            <?php else: ?>
                <div class="zazie-sheet-scroll">
                    <table class="zazie-sheet-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo htmlspecialchars(bakery_t('zazie_weekly.saved_sheet')); ?></th>
                                <th scope="col"><?php echo htmlspecialchars(bakery_t('zazie_weekly.saved_product')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <th scope="row"><?php echo htmlspecialchars($mapping['sheet_label']); ?></th>
                                <td>
                                    <form method="post" action="zazie_weekly_sheet.php" class="zazie-sheet-map">
                                        <?php echo bakery_csrf_field(); ?>
                                        <input type="hidden" name="action" value="save_mapping">
                                        <input type="hidden" name="sheet_name" value="<?php echo htmlspecialchars($mapping['sheet_label']); ?>">
                                        <label>
                                            <span class="sf-sr-only"><?php echo htmlspecialchars(bakery_t('zazie_weekly.map_product')); ?></span>
                                            <select name="product_id" required>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo (int)$product['id']; ?>"<?php echo (int)$product['id'] === (int)$mapping['product_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($product['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <button class="btn btn-secondary btn-small" type="submit"><?php echo htmlspecialchars(bakery_t('zazie_weekly.map_save')); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<style>
    .zazie-sheet-page { padding: 24px 16px 48px; color: var(--sf-text, #1c2a26); }
    .zazie-sheet-header h1 { margin: 0 0 8px; font-size: 1.6rem; }
    .zazie-sheet-header p, .zazie-sheet-preview p, .zazie-sheet-saved p { margin: 0 0 8px; color: var(--sf-text-secondary, #4a5c57); max-width: 42rem; }
    .zazie-sheet-kicker { margin: 0 0 6px; }
    .zazie-sheet-customer { font-weight: 650; }
    .zazie-sheet-customer span { color: var(--sf-text-muted, #6b7d78); font-weight: 500; }
    .zazie-sheet-upload, .zazie-sheet-confirm, .zazie-sheet-saved { margin-top: 20px; }
    .zazie-sheet-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; max-width: 28rem; font-weight: 650; }
    .zazie-sheet-field small { font-weight: 500; color: var(--sf-text-muted, #6b7d78); }
    .zazie-sheet-field input { font: inherit; min-height: 44px; }
    .zazie-sheet-flash, .zazie-sheet-error { padding: 10px 12px; border-radius: 8px; max-width: 42rem; }
    .zazie-sheet-flash { background: var(--sf-success-bg, #e8f5ec); border: 1px solid var(--sf-success-border, #a8d4b8); }
    .zazie-sheet-error { background: var(--sf-danger-bg, #fdeeee); border: 1px solid var(--sf-danger-border, #e7a1a1); }
    .zazie-sheet-scroll { overflow-x: auto; margin: 12px 0; }
    .zazie-sheet-table { border-collapse: collapse; min-width: 640px; background: var(--sf-surface, #fff); }
    .zazie-sheet-table th, .zazie-sheet-table td { border: 1px solid var(--sf-border, #ddd4c6); padding: 8px 10px; vertical-align: top; text-align: left; }
    .zazie-sheet-table td.num { text-align: right; white-space: nowrap; }
    .zazie-sheet-table td.is-new { background: var(--sf-success-bg, #e8f5ec); }
    .zazie-sheet-table td.is-changed { background: var(--sf-warning-bg, #fef6e4); }
    .zazie-sheet-table td.is-unmapped { background: var(--sf-danger-bg, #fdeeee); }
    .zazie-sheet-table td.is-unchanged { color: var(--sf-text-muted, #6b7d78); }
    .zazie-sheet-badge { display: inline-block; margin-left: 6px; padding: 2px 6px; border-radius: 999px; background: var(--sf-danger-bg, #fdeeee); font-size: 0.75rem; }
    .zazie-sheet-map { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
    .zazie-sheet-map select { min-height: 44px; max-width: 16rem; font: inherit; }
    .zazie-sheet-mapped-name { display: block; color: var(--sf-text-muted, #6b7d78); font-weight: 500; }
    @media (max-width: 640px) {
        .zazie-sheet-page { padding: 16px 12px 40px; }
        .zazie-sheet-map { align-items: stretch; }
        .zazie-sheet-map select, .zazie-sheet-map .btn { width: 100%; }
    }
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

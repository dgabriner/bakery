<?php
/**
 * Catalog gaps. Administrator and manager.
 * Bulk weight and catalog price edits are POST and CSRF protected.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/catalog_gaps.php';

bakery_require_role(['administrator', 'manager']);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo bakery_t('catalog_gaps.method_not_allowed');
    exit;
}

$errors = [];
if ($method === 'POST') {
    bakery_require_csrf();
    $weights = $_POST['weight'] ?? [];
    $prices = $_POST['price'] ?? [];
    if (!is_array($weights) || !is_array($prices)) {
        $errors[] = 'catalog_gaps.error_save';
    } else {
        $result = bakery_catalog_gap_apply($db, $weights, $prices);
        if ($result['ok']) {
            header('Location: catalog_gaps.php?saved=1');
            exit;
        }
        $errors = $result['errors'];
    }
}

$rows = bakery_catalog_gap_rows($db);
$counts = bakery_catalog_gap_counts($rows);

if ($method !== 'POST' && (string)($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="catalog-gaps.csv"');
    header('Cache-Control: no-store');
    echo bakery_catalog_gap_csv($rows);
    exit;
}

$page_title = bakery_t('catalog_gaps.page_title');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$gapOrder = ['missing_weight', 'missing_price', 'no_formula', 'no_cost_or_supplier'];
$gapLabels = [
    'missing_weight' => bakery_t('catalog_gaps.gap_missing_weight'),
    'missing_price' => bakery_t('catalog_gaps.gap_missing_price'),
    'no_formula' => bakery_t('catalog_gaps.gap_no_formula'),
    'no_cost_or_supplier' => bakery_t('catalog_gaps.gap_no_cost_or_supplier'),
];
$saved = (string)($_GET['saved'] ?? '') === '1';
?>
<div class="container catalog-gaps-page">
    <header class="catalog-gaps-header">
        <div>
            <p class="catalog-gaps-kicker"><a href="products.php"><?php echo htmlspecialchars(bakery_t('catalog_gaps.back_to_products')); ?></a></p>
            <h1><?php echo htmlspecialchars(bakery_t('catalog_gaps.heading')); ?></h1>
            <p><?php echo htmlspecialchars(bakery_t('catalog_gaps.intro')); ?></p>
        </div>
        <a class="catalog-gaps-export" href="catalog_gaps.php?export=csv"><?php echo htmlspecialchars(bakery_t('catalog_gaps.export_csv')); ?></a>
    </header>

    <section class="catalog-gaps-summary" aria-label="<?php echo htmlspecialchars(bakery_t('catalog_gaps.summary')); ?>">
        <?php foreach ($gapOrder as $gap): ?>
            <div class="catalog-gaps-count">
                <span><?php echo htmlspecialchars($gapLabels[$gap]); ?></span>
                <strong><?php echo number_format((int)$counts[$gap]); ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($saved): ?>
        <p class="catalog-gaps-saved" role="status"><?php echo htmlspecialchars(bakery_t('catalog_gaps.saved')); ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="catalog-gaps-error" role="alert"><?php echo htmlspecialchars(bakery_t((string)$error)); ?></p>
    <?php endforeach; ?>

    <?php if ($rows === []): ?>
        <p class="catalog-gaps-empty"><?php echo htmlspecialchars(bakery_t('catalog_gaps.empty')); ?></p>
    <?php else: ?>
        <form method="post" action="catalog_gaps.php">
            <?php echo bakery_csrf_field(); ?>
            <div class="catalog-gaps-actions">
                <button type="submit" class="catalog-gaps-save"><?php echo htmlspecialchars(bakery_t('catalog_gaps.save')); ?></button>
            </div>
            <div class="catalog-gaps-scroll">
                <table class="catalog-gaps-table">
                    <caption class="sr-only"><?php echo htmlspecialchars(bakery_t('catalog_gaps.heading')); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo htmlspecialchars(bakery_t('catalog_gaps.col_name')); ?></th>
                            <th scope="col"><?php echo htmlspecialchars(bakery_t('catalog_gaps.col_gap')); ?></th>
                            <th scope="col"><?php echo htmlspecialchars(bakery_t('catalog_gaps.col_detail')); ?></th>
                            <th scope="col"><?php echo htmlspecialchars(bakery_t('catalog_gaps.col_edit')); ?></th>
                        </tr>
                    </thead>
                    <?php foreach ($gapOrder as $gap): ?>
                        <?php if ((int)$counts[$gap] === 0) { continue; } ?>
                        <tbody>
                            <tr class="catalog-gaps-group">
                                <th colspan="4" scope="rowgroup">
                                    <?php echo htmlspecialchars(bakery_t('catalog_gaps.group', [
                                        'label' => $gapLabels[$gap],
                                        'count' => (string)(int)$counts[$gap],
                                    ])); ?>
                                </th>
                            </tr>
                            <?php foreach ($rows as $row): ?>
                                <?php if ((string)$row['gap'] !== $gap) { continue; } ?>
                                <tr>
                                    <th scope="row"><?php echo htmlspecialchars((string)$row['name']); ?></th>
                                    <td><?php echo htmlspecialchars($gapLabels[$gap]); ?></td>
                                    <td>
                                        <?php if ($gap === 'missing_weight'): ?>
                                            <label class="catalog-gaps-field">
                                                <span class="sr-only"><?php echo htmlspecialchars(bakery_t('catalog_gaps.weight_label', ['name' => (string)$row['name']])); ?></span>
                                                <input type="number" name="weight[<?php echo (int)$row['id']; ?>]" min="0" step="0.01" inputmode="decimal" value="">
                                                <span aria-hidden="true"><?php echo htmlspecialchars(bakery_t('catalog_gaps.grams')); ?></span>
                                            </label>
                                        <?php elseif ($gap === 'missing_price'): ?>
                                            <label class="catalog-gaps-field">
                                                <span class="sr-only"><?php echo htmlspecialchars(bakery_t('catalog_gaps.price_label', ['name' => (string)$row['name']])); ?></span>
                                                <span aria-hidden="true">$</span>
                                                <input type="number" name="price[<?php echo (int)$row['id']; ?>]" min="0" step="0.01" inputmode="decimal" value="">
                                            </label>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($row['kind'] === 'ingredient'
                                                ? bakery_t('catalog_gaps.kind_ingredient')
                                                : bakery_t('catalog_gaps.kind_product')); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($gap === 'no_formula' && (string)$row['href'] !== ''): ?>
                                            <a href="<?php echo htmlspecialchars((string)$row['href']); ?>"><?php echo htmlspecialchars(bakery_t('catalog_gaps.open_formula')); ?></a>
                                        <?php elseif ($gap === 'no_cost_or_supplier' && (string)$row['href'] !== ''): ?>
                                            <a href="<?php echo htmlspecialchars((string)$row['href']); ?>"><?php echo htmlspecialchars(bakery_t('catalog_gaps.open_prices')); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
<style>
    .catalog-gaps-page { padding: 24px 16px 48px; color: var(--sf-text, #1c2a26); }
    .catalog-gaps-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .catalog-gaps-header h1 { margin: 0 0 8px; font-size: 1.8rem; }
    .catalog-gaps-header p { margin: 0; max-width: 46rem; color: var(--sf-text-secondary, #4a5c57); }
    .catalog-gaps-kicker { margin: 0 0 6px; font-size: 0.85rem; }
    .catalog-gaps-kicker a, .catalog-gaps-export, .catalog-gaps-table a { color: var(--sf-primary, #24746a); font-weight: 700; }
    .catalog-gaps-export, .catalog-gaps-save {
        display: inline-flex; align-items: center; justify-content: center; min-height: var(--sf-touch-min, 44px);
        padding: 0 14px; border: 1px solid var(--sf-border-strong, #c9bda9); border-radius: var(--sf-radius-sm, 8px);
        background: var(--sf-surface, #fffefb); text-decoration: none; white-space: nowrap; font-weight: 700;
    }
    .catalog-gaps-save { background: var(--sf-primary, #24746a); color: #fff; border-color: var(--sf-primary, #24746a); cursor: pointer; }
    .catalog-gaps-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .catalog-gaps-count { padding: 12px; border: 1px solid var(--sf-border, #ddd4c6); border-radius: var(--sf-radius-md, 12px); background: var(--sf-surface, #fffefb); }
    .catalog-gaps-count span { display: block; color: var(--sf-text-secondary, #4a5c57); font-size: 0.82rem; }
    .catalog-gaps-count strong { font-size: 1.4rem; }
    .catalog-gaps-actions { margin: 0 0 12px; }
    .catalog-gaps-scroll { overflow-x: auto; border: 1px solid var(--sf-border, #ddd4c6); border-radius: var(--sf-radius-md, 12px); background: var(--sf-surface, #fffefb); }
    .catalog-gaps-table { width: 100%; border-collapse: collapse; }
    .catalog-gaps-table th, .catalog-gaps-table td { padding: 10px 12px; border-bottom: 1px solid var(--sf-border, #ddd4c6); text-align: left; vertical-align: middle; }
    .catalog-gaps-table thead th, .catalog-gaps-group th { background: var(--sf-surface-muted, #f3eee6); }
    .catalog-gaps-table tbody th { background: transparent; color: var(--sf-text, #1c2a26); font-size: 0.95rem; letter-spacing: 0; text-transform: none; }
    .catalog-gaps-field { display: inline-flex; align-items: center; gap: 6px; }
    .catalog-gaps-field input { min-height: var(--sf-touch-min, 44px); width: 7rem; padding: 0 8px; border: 1px solid var(--sf-border-strong, #c9bda9); border-radius: 8px; }
    .catalog-gaps-saved { color: #176b5d; font-weight: 700; }
    .catalog-gaps-error { color: #8f2d2d; font-weight: 700; }
    .catalog-gaps-empty { color: var(--sf-text-secondary, #4a5c57); }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    @media (max-width: 800px) {
        .catalog-gaps-header { flex-direction: column; align-items: stretch; }
        .catalog-gaps-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

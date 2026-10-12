<?php
/**
 * Read-only cost and margin. Administrator and manager. GET and HEAD only.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/cost_margin.php';

bakery_require_role(['administrator', 'manager']);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!bakery_cost_margin_method_allowed($method)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo bakery_t('cost_margin.method_not_allowed');
    exit;
}

$rows = bakery_cost_margin_rows($db);

if ((string)($_GET['export'] ?? '') === 'csv') {
    $csv = bakery_cost_margin_csv($rows);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cost-margin.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

$page_title = bakery_t('cost_margin.page_title');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

/**
 * @param list<string> $flags
 */
function bakery_cost_margin_flag_text(array $flags): string
{
    $labels = [];
    foreach ($flags as $flag) {
        if ($flag === 'empty_formula') {
            $labels[] = bakery_t('cost_margin.flag_empty_formula');
        } elseif ($flag === 'missing_price') {
            $labels[] = bakery_t('cost_margin.flag_missing_price');
        }
    }
    return implode('. ', $labels);
}
?>
<div class="container cost-margin-page">
    <header class="cost-margin-header">
        <div>
            <p class="cost-margin-kicker"><a href="products.php"><?php echo htmlspecialchars(bakery_t('cost_margin.back_to_products')); ?></a></p>
            <h1><?php echo htmlspecialchars(bakery_t('cost_margin.heading')); ?></h1>
            <p><?php echo htmlspecialchars(bakery_t('cost_margin.intro')); ?></p>
            <p><?php echo htmlspecialchars(bakery_t('cost_margin.loss_note')); ?></p>
        </div>
        <a class="cost-margin-export" href="cost_margin.php?export=csv"><?php echo htmlspecialchars(bakery_t('cost_margin.export_csv')); ?></a>
    </header>

    <?php if ($rows === []): ?>
        <p class="cost-margin-empty"><?php echo htmlspecialchars(bakery_t('cost_margin.empty')); ?></p>
    <?php else: ?>
        <div class="cost-margin-scroll">
            <table class="cost-margin-table">
                <caption class="sr-only"><?php echo htmlspecialchars(bakery_t('cost_margin.heading')); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_product')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_piece_grams')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_ingredient_cost')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_dough_loss')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_unit_price')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_margin_dollars')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_margin_percent')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_delivered')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('cost_margin.col_flag')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $flagText = bakery_cost_margin_flag_text($row['flags']); ?>
                    <tr<?php echo $flagText !== '' ? ' class="is-flagged"' : ''; ?>>
                        <th scope="row"><?php echo htmlspecialchars((string)$row['product_name']); ?></th>
                        <td class="num"><?php echo htmlspecialchars(bakery_cost_margin_format_grams($row['piece_grams'])); ?></td>
                        <td class="num"><?php echo htmlspecialchars(bakery_cost_margin_format_money($row['ingredient_cost'])); ?></td>
                        <td class="num">
                            <?php echo htmlspecialchars(bakery_cost_margin_format_money($row['dough_loss'])); ?>
                            <?php if ($row['loss_share_grams'] !== null): ?>
                                <span class="cost-margin-share"><?php echo htmlspecialchars(bakery_cost_margin_format_grams($row['loss_share_grams'])); ?> g</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?php echo htmlspecialchars(bakery_cost_margin_format_money($row['unit_price'])); ?></td>
                        <td class="num"><?php echo htmlspecialchars(bakery_cost_margin_format_money($row['margin_dollars'])); ?></td>
                        <td class="num"><?php echo htmlspecialchars(bakery_cost_margin_format_percent($row['margin_percent'])); ?></td>
                        <td class="num"><?php echo number_format((int)$row['delivered_90_days']); ?></td>
                        <td><?php echo htmlspecialchars($flagText); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<style>
    .cost-margin-page {
        padding: 24px 16px 48px;
        color: var(--sf-text, #1c2a26);
    }
    .cost-margin-header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
    }
    .cost-margin-header h1 {
        margin: 0 0 8px;
        font-size: 1.8rem;
    }
    .cost-margin-header p {
        margin: 0 0 8px;
        max-width: 46rem;
        color: var(--sf-text-secondary, #4a5c57);
    }
    .cost-margin-kicker {
        margin: 0 0 6px;
        font-size: 0.85rem;
    }
    .cost-margin-kicker a,
    .cost-margin-export {
        color: var(--sf-primary, #24746a);
        font-weight: 700;
    }
    .cost-margin-export {
        display: inline-flex;
        align-items: center;
        min-height: var(--sf-touch-min, 44px);
        padding: 0 14px;
        border: 1px solid var(--sf-border-strong, #c9bda9);
        border-radius: var(--sf-radius-sm, 8px);
        background: var(--sf-surface, #fffefb);
        text-decoration: none;
        white-space: nowrap;
    }
    .cost-margin-scroll {
        overflow-x: auto;
        border: 1px solid var(--sf-border, #ddd4c6);
        border-radius: var(--sf-radius-md, 12px);
        background: var(--sf-surface, #fffefb);
    }
    .cost-margin-table {
        width: 100%;
        border-collapse: collapse;
        font-variant-numeric: tabular-nums;
    }
    .cost-margin-table th,
    .cost-margin-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--sf-border, #ddd4c6);
        text-align: left;
        vertical-align: top;
    }
    .cost-margin-table thead th {
        background: var(--sf-surface-muted, #f3eee6);
        font-size: 0.82rem;
    }
    .cost-margin-table tbody th {
        background: transparent;
        color: var(--sf-text, #1c2a26);
        font-size: 0.95rem;
        letter-spacing: 0;
        text-transform: none;
    }
    .cost-margin-table .num { text-align: right; white-space: nowrap; }
    .cost-margin-table tr.is-flagged { background: #fbf3e8; }
    .cost-margin-share {
        display: block;
        color: var(--sf-text-muted, #6b7d78);
        font-size: 0.78rem;
        font-weight: 600;
    }
    .cost-margin-empty { color: var(--sf-text-secondary, #4a5c57); }
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    @media (max-width: 720px) {
        .cost-margin-header { align-items: stretch; flex-direction: column; }
        .cost-margin-export { justify-content: center; }
    }
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Closeout Radar — administrator-only, read-only ops screen.
 * Shows close gates and silent MRP holes for a delivery date. Does not mutate data.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/closeout_radar.php';

bakery_require_role(['administrator']);

$page_title = 'Closeout Radar';
$selectedDate = bakery_dashboard_resolve_date();
$today = date('Y-m-d');
$isToday = ($selectedDate === $today);
$radar = bakery_closeout_radar_build($db, $selectedDate);
$dateDisplay = date('l, F j, Y', strtotime($selectedDate));
$canClose = !empty($radar['can_close']);
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>css/dashboard.css">
<style>
  .radar-page { margin: 0 auto; max-width: 1100px; padding: 28px 20px 54px; }
  .radar-hero { background: #f5eee1; border: 1px solid #e2cfaa; border-radius: 15px; color: #503f25; padding: clamp(18px, 3vw, 28px); }
  .radar-hero h1 { color: #503f25; margin: 0; padding: 0; text-align: left; }
  .radar-hero h1::after { display: none; }
  .radar-hero p { line-height: 1.55; margin: 10px 0 0; max-width: 820px; }
  .radar-date-note { color: #6a5840; font-size: .92rem; margin-top: 8px; }
  .radar-verdict { border-radius: 12px; margin-top: 18px; padding: 16px 18px; }
  .radar-verdict h2 { font-size: 1.2rem; margin: 0 0 6px; }
  .radar-verdict p { margin: 0; }
  .radar-verdict--yes { background: #e5f5e9; border: 1px solid #b7dfc0; color: #195f35; }
  .radar-verdict--not-yet { background: #fdeaea; border: 1px solid #efc2c2; color: #9f2727; }
  .radar-section { margin-top: 28px; }
  .radar-section h2 { color: #254632; font-size: 1.12rem; margin: 0 0 10px; }
  .radar-table { background: #fff; border: 1px solid #dce8df; border-collapse: collapse; border-radius: 10px; overflow: hidden; width: 100%; }
  .radar-table th, .radar-table td { border-bottom: 1px solid #edf1ed; padding: 12px 14px; text-align: left; vertical-align: top; }
  .radar-table th { background: #f7faf7; color: #597064; font-size: .78rem; letter-spacing: .03em; text-transform: uppercase; }
  .radar-table tr:last-child td { border-bottom: 0; }
  .radar-status { border-radius: 999px; display: inline-block; font-size: .78rem; font-weight: 700; padding: 3px 9px; }
  .radar-status--blocked { background: #fdeaea; color: #9f2727; }
  .radar-status--clear { background: #e5f5e9; color: #195f35; }
  .radar-empty { background: #fff; border: 1px solid #dce8df; border-radius: 10px; color: #66786c; margin: 0; padding: 16px; }
  .radar-fix { color: #1f5b53; font-weight: 700; text-decoration: none; }
  .radar-fix:hover, .radar-fix:focus-visible { text-decoration: underline; }
  @media (max-width: 640px) {
    .radar-page { padding: 18px 12px 42px; }
    .radar-table { display: block; overflow-x: auto; }
  }
</style>

<div class="container">
<main class="radar-page">
  <header class="ops-header">
    <div class="radar-hero">
      <h1>Closeout Radar</h1>
      <p>Everything that will bite us on this delivery date or the next bake, with a link to the module that can fix it. This page is read-only — it does not close routes, confirm demand, or change catalog data.</p>
      <p class="radar-date-note"><strong>Delivery date:</strong> <?php echo htmlspecialchars($dateDisplay); ?><?php echo $isToday ? ' (today)' : ''; ?>. Bake day is not the same as sell/delivery day — this sheet is for deliveries on <?php echo htmlspecialchars($radar['weekday_label']); ?>.</p>
    </div>
    <nav class="ops-date-nav" aria-label="Delivery date navigation">
      <a href="?date=<?php echo urlencode($prevDate); ?>">← Prev</a>
      <?php if (!$isToday): ?>
        <a href="?" class="ops-today-link">Today</a>
      <?php endif; ?>
      <a href="?date=<?php echo urlencode($nextDate); ?>">Next →</a>
    </nav>
  </header>

  <section class="radar-verdict <?php echo $canClose ? 'radar-verdict--yes' : 'radar-verdict--not-yet'; ?>" aria-live="polite">
    <h2>Can we close? <?php echo $canClose ? 'Yes' : 'Not yet'; ?></h2>
    <p><?php echo htmlspecialchars($radar['blocking_reason']); ?></p>
  </section>

  <section class="radar-section">
    <h2>Close gates</h2>
    <table class="radar-table">
      <thead>
        <tr>
          <th>What</th>
          <th>Count</th>
          <th>Severity</th>
          <th>Fix</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($radar['gates'] as $gate): ?>
          <tr>
            <td>
              <strong><?php echo htmlspecialchars($gate['label']); ?></strong>
              <div><?php echo htmlspecialchars($gate['detail']); ?></div>
            </td>
            <td><?php echo number_format((int)$gate['count']); ?></td>
            <td>
              <span class="radar-status radar-status--<?php echo $gate['status'] === 'blocked' ? 'blocked' : 'clear'; ?>">
                <?php echo $gate['status'] === 'blocked' ? 'Blocking' : 'Clear'; ?>
              </span>
            </td>
            <td>
              <a class="radar-fix" href="<?php echo htmlspecialchars(BASE_URL . $gate['href']); ?>">Open module</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="radar-section">
    <h2>Silent MRP holes</h2>
    <p class="radar-date-note">These make Daily Production / ingredient math lie. Fix weights and formulas in the catalog — this page will not autosave them.</p>
    <?php if (!$radar['mrp_holes']['missing_weights'] && !$radar['mrp_holes']['empty_formulas']): ?>
      <p class="radar-empty">No missing product weights or empty dough formulas for this delivery date’s demand.</p>
    <?php else: ?>
      <?php if ($radar['mrp_holes']['missing_weights']): ?>
        <table class="radar-table">
          <thead>
            <tr>
              <th>Product missing weight_grams</th>
              <th>Line</th>
              <th>Fix</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($radar['mrp_holes']['missing_weights'] as $hole): ?>
              <tr>
                <td><?php echo htmlspecialchars($hole['name']); ?></td>
                <td><?php echo htmlspecialchars($hole['product_line'] !== '' ? $hole['product_line'] : 'Unclassified'); ?></td>
                <td><a class="radar-fix" href="<?php echo htmlspecialchars(BASE_URL . $hole['href']); ?>">Products</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($radar['mrp_holes']['empty_formulas']): ?>
        <table class="radar-table" style="margin-top:14px">
          <thead>
            <tr>
              <th>Dough type with empty formula</th>
              <th>Fix</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($radar['mrp_holes']['empty_formulas'] as $hole): ?>
              <tr>
                <td><?php echo htmlspecialchars($hole['name']); ?></td>
                <td><a class="radar-fix" href="<?php echo htmlspecialchars(BASE_URL . $hole['href']); ?>">Formulas</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
/** Current module and role reference for managers and administrators. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/navigation_catalog.php';

$guideUser = bakery_current_user();
$guideRole = $guideUser['role_slug'] ?? '';
$page_title = bakery_t('page.module_guide');
$guideGroups = bakery_navigation_groups_for_role($guideRole);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
  .module-guide { margin: 0 auto; max-width: 1180px; padding: 32px 20px 54px; }
  .module-guide__hero { background: linear-gradient(135deg, #173f3c, #28615d); border-radius: 16px; color: #fff; padding: clamp(22px, 4vw, 42px); }
  .module-guide__eyebrow { color: #ffe0a5; font-size: .78rem; font-weight: 750; letter-spacing: .12em; margin: 0 0 8px; text-transform: uppercase; }
  .module-guide__hero h1 { color: #fff; font-size: clamp(1.8rem, 4vw, 2.6rem); margin: 0; padding: 0; text-align: left; }
  .module-guide__hero h1::after { display: none; }
  .module-guide__hero p:last-child { font-size: 1.03rem; line-height: 1.55; margin: 13px 0 0; max-width: 770px; }
  .module-guide__roles { display: grid; gap: 14px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 26px 0 34px; }
  .module-guide__role { background: #fff; border: 1px solid #dce7e1; border-radius: 11px; padding: 16px; }
  .module-guide__role h2 { background: none; box-shadow: none; color: #1f4f49; font-size: 1rem; margin: 0 0 7px; padding: 0; }
  .module-guide__role p { color: #536560; font-size: .88rem; line-height: 1.48; margin: 0; }
  .module-guide__section { margin: 28px 0; }
  .module-guide__section h2 { background: none; box-shadow: none; color: #173f3c; font-size: 1.42rem; margin: 0 0 4px; padding: 0; }
  .module-guide__section > p { color: #5c6b67; margin: 0 0 12px; }
  .module-guide__table-wrap { background: #fff; border: 1px solid #dce7e1; border-radius: 11px; overflow-x: auto; }
  .module-guide table { box-shadow: none; margin: 0; min-width: 680px; }
  .module-guide th { background: #275b55; font-size: .78rem; }
  .module-guide td { vertical-align: top; }
  .module-guide__module { color: #1f4f49; font-weight: 750; }
  .module-guide__access { color: #5d6a66; font-size: .87rem; }
  .module-guide__usage { align-items: center; display: inline-flex; font-size: .75rem; font-weight: 740; gap: 6px; white-space: nowrap; }
  .module-guide__usage-dot { border-radius: 50%; flex: 0 0 8px; height: 8px; width: 8px; }
  .module-guide__usage--everyday { color: #1f7a48; }
  .module-guide__usage--everyday .module-guide__usage-dot { background: #1f7a48; }
  .module-guide__usage--moderate { color: #c26a16; }
  .module-guide__usage--moderate .module-guide__usage-dot { background: #d88346; }
  .module-guide__usage--occasional { color: #1a5f7a; }
  .module-guide__usage--occasional .module-guide__usage-dot { background: #1a5f7a; }
  .module-guide__legend { display: flex; flex-wrap: wrap; gap: 10px 16px; margin: 22px 0 0; }
  .module-guide__legend-item { align-items: center; color: #dcece9; display: inline-flex; font-size: .84rem; gap: 7px; }
  .module-guide__note { background: #fff8e9; border-left: 4px solid #d88346; border-radius: 7px; color: #5f4d2d; margin-top: 30px; padding: 14px 16px; }
  @media (max-width: 860px) { .module-guide__roles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 520px) { .module-guide { padding: 22px 12px 42px; } .module-guide__roles { grid-template-columns: 1fr; } }
</style>

<div class="container">
<main class="module-guide">
  <section class="module-guide__hero">
    <p class="module-guide__eyebrow">Current workspace reference</p>
    <h1>Module Guide</h1>
    <p>This is the curated day-to-day workspace. It groups active tools by the job they support and color-codes how often Admin and Manager typically open each screen.</p>
    <div class="module-guide__legend" aria-label="<?php echo htmlspecialchars(bakery_t('nav.usage.legend_aria'), ENT_QUOTES, 'UTF-8'); ?>">
      <?php foreach (bakery_navigation_usage_levels() as $usageLevel): ?>
      <span class="module-guide__legend-item module-guide__usage--<?php echo htmlspecialchars($usageLevel, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="module-guide__usage-dot" aria-hidden="true"></span>
        <strong><?php echo htmlspecialchars(bakery_navigation_usage_label($usageLevel), ENT_QUOTES, 'UTF-8'); ?></strong>
        — <?php echo htmlspecialchars(bakery_navigation_usage_description($usageLevel), ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="module-guide__roles" aria-label="Role access summary">
    <article class="module-guide__role"><h2>Administrator</h2><p>Full access to every current module, user management, and the preserved historical navigation.</p></article>
    <article class="module-guide__role"><h2>Manager</h2><p>All current operational modules: production, orders, customers, drivers, routes, products, and insights.</p></article>
    <article class="module-guide__role"><h2>Baker</h2><p>Today workspace with Mix / Bake / Pack segments across Mix Today, Daily Production, and Pack List. Weekly planning, inventory, orders, and dispatch stay with management.</p></article>
    <article class="module-guide__role"><h2>Driver</h2><p>My Route and Call HQ only. Delivery completion and proof-of-delivery actions remain available inside My Route.</p></article>
  </section>

  <?php foreach ($guideGroups as $group): ?>
  <section class="module-guide__section">
    <h2><?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p><?php echo htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="module-guide__table-wrap">
      <table>
        <thead><tr><th>Module</th><th>What it does</th><th>Usage</th><th>Access</th></tr></thead>
        <tbody>
          <?php foreach ($group['items'] as $item): ?>
          <?php $itemUsage = bakery_navigation_normalize_usage($item['usage'] ?? 'moderate'); ?>
          <tr>
            <td class="module-guide__module"><a href="<?php echo htmlspecialchars(BASE_URL . $item['href'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></a></td>
            <td><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <span class="module-guide__usage module-guide__usage--<?php echo htmlspecialchars($itemUsage, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="module-guide__usage-dot" aria-hidden="true"></span>
                <?php echo htmlspecialchars(bakery_navigation_usage_label($itemUsage), ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </td>
            <td class="module-guide__access"><?php echo htmlspecialchars(implode(', ', array_map('bakery_navigation_role_label', $item['roles'])), ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endforeach; ?>

  <aside class="module-guide__note"><strong>Historical tools:</strong> the prior full menu is available to administrators under Administration &rarr; Historical Navigation. Use it for retained workflows that have not been promoted into the current day-to-day workspace.</aside>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

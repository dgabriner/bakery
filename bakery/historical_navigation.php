<?php
/** Administrator-only, retained entry point for the prior navigation structure. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/navigation_catalog.php';

bakery_require_role(['administrator']);
$page_title = 'Historical Navigation';
$historicalGroups = bakery_historical_navigation_catalog();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
  .historical-nav { margin: 0 auto; max-width: 1180px; padding: 32px 20px 54px; }
  .historical-nav__hero { background: #f5eee1; border: 1px solid #e2cfaa; border-radius: 15px; color: #503f25; padding: clamp(20px, 3vw, 32px); }
  .historical-nav__hero h1 { color: #503f25; margin: 0; padding: 0; text-align: left; }
  .historical-nav__hero h1::after { display: none; }
  .historical-nav__hero p { line-height: 1.55; margin: 10px 0 0; max-width: 800px; }
  .historical-nav__grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: 24px; }
  .historical-nav__group { background: #fff; border: 1px solid #dfe6e3; border-radius: 10px; overflow: hidden; }
  .historical-nav__group h2 { background: #2c5b55; border-radius: 0; box-shadow: none; color: #fff; font-size: 1.02rem; margin: 0; padding: 12px 15px; }
  .historical-nav__links { list-style: none; margin: 0; padding: 7px; }
  .historical-nav__links a { border-radius: 6px; color: #1f5b53; display: block; padding: 8px 9px; text-decoration: none; }
  .historical-nav__links a:hover, .historical-nav__links a:focus-visible { background: #edf5f1; outline: none; }
  .historical-nav__source { color: #69756f; font-size: .88rem; margin-top: 22px; }
  @media (max-width: 520px) { .historical-nav { padding: 22px 12px 42px; } }
</style>

<div class="container">
<main class="historical-nav">
  <section class="historical-nav__hero">
    <h1>Historical Navigation</h1>
    <p>This retains the full menu structure that was in use before the current role-based workspace. It is administrator-only so active staff see only the tools required for their role.</p>
  </section>
  <div class="historical-nav__grid">
    <?php foreach ($historicalGroups as $label => $items): ?>
    <section class="historical-nav__group">
      <h2><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h2>
      <ul class="historical-nav__links">
        <?php foreach ($items as $item): ?>
        <li><a href="<?php echo htmlspecialchars(BASE_URL . $item[0], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8'); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>
  <p class="historical-nav__source">The original implementation is retained unchanged in <code>includes/nav_historical.php</code>.</p>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

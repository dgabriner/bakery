<?php
/**
 * Current role-aware workspace navigation.
 * The complete previous menu is retained in nav_historical.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/navigation_catalog.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? '', '.php');
$navUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$navRole = $navUser['role_slug'] ?? '';
$navSelectedDriverId = function_exists('bakery_get_selected_driver_id') ? bakery_get_selected_driver_id() : 0;
$navDriverRouteHref = BASE_URL . 'driver.php' . ($navSelectedDriverId > 0 ? ('?driver_id=' . (int)$navSelectedDriverId) : '');
$navBakerDate = date('Y-m-d', strtotime('+1 day'));
$navBakerWeekday = function_exists('bakery_standing_day_from_date')
    ? (int)bakery_standing_day_from_date($navBakerDate)
    : (int)date('N', strtotime($navBakerDate));

if (!function_exists('bakery_nav_is_active')) {
    function bakery_nav_is_active(array $item, $page) {
        return basename($item['href'], '.php') === $page;
    }
}
?>
<style>
  .bakery-nav { background: #173f3c; border-bottom: 1px solid #28615d; box-shadow: 0 2px 10px rgba(28, 48, 44, .14); color: #fff; font-family: "Segoe UI", system-ui, sans-serif; position: sticky; top: 0; z-index: 900; }
  .bakery-nav__inner { align-items: center; display: flex; gap: 16px; margin: 0 auto; max-width: 1500px; min-height: 58px; padding: 8px 18px; }
  .bakery-nav__brand { align-items: center; color: #fff; display: inline-flex; flex: 0 0 auto; font-size: 1.03rem; font-weight: 760; gap: 8px; letter-spacing: .01em; text-decoration: none; white-space: nowrap; }
  .bakery-nav__brand:hover { color: #ffe7b0; }
  .sf20-badge { background: #ffe7b0; border-radius: 999px; color: #173f3c; font-size: .72rem; font-weight: 800; letter-spacing: .04em; line-height: 1; padding: 4px 8px; }
  .bakery-nav__groups { align-items: center; display: flex; flex: 1 1 auto; gap: 7px; justify-content: flex-end; min-width: 0; }
  .bakery-nav__group { position: relative; }
  .bakery-nav__group summary { align-items: center; border: 1px solid transparent; border-radius: 7px; color: #eff8f6; cursor: pointer; display: flex; font-size: .89rem; font-weight: 650; gap: 6px; list-style: none; padding: 9px 10px; user-select: none; white-space: nowrap; }
  .bakery-nav__group summary::-webkit-details-marker { display: none; }
  .bakery-nav__group summary::after { content: "⌄"; font-size: 1rem; line-height: .75; transition: transform .15s ease; }
  .bakery-nav__group[open] summary, .bakery-nav__group summary:hover, .bakery-nav__group summary:focus-visible { background: rgba(255, 255, 255, .11); border-color: rgba(255, 255, 255, .18); outline: none; }
  .bakery-nav__group[open] summary::after { transform: rotate(180deg); }
  .bakery-nav__group--active summary { background: #d88346; border-color: #d88346; color: #fff; }
  .bakery-nav__panel { background: #fffdf9; border: 1px solid #d8e2dd; border-radius: 10px; box-shadow: 0 14px 28px rgba(21, 49, 45, .23); color: #233a36; display: grid; gap: 2px; min-width: 285px; padding: 7px; position: absolute; right: 0; top: calc(100% + 7px); z-index: 901; }
  .bakery-nav__item { border-radius: 7px; color: #233a36; display: block; padding: 9px 10px; text-decoration: none; }
  .bakery-nav__item:hover, .bakery-nav__item:focus-visible { background: #eaf4ee; outline: none; }
  .bakery-nav__item--active { background: #e5f1ea; box-shadow: inset 3px 0 0 #24746a; }
  .bakery-nav__item-label { display: block; font-size: .9rem; font-weight: 730; }
  .bakery-nav__item-description { color: #61706c; display: block; font-size: .75rem; line-height: 1.35; margin-top: 2px; }
  .bakery-nav--focused .bakery-nav__inner { justify-content: space-between; max-width: 1040px; }
  .bakery-nav--focused .bakery-nav__groups { flex: 0 1 auto; }
  .bakery-nav__direct { border: 1px solid rgba(255, 255, 255, .17); border-radius: 7px; color: #fff; font-size: .9rem; font-weight: 700; padding: 9px 12px; text-decoration: none; white-space: nowrap; }
  .bakery-nav__direct:hover, .bakery-nav__direct:focus-visible { background: rgba(255, 255, 255, .12); outline: none; }
  .bakery-nav__direct--active { background: #d88346; border-color: #d88346; }
  @media (max-width: 900px) {
    .bakery-nav__inner { align-items: flex-start; flex-direction: column; gap: 8px; padding: 10px 14px; }
    .bakery-nav__groups { justify-content: flex-start; max-width: 100%; overflow-x: auto; padding-bottom: 2px; width: 100%; }
    .bakery-nav__group summary { padding: 8px 9px; }
    .bakery-nav__panel { left: 0; right: auto; }
  }
  @media (max-width: 520px) {
    .bakery-nav__inner { padding: 9px 12px; }
    .bakery-nav__brand { font-size: .96rem; }
    .bakery-nav__group summary { font-size: .83rem; }
    .bakery-nav__panel { min-width: min(285px, calc(100vw - 24px)); }
    .bakery-nav--focused .bakery-nav__groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; width: 100%; }
    .bakery-nav--focused .bakery-nav__direct { text-align: center; }
  }
</style>

<?php if ($navRole === 'driver'): ?>
<nav class="bakery-nav bakery-nav--focused" aria-label="Driver workspace">
  <div class="bakery-nav__inner">
    <a class="bakery-nav__brand" href="<?php echo htmlspecialchars($navDriverRouteHref, ENT_QUOTES, 'UTF-8'); ?>">Driver workspace <span class="sf20-badge">SF 2.0</span></a>
    <div class="bakery-nav__groups">
      <a class="bakery-nav__direct <?php echo $currentPage === 'driver' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars($navDriverRouteHref, ENT_QUOTES, 'UTF-8'); ?>">My route</a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'call_headquarters' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>call_headquarters.php">Call HQ</a>
    </div>
  </div>
</nav>
<?php elseif ($navRole === 'baker'): ?>
<nav class="bakery-nav bakery-nav--focused" aria-label="Baker workspace">
  <div class="bakery-nav__inner">
    <span class="bakery-nav__brand">Baker workspace <span class="sf20-badge">SF 2.0</span></span>
    <div class="bakery-nav__groups">
      <a class="bakery-nav__direct <?php echo $currentPage === 'production' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>production.php?date=<?php echo urlencode($navBakerDate); ?>">Daily production</a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'pack_list' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>pack_list.php?day=<?php echo (int)$navBakerWeekday; ?>">Pack list</a>
    </div>
  </div>
</nav>
<?php else: ?>
<?php $navGroups = bakery_navigation_groups_for_role($navRole); ?>
<nav class="bakery-nav" aria-label="Operations workspace">
  <div class="bakery-nav__inner">
    <a class="bakery-nav__brand" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>index.php">Bakery workspace <span class="sf20-badge">SF 2.0</span></a>
    <div class="bakery-nav__groups">
      <?php foreach ($navGroups as $group): ?>
      <?php $groupActive = false; foreach ($group['items'] as $item) { if (bakery_nav_is_active($item, $currentPage)) { $groupActive = true; break; } } ?>
      <details class="bakery-nav__group <?php echo $groupActive ? 'bakery-nav__group--active' : ''; ?>"<?php echo $groupActive ? ' open' : ''; ?>>
        <summary><?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></summary>
        <div class="bakery-nav__panel">
          <?php foreach ($group['items'] as $item): ?>
          <?php $itemActive = bakery_nav_is_active($item, $currentPage); ?>
          <a class="bakery-nav__item <?php echo $itemActive ? 'bakery-nav__item--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . $item['href'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $itemActive ? ' aria-current="page"' : ''; ?>>
            <span class="bakery-nav__item-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="bakery-nav__item-description"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</nav>
<?php endif; ?>

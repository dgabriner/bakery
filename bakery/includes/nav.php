<?php
/**
 * Current role-aware workspace navigation.
 * The complete previous menu is retained in nav_historical.php.
 */
// TEST: production deploy trigger check — 2026-08-02
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/navigation_catalog.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? '', '.php');
$navUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$navRole = $navUser['role_slug'] ?? '';
$navSelectedDriverId = function_exists('bakery_get_selected_driver_id') ? bakery_get_selected_driver_id() : 0;
$navDriverRouteHref = BASE_URL . 'driver.php' . ($navSelectedDriverId > 0 ? ('?driver_id=' . (int)$navSelectedDriverId) : '');
$navDriverStopsHref = BASE_URL . 'driver_stops.php';
$navDriverPackHref = BASE_URL . 'pack_list.php?date=' . urlencode(date('Y-m-d'));
$navBakerDate = date('Y-m-d', strtotime('+1 day'));
$navBakerWeekday = function_exists('bakery_standing_day_from_date')
    ? (int)bakery_standing_day_from_date($navBakerDate)
    : (int)date('N', strtotime($navBakerDate));

if (!function_exists('bakery_nav_is_active')) {
    function bakery_nav_is_active(array $item, $page) {
        $path = parse_url($item['href'], PHP_URL_PATH);
        return basename((string)$path, '.php') === $page;
    }
}
?>
<?php
$navLogoutAction = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$navLogoutForm = function_exists('bakery_csrf_field')
    ? '<form class="bakery-nav__logout" method="post" action="' . $navLogoutAction . '">'
        . bakery_csrf_field()
        . '<button class="bakery-nav__logout-btn" type="submit" aria-label="' . htmlspecialchars(bakery_t('common.log_out'), ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="bakery-nav__label-full" aria-hidden="true">' . htmlspecialchars(bakery_t('common.log_out'), ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="bakery-nav__label-short" aria-hidden="true">' . htmlspecialchars(bakery_t('common.log_out_short'), ENT_QUOTES, 'UTF-8') . '</span>'
        . '</button></form>'
    : '';
?>
<?php if (bakery_is_driver_route_role($navRole)): ?>
<?php
$navDriverDateRaw = $_GET['date'] ?? date('Y-m-d');
$navDriverDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$navDriverDateRaw);
if (!$navDriverDateObject || $navDriverDateObject->format('Y-m-d') !== (string)$navDriverDateRaw) {
    $navDriverDateObject = new DateTimeImmutable('today');
}
$navDriverDateIsToday = $navDriverDateObject->format('Y-m-d') === date('Y-m-d');
$navDriverDateShort = $navDriverDateIsToday ? bakery_t('common.today') : $navDriverDateObject->format('M j');
$navDriverShowDateToggle = $currentPage === 'driver';
$navSelectedDriverName = function_exists('bakery_get_selected_driver_name') ? bakery_get_selected_driver_name() : '';
if ($navSelectedDriverName === '' && $navUser) {
    $navSelectedDriverName = (string)($navUser['display_name'] ?? '');
}
?>
<nav class="bakery-nav bakery-nav--focused bakery-nav--driver<?php echo $navDriverShowDateToggle ? ' bakery-nav--with-date' : ''; ?>" aria-label="<?php bakery_te('nav.driver_workspace_aria'); ?>">
  <?php if ($navSelectedDriverName !== ''): ?>
  <div class="bakery-nav__driver-bar" aria-label="<?php bakery_te('nav.active_driver'); ?>">
    <span class="bakery-nav__live-dot" aria-hidden="true"></span>
    <span class="bakery-nav__driver-name"><?php echo htmlspecialchars($navSelectedDriverName, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php
      $navTomorrowDate = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
      $navOnTomorrow = $currentPage === 'driver' && $navDriverDateObject->format('Y-m-d') === $navTomorrowDate;
      $navTomorrowHref = BASE_URL . 'driver.php?date=' . rawurlencode($navTomorrowDate);
    ?>
    <a class="bakery-nav__tomorrow<?php echo $navOnTomorrow ? ' bakery-nav__tomorrow--active' : ''; ?>" href="<?php echo htmlspecialchars($navTomorrowHref, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $navOnTomorrow ? ' aria-current="page"' : ''; ?>><?php bakery_te('nav.tomorrow_route'); ?></a>
    <?php if ($navDriverShowDateToggle): ?>
    <span class="bakery-nav__date-hint"><?php echo htmlspecialchars($navDriverDateShort, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="bakery-nav__inner">
    <a class="bakery-nav__brand" href="<?php echo htmlspecialchars($navDriverRouteHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('nav.driver_workspace'); ?></a>
    <div class="bakery-nav__groups">
      <a class="bakery-nav__direct <?php echo $currentPage === 'driver_stops' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars($navDriverStopsHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php bakery_te('nav.stops'); ?>"<?php echo $currentPage === 'driver_stops' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.stops'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.stops_short'); ?></span>
      </a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'pack_list' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars($navDriverPackHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php bakery_te('nav.pack_list'); ?>"<?php echo $currentPage === 'pack_list' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.pack_list'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.pack_list_short'); ?></span>
      </a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'driver' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars($navDriverRouteHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php bakery_te('nav.my_route'); ?>"<?php echo $currentPage === 'driver' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.my_route'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.my_route_short'); ?></span>
      </a>
      <?php if ($navDriverShowDateToggle): ?>
      <button type="button" class="bakery-nav__direct bakery-nav__date-toggle" id="routeDateNavToggle" aria-expanded="false" aria-controls="routeDateDisclosure" aria-label="<?php bakery_te('nav.choose_route_date'); ?>">
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.date'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php echo htmlspecialchars($navDriverDateShort, ENT_QUOTES, 'UTF-8'); ?></span>
      </button>
      <?php endif; ?>
      <a class="bakery-nav__direct <?php echo $currentPage === 'call_headquarters' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>call_headquarters.php" aria-label="<?php bakery_te('nav.call_hq'); ?>"<?php echo $currentPage === 'call_headquarters' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.call_hq'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.call_hq'); ?></span>
      </a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'qr_login' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>qr_login.php" aria-label="Customer login QR"<?php echo $currentPage === 'qr_login' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true">Customer login</span>
        <span class="bakery-nav__label-short" aria-hidden="true">QR login</span>
      </a>
      <?php $langSwitchVariant = 'nav'; require __DIR__ . '/language_switch.php'; ?>
      <?php echo $navLogoutForm; ?>
    </div>
  </div>
</nav>
<?php elseif ($navRole === 'baker'): ?>
<nav class="bakery-nav bakery-nav--focused bakery-nav--baker" aria-label="<?php bakery_te('nav.baker_workspace_aria'); ?>">
  <div class="bakery-nav__inner">
    <span class="bakery-nav__brand"><?php bakery_te('nav.baker_workspace'); ?></span>
    <div class="bakery-nav__groups">
      <a class="bakery-nav__direct <?php echo $currentPage === 'production' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>production.php?date=<?php echo urlencode($navBakerDate); ?>" aria-label="<?php bakery_te('nav.daily_production'); ?>"<?php echo $currentPage === 'production' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.daily_production'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.daily_production_short'); ?></span>
      </a>
      <a class="bakery-nav__direct <?php echo $currentPage === 'pack_list' ? 'bakery-nav__direct--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>pack_list.php?date=<?php echo urlencode($navBakerDate); ?>" aria-label="<?php bakery_te('nav.pack_list'); ?>"<?php echo $currentPage === 'pack_list' ? ' aria-current="page"' : ''; ?>>
        <span class="bakery-nav__label-full" aria-hidden="true"><?php bakery_te('nav.pack_list'); ?></span>
        <span class="bakery-nav__label-short" aria-hidden="true"><?php bakery_te('nav.pack_list_short'); ?></span>
      </a>
      <?php $langSwitchVariant = 'nav'; require __DIR__ . '/language_switch.php'; ?>
      <?php echo $navLogoutForm; ?>
    </div>
  </div>
</nav>
<?php else: ?>
<?php
  $navSections = bakery_navigation_sections_for_role($navRole);
  $navDisplayName = htmlspecialchars((string)($navUser['display_name'] ?? bakery_t('common.staff')), ENT_QUOTES, 'UTF-8');
  $navRoleLabel = htmlspecialchars(function_exists('bakery_navigation_role_label') ? bakery_navigation_role_label($navRole) : $navRole, ENT_QUOTES, 'UTF-8');
  $navSelectedDriverName = function_exists('bakery_get_selected_driver_name') ? bakery_get_selected_driver_name() : '';
  $navOpsLogoutForm = function_exists('bakery_csrf_field')
      ? '<form class="bakery-nav__logout" method="post" action="' . $navLogoutAction . '">'
          . bakery_csrf_field()
          . '<button class="bakery-nav__logout-btn" type="submit">' . htmlspecialchars(bakery_t('common.log_out'), ENT_QUOTES, 'UTF-8') . '</button></form>'
      : '';
?>
<nav class="bakery-nav bakery-nav--ops" aria-label="<?php bakery_te('nav.ops_workspace_aria'); ?>">
  <div class="bakery-nav__inner">
    <a class="bakery-nav__brand" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>index.php">
      <span class="bakery-nav__brand-full"><?php bakery_te('nav.brand_full'); ?></span>
      <span class="bakery-nav__brand-short"><?php bakery_te('nav.brand_short'); ?></span>
    </a>
    <a class="bakery-nav__manager-shortcut<?php echo $currentPage === 'manager' ? ' bakery-nav__manager-shortcut--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>manager.php"<?php echo $currentPage === 'manager' ? ' aria-current="page"' : ''; ?>>
      <span class="bakery-nav__label-full"><?php bakery_te('nav.manager_mode'); ?></span>
      <span class="bakery-nav__label-short"><?php bakery_te('nav.manager_mode_short'); ?></span>
    </a>
    <a class="bakery-nav__route-shortcut" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>driver.php?change_driver=1"><?php bakery_te('nav.my_route'); ?></a>
    <button class="bakery-nav__menu-toggle" type="button" aria-controls="bakeryWorkspaceMenu" aria-expanded="false">
      <span class="bakery-nav__menu-toggle-icon" aria-hidden="true">&#9776;</span>
      <span class="bakery-nav__menu-toggle-open"><?php bakery_te('common.menu'); ?></span>
      <span class="bakery-nav__menu-toggle-close"><?php bakery_te('common.close'); ?></span>
    </button>
    <div class="bakery-nav__drawer" id="bakeryWorkspaceMenu">
      <div class="bakery-nav__account">
        <div class="bakery-nav__account-meta">
          <span class="bakery-nav__account-name"><?php echo $navDisplayName; ?></span>
          <span class="bakery-nav__account-role"><?php echo $navRoleLabel; ?><?php
            if ($navSelectedDriverId > 0) {
                echo ' · ' . htmlspecialchars(bakery_t('role.driving_as_nav', ['name' => $navSelectedDriverName !== '' ? $navSelectedDriverName : ('#' . $navSelectedDriverId)]), ENT_QUOTES, 'UTF-8');
            }
          ?></span>
        </div>
        <?php $langSwitchVariant = 'nav'; require __DIR__ . '/language_switch.php'; ?>
        <?php echo $navOpsLogoutForm; ?>
      </div>
      <?php
        $navUsageLegend = '<div class="bakery-nav__usage-legend" aria-label="'
          . htmlspecialchars(bakery_t('nav.usage.legend_aria'), ENT_QUOTES, 'UTF-8')
          . '">';
        foreach (bakery_navigation_usage_levels() as $usageLevel) {
            $navUsageLegend .= '<span class="bakery-nav__usage-swatch bakery-nav__usage-swatch--'
              . htmlspecialchars($usageLevel, ENT_QUOTES, 'UTF-8')
              . '" title="'
              . htmlspecialchars(bakery_navigation_usage_description($usageLevel), ENT_QUOTES, 'UTF-8')
              . '"></span>';
        }
        $navUsageLegend .= '</div>';
      ?>
      <div class="bakery-nav__usage-legend-slot bakery-nav__usage-legend-slot--drawer">
        <?php echo $navUsageLegend; ?>
      </div>
      <div class="bakery-nav__groups">
        <?php foreach ($navSections as $section): ?>
        <?php $navSectionId = 'bakery-nav-section-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$section['key']); ?>
        <section class="bakery-nav__section bakery-nav__section--<?php echo htmlspecialchars((string)$section['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="<?php echo htmlspecialchars($navSectionId, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="bakery-nav__section-header" id="<?php echo htmlspecialchars($navSectionId, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="bakery-nav__section-label"><?php echo htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="bakery-nav__section-description"><?php echo htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="bakery-nav__section-groups">
          <?php foreach ($section['groups'] as $group): ?>
        <?php $groupActive = false; foreach ($group['items'] as $item) { if (bakery_nav_is_active($item, $currentPage)) { $groupActive = true; break; } } ?>
        <details class="bakery-nav__group <?php echo $groupActive ? 'bakery-nav__group--active' : ''; ?>">
          <summary><?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></summary>
          <div class="bakery-nav__panel">
            <div class="bakery-nav__usage-legend-slot bakery-nav__usage-legend-slot--panel">
              <?php echo $navUsageLegend; ?>
            </div>
            <?php foreach ($group['items'] as $item): ?>
            <?php
              $itemActive = bakery_nav_is_active($item, $currentPage);
              $itemUsage = bakery_navigation_normalize_usage($item['usage'] ?? 'moderate');
            ?>
            <a class="bakery-nav__item bakery-nav__item--usage-<?php echo htmlspecialchars($itemUsage, ENT_QUOTES, 'UTF-8'); ?> <?php echo $itemActive ? 'bakery-nav__item--active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . $item['href'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $itemActive ? ' aria-current="page"' : ''; ?> data-usage="<?php echo htmlspecialchars($itemUsage, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(bakery_navigation_usage_description($itemUsage), ENT_QUOTES, 'UTF-8'); ?>">
              <span class="bakery-nav__usage-mark" aria-hidden="true"></span>
              <span class="bakery-nav__item-copy">
                <span class="bakery-nav__item-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="bakery-nav__item-description"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
        </details>
          <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <button class="bakery-nav__backdrop" type="button" tabindex="-1" aria-label="<?php bakery_te('common.close_menu'); ?>" hidden></button>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.bakery-nav:not(.bakery-nav--focused)').forEach(function (nav) {
    var menuToggle = nav.querySelector('.bakery-nav__menu-toggle');
    var backdrop = nav.querySelector('.bakery-nav__backdrop');
    var groups = Array.prototype.slice.call(nav.querySelectorAll('.bakery-nav__group'));

    function closeGroups(except) {
      groups.forEach(function (group) {
        if (group !== except) {
          group.removeAttribute('open');
        }
      });
    }

    function setMenuOpen(isOpen) {
      nav.classList.toggle('bakery-nav--menu-open', isOpen);
      document.body.classList.toggle('bakery-nav-drawer-open', isOpen);
      if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
      if (backdrop) {
        if (isOpen) {
          backdrop.removeAttribute('hidden');
        } else {
          backdrop.setAttribute('hidden', 'hidden');
        }
      }
      if (!isOpen) {
        closeGroups();
      }
    }

    function closeMenu() {
      setMenuOpen(false);
    }

    groups.forEach(function (group) {
      group.addEventListener('toggle', function () {
        if (group.open) {
          closeGroups(group);
        }
      });
    });

    if (menuToggle) {
      menuToggle.addEventListener('click', function () {
        setMenuOpen(!nav.classList.contains('bakery-nav--menu-open'));
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', closeMenu);
    }

    nav.addEventListener('click', function (event) {
      if (event.target.closest('.bakery-nav__item')) {
        closeMenu();
      }
    });

    document.addEventListener('click', function (event) {
      if (!nav.contains(event.target)) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeMenu();
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 900) {
        closeMenu();
      }
    });
  });
});
</script>
<?php endif; ?>

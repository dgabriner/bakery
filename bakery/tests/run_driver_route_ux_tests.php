<?php
/**
 * Driver route UX audit item 3: delivery-window clock, progress copy, narrow layout.
 *
 * Source contract only. Runs without a database so it can sit beside the photo UI suite.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = 0;

function driver_ux_assert(string $label, bool $condition): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL  {$label}\n";
        $failures++;
        return;
    }
    echo "PASS  {$label}\n";
}

function driver_ux_fill(string $template, array $vars): string
{
    $out = $template;
    foreach ($vars as $key => $value) {
        $out = str_replace(':' . $key, (string)$value, $out);
    }
    return $out;
}

$page = (string)file_get_contents($root . '/driver.php')
    . (string)file_get_contents($root . '/includes/driver_route.js');
$styles = str_replace("\r\n", "\n", (string)file_get_contents($root . '/css/driver.css'));
$mapJs = (string)file_get_contents($root . '/includes/driver_route_map.js');
$english = require $root . '/lang/en.php';
$spanish = require $root . '/lang/es.php';

foreach ([
    'driver.map_window_opens',
    'driver.map_window_due',
    'driver.map_window_late',
    'driver.map_window_by',
] as $key) {
    driver_ux_assert(
        "{$key} keeps the :time placeholder for the map script",
        isset($english[$key], $spanish[$key])
            && strpos((string)$english[$key], ':time') !== false
            && strpos((string)$spanish[$key], ':time') !== false
    );
}

$catalogStart = strpos($page, "window.__DRIVER_PAGE_I18N__");
$catalogEnd = $catalogStart === false ? false : strpos($page, '], JSON_UNESCAPED_UNICODE)', $catalogStart);
$catalog = ($catalogStart !== false && $catalogEnd !== false)
    ? substr($page, $catalogStart, $catalogEnd - $catalogStart)
    : '';

driver_ux_assert(
    'map window catalog is not baked with the literal __TIME__ token',
    $catalog !== ''
        && strpos($catalog, '__TIME__') === false
        && strpos($catalog, "bakery_t('driver.map_window_opens')") !== false
        && strpos($catalog, "bakery_t('driver.map_window_due')") !== false
        && strpos($catalog, "bakery_t('driver.map_window_late')") !== false
        && strpos($catalog, "bakery_t('driver.map_window_by')") !== false
        && strpos($catalog, "bakery_t('driver.done_count')") !== false
);

$spanishDue = driver_ux_fill((string)$spanish['driver.map_window_due'], ['time' => '6:00 PM']);
$englishBy = driver_ux_fill((string)$english['driver.map_window_by'], ['time' => '6:00 PM']);
driver_ux_assert(
    'Spanish due window shows the clock instead of __TIME__',
    $spanishDue === 'Antes de 6:00 PM' && strpos($spanishDue, '__TIME__') === false
);
driver_ux_assert(
    'English by window shows the clock instead of __TIME__',
    $englishBy === 'By 6:00 PM' && strpos($englishBy, '__TIME__') === false
);

driver_ux_assert(
    'desktop progress uses driver.done_count',
    strpos($page, "id=\"routeProgressText\"><?php echo htmlspecialchars(bakery_t('driver.done_count'") !== false
);

driver_ux_assert(
    'refresh script uses the translated done count in both progress labels',
    strpos($page, 'function formatDoneCount(done, total)') !== false
        && strpos($page, "di.done_count || ':done / :total done'") !== false
        && strpos($page, 'var doneLabel = formatDoneCount(completed, total);') !== false
        && strpos($page, "text.textContent = completed + ' of ' + total + ' done'") === false
        && strpos($page, "mobileText.textContent = completed + ' / ' + total + ' done'") === false
);

$spanishDone = driver_ux_fill((string)$spanish['driver.done_count'], ['done' => '0', 'total' => '1']);
$englishDone = driver_ux_fill((string)$english['driver.done_count'], ['done' => '0', 'total' => '1']);
driver_ux_assert(
    'progress copy is translated in Spanish and English',
    $spanishDone === '0 / 1 hechas' && $englishDone === '0 / 1 done'
);

driver_ux_assert(
    'stop piece count uses driver.pcs',
    strpos($page, "bakery_te('driver.pcs')") !== false
        && strpos($page, '?> pcs</span>') === false
);

driver_ux_assert(
    'route grid minimum can shrink below 390px',
    strpos($styles, 'minmax(390px') === false
        && strpos($styles, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);') !== false
);

$headingAt = strpos($styles, '.stop-list-heading-row {');
$headingRule = $headingAt === false ? '' : substr($styles, $headingAt, 280);
driver_ux_assert(
    'stop list heading row wraps and its children can shrink',
    strpos($headingRule, 'flex-wrap: wrap;') !== false
        && strpos($headingRule, 'min-width: 0;') !== false
        && strpos($styles, ".stop-list-heading-row > * {\n    min-width: 0;\n}") !== false
        && strpos($styles, 'overflow-wrap: break-word;') !== false
);

$mobileDashboard = '';
$mobileAt = strpos($styles, '.route-dashboard:not(.route-dashboard--prep) {');
if ($mobileAt !== false) {
    $mobileDashboard = substr($styles, $mobileAt, 220);
}
driver_ux_assert(
    'narrow route column stretches instead of growing with the heading',
    strpos($mobileDashboard, 'align-items: stretch;') !== false
);

$setYouAt = strpos($mapJs, 'function setYou(');
$setYouBody = $setYouAt === false ? '' : substr($mapJs, $setYouAt, 900);
driver_ux_assert(
    'Directions refresh the driver leg only after 400 m and 2 minutes',
    strpos($mapJs, 'LEG_REFRESH_MS = 120000') !== false
        && strpos($mapJs, 'LEG_REFRESH_METERS = 400') !== false
        && strpos($mapJs, 'function driverLegIsStale()') !== false
);
driver_ux_assert(
    'a GPS fix no longer clears the Directions cache key',
    $setYouBody !== '' && strpos($setYouBody, "lastPathKey = ''") === false
);
driver_ux_assert(
    'GPS watch pauses while the page is hidden',
    strpos($mapJs, "'visibilitychange'") !== false && strpos($mapJs, 'clearWatch') !== false
);
driver_ux_assert(
    'map keeps waypoint order and never saves a route order',
    strpos($mapJs, 'optimizeWaypoints: false') !== false && strpos($mapJs, 'reorder_route') === false
);

$routeJs = (string)file_get_contents($root . '/includes/driver_route.js');
$completeDelivery = (string)file_get_contents($root . '/complete_delivery.php');
$refreshJs = (string)file_get_contents($root . '/includes/client_refresh.js');
driver_ux_assert(
    'My Route reloads only from the route-changed button',
    substr_count($routeJs, 'location.reload') === 1
        && strpos($routeJs, "refresh.addEventListener('click', function () { window.location.reload(); });") !== false
);
driver_ux_assert(
    'route snapshot answers unchanged with only the hash',
    strpos($completeDelivery, "case 'route_snapshot':") !== false
        && strpos($completeDelivery, "'unchanged' => true") !== false
        && strpos($routeJs, "body.set('action', 'route_snapshot');") !== false
);
driver_ux_assert(
    'a new build waits while a stop, sheet, or reorder is open',
    strpos($refreshJs, 'window.bakeryClientRefreshHold') !== false
        && strpos($routeJs, 'window.bakeryClientRefreshHold = function') !== false
);
driver_ux_assert(
    'phone Back closes the open layer before leaving the page',
    strpos($routeJs, "pushLayer('capture')") !== false
        && strpos($routeJs, "pushLayer('problem')") !== false
        && strpos($routeJs, "pushLayer('adjust')") !== false
        && strpos($routeJs, "window.addEventListener('popstate'") !== false
);
driver_ux_assert(
    'Suggest order previews and never saves until the driver accepts',
    strpos($routeJs, 'function suggestRouteOrder') !== false
        && strpos($routeJs, 'function acceptSuggestedOrder') !== false
        && strpos($mapJs, 'optimizeWaypoints: true') !== false
);

$openStopFn = '';
$openStopAt = strpos($routeJs, 'function tryOpenStopFromUrl()');
if ($openStopAt !== false) {
    $openStopFn = substr($routeJs, $openStopAt, 1800);
}
driver_ux_assert(
    'open_stop puts that daily order on the hero instead of the photo modal',
    strpos($page, "\$_GET['open_stop']") !== false
        && strpos($page, '$heroStop = $candidateStop;') !== false
        && strpos($openStopFn, "stop.closest('#stopList')") !== false
        && strpos($openStopFn, 'renderNextStop(stop);') !== false
        && strpos($openStopFn, 'openHeroOrders(orderId);') !== false
        && strpos($openStopFn, 'openStopDeliveryFromEl(stop, { expandOrders: expandOrders, autoOpenCamera: false })') !== false
);
driver_ux_assert(
    'view_orders expands the hero order list',
    strpos($routeJs, 'function openHeroOrders(') !== false
        && strpos($routeJs, "e.target.closest('#nextStopDetailsToggle')") !== false
        && strpos($page, '$openStopOrders') !== false
);
driver_ux_assert(
    'prep map header counts pending prep stops and saves Route complete for an empty queue',
    strpos($mapJs, '#routePrepList .route-prep-stop') !== false
        && strpos($mapJs, "el.closest('#routePrepList')") !== false
        && strpos($mapJs, "status !== 'delivered' && status !== 'cancelled'") !== false
        && strpos($mapJs, "bits.push(t('route_complete', 'Route complete'));") !== false
);

$spanishNext = (string)$spanish['driver.next_stop'];
$spanishAfter = (string)$spanish['driver.map_remaining_after'];
$spanishOff = (string)$spanish['driver.map_no_location'];
$spanishFollow = (string)$spanish['driver.map_follow'];
$spanishBy = (string)$spanish['driver.map_window_by'];
$spanishMore = (string)$spanish['driver.lines_more'];
driver_ux_assert(
    'driver chrome strings QA saw in English are Spanish in es.php',
    $spanishNext === 'Siguiente parada'
        && $spanishAfter === ':count después'
        && $spanishOff === 'Ubicación apagada'
        && $spanishFollow === 'Seguir'
        && $spanishBy === 'Antes de :time'
        && $spanishMore === '+:count más'
        && (string)$english['driver.next_stop'] === 'Next stop'
        && (string)$english['driver.map_window_by'] === 'By :time'
        && (string)$english['driver.lines_more'] === '+:count more'
        && (string)$english['driver.map_follow'] === 'Follow'
);
$navPhp = (string)file_get_contents($root . '/includes/nav.php');
$stopsPhp = (string)file_get_contents($root . '/driver_stops.php');
$navAssignAt = strpos($navPhp, '$navDriverDateShort = ');
$navAssign = $navAssignAt === false ? '' : substr($navPhp, $navAssignAt, 280);
driver_ux_assert(
    'driver header and chip dates are locale-aware and the nav chip is not English M j',
    strpos($page, "bakery_driver_chrome_date(\$selectedDateObject, 'weekday')") !== false
        && strpos($page, "bakery_driver_chrome_date(\$selectedDateObject, 'compact')") !== false
        && strpos($page, 'data-driver-date="weekday"') !== false
        && strpos($page, 'data-driver-date="compact"') !== false
        && strpos($page, "'routeDate' => \$selectedDate") !== false
        && strpos($navAssign, 'bakery_driver_chrome_date(') !== false
        && strpos($navAssign, "format('M j')") === false
        && strpos($stopsPhp, 'bakery_driver_chrome_date(') !== false
        && strpos($routeJs, 'function bakeryFormatDriverDate(') !== false
        && strpos($routeJs, 'function applyDriverDates(') !== false
        && strpos($routeJs, "header.textContent = bakeryFormatDriverDate(iso, 'weekday')") !== false
        && strpos($routeJs, "chip.textContent = bakeryFormatDriverDate(iso, 'compact')") !== false
        && strpos($routeJs, 'toLocaleDateString') === false
        && strpos($routeJs, 'Intl.DateTimeFormat') === false
        && strpos($mapJs, 'toLocaleDateString') === false
        && strpos($mapJs, 'Intl.DateTimeFormat') === false
);

$dateScript = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const sandbox = {
  window: { __BAKERY_LOCALE__: 'es', __DRIVER_PAGE_I18N__: {}, __DRIVER_ROUTE_BOOT__: { locale: 'es', routeDate: '2026-11-24' } },
  document: {
    addEventListener: function () {},
    getElementById: function () { return null; },
    querySelector: function () { return null; },
    querySelectorAll: function () { return []; },
    documentElement: { classList: { toggle: function () {} } },
    body: { classList: { add: function () {}, remove: function () {}, contains: function () { return false; }, toggle: function () {} } }
  },
  history: { replaceState: function () {}, pushState: function () {}, back: function () {}, state: null },
  location: { search: '', pathname: '/driver.php', hash: '', assign: function () {} },
  URLSearchParams: URLSearchParams,
  console: console,
  navigator: {},
  fetch: function () { return Promise.resolve({ json: function () { return Promise.resolve({}); } }); },
  setTimeout: function () {},
  clearTimeout: function () {},
  localStorage: { getItem: function () { return null; }, setItem: function () {} },
  sessionStorage: { getItem: function () { return null; }, setItem: function () {} }
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);
const format = sandbox.window.bakeryFormatDriverDate;
const esHeader = format('2026-11-24', 'weekday');
const esChip = format('2026-11-24', 'compact');
sandbox.window.__BAKERY_LOCALE__ = 'en';
const enHeader = format('2026-11-24', 'weekday');
const enChip = format('2026-11-24', 'compact');
const monthFirst = /^(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|January|February|March|April|June|July|August|September|October|November|December) \d/;
if (esHeader !== 'martes 24 de noviembre' || monthFirst.test(esHeader)) {
  console.error('es header ' + esHeader);
  process.exit(1);
}
if (esChip !== '24 nov' || monthFirst.test(esChip) || esChip === 'Nov 24') {
  console.error('es chip ' + esChip);
  process.exit(1);
}
if (enHeader !== 'November 24' || enChip !== 'Nov 24') {
  console.error('en ' + enHeader + ' / ' + enChip);
  process.exit(1);
}
console.log(esHeader + ' | ' + esChip + ' | ' + enHeader + ' | ' + enChip);
JS;
$dateScriptPath = sys_get_temp_dir() . '/driver_date_format_check.js';
file_put_contents($dateScriptPath, $dateScript);
$dateNode = shell_exec('node ' . escapeshellarg($dateScriptPath) . ' ' . escapeshellarg($root . '/includes/driver_route.js') . ' 2>&1');
driver_ux_assert(
    'driver JS date formatter prints Spanish day-first and leaves English month-day',
    is_string($dateNode) && strpos($dateNode, 'martes 24 de noviembre | 24 nov | November 24 | Nov 24') !== false
);

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver route UX checks passed\n";
exit(0);

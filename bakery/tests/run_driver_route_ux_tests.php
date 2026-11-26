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

driver_ux_assert(
    'filled photo buttons are not painted the tan link color',
    strpos($styles, "body.driver-workflow-page .contact-actions .photo-complete-btn {\n    border-color: #e9bf87;\n    color: #9b5c16;\n}") !== false
        && strpos($styles, "body.driver-workflow-page .photo-complete-btn {\n    border-color: #e9bf87;\n    color: #9b5c16;\n}") === false
        && strpos($styles, 'body.driver-workflow-page .route-btn.photo-complete-btn') !== false
);
driver_ux_assert(
    'hero actions are Photo, Navigate, and Problem with inline icons',
    strpos($page, "bakery_driver_action_label('photo', bakery_t('driver.photo'))") !== false
        && strpos($page, "bakery_driver_action_label('navigate', bakery_t('driver.navigate'))") !== false
        && strpos($page, "bakery_driver_action_label('problem', bakery_t('driver.problem'))") !== false
        && strpos($page, 'class="route-action-icon"') !== false
        && strpos($routeJs, "actionLabel('photo'") !== false
        && strpos($routeJs, "di.navigate || di.directions") !== false
);
driver_ux_assert(
    'Amounts opens delivery entry even when the photo button was not pre-bound',
    strpos($page, 'data-start-step="delivery"') !== false
        && strpos($page, "bakery_t('driver.amounts')") !== false
        && strpos($routeJs, "stepBtn.getAttribute('data-start-step')") !== false
        && isset($english['driver.amounts'], $spanish['driver.amounts'])
        && $english['driver.amounts'] === 'Amounts'
        && $spanish['driver.amounts'] === 'Cantidades'
);
driver_ux_assert(
    'row Problem and bottom-nav Problem open the problem sheet directly',
    strpos($routeJs, 'function bindProblemButtons') !== false
        && strpos($routeJs, 'openFailStopModal(problemOrderId') !== false
        && strpos($routeJs, 'cardProblem.click()') === false
);
driver_ux_assert(
    'phone hero actions stay visible and the route clears the dock and nav',
    strpos($styles, 'body:has(.bakery-nav__problem-tab) .driver-route[data-route-view="now"] .route-section-next .next-stop-actions') !== false
        && strpos($styles, "display: none;\n    }\n\n    /* Route view") === false
        && strpos($styles, 'padding-bottom: calc(var(--driver-nav-h) + var(--driver-dock-h) + 16px);') !== false
);
$nav = (string)file_get_contents($root . '/includes/nav.php');
$navCss = (string)file_get_contents($root . '/css/nav.css');
driver_ux_assert(
    'phone bottom nav uses a short HQ label and can wrap inside its cell',
    strpos($nav, "bakery_te('nav.call_hq_short')") !== false
        && isset($english['nav.call_hq_short'], $spanish['nav.call_hq_short'])
        && $english['nav.call_hq_short'] !== ''
        && $spanish['nav.call_hq_short'] !== ''
        && strpos($navCss, 'overflow-wrap: anywhere;') !== false
);

driver_ux_assert(
    'phone amounts panel scrolls above the fixed action bar and the keyboard',
    strpos($styles, 'padding-bottom: calc(var(--driver-action-bar) + env(safe-area-inset-bottom, 0px)) !important;') !== false
        && strpos($styles, 'scroll-padding-bottom: calc(var(--driver-action-bar) + env(safe-area-inset-bottom, 0px));') !== false
        && strpos($page, 'id="deliveryPiecesInput" min="0" step="1" inputmode="numeric"') !== false
        && strpos($page, 'id="deliveryCreditsInput" min="0" step="1" value="0" inputmode="numeric"') !== false
);
driver_ux_assert(
    'phone More menu is fixed inside the viewport and scrolls internally',
    strpos($styles, 'body.driver-workflow-page .bakery-nav--driver .bakery-nav__more[open] .bakery-nav__more-sheet') !== false
        && strpos($styles, 'max-height: calc(100svh - var(--driver-nav-h) - var(--driver-dock-h) - 16px - env(safe-area-inset-top, 0px));') !== false
        && strpos($styles, 'overflow-y: auto;') !== false
);
driver_ux_assert(
    'More and Reorder ignore the extra tap that used to close them',
    strpos($routeJs, 'function bindOneTapDetails') !== false
        && strpos($routeJs, 'function toggleReorderFromTap') !== false
        && strpos($routeJs, 'now - reorderToggleAt < 450') !== false
        && strpos($routeJs, 'now - last < 450') !== false
);
driver_ux_assert(
    'tapping a stop row opens that stop and rows do not select text',
    strpos($routeJs, 'openStopDeliveryFromEl(stop, { autoOpenCamera: false })') !== false
        && strpos($routeJs, "renderNextStop(stop);\n                setRouteView('now'") === false
        && strpos($styles, '.driver-route .stop-item-name') !== false
        && strpos($styles, 'user-select: none;') !== false
);
driver_ux_assert(
    'small screens slim the date bar and keep the staging banner from covering it',
    strpos($styles, 'body.driver-workflow-page .route-day-nav') !== false
        && strpos($styles, "position: static;\n        top: auto;") !== false
        && strpos($styles, 'body.driver-workflow-page .local-env-banner') !== false
        && strpos($styles, 'padding-bottom: calc(var(--driver-nav-h) + var(--driver-dock-h) + 28px);') !== false
);
driver_ux_assert(
    'the route strip no longer hangs past the screen edge',
    strpos($styles, 'margin: 0 -12px 8px;') === false
        && strpos($styles, ".now-strip {\n        position: static;") !== false
);
driver_ux_assert(
    'prep hides the floating Add a stop dock that covered the list',
    strpos($styles, 'bottom: calc(118px') !== false
        && strpos($styles, 'body.driver-route-prep .route-prep-add-dock') !== false
        && strpos($styles, 'display: none !important;') !== false
        && strpos($page, 'id="routePrepAddListBtn"') !== false
);
$deliveryJs = (string)file_get_contents($root . '/includes/driver_delivery.js');
driver_ux_assert(
    'amounts shows Take photo before a shot and Adjust moves to the pieces field',
    strpos($deliveryJs, 'function scrollCaptureFieldIntoView') !== false
        && strpos($deliveryJs, 'var hasShot = kind === \'uploading\' || kind === \'saved\' || kind === \'queued\'') !== false
        && strpos($deliveryJs, 'box.hidden = !hasShot;') !== false
        && strpos($deliveryJs, 'scrollCaptureFieldIntoView(pieces)') !== false
        && strpos($styles, '.photo-modal.is-inline.delivery-step-photo #deliveryCameraFrame.is-camera-idle') !== false
);
driver_ux_assert(
    'phone hero keeps Photo, Navigate, and Problem with readable labels',
    strpos($styles, 'body.driver-workflow-page .driver-route[data-route-view="now"] .next-stop-actions') !== false
        && strpos($styles, 'grid-template-columns: repeat(3, minmax(0, 1fr));') !== false
        && strpos($styles, '.next-stop-actions .route-action-label') !== false
        && strpos($page, "bakery_driver_action_label('photo'") !== false
        && strpos($page, "bakery_driver_action_label('navigate'") !== false
        && strpos($page, "bakery_driver_action_label('problem'") !== false
);
driver_ux_assert(
    'phone log out sits in the More sheet, above the dock, and is not labelled Out',
    strpos($styles, 'body.driver-workflow-page .bakery-nav__more-sheet .bakery-nav__logout') !== false
        && strpos($styles, 'bottom: calc(var(--driver-nav-h) + var(--driver-dock-h) + 8px);') !== false
        && $english['common.log_out_short'] === 'Log out'
        && $spanish['common.log_out_short'] === 'Salir'
        && $english['common.log_out_short'] !== 'Out'
);
driver_ux_assert(
    'past stops are not trapped in a short scroller under the dock',
    strpos($styles, 'body.driver-workflow-page .past-stops-list') !== false
        && strpos($styles, "max-height: none;\n        overflow: visible;") !== false
        && strpos($styles, 'padding-bottom: calc(var(--driver-nav-h) + var(--driver-dock-h) + 28px);') !== false
);
driver_ux_assert(
    'the date sheet sits above the Directions dock',
    strpos($styles, 'body.driver-field-mode.driver-workflow-page.route-has-sticky-dock .route-date-dock') !== false
        && strpos($styles, 'bottom: calc(var(--driver-nav-h) + var(--driver-dock-h) + 8px);') !== false
        && strpos($routeJs, 'dateDisclosure.open = !dateDisclosure.open') !== false
);
$loginPage = (string)file_get_contents($root . '/login.php');
driver_ux_assert(
    'login ignores a second Enter after the 4th digit auto-submits',
    strpos($loginPage, 'if (posted)') !== false
        && strpos($loginPage, 'event.preventDefault();') !== false
        && strpos($loginPage, "event.key === 'Enter' && posted") !== false
        && strpos($loginPage, 'var submitting = false;') === false
);
driver_ux_assert(
    'Today chip and Add / remove stops are at least 44px',
    strpos($styles, ".now-strip-date {\n    display: inline-flex;\n    align-items: baseline;\n    gap: 6px;\n    min-height: 44px;") !== false
        && strpos($styles, ".route-prep-edit-link {\n    min-height: 44px;") !== false
        && strpos($styles, ".route-adjust-toggle {\n    min-height: 44px;") !== false
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
        && strpos($routeJs, "targetEl.closest('#nextStopDetailsToggle')") !== false
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

driver_ux_assert(
    'date sheet starts closed and is not restored from sessionStorage',
    strpos($routeJs, 'sessionStorage') === false
        && strpos($routeJs, 'function forceDriverSheetsClosed()') !== false
        && strpos($routeJs, "pushLayer('date')") !== false
);
driver_ux_assert(
    'Back closes the date sheet and the More menu without leaving the page',
    strpos($routeJs, "pushLayer('more')") !== false
        && strpos($routeJs, "closeNamedLayer('date')") !== false
        && strpos($routeJs, "closeNamedLayer('more')") !== false
        && strpos($routeJs, "window.addEventListener('popstate'") !== false
);

$openFailAt = strpos($routeJs, 'function openFailStopModal(');
$closeFailAt = strpos($routeJs, 'function closeFailStopModal(', $openFailAt === false ? 0 : $openFailAt);
$openFail = ($openFailAt !== false && $closeFailAt !== false)
    ? substr($routeJs, $openFailAt, $closeFailAt - $openFailAt)
    : '';
driver_ux_assert(
    'Back keeps the problem note until the sheet is submitted or cancelled',
    $openFail !== ''
        && strpos($openFail, 'problemDraftNotes') !== false
        && strpos($openFail, "noteInput.value = ''") === false
        && strpos($routeJs, 'function rememberProblemNote()') !== false
        && strpos($routeJs, 'function forgetProblemNote(') !== false
        && strpos($routeJs, 'closeFailStopModal({ fromPop: true })') !== false
);
driver_ux_assert(
    'unsaved reorder asks once before Back discards it',
    strpos($routeJs, 'function reorderIsDirty()') !== false
        && strpos($routeJs, "di.discard_changes || 'Discard changes?'") !== false
        && strpos($routeJs, 'closeRouteAdjust(true, { fromPop: true })') !== false
        && (string)$english['driver.discard_changes'] === 'Discard changes?'
        && (string)$spanish['driver.discard_changes'] === '¿Descartar los cambios?'
        && strpos($page, "bakery_t('driver.discard_changes')") !== false
);

$createAt = strpos($mapJs, 'function createMap()');
$bindAt = $createAt === false ? false : strpos($mapJs, 'function bindChrome()', $createAt);
$createMap = ($createAt !== false && $bindAt !== false) ? substr($mapJs, $createAt, $bindAt - $createAt) : '';
$routeHideAt = strpos($styles, '.driver-route[data-route-view="route"] .route-map-horizon');
$routeHide = $routeHideAt === false ? '' : substr($styles, $routeHideAt, 420);
driver_ux_assert(
    'Follow stays on the Route tab and is labeled when the map is created',
    strpos($page, 'id="routeMapFollow"') !== false
        && $createMap !== ''
        && strpos($createMap, 'syncFollowButton()') !== false
        && strpos($createMap, "followBtn.hidden = false") !== false
        && strpos($routeHide, '#routeMapFollow') === false
        && strpos($routeHide, '#routeMapExpand') !== false
);
$config = (string)file_get_contents($root . '/includes/config.php');
preg_match('/connect-src[^;]+;/', $config, $connectSrc);
$connect = $connectSrc[0] ?? '';
driver_ux_assert(
    'map tile hosts are allowed on connect-src',
    strpos($connect, 'https://*.googleapis.com') !== false
        && strpos($connect, 'https://*.gstatic.com') !== false
        && strpos($connect, 'https://*.google.com') !== false
);
driver_ux_assert(
    'the unavailable map message waits until the Maps script fails',
    strpos($page, '__driverMapScriptPending') !== false
        && strpos($page, 'tag.onerror') !== false
        && strpos($page, 'gm_authFailure') !== false
        && strpos($mapJs, 'if (!window.__driverMapScriptPending) showFallback();') !== false
        && strpos($mapJs, 'DriverRouteMap') !== false
        && strpos($mapJs, 'fail: function ()') !== false
);

$deliveryJs = (string)file_get_contents($root . '/includes/driver_delivery.js');
$navPhp = (string)file_get_contents($root . '/includes/nav.php');
$parseStart = strpos($deliveryJs, '/* bakery-count-parse:start */');
$parseEnd = strpos($deliveryJs, '/* bakery-count-parse:end */');
$parseJs = ($parseStart !== false && $parseEnd !== false && $parseEnd > $parseStart)
    ? substr($deliveryJs, $parseStart, $parseEnd - $parseStart)
    : '';
$parseScript = $parseJs . <<<'JS'

const samples = [
  ['-5', 'invalid', null],
  ['-2', 'invalid', null],
  ['abc', 'invalid', null],
  ['e', 'invalid', null],
  ['1e2', 'invalid', null],
  ['5.5', 'invalid', null],
  ['', 'empty', null],
  ['   ', 'empty', null],
  ['0', 'ok', 0],
  ['5', 'ok', 5],
  [' 12 ', 'ok', 12]
];
let failed = 0;
samples.forEach(function (row) {
  const parsed = bakeryParseDeliveryCount(row[0]);
  const value = parsed && parsed.state === 'ok' ? parsed.value : null;
  if (!parsed || parsed.state !== row[1] || value !== row[2]) {
    console.log('PARSE ' + JSON.stringify(row) + ' => ' + JSON.stringify(parsed));
    failed++;
  }
});
const one = bakeryCreditsBackSuffix(1, ', :credits credit back', ', :credits credits back');
const many = bakeryCreditsBackSuffix(2, ', :credits credit back', ', :credits credits back');
const none = bakeryCreditsBackSuffix(0, ', :credits credit back', ', :credits credits back');
if (one !== ', 1 credit back' || many !== ', 2 credits back' || none !== '') {
  console.log('SUFFIX ' + JSON.stringify([one, many, none]));
  failed++;
}
if (failed > 0) process.exit(1);
JS;
$parseFile = tempnam(sys_get_temp_dir(), 'bakery-count-');
$parseOk = false;
$parseOut = '';
if ($parseJs !== '' && $parseFile !== false) {
    file_put_contents($parseFile, $parseScript);
    $parseOut = (string)shell_exec('node ' . escapeshellarg($parseFile) . ' 2>&1');
    $parseOk = $parseOut === '';
    @unlink($parseFile);
}
driver_ux_assert(
    'piece and credit counts reject negatives and letters, and pluralize one credit',
    $parseOk
);
if (!$parseOk && $parseOut !== '') {
    echo $parseOut;
}

driver_ux_assert(
    'amounts preview does not coerce a bad count to zero',
    strpos($deliveryJs, 'Math.max(0, parseInt(piecesInput.value, 10) || 0)') === false
        && strpos($deliveryJs, 'deliveryQtyBlocksFinish') !== false
        && strpos($deliveryJs, "i18n('qty_invalid')") !== false
        && strpos($page, 'id="deliveryQtyError"') !== false
);
driver_ux_assert(
    'empty pieces can be saved only as an explicit 0 or Save anyway',
    strpos($deliveryJs, "i18n('pieces_empty_confirm')") !== false
        && strpos($deliveryJs, 'showVarianceConfirm(0,') !== false
);
driver_ux_assert(
    'server rejects a non-whole piece or credit count with the same message',
    strpos($completeDelivery, 'function bakery_delivery_parse_whole_count') !== false
        && strpos($completeDelivery, "'/^(?:0|[1-9]\\d*)$/'") !== false
        && strpos($completeDelivery, "bakery_t('driver.qty_invalid')") !== false
);
driver_ux_assert(
    'a skipped photo is not a green done step and offers Take photo',
    strpos($deliveryJs, "i18n('photo_skipped'") !== false
        && strpos($deliveryJs, "i18n('take_photo_action'") !== false
        && strpos($deliveryJs, 'is-skipped') !== false
        && strpos($deliveryJs, "s === 'photo' && photoSkipped") !== false
);
driver_ux_assert(
    'NEXT stays only on the next undelivered stop',
    strpos($routeJs, 'function syncNextStopLabel') !== false
        && strpos($routeJs, 'syncNextStopLabel(el, false)') !== false
        && strpos($routeJs, 'syncNextStopLabel(el, true)') !== false
);
driver_ux_assert(
    'a delivered stop can edit amounts on the same invoice the same day',
    strpos($page, "bakery_te('driver.edit_amounts')") !== false
        && strpos($page, 'data-edit-amounts="1"') !== false
        && strpos($page, 'data-today=') !== false
        && strpos($routeJs, 'data-edit-amounts="1"') !== false
        && strpos($deliveryJs, 'edit_amounts=1') !== false
        && strpos($completeDelivery, 'BAKERY_OP_DELIVERY_MODIFIED') !== false
        && strpos($completeDelivery, "bakery_t('driver.edit_amounts_same_day')") !== false
        && strpos($completeDelivery, "bakery_t('driver.amounts_updated')") !== false
        && strpos($completeDelivery, 'actual_delivery_time = COALESCE(actual_delivery_time, CURTIME())') !== false
);
driver_ux_assert(
    'Report says it saves a case and does not text or call HQ',
    strpos((string)$english['driver.problem_cant_deliver_prompt'], 'does not text, call, or WhatsApp') !== false
        && strpos((string)$spanish['driver.problem_cant_deliver_prompt'], 'No envía texto, llamada ni WhatsApp') !== false
        && strpos((string)$english['exception_desk.reported'], 'Nobody was texted, called, or sent a WhatsApp') !== false
        && strpos((string)$spanish['exception_desk.reported'], 'No se envió texto, llamada ni WhatsApp') !== false
        && strpos($page, "bakery_te('driver.problem_cant_deliver_prompt')") !== false
        && strpos($page, "'fail_success' => bakery_t('exception_desk.reported')") !== false
);
driver_ux_assert(
    'one credit is singular and log out is not the clock Out button',
    ($english['driver.credits_suffix_one'] ?? '') === ', :credits credit back'
        && ($english['driver.credits_suffix'] ?? '') === ', :credits credits back'
        && ($spanish['driver.credits_suffix_one'] ?? '') === ', :credits crédito devuelto'
        && ($spanish['driver.credits_suffix'] ?? '') === ', :credits créditos devueltos'
        && ($english['common.log_out_short'] ?? '') === 'Log out'
        && ($spanish['common.log_out_short'] ?? '') === 'Salir'
        && strpos($navPhp, "bakery_t('common.log_out_short')") !== false
        && strpos($deliveryJs, 'bakeryCreditsBackSuffix') !== false
);
foreach ([
    'driver.qty_invalid',
    'driver.pieces_empty_confirm',
    'driver.photo_skipped',
    'driver.take_photo_action',
    'driver.edit_amounts',
    'driver.save_amounts',
    'driver.edit_amounts_same_day',
    'driver.amounts_updated',
    'driver.credits_suffix_one',
] as $key) {
    driver_ux_assert(
        "{$key} exists in English and Spanish",
        isset($english[$key], $spanish[$key])
            && trim((string)$english[$key]) !== ''
            && trim((string)$spanish[$key]) !== ''
            && (string)$english[$key] !== (string)$spanish[$key]
    );
}

$closeoutPhp = (string)file_get_contents($root . '/includes/driver_closeout.php');
$closeoutPage = (string)file_get_contents($root . '/route_closeout.php');
$navPhp = (string)file_get_contents($root . '/includes/nav.php');
driver_ux_assert(
    'Close out route is in the More menu and at the end of a finished route',
    strpos($navPhp, 'bakery-nav__more-link--closeout') !== false
        && strpos($page, 'driver-closeout-bar') !== false
        && strpos($page, 'driver-closeout-end') !== false
        && strpos($page, 'bakery_driver_closeout_render') !== false
);
driver_ux_assert(
    'closeout bar sits above the bottom dock at 375 and 393 widths',
    strpos($styles, '@media (max-width: 393px)') !== false
        && strpos($styles, 'bottom: calc(var(--driver-nav-h) + 8px);') !== false
        && strpos($styles, '@media (max-width: 720px)') !== false
);
driver_ux_assert(
    'closeout labels are Close out route and Cerrar ruta',
    ($english['driver.close_route'] ?? '') === 'Close out route'
        && ($spanish['driver.close_route'] ?? '') === 'Cerrar ruta'
        && ($english['driver.closeout_submit'] ?? '') === 'Close out route'
        && ($spanish['driver.closeout_submit'] ?? '') === 'Cerrar ruta'
);
driver_ux_assert(
    'driver closeout posts to the manager route closeout action',
    strpos($closeoutPhp, "route_closeout.php") !== false
        && strpos($closeoutPhp, 'value="close_route"') !== false
        && strpos($closeoutPhp, 'name="confirm_open_stops"') !== false
        && strpos($closeoutPage, 'confirm_open_stops') !== false
        && strpos($closeoutPage, 'bakery_driver_route_control_reopen') !== false
);
driver_ux_assert(
    'finishing the last stop reveals the closeout bar without covering the hero dock',
    strpos($routeJs, "getElementById('driverCloseoutBar')") !== false
        && strpos($routeJs, "classList.toggle('driver-closeout-ready', show)") !== false
);

$navCss = (string)file_get_contents($root . '/css/nav.css');
driver_ux_assert(
    'a short coarse phone keeps the phone layout and closes sheets on rotate',
    strpos($styles, '@media (max-width: 720px), ((pointer: coarse) and (max-height: 499px))') !== false
        && strpos($navCss, '@media (max-width: 720px), ((pointer: coarse) and (max-height: 499px))') !== false
        && strpos($routeJs, 'orientationchange') !== false
        && strpos($routeJs, 'closeDriverSheetsForOrientation') !== false
        && strpos($styles, '@media (max-height: 500px)') !== false
        && strpos($styles, 'position: sticky;') !== false
);

driver_ux_assert(
    'wizard pills are done only when that step really happened',
    strpos($deliveryJs, 'function deliveryStepIsDone(step, info)') !== false
        && strpos($deliveryJs, "info.proofState === 'saved'") !== false
        && strpos($deliveryJs, "info.proofState === 'skipped'") !== false
        && strpos($deliveryJs, 'confirmationSaved === true && info.visualStep === \'invoice\'') !== false
);

$stepFnAt = strpos($deliveryJs, 'function deliveryStepIsDone(step, info)');
$stepFnEnd = $stepFnAt === false ? false : strpos($deliveryJs, 'function goToStep(step)', $stepFnAt);
$stepFn = ($stepFnAt !== false && $stepFnEnd !== false) ? substr($deliveryJs, $stepFnAt, $stepFnEnd - $stepFnAt) : '';
$stepScript = $stepFn . <<<'JS'

const cases = [
  ['photo', { proofState: '', hasProofPhoto: false, confirmationSaved: false, visualStep: 'delivery' }, false],
  ['photo', { proofState: 'skipped', hasProofPhoto: false, confirmationSaved: false, visualStep: 'delivery' }, false],
  ['photo', { proofState: 'saved', hasProofPhoto: false, confirmationSaved: false, visualStep: 'delivery' }, true],
  ['photo', { proofState: '', hasProofPhoto: true, confirmationSaved: false, visualStep: 'delivery' }, true],
  ['delivery', { proofState: 'saved', hasProofPhoto: true, confirmationSaved: false, visualStep: 'delivery' }, false],
  ['delivery', { proofState: 'saved', hasProofPhoto: true, confirmationSaved: true, visualStep: 'invoice' }, true]
];
let stepFailed = 0;
cases.forEach(function (row) {
  const got = deliveryStepIsDone(row[0], row[1]);
  if (got !== row[2]) {
    console.log('STEP ' + JSON.stringify(row) + ' => ' + got);
    stepFailed++;
  }
});
if (stepFailed) process.exit(1);
JS;
$stepFile = tempnam(sys_get_temp_dir(), 'bakery-step-');
$stepOk = false;
if ($stepFn !== '' && $stepFile !== false) {
    file_put_contents($stepFile, $stepScript);
    $stepOut = (string)shell_exec('node ' . escapeshellarg($stepFile) . ' 2>&1');
    $stepOk = $stepOut === '';
    if (!$stepOk && $stepOut !== '') {
        echo $stepOut;
    }
    @unlink($stepFile);
}
driver_ux_assert('arrive and photo pills stay undone until the step is saved', $stepOk);

driver_ux_assert(
    'first paint counts come from the same server totals as the strip',
    strpos($page, 'data-total="<?php echo (int)$totalStops; ?>"') !== false
        && strpos($page, 'data-remaining="<?php echo (int)$remainingStops; ?>"') !== false
        && strpos($page, 'id="nowStripProgress"') !== false
        && strpos($page, '(int)$driverCompletedStops; ?>/<?php echo (int)$totalStops;') !== false
        && strpos($page, 'id="queueCount"><?php echo (int)$remainingStops;') !== false
        && strpos($routeJs, 'var renderedTotal = active.length + past.length;') !== false
);

$numberFnAt = strpos($page, 'function bakery_driver_visible_stop_numbers(array $stops): array');
$numberFnEnd = $numberFnAt === false ? false : strpos($page, 'function bakery_driver_stop_checked_in', $numberFnAt);
$numberFn = ($numberFnAt !== false && $numberFnEnd !== false) ? substr($page, $numberFnAt, $numberFnEnd - $numberFnAt) : '';
$visibleNumbers = [];
if ($numberFn !== '') {
    eval($numberFn);
    $visibleNumbers = bakery_driver_visible_stop_numbers([
        ['daily_order_id' => 10, 'delivery_status' => 'pending', 'route_order' => 1],
        ['daily_order_id' => 11, 'delivery_status' => 'delivered', 'route_order' => 2],
        ['daily_order_id' => 12, 'delivery_status' => 'pending', 'route_order' => 6],
        ['daily_order_id' => 13, 'delivery_status' => 'office_flag', 'route_order' => 7],
        ['daily_order_id' => 14, 'delivery_status' => 'pending', 'route_order' => 9],
    ]);
}
driver_ux_assert(
    'visible stops are numbered 1..N and internal gaps stay out of the badge',
    $visibleNumbers === [10 => 1, 12 => 2, 14 => 3]
        && strpos($page, 'data-route-order=') !== false
        && strpos($page, 'data-visible-number=') !== false
        && strpos($routeJs, 'function paintVisibleStopNumbers()') !== false
);

driver_ux_assert(
    'the problem sheet starts with no reason and Report stays disabled',
    strpos($page, "bakery_exception_desk_reason_chips_html('reason_code', false)") !== false
        && strpos($page, 'id="failStopConfirmBtn" disabled') !== false
        && strpos($page, "bakery_te('driver.problem_question')") !== false
        && ($english['driver.problem_question'] ?? '') === "Can't deliver?"
        && ($spanish['driver.problem_question'] ?? '') === '¿No puedes entregar?'
        && strpos($routeJs, 'function syncFailReportButton()') !== false
        && strpos($routeJs, 'confirmBtn.disabled = !checked;') !== false
);

$dateFnAt = strpos($page, 'function bakery_driver_route_date_or_today(string $raw): array');
$dateFnEnd = $dateFnAt === false ? false : strpos($page, 'function bakery_driver_open_stop_id', $dateFnAt);
$dateFn = ($dateFnAt !== false && $dateFnEnd !== false) ? substr($page, $dateFnAt, $dateFnEnd - $dateFnAt) : '';
$openFnAt = strpos($page, 'function bakery_driver_open_stop_id(string $openStop, int $dailyOrderId): int');
$openFnEnd = $openFnAt === false ? false : strpos($page, 'function bakery_driver_visible_stop_numbers', $openFnAt);
$openFn = ($openFnAt !== false && $openFnEnd !== false) ? substr($page, $openFnAt, $openFnEnd - $openFnAt) : '';
$invalidDate = [];
$missingStopId = -1;
if ($dateFn !== '' && $openFn !== '') {
    eval($dateFn);
    eval($openFn);
    $invalidDate = bakery_driver_route_date_or_today('2026-13-40');
    $missingStopId = bakery_driver_open_stop_id('999999', 0);
}
driver_ux_assert(
    'a bad date falls back to today and a bare open_stop id is the order id',
    ($invalidDate['invalid'] ?? false) === true
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($invalidDate['date'] ?? '')) === 1
        && ($invalidDate['date'] ?? '') !== '2026-13-40'
        && bakery_driver_route_date_or_today('2026-11-26')['invalid'] === false
        && $missingStopId === 999999
        && bakery_driver_open_stop_id('1', 42) === 42
        && strpos($page, 'driver.date_invalid') !== false
        && strpos($page, 'driver.stop_not_found') !== false
        && strpos($routeJs, 'function cleanInvalidRouteParams()') !== false
        && ($english['driver.date_invalid'] ?? '') !== ''
        && ($spanish['driver.stop_not_found'] ?? '') !== ''
        && ($english['driver.date_invalid'] ?? '') !== ($spanish['driver.date_invalid'] ?? '')
);

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/time_clock.php';
require_once $root . '/includes/client_request_id.php';
$_SESSION['locale'] = 'en';
if (function_exists('bakery_locale')) {
    bakery_locale(true);
}
$earlierPunch = ['clock_in_at' => '2098-03-17 22:16:00'];
$todayPunch = ['clock_in_at' => '2098-03-18 08:05:00'];
$clockNow = strtotime('2098-03-18 09:30:00');
$earlierLabel = bakery_time_clock_open_label($earlierPunch, $clockNow);
$todayLabel = bakery_time_clock_open_label($todayPunch, $clockNow);
$earlierNav = bakery_time_clock_nav_label($earlierPunch, $clockNow);
driver_ux_assert(
    'an earlier open punch uses one sentence on the page and in More',
    $earlierLabel === 'Clock-in from 3/17 still open, clock out to fix'
        && $earlierNav === $earlierLabel
        && strpos($todayLabel, 'Clocked in since') === 0
        && strpos($todayLabel, 'p. m..') === false
        && strpos($page, 'bakery_time_clock_open_label') === false
        && strpos((string)file_get_contents($root . '/time_clock.php'), 'bakery_time_clock_open_label') !== false
        && strpos((string)file_get_contents($root . '/includes/nav.php'), 'bakery_time_clock_nav_label') !== false
        && ($english['time_clock.still_open'] ?? '') === 'Clock-in from :date still open, clock out to fix'
        && strpos((string)($spanish['time_clock.status_in'] ?? ''), ':time.') === false
);
$_SESSION['locale'] = 'es';
bakery_locale(true);
$spanishClock = bakery_time_clock_format_time('2098-03-17 22:16:00');
driver_ux_assert(
    'Spanish clock uses a non-breaking space and day/month',
    strpos($spanishClock, "p.\u{00A0}m.") !== false
        && strpos($spanishClock, '17/3') === 0
        && strpos($spanishClock, 'p. m..') === false
);
$_SESSION['locale'] = 'en';
bakery_locale(true);

$dupError = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'abc' for key 'uq_daily_orders_confirm_request_id'");
driver_ux_assert(
    'a second queued confirm is recognized as a duplicate and locked on the server',
    bakery_is_duplicate_confirm_error($dupError) === true
        && bakery_is_duplicate_confirm_error(new Exception('plain failure')) === false
        && strpos((string)file_get_contents($root . '/complete_delivery.php'), 'FOR UPDATE') !== false
        && strpos((string)file_get_contents($root . '/complete_delivery.php'), 'bakery_confirm_delivery_replay') !== false
        && strpos((string)file_get_contents($root . '/includes/driver_offline_outbox.js'), 'function queuedConfirmForOrder') !== false
        && strpos($page, 'id="driverOfflineBanner"') !== false
        && ($english['driver.offline_banner'] ?? '') !== ($spanish['driver.offline_banner'] ?? '')
        && ($english['driver.offline_finish_blocked'] ?? '') !== ''
);

$reuseFnAt = strpos((string)file_get_contents($root . '/includes/driver_offline_outbox.js'), 'function queuedConfirmForOrder(jobs, dailyOrderId)');
$outboxJs = (string)file_get_contents($root . '/includes/driver_offline_outbox.js');
$reuseFnEnd = $reuseFnAt === false ? false : strpos($outboxJs, 'function queuedCount', $reuseFnAt);
$reuseFn = ($reuseFnAt !== false && $reuseFnEnd !== false) ? substr($outboxJs, $reuseFnAt, $reuseFnEnd - $reuseFnAt) : '';
$reuseScript = $reuseFn . <<<'JS'

const jobs = [
  { id: 'confirm-a', kind: 'confirm', dailyOrderId: 9, status: 'queued' },
  { id: 'confirm-b', kind: 'confirm', dailyOrderId: 9, status: 'synced' },
  { id: 'photo-a', kind: 'photo', dailyOrderId: 9, status: 'queued' }
];
const reused = queuedConfirmForOrder(jobs, 9);
const fresh = queuedConfirmForOrder(jobs, 10);
if (!reused || reused.id !== 'confirm-a' || fresh !== null) {
  console.log(JSON.stringify({ reused: reused, fresh: fresh }));
  process.exit(1);
}
JS;
$reuseFile = tempnam(sys_get_temp_dir(), 'bakery-outbox-');
$reuseOk = false;
if ($reuseFn !== '' && $reuseFile !== false) {
    file_put_contents($reuseFile, $reuseScript);
    $reuseOut = (string)shell_exec('node ' . escapeshellarg($reuseFile) . ' 2>&1');
    $reuseOk = $reuseOut === '';
    if (!$reuseOk && $reuseOut !== '') {
        echo $reuseOut;
    }
    @unlink($reuseFile);
}
driver_ux_assert('a queued confirm for the same stop is not sent twice', $reuseOk);

$stopsPage = (string)file_get_contents($root . '/driver_stops.php');
driver_ux_assert(
    'you are here waits for check-in in English and Spanish',
    strpos($stopsPage, "bakery_te(\$currentCheckedIn ? 'driver.you_are_here' : 'driver.next_stop')") !== false
        && strpos($stopsPage, "photo_type = 'Before'") !== false
        && ($english['driver.you_are_here'] ?? '') === "You're here now"
        && ($spanish['driver.you_are_here'] ?? '') === 'Estás aquí ahora'
);

driver_ux_assert(
    'language links and the carried-stop menu are at least 44px, and recover says what it does',
    strpos($navCss, '.bakery-nav--driver .bakery-nav__more-sheet .bakery-lang-switch__btn') !== false
        && strpos($navCss, 'min-height: 44px;') !== false
        && strpos($styles, '.pan-carry-menu-toggle') !== false
        && strpos($styles, 'min-height: 44px;') !== false
        && strpos((string)file_get_contents($root . '/includes/pan_carry.php'), 'pan-carry-menu') !== false
        && strpos((string)file_get_contents($root . '/includes/pan_carry.php'), 'data-confirm') !== false
        && ($english['exception_desk.cant_deliver'] ?? '') === "Can't finish, send to office"
        && ($spanish['exception_desk.cant_deliver'] ?? '') === 'No puedo terminar, enviar a la oficina'
        && ($spanish['driver.queue'] ?? '') === 'Tus paradas'
        && ($spanish['nav.call_hq'] ?? '') === 'Llamar a la oficina'
);

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver route UX checks passed\n";
exit(0);

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

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver route UX checks passed\n";
exit(0);

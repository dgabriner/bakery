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

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver route UX checks passed\n";
exit(0);

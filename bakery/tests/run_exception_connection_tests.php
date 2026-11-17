<?php
/**
 * Exception connection contract: canonical deep links, return= round-trip,
 * failed-delivery recovery, generate preserves dated edits, chip helper safety.
 * CLI / local test DB only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/dashboard_command_center.php';
require_once $root . '/includes/operational_exceptions.php';
require_once $root . '/includes/daily_status.php';
require_once $root . '/includes/manager_mode.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: exception connection tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

$date = '2099-08-17';
$forbidden = ['driver_list.php', 'generate_invoice.php', 'generate_invoice_simple.php'];
$canonical = bakery_ops_canonical_pages();

foreach (bakery_ops_known_exception_types() as $type) {
    $enriched = bakery_ops_enrich_exceptions([
        ['type' => $type, 'category' => 'general', 'title' => $type, 'severity' => 'warning'],
    ], $date, 'manager');
    $assert($enriched !== [], $type . ' enriches to one exception');
    $href = (string)($enriched[0]['href'] ?? '');
    $page = bakery_ops_href_page($href);
    $assert($href !== '', $type . ' has a deep link');
    $assert(in_array($page, $canonical, true), $type . ' lands on canonical page (' . $page . ')');
    foreach ($forbidden as $bad) {
        $assert(strpos($href, $bad) === false, $type . ' does not use ' . $bad);
    }
}

$managerReturn = bakery_ops_return_resolve('manager', $date);
$assert(is_array($managerReturn), 'return=manager resolves');
$assert(
    isset($managerReturn['href']) && strpos($managerReturn['href'], 'manager.php?date=' . $date) !== false,
    'return=manager round-trips to manager.php?date='
);

$failed = bakery_ops_enrich_exceptions([
    ['type' => 'delivery_failed', 'category' => 'delivery', 'title' => 'Failed', 'severity' => 'critical'],
], $date, 'daily_run');
$failedHref = (string)($failed[0]['href'] ?? '');
$failedPage = bakery_ops_href_page($failedHref);
$assert(
    in_array($failedPage, ['manager.php', 'driver_assignment.php'], true),
    'failed-delivery points at recovery or assignment'
);
$assert(
    strpos($failedHref, 'attention=failed') !== false || strpos($failedHref, 'filter=failed') !== false,
    'failed-delivery includes a failed/attention filter'
);
$assert(strpos($failedHref, 'driver_list.php') === false, 'failed-delivery does not use driver_list.php');

$apiSrc = file_get_contents($root . '/daily_run_api.php');
$assert($apiSrc !== false, 'daily_run_api.php is readable');
$assert(
    preg_match("/case\\s+'generate_daily_orders':[\\s\\S]*?'overwrite_changed'\\s*=>\\s*false/", $apiSrc) === 1,
    'inline generate_daily_orders still uses overwrite_changed=false'
);

$missing = bakery_ops_enrich_exceptions([
    ['type' => 'demand_missing_daily', 'category' => 'demand', 'title' => 'Missing'],
], $date, 'dashboard');
$assert(
    ($missing[0]['inline_action']['action'] ?? '') === 'generate_daily_orders',
    'missing-daily inline action is generate_daily_orders'
);

$threw = false;
$emptyChips = [];
try {
    $emptyChips = bakery_ops_chips_for_row([
        bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
    ], []);
} catch (Throwable $e) {
    $threw = true;
}
$assert(!$threw && $emptyChips === [], 'chip helper is a no-op when context ids are missing');

$namedOnly = bakery_ops_chips_for_row([
    bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
], ['customer_name' => 'Cafe']);
$assert($namedOnly === [], 'chip helper is a no-op when only a name is present');

$matched = bakery_ops_chips_for_row([
    bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
], ['customer_id' => 12, 'flags' => ['missing_daily' => true]]);
$assert(count($matched) === 1, 'chip helper matches a row that caused the exception');

$dailyRunSrc = (string)file_get_contents($root . '/includes/daily_run.php');
$assert(
    strpos($dailyRunSrc, "\$dispatchStage['action_label'] = 'Open Driver Pickup Loads'") !== false
        && strpos($dailyRunSrc, "'attention' => 'incomplete'") !== false,
    'Assign/Load/Dispatch with only incomplete loads opens Driver Pickup Loads'
);
$ccSrc = (string)file_get_contents($root . '/includes/dashboard_command_center.php');
$assert(
    strpos($ccSrc, 'bakery_inventory_load_progress') !== false
        && strpos($ccSrc, "\$loadParams['driver_id'] = \$focusDriverId") !== false,
    'a single incomplete load deep-links to that driver'
);
$loadSrc = (string)file_get_contents($root . '/driver_load.php');
$assert(
    strpos($loadSrc, 'data-load-finish-hint') !== false
        && strpos($loadSrc, 'driver_load.today_still_open') !== false,
    'Driver Pickup Loads names the stuck load and warns when Daily Run is a different day'
);

// ---- Exception text localization (title_key / detail_key / action_key) ----
$localizedFields = static function (int $count): array {
    return [
        'type' => 'delivery_failed',
        'category' => 'delivery',
        'severity' => 'critical',
        'title' => 'Failed deliveries',
        'title_key' => 'ops.ex.delivery_failed.title',
        'detail' => $count . ' stop' . ($count === 1 ? '' : 's') . ' marked failed.',
        'detail_key' => ['ops.ex.delivery_failed.detail_one', 'ops.ex.delivery_failed.detail_many'],
        'count' => $count,
        'action' => 'Open failed-stop recovery',
        'action_key' => 'ops.act.open_failed_recovery',
    ];
};

$setLocale = static function (string $locale): void {
    $GLOBALS['bakery_i18n_catalog'] = null;
    bakery_set_locale($locale, false);
};

$setLocale('es');
$esMany = bakery_ops_exception($localizedFields(2));
$esOne = bakery_ops_exception($localizedFields(1));
$assert($esMany['title'] === 'Entregas fallidas', 'exception title is Spanish under es');
$assert($esMany['detail'] === '2 paradas marcadas como fallidas.', 'exception detail uses the plural Spanish string');
$assert($esOne['detail'] === '1 parada marcada como fallida.', 'exception detail uses the singular Spanish string for count 1');
$assert($esMany['action'] === 'Abrir recuperación de paradas fallidas', 'exception action is Spanish under es');
$assert(
    $esMany['title_en'] === 'Failed deliveries'
        && $esMany['detail_en'] === '2 stops marked failed.'
        && $esMany['action_en'] === 'Open failed-stop recovery',
    'English text is preserved in *_en fields'
);

$esAgain = bakery_ops_enrich_exceptions([$esMany], $date, 'manager');
$assert(
    ($esAgain[0]['title'] ?? '') === 'Entregas fallidas'
        && ($esAgain[0]['title_en'] ?? '') === 'Failed deliveries'
        && ($esAgain[0]['detail'] ?? '') === '2 paradas marcadas como fallidas.',
    'enrich re-processing keeps the localized text and the English originals'
);

$publicBlocker = bakery_daily_status_public_blocker($esMany);
$assert(
    $publicBlocker['title'] === 'Failed deliveries'
        && $publicBlocker['detail'] === '2 stops marked failed.'
        && $publicBlocker['action'] === 'Open failed-stop recovery',
    'public daily status blocker stays English when the viewer is Spanish'
);

$esBlockers = bakery_ops_exceptions_to_blockers([$esMany]);
$assert(
    ($esBlockers[0]['title_en'] ?? '') === 'Failed deliveries',
    'blockers carry the English title for the audit log and public JSON'
);

$identityEs = bakery_manager_exception_key($esMany, $date);
$setLocale('en');
$enMany = bakery_ops_exception($localizedFields(2));
$assert($enMany['title'] === 'Failed deliveries' && $enMany['detail'] === '2 stops marked failed.', 'exception text is English under en');
$assert(
    bakery_manager_exception_key($enMany, $date) === $identityEs,
    'exception identity key does not change with the viewer language'
);

$fallback = bakery_ops_exception([
    'type' => 'delivery_failed',
    'title' => 'Plain English title',
    'title_key' => 'ops.ex.this_key_does_not_exist.title',
    'detail' => 'Plain English detail',
    'detail_key' => 'ops.ex.this_key_does_not_exist.detail',
]);
$assert(
    $fallback['title'] === 'Plain English title' && $fallback['detail'] === 'Plain English detail',
    'a missing catalog key falls back to the English text'
);

$loadDriver = bakery_ops_exception([
    'type' => 'load_incomplete',
    'category' => 'load',
    'title' => 'Incomplete driver pickup loads',
    'title_key' => 'ops.ex.load_incomplete.title',
    'detail' => 'Ana has assigned order units with no matching pickup saved.',
    'detail_key' => 'ops.ex.load_incomplete.detail_driver',
    'detail_params' => ['driver' => 'Ana'],
]);
$setLocale('es');
$loadDriverEs = bakery_ops_exception([
    'type' => 'load_incomplete',
    'category' => 'load',
    'title' => 'Incomplete driver pickup loads',
    'title_key' => 'ops.ex.load_incomplete.title',
    'detail' => 'Ana has assigned order units with no matching pickup saved.',
    'detail_key' => 'ops.ex.load_incomplete.detail_driver',
    'detail_params' => ['driver' => 'Ana'],
]);
$assert(
    strpos($loadDriver['detail'], 'Ana') === 0 && strpos($loadDriverEs['detail'], 'Ana tiene') === 0,
    'detail_params placeholders are filled in both languages'
);
$setLocale('en');

// Every ops.ex / ops.act / ops.ia key the code references exists in both catalogs with matching placeholders.
$enCatalog = bakery_load_lang_catalog('en');
$esCatalog = bakery_load_lang_catalog('es');
$referencedKeys = [];
foreach (['includes/dashboard_command_center.php', 'includes/daily_run.php', 'includes/operational_exceptions.php'] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    if (preg_match_all("/'(ops\\.(?:ex|act|ia)\\.[a-z0-9_.]+)'/", $src, $m)) {
        foreach ($m[1] as $k) {
            $referencedKeys[$k] = true;
        }
    }
}
$assert(count($referencedKeys) > 50, 'exception keys are referenced from the exception builders (' . count($referencedKeys) . ')');
$missingEn = [];
$missingEs = [];
$placeholderDrift = [];
$esSameAsEn = [];
foreach (array_keys($referencedKeys) as $k) {
    if (!isset($enCatalog[$k])) {
        $missingEn[] = $k;
    }
    if (!isset($esCatalog[$k])) {
        $missingEs[] = $k;
    }
    if (isset($enCatalog[$k], $esCatalog[$k])) {
        preg_match_all('/:[a-z_]+/', (string)$enCatalog[$k], $a);
        preg_match_all('/:[a-z_]+/', (string)$esCatalog[$k], $b);
        $ta = array_unique($a[0]);
        $tb = array_unique($b[0]);
        sort($ta);
        sort($tb);
        if ($ta !== $tb) {
            $placeholderDrift[] = $k;
        }
        if ((string)$enCatalog[$k] === (string)$esCatalog[$k]) {
            $esSameAsEn[] = $k;
        }
    }
}
$assert($missingEn === [], 'every referenced exception key exists in en.php' . ($missingEn ? ' (missing: ' . implode(', ', $missingEn) . ')' : ''));
$assert($missingEs === [], 'every referenced exception key exists in es.php' . ($missingEs ? ' (missing: ' . implode(', ', $missingEs) . ')' : ''));
$assert($placeholderDrift === [], 'en/es placeholders match for exception keys' . ($placeholderDrift ? ' (drift: ' . implode(', ', $placeholderDrift) . ')' : ''));
$assert($esSameAsEn === [], 'es exception text is translated, not copied from en' . ($esSameAsEn ? ' (same: ' . implode(', ', $esSameAsEn) . ')' : ''));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

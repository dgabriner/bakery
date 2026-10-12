<?php
/**
 * Basic i18n smoke tests (CLI).
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

$_SERVER['SCRIPT_NAME'] = '/login.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/i18n.php';

$failures = 0;

function i18n_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL: $label\n";
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

// Staff login page defaults to Spanish before auth.
$_SESSION = [];
$_COOKIE = [];
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
i18n_assert('Spanish login title', bakery_t('login.title') === 'Código para entrar');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
i18n_assert('English login title', bakery_t('login.title') === 'Sign in code');

// Role defaults
i18n_assert('Baker default es', bakery_default_locale_for_role('baker', false) === 'es');
i18n_assert('Driver default es', bakery_default_locale_for_role('driver', false) === 'es');
i18n_assert('Manager default es', bakery_default_locale_for_role('manager', false) === 'es');
i18n_assert('Admin default en', bakery_default_locale_for_role('administrator', false) === 'en');
i18n_assert('Customer default en', bakery_default_locale_for_role(null, true) === 'en');

// Navigation translation
require_once dirname(__DIR__) . '/includes/navigation_catalog.php';
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
$groups = bakery_navigation_groups_for_role('manager');
i18n_assert('Nav groups returned', count($groups) > 0);
i18n_assert('Spanish nav group label', $groups[0]['label'] === 'Jornada');
i18n_assert('Spanish driver guides page', bakery_t('page.driver_guides') === 'Guías del repartidor');
i18n_assert('Spanish common view', bakery_t('common.view') === 'Ver');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
i18n_assert('English walkthroughs page', bakery_t('page.walkthroughs') === 'Walkthroughs');
i18n_assert('English common view', bakery_t('common.view') === 'View');

$root = dirname(__DIR__);
$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$uxKeys = [
    'ux.stage.demand',
    'ux.stage.production',
    'ux.stage.pack',
    'ux.stage.load',
    'ux.stage.delivery',
    'ux.stage.invoice',
    'ux.stage.confirm_demand',
    'ux.cc.daily_order_one',
    'ux.cc.daily_order_many',
    'ux.cc.customer_one',
    'ux.cc.short_one',
    'ux.cc.short_many',
    'ux.cc.pack_one',
    'ux.cc.nothing_to_invoice',
    'ux.daily_orders.next_sell',
    'ux.daily_orders.generate_standing',
    'ux.daily_orders.generate_week',
    'ux.daily_orders.change_date',
    'ux.daily_orders.clear_day',
    'ux.daily_orders.prev_day',
    'ux.daily_orders.next_day',
    'ux.pricing.mixed',
    'ux.pricing.store',
    'ux.pricing.standard',
    'ux.delivery_get.title',
    'ux.delivery_get.body',
    'ux.delivery_get.link',
];
$enKeys = array_keys($en);
$esKeys = array_keys($es);
$enUx = array_values(array_filter($enKeys, static fn(string $key): bool => strpos($key, 'ux.') === 0));
$esUx = array_values(array_filter($esKeys, static fn(string $key): bool => strpos($key, 'ux.') === 0));
i18n_assert('ux keys match in English and Spanish', $enUx !== [] && $enUx === $esUx);
foreach ($uxKeys as $key) {
    i18n_assert('ux key present ' . $key, isset($en[$key], $es[$key]) && $en[$key] !== '' && $es[$key] !== '');
    i18n_assert('ux key translated ' . $key, ($es[$key] ?? '') !== ($en[$key] ?? $key));
}
if ($enUx !== []) {
    $enTail = array_slice($enKeys, (int)array_search($enUx[0], $enKeys, true));
    $esTail = array_slice($esKeys, (int)array_search($esUx[0], $esKeys, true));
    $uxTail = static function (array $tail): bool {
        foreach ($tail as $key) {
            if (strpos((string)$key, 'ux.') !== 0) {
                return false;
            }
        }
        return $tail !== [];
    };
    i18n_assert('ux keys are one contiguous block at the end', $uxTail($enTail) && $uxTail($esTail));
}

i18n_assert(
    'English billing empty queue is guidance, not a 2099 fixture hunt',
    isset($en['billing.empty_queue'])
        && strpos($en['billing.empty_queue'], '2099') === false
        && stripos($en['billing.empty_queue'], 'fixture') === false
        && stripos($en['billing.empty_queue'], 'All in range') !== false
);
i18n_assert(
    'Spanish billing empty queue is guidance, not a 2099 fixture hunt',
    isset($es['billing.empty_queue'])
        && strpos($es['billing.empty_queue'], '2099') === false
        && stripos($es['billing.empty_queue'], 'fixture') === false
        && strpos($es['billing.empty_queue'], 'Todo el rango') !== false
);

$indexSrc = (string)file_get_contents($root . '/index.php');
i18n_assert('dashboard date uses bakery_localized_date_label', strpos($indexSrc, 'bakery_localized_date_label') !== false);
i18n_assert('dashboard date is not an English date() format', strpos($indexSrc, "date('l, F j, Y'") === false);

$ordersSrc = (string)file_get_contents($root . '/daily_orders.php');
i18n_assert('daily orders title is not a hardcoded English h1', strpos($ordersSrc, '<h1>Daily Orders</h1>') === false);
i18n_assert('daily orders defaults with bakery_next_sell_date', strpos($ordersSrc, 'bakery_next_sell_date') !== false);
i18n_assert('daily orders generate button is translated', strpos($ordersSrc, 'Generate from Standing Orders') === false);
i18n_assert('daily orders names the next sell day', strpos($ordersSrc, 'ux.daily_orders.next_sell') !== false);

$ccSrc = (string)file_get_contents($root . '/includes/dashboard_command_center.php');
i18n_assert('command center demand label is translated', strpos($ccSrc, "'label' => 'Demand'") === false);
i18n_assert('command center invoice empty state is translated', strpos($ccSrc, 'Nothing to invoice yet') === false);
i18n_assert('command center short summary is translated', strpos($ccSrc, ". ' short'") === false);

$runSrc = (string)file_get_contents($root . '/includes/daily_run.php');
i18n_assert('daily run confirm-demand label is translated', strpos($runSrc, "'label' => 'Confirm Demand'") === false);
i18n_assert('daily run commit button uses the catalog key', strpos($runSrc, "bakery_t('daily_run.commit_plan')") !== false);

$deliverySrc = (string)file_get_contents($root . '/complete_delivery.php');
i18n_assert('complete delivery has a browser GET plan', strpos($deliverySrc, 'function bakery_complete_delivery_browser_get_plan') !== false);
i18n_assert('mixed pan dulce pricing is not a raw English assignment', strpos($deliverySrc, "pricingLabel = 'Mixed Pan Dulce pricing'") === false);

require_once $root . '/includes/common_functions.php';
i18n_assert('next sell day helper exists', function_exists('bakery_next_sell_date'));
if (function_exists('bakery_next_sell_date')) {
    i18n_assert('Friday bake day next sell day is Saturday', bakery_next_sell_date('2026-10-09') === '2026-10-10');
    i18n_assert('Saturday next sell day is Sunday', bakery_next_sell_date('2026-10-10') === '2026-10-11');
}

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
$oct9 = DateTime::createFromFormat('!Y-m-d', '2026-10-09');
i18n_assert(
    'Spanish date helper names octubre',
    $oct9 instanceof DateTimeInterface && strpos(bakery_localized_date_label($oct9, true), 'octubre') !== false
);
i18n_assert('Spanish demand stage label', bakery_t('ux.stage.demand') === 'Demanda');
i18n_assert('Spanish one daily order', bakery_t('ux.cc.daily_order_one', ['count' => 1]) === '1 pedido del día');
i18n_assert('Spanish short count', bakery_t('ux.cc.short_many', ['count' => 2]) === '2 con falta');
i18n_assert('Spanish nothing to invoice', bakery_t('ux.cc.nothing_to_invoice') === 'Nada que facturar todavía');
i18n_assert('Spanish mixed pan dulce pricing', bakery_t('ux.pricing.mixed') === 'Precio mixto de pan dulce');

exit($failures > 0 ? 1 : 0);

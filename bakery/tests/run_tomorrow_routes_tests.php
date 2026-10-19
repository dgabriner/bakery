<?php
/**
 * Tomorrow's routes for a manager: read-only GET, 5 PM Pacific open,
 * orphans, assign/move/skip, Survey Center names, absolute Ajustes links.
 * CLI / local bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/surveys.php';
require_once $root . '/includes/survey_dual_open.php';
require_once $root . '/includes/driver_day_sms.php';
require_once $root . '/includes/route_manager.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/cron_run.php';

$tomorrowFile = $root . '/includes/tomorrow_routes.php';
if (is_file($tomorrowFile)) {
    require_once $tomorrowFile;
}

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$surveyPhp = (string)file_get_contents($root . '/survey.php');
$commsSrc = (string)file_get_contents($root . '/text_comms.php');
$dailyRun = (string)file_get_contents($root . '/daily_run.php');
$pageSrc = is_file($root . '/tomorrow_routes.php') ? (string)file_get_contents($root . '/tomorrow_routes.php') : '';
$navSrc = (string)file_get_contents($root . '/includes/nav.php');
$catalogSrc = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$openSrc = (string)file_get_contents($root . '/includes/survey_dual_open.php');
$healthSrc = (string)file_get_contents($root . '/health_deploy.php');
$css = is_file($root . '/css/tomorrow_routes.css') ? (string)file_get_contents($root . '/css/tomorrow_routes.css') : '';
$dailyRouteSrc = (string)file_get_contents($root . '/daily_route.php');

$assert(function_exists('bakery_survey_dual_open_if_due'), '5 PM page open helper exists');
$assert(function_exists('bakery_tomorrow_routes_apply'), 'tomorrow routes apply helper exists');
$assert(function_exists('bakery_survey_center_driver_label'), 'Survey Center driver label helper exists');
$assert(function_exists('bakery_survey_public_origin'), 'public origin helper exists');
$assert(strpos($surveyPhp, 'bakery_survey_dual_open_if_due') !== false, 'survey page calls the due open');
$assert(strpos($surveyPhp, 'bakery_survey_dual_open_next') === false, 'survey page does not call the always-write next open');
$assert(strpos($surveyPhp, 'bakery_survey_dual_open_for_date') === false, 'survey page does not call the always-write date open');
$assert(strpos($surveyPhp, 'bakery_survey_store_verify_assign_customer') === false, 'survey page does not assign on its own');
$assert(strpos($surveyPhp, 'bakery_tomorrow_routes_apply') === false, 'survey page does not apply route writes');
$assert(substr_count($surveyPhp, 'bakery_survey_dual_hub_links') === 1, 'survey page still mints a hub only from the open button');
$assert(strpos($commsSrc, 'bakery_survey_dual_open_if_due') !== false, 'Survey Center calls the due open');
$assert(strpos($commsSrc, 'bakery_survey_dual_open_next') === false, 'Survey Center does not call the always-write next open');
$assert(substr_count($commsSrc, 'bakery_survey_dual_hub_links') === 2, 'Survey Center still mints hubs only from its two POST actions');
$assert(strpos($commsSrc, 'bakery_survey_center_driver_label') !== false, 'Survey Center rows use the driver label helper');
$assert(strpos($commsSrc, 'texts.survey_row_driver') !== false, 'Survey Center rows label the driver');
$assert(strpos($commsSrc, 'tomorrow_routes.php') !== false, 'Survey Center links to tomorrow routes');
$assert(strpos($dailyRun, 'bakery_survey_dual_open_if_due') !== false, 'Daily Run calls the due open before SMS copy');
$assert(strpos($dailyRun, 'bakery_survey_dual_open_next') === false, 'Daily Run does not call the always-write next open');
$assert(strpos($pageSrc, "REQUEST_METHOD") !== false, 'tomorrow routes page checks the request method');
$assert(strpos($pageSrc, 'bakery_require_csrf') !== false, 'tomorrow routes page requires CSRF');
$includeSrc = is_file($tomorrowFile) ? (string)file_get_contents($tomorrowFile) : '';
$assert(strpos($includeSrc, 'tomorrow_routes.save') !== false, 'tomorrow routes forms use the save label');
$assert(strpos($pageSrc, 'bakery_tomorrow_routes_apply') !== false, 'tomorrow routes page applies posted actions');
$postAt = strpos($pageSrc, "=== 'POST'");
$applyAt = strpos($pageSrc, 'bakery_tomorrow_routes_apply');
$assert($postAt !== false && $applyAt !== false && $postAt < $applyAt, 'apply runs only after the POST check');
$assert(strpos($navSrc, 'tomorrow_routes.php') !== false, 'manager navigation links to tomorrow routes');
$sheetPos = strpos($navSrc, 'bakery-nav__more-sheet');
$surveyPos = strpos($navSrc, "BASE_URL . 'survey.php'");
$tomorrowPos = strpos($navSrc, 'tomorrow_routes.php');
$assert($sheetPos !== false && $surveyPos !== false && $tomorrowPos > $surveyPos, 'tomorrow routes sits after the survey shortcut');
$assert(strpos($catalogSrc, 'tomorrow_routes.php') !== false, 'navigation catalog lists tomorrow routes');
$assert(strpos($manifest, 'tomorrow_routes.php') !== false, 'deploy root list includes tomorrow routes');
$assert(strpos($openSrc, 'bakery_survey_dual_open_if_due') !== false, 'due open lives next to the cron helpers');
$assert(strpos($openSrc, '5 PM') !== false || strpos($openSrc, '5:00') !== false, 'open comment explains the 5 PM page exception');
$assert(strpos($healthSrc, 'survey_dual_open') !== false, 'health page reports the survey cron age');
$assert(strpos($css, 'min-height: 48px') !== false, 'tap targets are at least 48px');
$assert(strpos($dailyRouteSrc, 'bakery_daily_route_day_rows') !== false, 'Daily Route day view uses dated rows');
$assert(strpos($openSrc, "\u{2013}") === false && strpos($openSrc, "\u{2014}") === false, 'open helper comment has no dash glyphs');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
foreach ([
    'tomorrow_routes.title',
    'tomorrow_routes.kicker',
    'tomorrow_routes.unassigned_title',
    'tomorrow_routes.orphan_title',
    'tomorrow_routes.orphan_help',
    'tomorrow_routes.suggestions',
    'tomorrow_routes.zone',
    'tomorrow_routes.no_zone',
    'tomorrow_routes.driver_stops',
    'tomorrow_routes.add',
    'tomorrow_routes.skip',
    'tomorrow_routes.move',
    'tomorrow_routes.save',
    'tomorrow_routes.pick_driver',
    'tomorrow_routes.driver_off',
    'tomorrow_routes.driver_off_help',
    'tomorrow_routes.empty_driver',
    'tomorrow_routes.saved',
    'tomorrow_routes.none_open',
    'tomorrow_routes.open_link',
    'tomorrow_routes.reason',
    'texts.survey_row_driver',
    'nav.item.tomorrow_routes',
    'nav.item_desc.tomorrow_routes',
] as $key) {
    $enVal = (string)($en[$key] ?? '');
    $esVal = (string)($es[$key] ?? '');
    $assert($enVal !== '' && $esVal !== '', 'lang key ' . $key);
    $assert(strpos($enVal, "\u{2013}") === false && strpos($enVal, "\u{2014}") === false, 'en ' . $key . ' has no dash glyph');
    $assert(strpos($esVal, "\u{2013}") === false && strpos($esVal, "\u{2014}") === false, 'es ' . $key . ' has no dash glyph');
}
$assert((string)($es['tomorrow_routes.save'] ?? '') === 'Guardar', 'Spanish save button says Guardar');
$assert((string)($es['tomorrow_routes.add'] ?? '') === 'Agregar', 'Spanish add action says Agregar');
$assert((string)($es['tomorrow_routes.skip'] ?? '') === 'Saltar', 'Spanish skip action says Saltar');
$assert((string)($es['tomorrow_routes.move'] ?? '') === 'Mover', 'Spanish move action says Mover');
$assert(strpos((string)($es['driver_sms.ajustes'] ?? ''), 'Ajustes: :url') !== false, 'Spanish SMS ajustes line keeps the URL placeholder');
$assert(strpos((string)($en['driver_sms.ajustes'] ?? ''), 'Ajustes: :url') !== false, 'English SMS ajustes line keeps the URL placeholder');
$assert(strpos((string)($es['driver_sms.ajustes'] ?? ''), 'Mañana') !== false, 'SMS ajustes line says Manana');

$pt = new DateTimeZone('America/Los_Angeles');
$probeDate = '2026-10-16';
$nextSell = function_exists('bakery_survey_next_sell_date_from_db')
    ? bakery_survey_next_sell_date_from_db($db, $probeDate)
    : '';
$surveyCount = static function (PDO $db, string $date): int {
    if ($date === '' || !table_exists($db, 'surveys')) {
        return 0;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM surveys WHERE delivery_date = ?');
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn();
};
$wipeSurveys = static function (PDO $db, string $date): void {
    if ($date === '' || !table_exists($db, 'surveys')) {
        return;
    }
    $db->prepare('DELETE FROM surveys WHERE delivery_date = ?')->execute([$date]);
};

if (function_exists('bakery_survey_dual_open_if_due') && $nextSell !== '') {
    $wipeSurveys($db, $nextSell);
    $before = $surveyCount($db, $nextSell);
    $early = bakery_survey_dual_open_if_due(
        $db,
        new DateTimeImmutable($probeDate . ' 16:59:00', $pt)
    );
    $assert(($early['reason'] ?? '') === 'before_5pm', '16:59 Pacific does not open the survey');
    $assert(empty($early['wrote']), '16:59 Pacific does not write');
    $assert($surveyCount($db, $nextSell) === $before, 'survey count stays put before 5 PM');

    $utc = bakery_survey_dual_open_if_due(
        $db,
        new DateTimeImmutable($probeDate . ' 17:00:00', new DateTimeZone('UTC'))
    );
    $assert(($utc['reason'] ?? '') === 'before_5pm', '17:00 UTC is still morning in Pacific and does not open');
    $assert($surveyCount($db, $nextSell) === $before, 'survey count stays put at 17:00 UTC');

    $opened = bakery_survey_dual_open_if_due(
        $db,
        new DateTimeImmutable($probeDate . ' 17:00:00', $pt)
    );
    $assert(($opened['reason'] ?? '') === 'opened', '17:00 Pacific opens tomorrow survey');
    $assert(!empty($opened['wrote']), '17:00 Pacific writes once');
    $assert((string)($opened['delivery_date'] ?? '') === $nextSell, 'opened date is the next sell day');
    $afterOpen = $surveyCount($db, $nextSell);
    $assert($afterOpen > $before, '5 PM open inserts the dual survey rows');

    $again = bakery_survey_dual_open_if_due(
        $db,
        new DateTimeImmutable($probeDate . ' 17:05:00', $pt)
    );
    $assert(($again['reason'] ?? '') === 'already_open', 'a second 5 PM open is a no-op');
    $assert(empty($again['wrote']), 'the second open does not write');
    $assert($surveyCount($db, $nextSell) === $afterOpen, 'survey count does not grow on the second open');
    $wipeSurveys($db, $nextSell);
} else {
    $assert(false, '5 PM open can be exercised');
}

if (function_exists('bakery_survey_center_driver_label')) {
    $named = $db->query('SELECT id, name FROM drivers WHERE archived = 0 AND name <> \'\' ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($named), 'a named driver exists for Survey Center');
    if (is_array($named)) {
        $survey = bakery_survey_create($db, [
            'mode' => 'link',
            'kind' => 'store_verify',
            'audience' => 'driver',
            'driver_id' => (int)$named['id'],
            'delivery_date' => '2099-04-02',
            'title' => 'Store verify',
        ]);
        $label = bakery_survey_center_driver_label(
            (int)($survey['driver_id'] ?? 0),
            (string)$named['name'],
            'HQ'
        );
        $assert($label === (string)$named['name'], 'Survey Center shows the driver name');
        $assert($label !== 'HQ' && $label !== 'Central', 'a real driver is not labeled HQ');
        $db->prepare('DELETE FROM surveys WHERE id = ?')->execute([(int)$survey['id']]);
    }
    $assert(bakery_survey_center_driver_label(0, '', 'HQ') === 'HQ', 'HQ surveys keep the HQ label');
    $assert(bakery_survey_center_driver_label(4242, '', 'HQ') === '#4242', 'a missing driver name shows the id, not HQ');
}

$savedHost = $_SERVER['HTTP_HOST'] ?? null;
$savedHttps = $_SERVER['HTTPS'] ?? null;
$savedOrigin = getenv('BAKERY_PUBLIC_ORIGIN');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
putenv('BAKERY_PUBLIC_ORIGIN=https://staging.sourflour.org'); // pragma: allowlist secret
$_ENV['BAKERY_PUBLIC_ORIGIN'] = 'https://staging.sourflour.org'; // pragma: allowlist secret
$originUrl = bakery_survey_link_url('tokentokentokentokentokentoken12');
$assert(strpos($originUrl, 'https://staging.sourflour.org') === 0, 'missing host still builds an absolute staging URL'); // pragma: allowlist secret
$assert(strpos($originUrl, 'survey.php') !== false, 'absolute URL still points at survey.php');
$assert(strpos($originUrl, '/') !== 0, 'absolute URL is not path-only');

$_SERVER['HTTP_HOST'] = 'staging.sourflour.org'; // pragma: allowlist secret
$_SERVER['HTTPS'] = 'on';
putenv('BAKERY_PUBLIC_ORIGIN');
unset($_ENV['BAKERY_PUBLIC_ORIGIN']);
$hostUrl = bakery_survey_link_url('tokentokentokentokentokentoken12', '2099-04-02');
$assert(strpos($hostUrl, 'https://staging.sourflour.org') === 0, 'request host still builds an absolute https URL'); // pragma: allowlist secret
$assert(strpos($hostUrl, 'date=2099-04-02') !== false, 'request host URL keeps the delivery date');

if ($savedHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $savedHost;
}
if ($savedHttps === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $savedHttps;
}
if ($savedOrigin === false) {
    putenv('BAKERY_PUBLIC_ORIGIN');
    unset($_ENV['BAKERY_PUBLIC_ORIGIN']);
} else {
    putenv('BAKERY_PUBLIC_ORIGIN=' . $savedOrigin);
    $_ENV['BAKERY_PUBLIC_ORIGIN'] = $savedOrigin;
}

$date = '2099-06-16';
$weekday = (int)(new DateTimeImmutable($date))->format('N');
$drivers = $db->query('SELECT id, name FROM drivers WHERE archived = 0 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
$driverA = (int)($drivers[0]['id'] ?? 0);
$driverB = (int)($drivers[1]['id'] ?? 0);
$driverAName = (string)($drivers[0]['name'] ?? '');
$driverBName = (string)($drivers[1]['name'] ?? '');
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$prefix = 'TR Laura 20990616 ';
$customerIds = [];

$wipeRouteDay = static function (PDO $db, string $date, string $prefix) use (&$customerIds): void {
    $orderStmt = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderStmt->execute([$date]);
    $orderIds = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($orderIds !== []) {
        $in = implode(',', $orderIds);
        $db->exec('DELETE FROM daily_order_assignments WHERE daily_order_id IN (' . $in . ')');
        $db->exec('DELETE FROM daily_order_items WHERE daily_order_id IN (' . $in . ')');
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $in . ')');
    }
    if (table_exists($db, 'dated_route_removals')) {
        $db->prepare('DELETE FROM dated_route_removals WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'dated_route_removal_log')) {
        $db->prepare('DELETE FROM dated_route_removal_log WHERE delivery_date = ?')->execute([$date]);
    }
    $nameStmt = $db->prepare('SELECT id FROM customers WHERE name LIKE ?');
    $nameStmt->execute([$prefix . '%']);
    $ids = array_map('intval', $nameStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) {
        $in = implode(',', $ids);
        $db->exec('DELETE FROM standing_orders WHERE customer_id IN (' . $in . ')');
        $db->exec('DELETE FROM standing_routes WHERE customer_id IN (' . $in . ')');
        $db->exec('DELETE FROM customers WHERE id IN (' . $in . ')');
    }
    $customerIds = [];
};

$makeCustomer = static function (PDO $db, string $name, string $zone) use (&$customerIds): int {
    $stmt = $db->prepare('INSERT INTO customers (name, zone, is_active) VALUES (?, ?, 1)');
    $stmt->execute([$name, $zone]);
    $id = (int)$db->lastInsertId();
    $customerIds[] = $id;
    return $id;
};

if ($driverA > 0 && $driverB > 0 && $productId > 0 && function_exists('bakery_tomorrow_routes_apply') && function_exists('bakery_tomorrow_routes_board')) {
    $wipeRouteDay($db, $date, $prefix);
    $orphanId = $makeCustomer($db, $prefix . 'Orphan', 'Mision');
    $standingId = $makeCustomer($db, $prefix . 'Standing', 'Richmond');
    $looseId = $makeCustomer($db, $prefix . 'Loose', 'Mision');
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, 2)')
        ->execute([$orphanId, $productId, $weekday]);
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, 1)')
        ->execute([$standingId, $productId, $weekday]);
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, 1)')
        ->execute([$looseId, $productId, $weekday]);
    $db->prepare('INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, 1)')
        ->execute([$weekday, $driverA, $standingId]);
    $db->prepare('INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, 2)')
        ->execute([$weekday, $driverA, $looseId]);

    $names = static function (array $rows): array {
        return array_map(static function ($row): string {
            return (string)($row['customer_name'] ?? $row['name'] ?? '');
        }, $rows);
    };

    $board = bakery_tomorrow_routes_board($db, $date);
    $orphanNames = $names($board['orphans'] ?? []);
    $assert(in_array($prefix . 'Orphan', $orphanNames, true), 'orphan standing order with no route is listed');
    $assert(!in_array($prefix . 'Standing', $orphanNames, true), 'a store already on a standing route is not an orphan');

    $assignCount = static function (PDO $db, string $date): int {
        $stmt = $db->prepare('SELECT COUNT(*) FROM daily_order_assignments WHERE delivery_date = ?');
        $stmt->execute([$date]);
        return (int)$stmt->fetchColumn();
    };
    $beforeAssign = $assignCount($db, $date);
    $getResult = bakery_tomorrow_routes_apply($db, 'GET', [
        'action' => 'add',
        'customer_id' => $orphanId,
        'driver_id' => $driverA,
        'date' => $date,
    ], $date);
    $assert(empty($getResult['ok']), 'GET apply refuses the write');
    $assert($assignCount($db, $date) === $beforeAssign, 'GET apply does not create an assignment');

    $added = bakery_tomorrow_routes_apply($db, 'POST', [
        'action' => 'add',
        'customer_id' => $orphanId,
        'driver_id' => $driverA,
        'date' => $date,
    ], $date);
    $assert(!empty($added['ok']), 'POST add assigns the orphan');
    $dayRows = function_exists('bakery_daily_route_day_rows')
        ? bakery_daily_route_day_rows($db, $driverA, $date)
        : [];
    $dayNames = $names($dayRows);
    $assert(in_array($prefix . 'Orphan', $dayNames, true), 'Daily Route day view shows the dated orphan');
    $assert(in_array($prefix . 'Standing', $dayNames, true), 'Daily Route still shows the standing stop beside the new dated stop');
    $deliveries = route_manager_fetch_deliveries($db, $date, [$driverA]);
    $deliveryNames = [];
    foreach ($deliveries as $driverRow) {
        foreach ($driverRow['deliveries'] ?? [] as $delivery) {
            $deliveryNames[] = (string)($delivery['customer_name'] ?? '');
        }
    }
    $assert(in_array($prefix . 'Orphan', $deliveryNames, true), 'Route Manager dated stops include the assigned orphan');

    $moved = bakery_tomorrow_routes_apply($db, 'POST', [
        'action' => 'move',
        'customer_id' => $orphanId,
        'driver_id' => $driverB,
        'date' => $date,
    ], $date);
    $assert(!empty($moved['ok']), 'POST move changes the driver');
    $dayA = $names(bakery_daily_route_day_rows($db, $driverA, $date));
    $dayB = $names(bakery_daily_route_day_rows($db, $driverB, $date));
    $assert(!in_array($prefix . 'Orphan', $dayA, true), 'the original driver loses the moved stop');
    $assert(in_array($prefix . 'Orphan', $dayB, true), 'the other driver receives the moved stop');

    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    putenv('BAKERY_PUBLIC_ORIGIN=https://staging.sourflour.org'); // pragma: allowlist secret
    $_ENV['BAKERY_PUBLIC_ORIGIN'] = 'https://staging.sourflour.org'; // pragma: allowlist secret
    bakery_survey_dual_hub_links($db, $driverB, $date, 0);
    $blocks = bakery_driver_day_sms_blocks($db, $date);
    $smsBody = '';
    foreach ($blocks as $block) {
        if ((int)($block['driver_id'] ?? 0) === $driverB) {
            $smsBody = (string)($block['body'] ?? '');
        }
    }
    $assert(strpos($smsBody, 'Ajustes: https://') !== false, 'driver SMS Ajustes link is absolute https');
    $assert(strpos($smsBody, 'Ajustes: /') === false, 'driver SMS Ajustes link is not path-only');
    $db->prepare('DELETE FROM surveys WHERE delivery_date = ?')->execute([$date]);
    putenv('BAKERY_PUBLIC_ORIGIN');
    unset($_ENV['BAKERY_PUBLIC_ORIGIN']);

    $orderIdStmt = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $orderIdStmt->execute([$orphanId, $date]);
    $orphanOrderId = (int)$orderIdStmt->fetchColumn();
    $skipped = bakery_tomorrow_routes_apply($db, 'POST', [
        'action' => 'skip',
        'daily_order_id' => $orphanOrderId,
        'customer_id' => $orphanId,
        'reason' => '',
        'date' => $date,
    ], $date);
    $assert(!empty($skipped['ok']), 'POST skip with a blank reason still saves');
    $statusStmt = $db->prepare(
        'SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?'
    );
    $statusStmt->execute([$orphanOrderId, $date]);
    $assert((string)$statusStmt->fetchColumn() === 'cancelled', 'skip marks the dated stop cancelled');
    $assert(!in_array($prefix . 'Orphan', $names(bakery_daily_route_day_rows($db, $driverB, $date)), true), 'Daily Route hides the skipped stop');

    $off = bakery_tomorrow_routes_apply($db, 'POST', [
        'action' => 'driver_off',
        'driver_id' => $driverA,
        'date' => $date,
    ], $date);
    $assert(!empty($off['ok']), 'POST driver off saves');
    $assert(!in_array($prefix . 'Standing', $names(bakery_daily_route_day_rows($db, $driverA, $date)), true), 'driver off removes the standing stop from Daily Route');
    $assert(!in_array($prefix . 'Loose', $names(bakery_daily_route_day_rows($db, $driverA, $date)), true), 'driver off removes every standing stop for that driver');
    $looseOrder = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $looseOrder->execute([$looseId, $date]);
    $assert((int)$looseOrder->fetchColumn() === 0, 'driver off does not create an order for a standing-only stop');
    $afterOff = bakery_tomorrow_routes_board($db, $date);
    $suggestionNames = [];
    foreach ($afterOff['suggestions'] ?? [] as $zone => $rows) {
        foreach ($rows as $row) {
            $suggestionNames[(string)$zone][] = (string)($row['customer_name'] ?? '');
        }
    }
    $foundZone = false;
    foreach ($suggestionNames as $zone => $zoneNames) {
        if (in_array($prefix . 'Loose', $zoneNames, true) || in_array($prefix . 'Standing', $zoneNames, true)) {
            $foundZone = $zone !== '';
        }
    }
    $assert($foundZone, 'driver off suggests the freed stops by zone');

    $GLOBALS['bakery_i18n_catalog'] = null;
    bakery_set_locale('es', false);
    $html = bakery_tomorrow_routes_render($afterOff);
    $assert(strpos($html, $date) !== false, 'page shows the route date');
    $assert(strpos($html, $prefix . 'Orphan') !== false || strpos($html, $prefix . 'Loose') !== false, 'page shows a loose store');
    $assert(strpos($html, $driverAName) !== false, 'page shows the driver name');
    $assert(strpos($html, 'method="post"') !== false, 'page actions are POST forms');
    $assert(strpos($html, 'Guardar') !== false, 'page save button says Guardar');
    $assert(strpos($html, '<label') !== false && strpos($html, 'for=') !== false, 'page uses real labels');
    $assert(strpos($html, 'Agregar') !== false && strpos($html, 'Saltar') !== false && strpos($html, 'Mover') !== false, 'page offers add, skip, and move');
    $assert(strpos($html, 'tomorrow_routes.php') !== false, 'forms post back to tomorrow routes');

    $wipeRouteDay($db, $date, $prefix);
} else {
    $assert(false, 'route board fixtures can run');
}

echo "\nTomorrow routes: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

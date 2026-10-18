<?php
/**
 * After-photo gate, 5pm dual-survey open, Survey Center date, driver SMS copy.
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

require_once $root . '/includes/operational_timeline.php';
require_once $root . '/includes/surveys.php';
require_once $root . '/includes/cron_run.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/complete_delivery.php';

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

$proofFile = $root . '/includes/delivery_proof.php';
$smsFile = $root . '/includes/driver_day_sms.php';
$openFile = $root . '/includes/survey_dual_open.php';
$scriptFile = $root . '/scripts/survey_dual_open.php';
$assert(is_file($proofFile), 'delivery proof helper exists');
$assert(is_file($smsFile), 'driver day SMS helper exists');
$assert(is_file($openFile), 'dual survey open helper exists');
$assert(is_file($scriptFile), '5pm dual survey script exists');

if (is_file($proofFile)) {
    require_once $proofFile;
}
if (is_file($smsFile)) {
    require_once $smsFile;
}
if (is_file($openFile)) {
    require_once $openFile;
}

$js = (string)file_get_contents($root . '/includes/driver_delivery.js');
$confirmSection = '';
$confirmStart = strpos($js, 'async function confirmDelivery');
$confirmEnd = strpos($js, 'function finishDeliveryUi');
if ($confirmStart !== false && $confirmEnd !== false && $confirmEnd > $confirmStart) {
    $confirmSection = substr($js, $confirmStart, $confirmEnd - $confirmStart);
}
$promptAt = strpos($confirmSection, 'promptDeparturePhoto()');
$postAt = strpos($confirmSection, 'action=confirm_delivery');
$assert(
    $promptAt !== false && $postAt !== false && $promptAt < $postAt,
    'confirm prompts for the after photo before the delivered POST'
);
$assert(strpos($js, 'proof_skip=1') !== false, 'skipping the after photo is an explicit confirm flag');
$assert(
    strpos($js, "state.confirmationSaved && state.photoReturnStep === 'complete'") === false,
    'closing the after-photo prompt does not mark the stop delivered'
);
$assert(strpos($js, "hasPhotoType('After')") !== false || strpos($js, 'stopHasProofPhoto') !== false, 'an existing after photo can satisfy the prompt');

$deliverySrc = (string)file_get_contents($root . '/complete_delivery.php');
$assert(strpos($deliverySrc, 'bakery_delivery_assert_proof_for_confirm') !== false, 'confirm endpoint refuses delivery without proof or a logged skip');
$assert(strpos($deliverySrc, 'bakery_route_summary_is_pod_photo') !== false || strpos((string)@file_get_contents($proofFile), 'bakery_route_summary_is_pod_photo') !== false, 'proof check reuses the route summary photo classifier');

$scriptSrc = is_file($scriptFile) ? (string)file_get_contents($scriptFile) : '';
$assert(strpos($scriptSrc, "PHP_SAPI !== 'cli'") !== false, 'dual survey open script is CLI only');
$assert(strpos($scriptSrc, 'bakery_demand_scheduler_assert_cli') !== false, 'dual survey open uses the demand scheduler CLI guard');
$assert(strpos($scriptSrc, '0 17 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/survey_dual_open.php') !== false, 'cron line is 5 PM for the bake path');
$assert(strpos($scriptSrc, 'bakery_survey_dual_open_next') !== false, 'script opens the next sell day');
$surveyPhp = (string)file_get_contents($root . '/survey.php');
$assert(strpos($surveyPhp, 'bakery_survey_dual_open_next') === false, 'survey page GET does not open the dual survey');
$commsSrc = (string)file_get_contents($root . '/text_comms.php');
$assert(strpos($commsSrc, 'bakery_survey_dual_open_next') === false, 'Survey Center does not open surveys while rendering');
$assert(strpos($commsSrc, 'bakery_survey_center_date') !== false, 'Survey Center resolves the date in the link');
$assert(strpos($commsSrc, 'tc-survey-driver') !== false, 'Survey Center link rows name the driver');
$assert(strpos($commsSrc, 'bakery_survey_link_url') !== false, 'Survey Center open links keep the delivery date');

$dailyRun = (string)file_get_contents($root . '/daily_run.php');
$smsSrc = is_file($smsFile) ? (string)file_get_contents($smsFile) : '';
$assert(strpos($dailyRun, 'dr-driver-sms') !== false, 'Daily Run has a per-driver SMS block');
$assert(strpos($dailyRun, 'type="button"') !== false && strpos($dailyRun, 'dr-driver-sms-copy') !== false, 'copy control is a button');
$assert(strpos($dailyRun, 'navigator.clipboard') !== false, 'copy writes to the clipboard');
$assert(strpos($dailyRun, 'bakery_text_send') === false, 'Daily Run SMS block does not send');
$assert(strpos($smsSrc, 'bakery_text_send') === false, 'SMS helper does not send');
$assert(strpos($smsSrc, 'bakery_survey_dual_hub_links') === false, 'SMS helper does not mint survey rows');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
foreach ([
    'driver.proof_required',
    'daily_run.copy_driver_sms',
    'daily_run.copy_driver_sms_help',
    'daily_run.driver_sms_title',
    'texts.survey_driver_hq',
    'driver_sms.greeting',
    'driver_sms.stops_heading',
    'driver_sms.stop_line',
    'driver_sms.no_stops',
    'driver_sms.ajustes',
    'driver_sms.ajustes_missing',
    'driver_sms.skip_add',
] as $key) {
    $enVal = (string)($en[$key] ?? '');
    $esVal = (string)($es[$key] ?? '');
    $assert($enVal !== '' && $esVal !== '', 'lang key ' . $key);
    $assert(strpos($enVal, "\u{2013}") === false && strpos($enVal, "\u{2014}") === false, 'en ' . $key . ' has no en or em dash');
    $assert(strpos($esVal, "\u{2013}") === false && strpos($esVal, "\u{2014}") === false, 'es ' . $key . ' has no en or em dash');
}

if (function_exists('bakery_survey_center_date')) {
    $weekdays = [1, 2, 3, 4, 5, 6];
    $assert(
        bakery_survey_center_date('', '2026-10-16', $weekdays) === '2026-10-17',
        'Survey Center defaults to the next delivery day'
    );
    $assert(
        bakery_survey_center_date('2026-10-20', '2026-10-16', $weekdays) === '2026-10-20',
        'Survey Center honors the date in the link'
    );
    $assert(
        bakery_survey_center_date('not-a-date', '2026-10-16', $weekdays) === '2026-10-17',
        'a junk Survey Center date falls back to the next delivery day'
    );
} else {
    $assert(false, 'bakery_survey_center_date exists');
}

if (function_exists('bakery_driver_day_sms_body')) {
    $body = bakery_driver_day_sms_body([
        'driver_name' => 'Marcos',
        'date' => '2026-10-16',
        'stops' => ['Cafe Norte', 'Luna'],
        'ajustes_url' => 'https://bakery.example/bake/survey.php?t=abc&date=2026-10-16',
    ]);
    $assert(strpos($body, 'Hola Marcos,') === 0, 'SMS starts with a Spanish greeting');
    $assert(strpos($body, '1. ') !== false && strpos($body, '2. ') !== false, 'SMS numbers the stops');
    $assert(strpos($body, '2026-10-16') !== false || strpos($body, '16 de octubre') !== false, 'SMS dates the stops');
    $assert(strpos($body, 'Cafe Norte') !== false && strpos($body, 'Luna') !== false, 'SMS names the stops');
    $assert(strpos($body, 'Ajustes: https://bakery.example/bake/survey.php?t=abc&date=2026-10-16') !== false, 'SMS carries that driver ajustes link');
    $assert(strpos($body, 'SKIP/ADD:') !== false, 'SMS has a SKIP/ADD line');
    $missing = bakery_driver_day_sms_body([
        'driver_name' => 'Marcos',
        'date' => '2026-10-16',
        'stops' => [],
        'ajustes_url' => '',
    ]);
    $assert(strpos($missing, 'Ajustes:') !== false && strpos($missing, 'http') === false, 'missing survey link is not invented');
} else {
    $assert(false, 'bakery_driver_day_sms_body exists');
}

$date = '2099-12-01';
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$customerIds = array_map('intval', $db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
$customerId = (int)($customerIds[0] ?? 0);
$afterCustomerId = (int)($customerIds[1] ?? 0);
$receiptCustomerId = (int)($customerIds[2] ?? 0);
$product = $db->query('SELECT id, price FROM products WHERE price > 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$wipeOrders = static function (PDO $db, string $date): void {
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) {
        $in = implode(',', $ids);
        if (table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ({$in})");
        }
        if (table_exists($db, 'driver_photos')) {
            $db->prepare('DELETE FROM driver_photos WHERE delivery_date = ?')->execute([$date]);
        }
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
    }
    if (table_exists($db, 'surveys')) {
        $db->prepare('DELETE FROM surveys WHERE delivery_date = ?')->execute([$date]);
    }
};

if ($driverId > 0 && $customerId > 0 && $afterCustomerId > 0 && $receiptCustomerId > 0 && is_array($product) && function_exists('bakery_delivery_assert_proof_for_confirm')) {
    $wipeOrders($db, $date);
    $price = round((float)$product['price'], 2);
    $routeOrder = 0;
    $insertOrder = static function (PDO $db, int $customerId, int $driverId, string $date, float $price, int $productId) use (&$routeOrder): int {
        $routeOrder++;
        $db->prepare(
            "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'out_for_delivery', ?)"
        )->execute([$customerId, $date, $price]);
        $orderId = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 1, ?, ?)'
        )->execute([$orderId, $productId, $price, $price]);
        $db->prepare(
            "INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$orderId, $driverId, $date, $routeOrder]);
        return $orderId;
    };
    $insertPhoto = static function (PDO $db, int $driverId, int $customerId, string $date, string $type): void {
        $db->prepare(
            'INSERT INTO driver_photos
                (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
             VALUES (?, ?, ?, ?, ?, ?, 10, ?, ?)'
        )->execute([
            $driverId,
            $customerId,
            $date,
            $type . '.jpg',
            $type . '.jpg',
            'test/' . $type . '.jpg',
            'image/jpeg',
            $type,
        ]);
    };

    $bareId = $insertOrder($db, $customerId, $driverId, $date, $price, (int)$product['id']);
    $threw = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $bareId, false);
    } catch (Throwable $e) {
        $threw = true;
        $assert(strpos($e->getMessage(), 'after photo') !== false || strpos($e->getMessage(), 'foto') !== false, 'missing proof explains the after photo');
    }
    $assert($threw, 'a stop without an after photo cannot be marked delivered');

    $insertPhoto($db, $driverId, $customerId, $date, 'Before');
    $arrivalThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $bareId, false);
    } catch (Throwable $e) {
        $arrivalThrew = true;
    }
    $assert($arrivalThrew, 'an arrival photo is not proof of delivery');

    bakery_delivery_assert_proof_for_confirm($db, $bareId, true);
    $skipCount = 0;
    if (table_exists($db, 'operational_events')) {
        $skipStmt = $db->prepare(
            "SELECT COUNT(*) FROM operational_events WHERE daily_order_id = ? AND event_type = 'delivery_proof_photo_skipped'"
        );
        $skipStmt->execute([$bareId]);
        $skipCount = (int)$skipStmt->fetchColumn();
    }
    $assert($skipCount === 1, 'an explicit after-photo skip is logged once');
    bakery_delivery_assert_proof_for_confirm($db, $bareId, true);
    if (table_exists($db, 'operational_events')) {
        $skipStmt->execute([$bareId]);
        $assert((int)$skipStmt->fetchColumn() === 1, 'repeating the skip does not add another log');
    }

    $db->prepare('DELETE FROM driver_photos WHERE delivery_date = ? AND customer_id = ?')->execute([$date, $customerId]);
    $afterId = $insertOrder($db, $afterCustomerId, $driverId, $date, $price, (int)$product['id']);
    $insertPhoto($db, $driverId, $afterCustomerId, $date, 'After');
    $afterThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $afterId, false);
    } catch (Throwable $e) {
        $afterThrew = true;
    }
    $assert(!$afterThrew, 'an after photo lets the stop be marked delivered');

    $receiptId = $insertOrder($db, $receiptCustomerId, $driverId, $date, $price, (int)$product['id']);
    $insertPhoto($db, $driverId, $receiptCustomerId, $date, 'Receipt');
    $receiptThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $receiptId, false);
    } catch (Throwable $e) {
        $receiptThrew = true;
    }
    $assert(!$receiptThrew, 'a receipt photo counts as proof');

    $db->prepare("UPDATE daily_orders SET status = 'delivered' WHERE id = ?")->execute([$bareId]);
    $db->prepare('DELETE FROM driver_photos WHERE delivery_date = ?')->execute([$date]);
    if (table_exists($db, 'operational_events')) {
        $db->prepare('DELETE FROM operational_events WHERE daily_order_id = ?')->execute([$bareId]);
    }
    $alreadyThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $bareId, false);
    } catch (Throwable $e) {
        $alreadyThrew = true;
    }
    $assert(!$alreadyThrew, 'an already delivered stop can be saved again');
    $wipeOrders($db, $date);
} else {
    $assert(false, 'proof gate can be exercised on bakerysf_test');
}

if (function_exists('bakery_survey_dual_open_for_date') && table_exists($db, 'surveys') && $driverId > 0) {
    $db->prepare('DELETE FROM surveys WHERE delivery_date = ?')->execute([$date]);
    $first = bakery_survey_dual_open_for_date($db, $date, 0);
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM surveys WHERE delivery_date = ? AND status = 'open' AND kind IN ('store_verify', 'route_order')"
    );
    $countStmt->execute([$date]);
    $openedCount = (int)$countStmt->fetchColumn();
    $assert($openedCount >= 4, 'open creates HQ and driver verify plus order rows');
    $driverVerify = bakery_survey_find_store_verify($db, $driverId, $date);
    $hqVerify = bakery_survey_find_store_verify($db, 0, $date);
    $assert($driverVerify !== [] && $hqVerify !== [], 'driver and HQ store-verify surveys are open');
    $assert((string)($first['delivery_date'] ?? '') === $date, 'open reports the requested delivery date');
    bakery_survey_dual_open_for_date($db, $date, 0);
    $countStmt->execute([$date]);
    $assert((int)$countStmt->fetchColumn() === $openedCount, 'opening again does not duplicate surveys');
    if (function_exists('bakery_survey_dual_open_next')) {
        $beforeNext = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM surveys')->fetchColumn();
        $next = bakery_survey_dual_open_next($db, new DateTimeImmutable('2026-10-16 17:00:00', new DateTimeZone('America/Los_Angeles')));
        $expected = bakery_survey_next_sell_date_from_db($db, '2026-10-16');
        $assert((string)($next['delivery_date'] ?? '') === $expected, '5pm open targets the next sell day');
        $assert((string)($next['today'] ?? '') === '2026-10-16', '5pm open records the Pacific day it ran');
        $db->prepare('DELETE FROM surveys WHERE id > ?')->execute([$beforeNext]);
    }
    $db->prepare('DELETE FROM surveys WHERE delivery_date = ?')->execute([$date]);
} else {
    $assert(false, 'dual survey open can be exercised on bakerysf_test');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

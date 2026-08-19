<?php
/**
 * Desktop exception workshop contracts (local/test DB only).
 * Usage: php tests/run_exception_workshop_tests.php
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
require_once $root . '/includes/operational_exceptions.php';
require_once $root . '/includes/exception_workshop.php';
require_once $root . '/includes/delivery_recovery.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: workshop tests must run with APP_ENV=local\n");
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

$a = bakery_ops_exception([
    'type' => 'delivery_qty_variance',
    'severity' => 'warning',
    'category' => 'delivery',
    'title' => 'Wrong qty',
    'customer_id' => 42,
]);
$b = bakery_ops_exception([
    'type' => 'invoice_uninvoiced',
    'severity' => 'warning',
    'category' => 'invoice',
    'title' => 'Uninvoiced',
    'customer_id' => 42,
]);
$c = bakery_ops_exception([
    'type' => 'production_fg_shortfall',
    'severity' => 'critical',
    'category' => 'production',
    'title' => 'Short bake',
    'customer_id' => 7,
]);
$groups = bakery_exception_workshop_group([$a, $b, $c], 'customer');
$byKey = [];
foreach ($groups as $group) {
    $byKey[$group['key']] = $group;
}
$assert(isset($byKey['customer:42']) && (int)$byKey['customer:42']['count'] === 2, 'Group-by customer clusters two exceptions that share context.customer_id');
$assert(isset($byKey['customer:7']) && (int)$byKey['customer:7']['count'] === 1, 'Group-by customer keeps a different customer in its own cluster');

$threw = false;
try {
    bakery_exception_workshop_bulk_complete($db, [$a], '2099-08-17', ['missing-key'], ['resolution_note' => '']);
} catch (InvalidArgumentException $e) {
    $threw = true;
    $assert(stripos($e->getMessage(), 'note') !== false, 'Bulk complete without a note mentions the note requirement');
} catch (Throwable $e) {
    $threw = true;
    $assert(false, 'Bulk complete without a note should be InvalidArgumentException, got ' . get_class($e));
}
$assert($threw, 'Bulk complete without a note throws / is rejected');

$delivered = 0;
$notDelivered = 0;
if (table_exists($db, 'daily_orders')) {
    $delivered = (int)$db->query(
        "SELECT id FROM daily_orders WHERE delivery_confirmed_at IS NOT NULL AND status <> 'invoiced' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    $notDelivered = (int)$db->query(
        "SELECT id FROM daily_orders WHERE delivery_confirmed_at IS NULL ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
}
if ($delivered > 0) {
    $ids = bakery_exception_workshop_delivered_order_ids($db, [$delivered, $notDelivered, $delivered + 999999, 0]);
    $assert($ids === [$delivered], 'Bulk mark-invoiced eligibility only includes selected delivered order ids');
} else {
    $ids = bakery_exception_workshop_delivered_order_ids($db, [0, -3]);
    $assert($ids === [], 'Bulk mark-invoiced eligibility is empty when no delivered ids are selected');
}

$markSource = file_get_contents($root . '/includes/exception_workshop.php');
$assert($markSource !== false && strpos($markSource, 'function bakery_exception_workshop_mark_invoiced') !== false, 'Workshop mark-invoiced helper exists');
$assert(
    preg_match('/function bakery_exception_workshop_mark_invoiced.*?eligibleMap/s', $markSource) === 1,
    'Workshop mark-invoiced only loops ids that passed the delivered-order filter'
);

$genSource = '';
if (preg_match('/function bakery_exception_workshop_generate_orders\(.*?\n\}/s', $markSource, $m)) {
    $genSource = $m[0];
}
$assert(
    strpos($genSource, "'overwrite_changed' => false") !== false
        && strpos($genSource, "'overwrite_changed' => true") === false,
    'Generate-from-workshop uses overwrite_changed=false'
);

ob_start();
bakery_exception_workshop_render($db, '2099-08-17', [$a, $b], ['mobile_only' => true]);
$mobileHtml = ob_get_clean();
$assert($mobileHtml === '', 'Workshop markup is absent from a simulated mobile-only render helper');

$css = file_get_contents($root . '/css/exception_workshop.css');
$assert(
    $css !== false
        && strpos($css, '.exception-workshop') !== false
        && preg_match('/min-width:\s*900px/', $css) === 1,
    'CSS class exception-workshop is documented as min-width: 900px'
);

$recoverySrc = file_get_contents($root . '/includes/delivery_recovery.php');
$assert($recoverySrc !== false, 'Recovery source is readable');
$assert(strpos($recoverySrc, 'bakery_billing_mark_invoiced') === false, 'Recovery does not call mark-invoiced');
$assert(
    !preg_match("/status\s*=\s*'paid'|status\s*=\s*\"paid\"/", $recoverySrc),
    'Recovery still cannot mark an invoice paid'
);
$paidBlocked = false;
try {
    bakery_delivery_recovery_validate_input('mark_paid', ['manager_note' => 'Mark it paid']);
} catch (InvalidArgumentException $e) {
    $paidBlocked = true;
}
$assert($paidBlocked, 'Recovery rejects a mark-paid action');

$page = file_get_contents($root . '/manager.php');
$assert(
    $page !== false && strpos($page, 'bakery_exception_workshop_render') !== false,
    'Manager Mode inserts the workshop render call'
);
$assert(
    $page !== false && strpos($page, 'manager-workshop-host') !== false,
    'Manager Mode wraps the workshop in manager-workshop-host'
);

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$missingEs = [];
foreach ($en as $key => $_text) {
    if (strpos($key, 'workshop.') === 0 && !isset($es[$key])) {
        $missingEs[] = $key;
    }
}
$assert($missingEs === [], 'Workshop i18n keys exist in es.php' . ($missingEs ? (' missing: ' . implode(',', $missingEs)) : ''));

echo "\nException workshop tests: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Surface-hygiene regression gate: keeps the deployable root clean after the
 * 2026-08-22 sweep (see docs/QUARANTINE_INVENTORY.md).
 * Usage: php tests/run_surface_hygiene_tests.php   (filesystem-only, no DB)
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function surface_hygiene_assert($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "PASS  $message\n";
        $passed++;
    } else {
        echo "FAIL  $message\n";
        $failed++;
    }
}

/* 1. Root scratch patterns stay gone. Each pattern must match zero files. */
$junkPatterns = [
    'blah*.php',
    'debug*.php',
    'simple-debug.php',
    'table_debug.php',
    'db_test.php',
    'test*.php',
    'test.html',
    'test_*.ps1',
    '*_backup.php',
    '*_fixed.php',
    '*_optimized.php',
    '*_working.php',
    '*Copy*.php',
    'probe_smoke_*.php',
    'route_tester.php',
    'run_sql_setup.php',
    'check_photo_db.php',
    'find_photo_ids.php',
    'simple_performance_test.php',
    '.htaccess.bak',
];
foreach ($junkPatterns as $pattern) {
    $hits = glob($root . '/' . $pattern);
    surface_hygiene_assert(empty($hits), "root has no $pattern");
}

/* 2. Quarantined legacy invoice pages remain redirect-only via the shared billing quarantine
 *    emitter (includes/billing.php); tests/run_invoice_send_tests.php depends on them. */
$redirectPages = [
    'generate_invoice.php',
    'generate_invoice_simple.php',
    'simple_invoice.php',
];
foreach ($redirectPages as $page) {
    $path = $root . '/' . $page;
    $exists = file_exists($path);
    $delegatesToEmitter = $exists && (strpos((string)file_get_contents($path), 'bakery_billing_legacy_generator_emit_quarantine') !== false);
    surface_hygiene_assert($exists && $delegatesToEmitter, "$page exists and delegates to the billing quarantine emitter");
}
$invoiceCenterPath = $root . '/invoice_center.php';
$centerRedirects = file_exists($invoiceCenterPath)
    && (stripos((string)file_get_contents($invoiceCenterPath), 'billing_center') !== false);
surface_hygiene_assert($centerRedirects, 'invoice_center.php exists and redirects toward Billing Center');

/* 2b. The dead customer_upcoming.php link is repaired as a quarantined portal
 *     redirect stub (Deliveries tab / dated edit screen) and must stay redirect-only. */
$upcomingStubPath = $root . '/customer_upcoming.php';
$upcomingStub = file_exists($upcomingStubPath) ? (string)file_get_contents($upcomingStubPath) : '';
surface_hygiene_assert($upcomingStub !== '', 'customer_upcoming.php exists as a redirect stub');
surface_hygiene_assert(
    $upcomingStub !== '' && strpos($upcomingStub, "header('Location: ')") === false
        && strpos($upcomingStub, "header('Location: '") !== false
        && preg_match('/exit\s*;/s', $upcomingStub) === 1,
    'customer_upcoming.php redirects with Location header and exits'
);
surface_hygiene_assert(
    $upcomingStub !== '' && stripos($upcomingStub, '<!DOCTYPE') === false
        && stripos($upcomingStub, '<html') === false,
    'customer_upcoming.php renders no HTML'
);
surface_hygiene_assert(
    strpos($upcomingStub, 'customer_portal_calendar.php') !== false
        && strpos($upcomingStub, 'customer_upcoming_edit.php') !== false,
    'customer_upcoming.php targets the live portal deliveries screens'
);
$portalListSrc = (string)@file_get_contents($root . '/includes/customer_portal.php');
surface_hygiene_assert(
    preg_match("/'customer_upcoming\.php'/", $portalListSrc) === 1,
    'customer_upcoming.php is registered in bakery_customer_portal_scripts()'
);

/* 3. Nav link-rot tripwire: every page referenced by the nav includes must exist at the root. */
$navRefs = [];
$catalogSrc = (string)@file_get_contents($root . '/includes/navigation_catalog.php');
if (preg_match_all('/\'href\'\s*=>\s*\'([a-z0-9_]+\.php)\'/', $catalogSrc, $m)) {
    $navRefs = array_merge($navRefs, $m[1]);
}
$historicalSrc = (string)@file_get_contents($root . '/includes/nav_historical.php');
if (preg_match_all('/BASE_URL;\s*\?>([a-z0-9_]+\.php)/', $historicalSrc, $m)) {
    $navRefs = array_merge($navRefs, $m[1]);
}
$navRefs = array_values(array_unique($navRefs));
surface_hygiene_assert(count($navRefs) > 20, 'nav link extraction found the expected reference set');
$missingNavTargets = [];
foreach ($navRefs as $ref) {
    if (!file_exists($root . '/' . $ref)) {
        $missingNavTargets[] = $ref;
    }
}
surface_hygiene_assert(empty($missingNavTargets), 'every nav-referenced page exists (' . implode(', ', $missingNavTargets) . ')');

/* 4. Loose SQL stays either archived or on the gitignored PII keep-list. */
$allowedRootSql = [
    'bakerysf_schema.sql',
    'update_customer_addresses.sql',
    'update_customer_addresses_correct.sql',
    'update_customer_coordinates.sql',
    'update_final_customer_coordinates.sql',
    'update_missing_customer_coordinates.sql',
    'add_delivery_times.sql',
];
$straySql = [];
foreach (glob($root . '/*.sql') ?: [] as $sqlPath) {
    $name = basename($sqlPath);
    if (!in_array($name, $allowedRootSql, true)) {
        $straySql[] = $name;
    }
}
surface_hygiene_assert(empty($straySql), 'no unarchived loose SQL patches at root (' . implode(', ', $straySql) . ')');

/* 5. The archived patch set survived the move intact. */
$expectedPatches = [
    'add_coordinates_to_customers.sql',
    'add_default_quantity_columns.sql',
    'assign_dough_types_to_product_lines.sql',
    'create_daily_order_assignments_table.sql',
    'product_lines_setup.sql',
    'setup_photo_functionality.sql',
    'standing_orders_performance_optimization.sql',
];
foreach ($expectedPatches as $patch) {
    surface_hygiene_assert(file_exists($root . '/docs/archive/sql-patches/' . $patch), "archive holds $patch");
}

/* 6. The retired nav key must not resurface in either language file alone. */
$enHasRetiredKey = strpos((string)@file_get_contents($root . '/lang/en.php'), 'page.route_tester') !== false;
$esHasRetiredKey = strpos((string)@file_get_contents($root . '/lang/es.php'), 'page.route_tester') !== false;
surface_hygiene_assert(!$enHasRetiredKey && !$esHasRetiredKey, "retired 'page.route_tester' key absent from both lang files");

echo "\nSurface hygiene: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

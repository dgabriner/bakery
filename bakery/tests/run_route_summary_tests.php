<?php
/**
 * Route Summary contracts: photo-first assembly, snapshot amounts, i18n, nav.
 *
 * Usage: php tests/run_route_summary_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

$root = dirname(__DIR__);
require_once $root . '/includes/route_manager_cash.php';
require_once $root . '/includes/route_manager.php';
require_once $root . '/includes/route_summary.php';

$failed = 0;

function route_summary_assert(string $label, bool $ok): void
{
    global $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    echo "FAIL  {$label}\n";
    $failed++;
}

route_summary_assert(
    'invalid dates fall back to today',
    route_manager_parse_date('nope', '2026-08-18') === '2026-08-18'
        && route_manager_parse_date('2026-08-18') === '2026-08-18'
);

route_summary_assert(
    'hero photo prefers After over Before and Receipt',
    (bakery_route_summary_choose_hero_photo([
        ['photo_type' => 'Before', 'url' => 'before.jpg'],
        ['photo_type' => 'Receipt', 'url' => 'receipt.jpg'],
        ['photo_type' => 'After', 'url' => 'after.jpg'],
    ])['url'] ?? '') === 'after.jpg'
);

route_summary_assert(
    'hero photo falls back to the first image when types are unknown',
    (bakery_route_summary_choose_hero_photo([
        ['photo_type' => 'Shelf', 'url' => 'shelf.jpg'],
    ])['url'] ?? '') === 'shelf.jpg'
);

$delivered = [
    'payment_collection' => 'cod',
    'delivery_status' => 'delivered',
    'amount_collected' => 12.5,
    'delivery_order_total' => 40,
    'total_amount' => 99,
    'order_total_estimate' => 88,
    'delivered_pieces' => 6,
    'item_count' => 10,
];
route_summary_assert(
    'sold amount uses recorded COD cash, not a live catalog price',
    bakery_route_summary_sold_amount($delivered) === 12.5
);
route_summary_assert(
    'delivered pieces prefer the confirmation snapshot',
    bakery_route_summary_pieces($delivered) === 6
);

$attached = route_manager_attach_pickup_manifests(
    [7 => ['id' => 7, 'name' => 'Mina', 'deliveries' => []]],
    [7 => [['name' => 'Concha', 'loaded_quantity' => 40], ['name' => 'Bolillo', 'loaded_quantity' => 12]]]
);
route_summary_assert(
    'pickup manifest counts saved load pieces, not live catalog prices',
    ($attached[7]['pickup_sku_count'] ?? 0) === 2
        && ($attached[7]['pickup_piece_count'] ?? 0) === 52
        && ($attached[7]['pickup_manifest'][0]['name'] ?? '') === 'Concha'
);
route_summary_assert(
    'drivers without a saved load still get an empty pickup manifest',
    ($attached[7]['pickup_manifest'] ?? null) !== null
        && route_manager_attach_pickup_manifests([3 => ['deliveries' => []]], [])[3]['pickup_sku_count'] === 0
);

$snapshotOnly = [
    'delivery_status' => 'delivered',
    'payment_collection' => 'signature',
    'delivery_order_total' => 41.25,
    'total_amount' => 99,
    'item_count' => 4,
];
route_summary_assert(
    'signature sold amount uses the delivery snapshot total',
    bakery_route_summary_sold_amount($snapshotOnly) === 41.25
);

$driversData = [
    7 => [
        'id' => 7,
        'name' => 'Ana',
        'deliveries' => [
            [
                'daily_order_id' => 11,
                'customer_id' => 21,
                'customer_name' => 'Cafe Luna',
                'delivery_status' => 'delivered',
                'payment_collection' => 'cod',
                'amount_collected' => 20,
                'delivery_order_total' => 20,
                'total_amount' => 20,
                'item_count' => 8,
                'route_order' => 1,
                'scheduled_delivery_time' => '08:00:00',
            ],
            [
                'daily_order_id' => 12,
                'customer_id' => 22,
                'customer_name' => 'Market',
                'delivery_status' => 'pending',
                'payment_collection' => 'cod',
                'delivery_order_total' => 15,
                'total_amount' => 15,
                'item_count' => 3,
                'route_order' => 2,
            ],
            [
                'daily_order_id' => 13,
                'customer_id' => 23,
                'customer_name' => 'Closed Shop',
                'delivery_status' => 'cancelled',
                'payment_collection' => 'cod',
                'total_amount' => 50,
                'item_count' => 9,
                'route_order' => 3,
            ],
        ],
    ],
];
$photos = [
    '7:21' => [
        ['photo_type' => 'Before', 'url' => 'b.jpg'],
        ['photo_type' => 'After', 'url' => 'a.jpg'],
    ],
];
$day = bakery_route_summary_build_day($driversData, $photos, 'all');
route_summary_assert('day counts every assigned stop', (int)$day['stats']['stops'] === 3);
route_summary_assert('cancelled stops are excluded from amount sold', (float)$day['stats']['sold'] === 35.0);
route_summary_assert('missing-photo count skips cancelled stops', (int)$day['stats']['missing_photos'] === 1);
route_summary_assert('hero photo is attached to the delivered stop', ($day['drivers'][0]['stops'][0]['hero_photo']['url'] ?? '') === 'a.jpg');

$missing = bakery_route_summary_build_day($driversData, $photos, 'missing');
route_summary_assert(
    'missing-photo filter hides stops that already have photos',
    count($missing['drivers'][0]['stops'] ?? []) === 1
        && ($missing['drivers'][0]['stops'][0]['customer_name'] ?? '') === 'Market'
);

$coverageDrivers = [
    4 => [
        'id' => 4,
        'name' => 'Marcos',
        'deliveries' => [
            [
                'daily_order_id' => 41,
                'customer_id' => 401,
                'customer_name' => 'Arrival Only',
                'delivery_status' => 'delivered',
                'payment_collection' => 'cod',
                'amount_collected' => 10,
                'item_count' => 2,
                'route_order' => 1,
            ],
            [
                'daily_order_id' => 42,
                'customer_id' => 402,
                'customer_name' => 'Proof After',
                'delivery_status' => 'delivered',
                'payment_collection' => 'cod',
                'amount_collected' => 10,
                'item_count' => 2,
                'route_order' => 2,
            ],
            [
                'daily_order_id' => 43,
                'customer_id' => 403,
                'customer_name' => 'Proof Receipt',
                'delivery_status' => 'delivered',
                'payment_collection' => 'signature',
                'delivery_order_total' => 8,
                'item_count' => 1,
                'route_order' => 3,
            ],
            [
                'daily_order_id' => 44,
                'customer_id' => 404,
                'customer_name' => 'No Photo',
                'delivery_status' => 'delivered',
                'payment_collection' => 'cod',
                'amount_collected' => 4,
                'item_count' => 1,
                'route_order' => 4,
            ],
            [
                'daily_order_id' => 45,
                'customer_id' => 405,
                'customer_name' => 'Pending Arrival',
                'delivery_status' => 'pending',
                'payment_collection' => 'cod',
                'total_amount' => 6,
                'item_count' => 1,
                'route_order' => 5,
            ],
        ],
    ],
];
$coveragePhotos = [
    '4:401' => [['photo_type' => 'Before', 'url' => 'arrive.jpg']],
    '4:402' => [['photo_type' => 'After', 'url' => 'leave.jpg'], ['photo_type' => 'Before', 'url' => 'arrive-too.jpg']],
    '4:403' => [['photo_type' => 'Receipt', 'url' => 'receipt.jpg']],
    '4:405' => [['photo_type' => 'Before', 'url' => 'pending-arrive.jpg']],
];
$coverage = bakery_route_summary_build_day($coverageDrivers, $coveragePhotos, 'all');
$coverageStops = [];
foreach ($coverage['drivers'][0]['stops'] ?? [] as $stop) {
    $coverageStops[(string)$stop['customer_name']] = $stop;
}
route_summary_assert(
    'a delivered stop counts as with photo only when it has a proof photo',
    (int)$coverage['stats']['with_photos'] === 2
);
route_summary_assert(
    'arrival and proof photos are counted separately',
    (int)$coverage['stats']['arrival_photo_count'] === 3
        && (int)$coverage['stats']['pod_photo_count'] === 2
);
route_summary_assert(
    'delivered stops with only an arrival photo are flagged and counted as missing proof',
    (int)$coverage['stats']['arrival_only'] === 1
        && (int)$coverage['stats']['missing_photos'] === 3
        && !empty($coverageStops['Arrival Only']['arrival_only'])
        && empty($coverageStops['Arrival Only']['has_pod_photo'])
        && !empty($coverageStops['Arrival Only']['has_arrival_photo'])
        && empty($coverageStops['Proof After']['arrival_only'])
        && !empty($coverageStops['Proof After']['has_pod_photo'])
        && !empty($coverageStops['Proof Receipt']['has_pod_photo'])
        && empty($coverageStops['Pending Arrival']['arrival_only'])
);
$coverageMissing = bakery_route_summary_build_day($coverageDrivers, $coveragePhotos, 'missing');
$coverageMissingNames = array_map(static function ($stop) {
    return (string)$stop['customer_name'];
}, $coverageMissing['drivers'][0]['stops'] ?? []);
sort($coverageMissingNames);
route_summary_assert(
    'missing-photo filter includes delivered stops that only have an arrival photo',
    $coverageMissingNames === ['Arrival Only', 'No Photo', 'Pending Arrival']
);
$coverageWith = bakery_route_summary_build_day($coverageDrivers, $coveragePhotos, 'photos');
$coverageWithNames = array_map(static function ($stop) {
    return (string)$stop['customer_name'];
}, $coverageWith['drivers'][0]['stops'] ?? []);
sort($coverageWithNames);
route_summary_assert(
    'with-photo filter keeps proof photos and drops arrival-only stops',
    $coverageWithNames === ['Proof After', 'Proof Receipt']
);

$page = (string)file_get_contents($root . '/route_summary.php');
$css = (string)file_get_contents($root . '/css/route_summary.css');
$js = (string)file_get_contents($root . '/includes/route_summary.js');
$managerPage = (string)file_get_contents($root . '/route_manager.php');
$managerJs = (string)file_get_contents($root . '/includes/route_manager.js');
$managerAll = $managerPage . "\n" . $managerJs;
$english = require $root . '/lang/en.php';
$spanish = require $root . '/lang/es.php';

route_summary_assert('page is a photo-first grid', strpos($page, 'class="rs-grid"') !== false && strpos($css, '.rs-card__photo') !== false);
route_summary_assert('cards show sold amount and driver', strpos($page, 'rs-card__sold') !== false && strpos($page, 'driver_name') !== false);
route_summary_assert('page links back to the Route Manager board', strpos($page, 'route_manager.php?date=') !== false);
route_summary_assert('lightbox script is present', strpos($js, 'rsLightbox') !== false);
route_summary_assert('Route Manager links to Route Summary', strpos($managerPage, 'route_summary.php?date=') !== false);
route_summary_assert('Route Manager uses the shared include', strpos($managerPage, "require_once 'includes/route_manager.php'") !== false);
route_summary_assert('Route Manager reorders through the canonical helper', strpos($managerPage, 'bakery_driver_reorder_remaining_stops') !== false);
route_summary_assert('Route Manager no longer rewrites route_order directly', strpos($managerPage, 'SET route_order = ?') === false);
route_summary_assert('Route Manager ignores stale async responses', strpos($managerAll, 'deliveriesRequestSeq') !== false && strpos($managerAll, 'trackingRequestSeq') !== false);
route_summary_assert('Route Manager background refreshes delivery state', strpos($managerAll, 'loadDeliveries({ background: true })') !== false);

$requiredKeys = [
    'page.route_summary',
    'nav.item.route_summary',
    'nav.item_desc.route_summary',
    'route_summary.subtitle',
    'route_summary.stat_sold',
    'route_summary.filter_missing',
    'route_summary.no_photo',
    'route_summary.arrival_only',
    'route_summary.arrival_only_help',
    'route_summary.stops_with_proof',
    'closeout.stops_heading',
    'closeout.photo_pod',
    'closeout.photo_arrival_only',
    'closeout.photo_arrival',
    'closeout.photo_none',
    'closeout.no_stops',
    'route_manager.pickup_manifest',
    'route_manager.pickup_summary',
    'route_manager.no_pickup',
    'route_manager.edit_pickup_loads',
    'route_manager.unit_trays',
    'route_manager.col_per_box',
];
foreach ($requiredKeys as $key) {
    route_summary_assert("en has {$key}", isset($english[$key]) && $english[$key] !== '');
    route_summary_assert("es has {$key}", isset($spanish[$key]) && $spanish[$key] !== '');
}

$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
route_summary_assert('Delivery nav includes Route Summary', strpos($catalog, "'href' => 'route_summary.php'") !== false);

require_once $root . '/includes/photo_handler.php';
route_summary_assert(
    'Live delivery photo URL keeps the /bake/ base',
    bakery_upload_display_url('driver_photos', '2026/10/proof.jpg', '/bake/') === '/bake/uploads/driver_photos/2026/10/proof.jpg'
);
route_summary_assert(
    'Staging delivery photo URL stays at the site root',
    bakery_upload_display_url('driver_photos', '2026/10/proof.jpg', '/') === '/uploads/driver_photos/2026/10/proof.jpg'
);
route_summary_assert(
    'A stored /uploads path is rewritten onto the app base',
    bakery_upload_display_url('driver_photos', '/uploads/driver_photos/2026/10/proof.jpg', '/bake/') === '/bake/uploads/driver_photos/2026/10/proof.jpg'
);
route_summary_assert(
    'A legacy host /uploads URL is rewritten onto the app base',
    bakery_upload_display_url('driver_photos', 'https://bakery.sourflour.org/uploads/driver_photos/2026/10/proof.jpg', '/bake/') === '/bake/uploads/driver_photos/2026/10/proof.jpg'
);
route_summary_assert(
    'Shop photos use the same base-path helper',
    bakery_upload_display_url('shop_photos', '2026/10/window.jpg', '/') === '/uploads/shop_photos/2026/10/window.jpg'
);
$photoHandlerSrc = (string)file_get_contents($root . '/includes/photo_handler.php');
$shopPhotoSrc = (string)file_get_contents($root . '/includes/shop_photo_handler.php');
route_summary_assert(
    'Driver photo URLs go through the login handler',
    strpos($photoHandlerSrc, 'https://bakery.sourflour.org/uploads/driver_photos/') === false
        && strpos($photoHandlerSrc, 'driver_photo.php?file=') !== false
);
route_summary_assert(
    'Shop photo URLs are not hard-coded to the site-root host',
    strpos($shopPhotoSrc, 'https://bakery.sourflour.org/uploads/shop_photos/') === false
        && strpos($shopPhotoSrc, 'bakery_upload_display_url(') !== false
);
route_summary_assert('Route summary thumbnails come from photo URL fields', strpos((string)file_get_contents($root . '/route_summary.php'), "\$hero['url']") !== false);
route_summary_assert(
    'Route Summary flags a delivered stop that only has an arrival photo',
    strpos($page, 'is-arrival-only') !== false && strpos($page, 'route_summary.arrival_only') !== false
);
$closeoutPage = (string)file_get_contents($root . '/route_closeout.php');
route_summary_assert(
    'closeout page lists each stop with its photo status',
    strpos($closeoutPage, 'bakery_route_closeout_stops') !== false
        && strpos($closeoutPage, 'closeout-stops') !== false
        && strpos($closeoutPage, 'closeout.photo_arrival_only') !== false
);
foreach (['route_summary.arrival_only', 'route_summary.arrival_only_help', 'closeout.photo_pod', 'closeout.photo_arrival_only', 'closeout.stops_heading'] as $plainKey) {
    $enValue = (string)($english[$plainKey] ?? '');
    $esValue = (string)($spanish[$plainKey] ?? '');
    route_summary_assert(
        "{$plainKey} has no dash punctuation",
        $enValue !== '' && $esValue !== '' && $enValue !== $esValue
            && strpos($enValue, "\u{2014}") === false && strpos($enValue, "\u{2013}") === false
            && strpos($esValue, "\u{2014}") === false && strpos($esValue, "\u{2013}") === false
    );
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} Route Summary checks failed\n");
    exit(1);
}

echo "All Route Summary checks passed\n";
exit(0);

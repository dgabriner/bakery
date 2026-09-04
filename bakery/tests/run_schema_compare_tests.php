<?php
/** Schema inventory compare: equal, Live behind, or discrepancy. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/schema_inventory.php';
require_once $root . '/includes/schema_migration_numbers.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$fail = 0;
$assert = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

function schema_compare_fixture(array $columns, array $indexes = [], array $migrations = [], string $database = 'bakerysf'): array
{
    ksort($columns, SORT_STRING);
    ksort($indexes, SORT_STRING);
    $canonical = json_encode(['columns' => $columns, 'indexes' => $indexes], JSON_UNESCAPED_SLASHES);
    return [
        'format' => 1,
        'captured_at' => '2026-01-01T00:00:00+00:00',
        'database' => $database,
        'hash' => hash('sha256', (string)$canonical),
        'column_count' => count($columns),
        'index_count' => count($indexes),
        'migration_ids' => $migrations,
        'columns' => $columns,
        'indexes' => $indexes,
    ];
}

$baseColumns = ['customers.id' => 'int|NO|auto_increment', 'customers.name' => 'varchar(255)|NO|'];
$baseIndexes = ['customers.PRIMARY' => '1:id'];
$staging = schema_compare_fixture($baseColumns, $baseIndexes, ['049_done']);
$live = schema_compare_fixture($baseColumns, $baseIndexes, ['049_done']);
$equal = bakery_schema_inventory_compare($staging, $live);
$assert(($equal['state'] ?? '') === 'equal', 'identical inventories are equal');

$behindColumns = $baseColumns;
$behindColumns['customers.nickname'] = 'varchar(80)|YES|';
$behind = bakery_schema_inventory_compare(
    schema_compare_fixture($behindColumns, $baseIndexes, ['049_done', '050_nickname']),
    $live
);
$assert(($behind['state'] ?? '') === 'live_behind', 'Staging-only column is Live-behind');
$assert(in_array('customers.nickname', $behind['missing_on_live'], true), 'behind lists the missing column');
$assert(in_array('050_nickname', $behind['staging_only_migrations'], true), 'behind lists the pending migration id');

$indexBehind = bakery_schema_inventory_compare(
    schema_compare_fixture($baseColumns, $baseIndexes + ['customers.idx_name' => '0:name'], ['049_done']),
    $live
);
$assert(($indexBehind['state'] ?? '') === 'live_behind', 'Staging-only index is Live-behind');

$ledgerBehind = bakery_schema_inventory_compare(
    schema_compare_fixture($baseColumns, $baseIndexes, ['049_done', '050_recorded']),
    $live
);
$assert(($ledgerBehind['state'] ?? '') === 'equal', 'ledger-only Staging id is Match when structure matches');

$extraLive = $baseColumns;
$extraLive['customers.shadow'] = 'varchar(10)|YES|';
$discrepancyExtra = bakery_schema_inventory_compare(
    $staging,
    schema_compare_fixture($extraLive, $baseIndexes, ['049_done'])
);
$assert(($discrepancyExtra['state'] ?? '') === 'discrepancy', 'Live-only column is a discrepancy');

$extraIndexLive = bakery_schema_inventory_compare(
    $staging,
    schema_compare_fixture($baseColumns, $baseIndexes + ['text_messages.uq_text_messages_twilio_sid' => '1:twilio_sid'], ['049_done'])
);
$assert(($extraIndexLive['state'] ?? '') === 'equal', 'Live-only index is listed, not Stop');
$assert(in_array('index:text_messages.uq_text_messages_twilio_sid', $extraIndexLive['extra_on_live'], true), 'Live-only index is named');

$renamedUnique = bakery_schema_inventory_compare(
    schema_compare_fixture($baseColumns, $baseIndexes + ['text_messages.uq_text_messages_sid' => '1:twilio_sid'], ['049_done']),
    schema_compare_fixture($baseColumns, $baseIndexes + ['text_messages.uq_text_messages_twilio_sid' => '1:twilio_sid'], ['049_done'])
);
$assert(($renamedUnique['state'] ?? '') === 'equal', 'same unique columns under different names are Match');
$assert(!in_array('index:text_messages.uq_text_messages_sid', $renamedUnique['missing_on_live'] ?? [], true), 'renamed unique is not missing');
$assert(!in_array('index:text_messages.uq_text_messages_twilio_sid', $renamedUnique['extra_on_live'] ?? [], true), 'renamed unique is not extra');

$behindWithLiveIndex = bakery_schema_inventory_compare(
    schema_compare_fixture($behindColumns, $baseIndexes, ['049_done', '050_nickname']),
    schema_compare_fixture($baseColumns, $baseIndexes + ['text_messages.uq_text_messages_twilio_sid' => '1:twilio_sid'], ['049_done'])
);
$assert(($behindWithLiveIndex['state'] ?? '') === 'live_behind', 'Live-only index does not hide Staging additions');

$mismatchLive = $baseColumns;
$mismatchLive['customers.name'] = 'text|NO|';
$discrepancyType = bakery_schema_inventory_compare(
    $staging,
    schema_compare_fixture($mismatchLive, $baseIndexes, ['049_done'])
);
$assert(($discrepancyType['state'] ?? '') === 'discrepancy', 'type mismatch is a discrepancy');
$assert(in_array('customers.name', $discrepancyType['mismatches'], true), 'type mismatch names the column');

$liveKind = $baseColumns + ['sfb_offerings.kind' => "enum('class','membership','kit')|NO|"];
$stagingKind = $baseColumns + ['sfb_offerings.kind' => "enum('class','membership','kit','donation','credits')|NO|"];
$enumWiden = bakery_schema_inventory_compare(
    schema_compare_fixture($stagingKind, $baseIndexes, ['066_payments', '067_bread_education_offerings_v2']),
    schema_compare_fixture($liveKind, $baseIndexes, ['066_payments'])
);
$assert(($enumWiden['state'] ?? '') === 'equal', 'appending ENUM values is not a type clash');
$assert(!in_array('sfb_offerings.kind', $enumWiden['mismatches'] ?? [], true), 'widened kind is not listed as a mismatch');

$enumWidenBehind = bakery_schema_inventory_compare(
    schema_compare_fixture(
        $stagingKind + ['sfb_offerings.units' => 'int|YES|'],
        $baseIndexes,
        ['066_payments', '067_bread_education_offerings_v2']
    ),
    schema_compare_fixture($liveKind, $baseIndexes, ['066_payments'])
);
$assert(($enumWidenBehind['state'] ?? '') === 'live_behind', '067-style enum widen plus new column is Live-behind');
$assert(in_array('067_bread_education_offerings_v2', $enumWidenBehind['staging_only_migrations'] ?? [], true), '067 remains the pending Live update');

$enumReorder = bakery_schema_inventory_compare(
    schema_compare_fixture($baseColumns + ['sfb_offerings.kind' => "enum('credits','class')|NO|"], $baseIndexes, ['049_done']),
    schema_compare_fixture($baseColumns + ['sfb_offerings.kind' => "enum('class','credits')|NO|"], $baseIndexes, ['049_done'])
);
$assert(($enumReorder['state'] ?? '') === 'discrepancy', 'reordered ENUM values stay a mismatch');

$discrepancyLedger = bakery_schema_inventory_compare(
    $staging,
    schema_compare_fixture($baseColumns, $baseIndexes, ['049_done', '050_hot_fix'])
);
$assert(($discrepancyLedger['state'] ?? '') === 'equal', 'ledger-only Live id is Match when structure matches');

$viewNoise = schema_compare_fixture(
    $baseColumns + ['v_daily_routes.delivery_status' => "enum('pending')|YES|"],
    $baseIndexes,
    ['049_done']
);
$viewEqual = bakery_schema_inventory_compare($staging, $viewNoise);
$assert(($viewEqual['state'] ?? '') === 'equal', 'Live-only view columns are ignored');
$assert(!in_array('v_daily_routes.delivery_status', $viewEqual['extra_on_live'] ?? [], true), 'view extras are stripped');

$wrongDb = bakery_schema_inventory_compare(
    $staging,
    schema_compare_fixture($baseColumns, $baseIndexes, ['049_done'], 'otherdb')
);
$assert(($wrongDb['state'] ?? '') === 'discrepancy', 'unexpected Live database name is a discrepancy');

$assert(bakery_schema_inventory_normalize_type('INT(11)') === 'int', 'integer display width is ignored');
$assert(bakery_schema_inventory_normalize_type('varchar(255)') === 'varchar(255)', 'varchar length is kept');
$assert(bakery_schema_inventory_normalize_type('JSON') === 'longtext', 'MySQL JSON and MariaDB LONGTEXT JSON alias is normalized');

$jsonCompatibility = bakery_schema_inventory_compare(
    schema_compare_fixture(['operational_events.metadata' => 'longtext|YES|'], [], ['049_done']),
    schema_compare_fixture(['operational_events.metadata' => 'json|YES|'], [], ['049_done'])
);
$assert(($jsonCompatibility['state'] ?? '') === 'equal', 'JSON and LONGTEXT metadata columns do not block cross-engine promotion');

$inventory = bakery_schema_inventory_from_pdo($db);
$public = bakery_schema_inventory_public($inventory);
$encoded = json_encode($public);
$assert(preg_match('/^[a-f0-9]{64}$/', (string)($public['hash'] ?? '')), 'live dump has a sha256 hash');
$assert(!isset($public['table_rows']) && strpos((string)$encoded, 'TABLE_ROWS') === false, 'public inventory omits row counts');
$assert(!isset($public['auto_increment']) && strpos((string)$encoded, 'DATA_LENGTH') === false, 'public inventory omits table data stats');
$assert(($public['database'] ?? '') === 'bakerysf_test', 'dump reports the selected test database');

$statusSource = (string)file_get_contents($root . '/schema_status.php');
$assert(strpos($statusSource, "define('BAKERY_SKIP_REQUEST_SECURITY', true)") !== false, 'schema status skips the login gate');
$assert(strpos($statusSource, 'bakery_is_live_bakery_host') !== false, 'schema status is Live-only');
$assert(strpos($statusSource, '$e->getMessage()') === false, 'schema status does not print exception detail');

require_once $root . '/includes/hosted_migration_approval.php';
require_once $root . '/includes/staging_live_approval.php';

$assert(bakery_pacific_display_time('2026-08-24T01:02:06+00:00') === 'Aug 23, 2026 6:02 PM PDT', 'UTC Live stamp formats as Pacific');
$assert(bakery_pacific_display_time('2026-08-23T18:01:39-07:00') === 'Aug 23, 2026 6:01 PM PDT', 'already-Pacific stamp stays Pacific and readable');

$assert(bakery_staging_live_next_step('unknown', false, false, 'missing') === 'promote_files', 'unknown schema asks for a file send');
$assert(bakery_staging_live_next_step('unknown', false, true, 'timeout') === 'retry', 'timeout after applied migration asks for refresh');
$assert(bakery_staging_live_next_step('unknown', false, true, '') === 'retry', 'applied migration asks to refresh, not wait');
$assert(bakery_staging_live_next_step('live_behind', true) === 'migrate', 'behind with a file asks for a database update');
$assert(bakery_staging_live_next_step('live_behind', false) === 'migrate_missing', 'behind without a file does not invent one');
$assert(bakery_staging_live_next_step('equal', false) === 'done', 'matching schemas are done');
$assert(bakery_staging_live_next_step('discrepancy', true) === 'stop', 'mismatch stops a database update');
$assert(bakery_staging_live_next_step('live_behind', true, true, '', true) === 'retry', 'stale report after apply asks for refresh, not another send');
$assert(bakery_hosted_schema_unavailable_reason('<html>not found</html>', 404) === 'missing', 'Live 404 is a missing report file');
$assert(bakery_hosted_schema_unavailable_reason('', 0) === 'timeout', 'empty body is a timeout');
$assert(bakery_hosted_schema_unavailable_reason('{"status":"unavailable"}', 503) === 'refused', 'Live unavailable JSON is refused');
$assert(bakery_hosted_schema_unavailable_reason('{"hash":"abc","columns":{},"indexes":{}}', 200) === '', 'valid inventory is available');
$assert(bakery_hosted_migration_succeeded(['status' => 'succeeded', 'migration_id' => '050_driver_trusted_devices']), '050 success counts as applied');
$assert(bakery_staging_live_unknown_detail_key('timeout', true) === 'manager.live_db_unknown_timeout', 'timeout uses timeout copy');
$assert(bakery_staging_live_unknown_detail_key('missing', true) === 'manager.live_db_unknown_applied', 'applied 050 uses the applied copy');
$portableSql = (string)file_get_contents($root . '/database/schema/054_live_product_pack_yields_mysql_compat.sql');
[$portableSafe, $portableMessage] = bakery_hosted_migration_sql_safe($portableSql);
$assert($portableSafe, '054 portable Live SQL is allow-listed: ' . $portableMessage);
$legacySql = (string)file_get_contents($root . '/database/schema/053_live_product_pack_yields.sql');
[$legacySafe] = bakery_hosted_migration_sql_safe($legacySql);
$assert(!$legacySafe, '053 TEXT-default incompatibility is refused before Live approval');
$assert(strpos($managerSource = (string)file_get_contents($root . '/manager.php'), 'manager-live-state') !== false, 'Manager shows a large database state banner');
$assert(function_exists('bakery_schema_inventory_for_live_publish'), 'Live schema publish uses a cache helper');
$picked = bakery_staging_live_recommended_migration(
    ['state' => 'live_behind', 'staging_only_migrations' => ['050_nickname']],
    [['id' => '050_nickname', 'file' => '050_nickname.sql', 'safe' => true]]
);
$assert(is_array($picked) && $picked['file'] === '050_nickname.sql', 'recommended migration follows the missing ledger id');
$pickedPastSuperseded = bakery_staging_live_recommended_migration(
    ['state' => 'live_behind', 'staging_only_migrations' => ['053_old', '054_portable']],
    [
        ['id' => '053_old', 'file' => '053_old.sql', 'safe' => false],
        ['id' => '054_portable', 'file' => '054_portable.sql', 'safe' => true],
    ]
);
$assert(is_array($pickedPastSuperseded) && $pickedPastSuperseded['file'] === '054_portable.sql', 'unsafe superseded ledger ids do not hide one exact safe migration');
$pickedFirstSafe = bakery_staging_live_recommended_migration(
    ['state' => 'live_behind', 'staging_only_migrations' => ['056_square', '057_text', '059_bolillo']],
    [
        ['id' => '056_square', 'file' => '056_square.sql', 'safe' => true],
        ['id' => '057_text', 'file' => '057_text.sql', 'safe' => true],
        ['id' => '059_bolillo', 'file' => '059_bolillo.sql', 'safe' => true],
    ]
);
$assert(is_array($pickedFirstSafe) && $pickedFirstSafe['file'] === '056_square.sql', 'several safe pending migrations recommend the earliest file, not a greyed-out guess');
$remainingSafe = bakery_staging_live_recommended_migrations(
    ['state' => 'live_behind', 'staging_only_migrations' => ['056_square', '057_text', '059_bolillo']],
    [
        ['id' => '056_square', 'file' => '056_square.sql', 'safe' => true],
        ['id' => '057_text', 'file' => '057_text.sql', 'safe' => true],
        ['id' => '059_bolillo', 'file' => '059_bolillo.sql', 'safe' => true],
    ]
);
$assert(array_column($remainingSafe, 'file') === ['056_square.sql', '057_text.sql', '059_bolillo.sql'], 'catch-up queues every remaining safe migration in file order');
$notPicked = bakery_staging_live_recommended_migration(
    ['state' => 'live_behind', 'staging_only_migrations' => []],
    [['id' => '050_nickname', 'file' => '050_nickname.sql', 'safe' => true]]
);
$assert($notPicked === null, 'recommendation is never guessed from the only available file');

$kindStopBoard = bakery_staging_live_relax_067_kind_stop([
    'compare' => [
        'state' => 'discrepancy',
        'mismatches' => ['sfb_offerings.kind'],
        'extra_on_live' => ['index:sfb_credit_entries.PRIMARY'],
        'staging_only_migrations' => ['067_bread_education_offerings_v2'],
        'unexpected_database' => false,
    ],
    'candidates' => [[
        'id' => '067_bread_education_offerings_v2',
        'file' => '067_bread_education_offerings_v2.sql',
        'safe' => true,
    ]],
    'recommended' => null,
    'stale_after_apply' => false,
    'migration_status' => null,
]);
$assert(($kindStopBoard['compare']['state'] ?? '') === 'live_behind', '067 kind-only Stop becomes Live-behind');
$assert(($kindStopBoard['recommended']['file'] ?? '') === '067_bread_education_offerings_v2.sql', '067 is recommended after kind relax');
$assert(($kindStopBoard['next'] ?? '') === 'migrate', 'kind relax opens the database button');

$realKindClash = bakery_staging_live_relax_067_kind_stop([
    'compare' => [
        'state' => 'discrepancy',
        'mismatches' => ['sfb_offerings.kind', 'customers.name'],
        'staging_only_migrations' => ['067_bread_education_offerings_v2'],
    ],
    'candidates' => [[
        'id' => '067_bread_education_offerings_v2',
        'file' => '067_bread_education_offerings_v2.sql',
        'safe' => true,
    ]],
    'recommended' => null,
    'stale_after_apply' => false,
    'migration_status' => null,
]);
$assert(($realKindClash['compare']['state'] ?? '') === 'discrepancy', 'other type clashes stay Stop');

$giftStopBoard = bakery_staging_live_relax_067_kind_stop([
    'compare' => [
        'state' => 'discrepancy',
        'mismatches' => ['sfb_offerings.kind', 'sfb_offering_purchases.paid_with'],
        'extra_on_live' => [],
        'staging_only_migrations' => ['071_bread_education_purchase_home', '072_first_loaf_kit'],
        'unexpected_database' => false,
    ],
    'candidates' => [
        [
            'id' => '071_bread_education_purchase_home',
            'file' => '071_bread_education_purchase_home.sql',
            'safe' => true,
        ],
        [
            'id' => '072_first_loaf_kit',
            'file' => '072_first_loaf_kit.sql',
            'safe' => true,
        ],
    ],
    'recommended' => null,
    'stale_after_apply' => false,
    'migration_status' => null,
]);
$assert(($giftStopBoard['compare']['state'] ?? '') === 'live_behind', '071 gift ENUM Stop becomes Live-behind');
$assert(($giftStopBoard['recommended']['file'] ?? '') === '071_bread_education_purchase_home.sql', '071 is recommended after gift relax');
$assert(($giftStopBoard['next'] ?? '') === 'migrate', 'gift relax opens the database button');
$assert(array_column($giftStopBoard['recommended_all'] ?? [], 'id') === [
    '071_bread_education_purchase_home',
    '072_first_loaf_kit',
], '071 relax keeps 072 in the remainder queue');

$job067 = [
    'id' => '067_bread_education_offerings_v2',
    'file' => '067_bread_education_offerings_v2.sql',
    'safe' => true,
];
$queueFromBoard = bakery_hosted_migration_queue_from_board([
    'compare' => [
        'state' => 'live_behind',
        'staging_only_migrations' => ['067_bread_education_offerings_v2'],
    ],
    'candidates' => [$job067],
    'recommended_all' => [$job067],
], '062_surveys_custom.sql');
$assert(($queueFromBoard[0]['file'] ?? '') === '067_bread_education_offerings_v2.sql', 'approve queues the board remainder, not a second first-file guess');

$postedOnly = bakery_hosted_migration_queue_from_board([
    'compare' => [
        'state' => 'discrepancy',
        'mismatches' => ['sfb_offerings.kind'],
        'staging_only_migrations' => ['067_bread_education_offerings_v2'],
    ],
    'candidates' => [$job067],
    'recommended_all' => [],
], '067_bread_education_offerings_v2.sql');
$assert(($postedOnly[0]['file'] ?? '') === '067_bread_education_offerings_v2.sql', 'a remaining safe posted file still queues when older compare says Stop');

$assert(bakery_schema_unexpected_duplicate_prefixes() === [], 'only historical 010/021/025/062/073 prefix pairs exist');
$assert(bakery_schema_next_migration_number() === 78, 'next unused schema number is 078');
$assert(bakery_schema_next_migration_id('demo_feature') === '078_demo_feature', 'next id is 078_ plus slug');
$third062 = bakery_schema_migration_ids_from_dir();
$third062[] = '062_another_collision';
$assert(isset(bakery_schema_unexpected_duplicate_prefixes($third062)['062']), 'a third 062 file is rejected');

$assert(strpos($managerSource, "if (\$schemaState === 'unknown'):") === false, 'refresh compare is not hidden on Match or Stop');
$assert(strpos($managerSource, 'manager-live-board') !== false, 'Manager shows the Staging to Live board');
$assert(strpos($managerSource, 'bakery_staging_live_board') !== false, 'Manager uses the live board helper');
$assert(strpos($managerSource, 'manager-live-history') !== false, 'Manager keeps Staging to Live history collapsed');
$assert(strpos($managerSource, 'Apply an additive database migration to Live') === false, 'Manager does not hide the status in a collapsed button');

$queued = bakery_staging_live_history_from_approval([
    'status' => 'approved_for_live',
    'release_id' => 'stage-20260823-010203-abc123',
    'approved_at' => '2026-08-23T01:02:03+00:00',
    'approved_by' => 'test@example.com',
    'file_count' => 2,
    'files' => [
        ['path' => 'manager.php', 'size' => 10, 'sha256' => str_repeat('a', 64)],
        ['path' => 'includes/foo.php', 'size' => 11, 'sha256' => str_repeat('b', 64)],
    ],
], 'files');
$assert($queued['status'] === 'queued' && count($queued['files']) === 2, 'queued file history keeps the snapshot file list');

$tmpHistory = sys_get_temp_dir() . '/sf-history-' . bin2hex(random_bytes(3)) . '.json';
$first = bakery_hosted_status_history_append($tmpHistory, [
    'status' => 'succeeded',
    'release_id' => 'stage-20260823-010203-abc123',
    'completed_at' => '2026-08-23T01:03:00+00:00',
    'file_count' => 400,
    'changed_file_count' => 2,
    'changed_files' => ['manager.php', 'css/manager.css'],
    'public_message' => '2 changed file(s) from the approved Staging version are now Live.',
], 'files');
$again = bakery_hosted_status_history_append($tmpHistory, [
    'status' => 'succeeded',
    'release_id' => 'stage-20260823-010203-abc123',
    'completed_at' => '2026-08-23T01:03:00+00:00',
    'public_message' => 'duplicate',
], 'files');
$assert(count($first) === 1 && count($again) === 1, 'Live history dedupes the same terminal snapshot');
$promoting = bakery_hosted_status_history_append($tmpHistory, [
    'status' => 'promoting',
    'release_id' => 'stage-20260823-010204-def456',
    'started_at' => '2026-08-23T01:04:00+00:00',
], 'files');
$assert(count($promoting) === 1, 'in-progress Live status is not stored as history');
@unlink($tmpHistory);

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
foreach ([
    'manager.live_title', 'manager.live_db_match', 'manager.live_db_behind', 'manager.live_db_stop', 'manager.live_db_unknown',
    'manager.live_next_retry', 'manager.live_db_unknown_applied', 'manager.live_db_unknown_missing', 'manager.live_db_unknown_timeout',
    'manager.live_retry',
    'manager.live_no_exact_update', 'manager.live_worker_waiting', 'manager.live_db_extra_ok',
    'manager.live_next_stale', 'manager.live_db_stale_after_apply', 'manager.live_db_report_at',
    'manager.live_history', 'manager.live_history_summary', 'manager.live_history_filter_failed',
    'manager.live_send_db_all', 'manager.live_db_remaining', 'manager.live_db_details',
] as $key) {
    $assert(isset($en[$key], $es[$key]) && $en[$key] !== '' && $es[$key] !== '', 'i18n key ' . $key);
}
$assert(stripos($en['manager.live_db_unknown_applied'], 'wait about a minute') === false, 'applied copy does not tell bakers to wait');
$assert(stripos($en['manager.live_db_unknown_detail'], 'wait about a minute') === false, 'unknown copy does not tell bakers to wait');

echo $fail . " failed\n";
exit($fail === 0 ? 0 : 1);

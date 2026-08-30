<?php
/**
 * Driver store-verify survey — next delivery day toggles, SMS body, log payload.
 * No bakerysf_test. Pure helpers + source/lang contracts.
 *
 * Usage: php tests/run_survey_store_verify_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/survey_store_verify.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  {$msg}\n";
        $pass++;
        return;
    }
    echo "FAIL  {$msg}\n";
    $fail++;
};

// ---- Next delivery date is sell/delivery day, not bake day -------------------
$assert(
    bakery_survey_next_delivery_date('2026-08-21', [1, 2, 3, 4, 5, 6]) === '2026-08-22',
    'Friday bake day → next delivery is Saturday (sell day), not Friday'
);
$assert(
    bakery_survey_next_delivery_date('2026-08-22', [1, 2, 3, 4, 5, 6, 7]) === '2026-08-23',
    'Saturday → next delivery is Sunday (sell day), not Monday'
);
$assert(
    bakery_survey_next_delivery_date('2026-08-29', [1, 2, 3, 4, 5, 6, 7]) === '2026-08-30',
    'Sat night 2026-08-29 → Sunday 2026-08-30 deliveries'
);
$assert(
    bakery_survey_next_delivery_date('2026-08-22') === '2026-08-23',
    'default weekdays include Sunday so Sat night is Sunday'
);
$assert(
    bakery_survey_next_delivery_date('2026-08-23', [1, 2, 3, 4, 5, 6]) === '2026-08-24',
    'Sunday (typical SF bake for Monday) → next delivery is Monday'
);
$assert(
    bakery_survey_next_delivery_date('2026-08-24', [1, 2, 3, 4, 5, 6]) === '2026-08-25',
    'Monday delivery day → next is Tuesday'
);
try {
    bakery_survey_next_delivery_date('not-a-date');
    $assert(false, 'invalid from-date rejected');
} catch (RuntimeException $e) {
    $assert(true, 'invalid from-date rejected');
}

// ---- HQ SMS destination -----------------------------------------------------
$assert(bakery_survey_hq_sms_number() === '+14155091210', 'HQ SMS number is +14155091210');

// ---- Partition + defaults ---------------------------------------------------
$stores = [
    ['id' => 10, 'name' => 'Tamalero'],
    ['id' => 11, 'name' => 'Bi-Rite'],
    ['id' => 12, 'name' => 'Rainbow'],
    ['id' => 99, 'name' => 'Other Cafe'],
];
$split = bakery_survey_store_verify_partition($stores, [10, 11, 12]);
$assert(count($split['assigned']) === 3 && count($split['other']) === 1, 'assigned stores listed before other');
$assert((int)$split['assigned'][0]['id'] === 10 && (int)$split['other'][0]['id'] === 99, 'partition keeps assigned vs other ids');

$defaults = bakery_survey_store_verify_default_on_ids($split['assigned'], $split['other']);
$assert($defaults === [10, 11, 12], 'assigned stores default ON; others default OFF');

$choice = bakery_survey_store_verify_collect([10, 12, 99], $split['assigned'], $split['other']);
$assert(
    array_column($choice['on'], 'id') === [10, 12, 99]
        && array_column($choice['off'], 'id') === [11]
        && (int)$choice['assigned_off_count'] === 1,
    'posted toggles keep ON set and count assigned turned off'
);

// ---- SMS body is short and includes driver, date, ON stores -----------------
$sms = bakery_survey_store_verify_sms_body([
    'driver_name' => 'Maria',
    'delivery_date' => '2026-08-25',
    'on' => [
        ['id' => 10, 'name' => 'Tamalero'],
        ['id' => 12, 'name' => 'Rainbow'],
    ],
    'assigned_off_count' => 1,
]);
$assert(
    strpos($sms, 'Maria') !== false
        && strpos($sms, '2026-08-25') !== false
        && strpos($sms, 'Tamalero') !== false
        && strpos($sms, 'Rainbow') !== false
        && strpos($sms, '1') !== false,
    'SMS names the driver, date, ON stores, and assigned-off count'
);
$assert(strlen($sms) <= 320, 'SMS body stays short for a phone text');

$payload = bakery_survey_store_verify_log_payload([
    'driver_id' => 7,
    'driver_name' => 'Maria',
    'delivery_date' => '2026-08-25',
    'on' => [['id' => 10, 'name' => 'Tamalero']],
    'off' => [['id' => 11, 'name' => 'Bi-Rite']],
    'assigned_off_count' => 1,
    'created_at' => '2026-08-24 21:00:00',
]);
$assert(
    (int)$payload['driver_id'] === 7
        && $payload['delivery_date'] === '2026-08-25'
        && isset($payload['on'][0]['id'], $payload['off'][0]['name'], $payload['created_at'])
        && (int)$payload['assigned_off_count'] === 1,
    'log payload stores driver, date, timestamp, and on/off store ids+names'
);

// ---- Token is the auth for open store_verify / route_review -----------------
$assert(
    bakery_survey_token_allows_public([
        'kind' => 'store_verify',
        'status' => 'open',
        'token' => 'aabbccddeeff00112233445566778899',
    ]) === true,
    'open store_verify token is public'
);
$assert(
    bakery_survey_token_allows_public([
        'kind' => 'route_review',
        'status' => 'open',
    ]) === true,
    'open route_review token is public'
);
$assert(
    bakery_survey_token_allows_public(['kind' => 'question', 'status' => 'open']) === false,
    'question survey still requires login'
);
$assert(
    bakery_survey_token_allows_public(['kind' => 'store_verify', 'status' => 'closed']) === false,
    'closed store_verify is not public'
);
$assert(bakery_survey_token_allows_public([]) === false, 'missing survey is not public');
$assert(bakery_survey_page_needs_login('', []) === true, 'no token still requires login');
$assert(
    bakery_survey_page_needs_login('nope', []) === true,
    'invalid/missing token still requires login'
);
$assert(
    bakery_survey_page_needs_login('aabbccddeeff00112233445566778899', [
        'kind' => 'store_verify',
        'status' => 'open',
    ]) === false,
    'valid open store_verify token does not require login'
);
$assert(
    bakery_survey_page_needs_identity([
        'kind' => 'store_verify',
        'status' => 'open',
    ]) === false,
    'identity not required with a valid public token'
);
$assert(
    bakery_survey_page_needs_identity(['kind' => 'question', 'status' => 'open']) === true,
    'identity still required without a public token'
);

$hqGroups = [
    [
        'driver_id' => 1,
        'driver_name' => 'Maria',
        'assigned' => [['id' => 10, 'name' => 'Tamalero']],
        'other' => [['id' => 99, 'name' => 'Other Cafe']],
    ],
    [
        'driver_id' => 2,
        'driver_name' => 'Luis',
        'assigned' => [['id' => 11, 'name' => 'Bi-Rite']],
        'other' => [['id' => 99, 'name' => 'Other Cafe']],
    ],
];
$hq = bakery_survey_store_verify_collect_hq([
    1 => [10],
    2 => [11, 99],
], $hqGroups);
$assert(count($hq['drivers']) === 2, 'HQ combined groups by driver');
$assert(
    $hq['drivers'][0]['driver_name'] === 'Maria'
        && array_column($hq['drivers'][0]['on'], 'id') === [10]
        && (int)$hq['drivers'][0]['assigned_off_count'] === 0,
    'HQ Maria keeps assigned Tamalero ON'
);
$assert(
    $hq['drivers'][1]['driver_name'] === 'Luis'
        && array_column($hq['drivers'][1]['on'], 'id') === [11, 99]
        && (int)$hq['drivers'][1]['assigned_off_count'] === 0,
    'HQ Luis can turn an other store ON'
);
$hqSms = bakery_survey_store_verify_sms_body($hq + ['delivery_date' => '2026-08-30', 'driver_name' => 'HQ']);
$assert(
    strpos($hqSms, 'Maria') !== false && strpos($hqSms, 'Luis') !== false && strpos($hqSms, '2026-08-30') !== false,
    'HQ SMS names each driver and the delivery date'
);

// ---- Page + helpers stay on the existing survey -----------------------------
$surveyPhp = (string)file_get_contents($root . '/survey.php');
$surveysInc = (string)file_get_contents($root . '/includes/surveys.php');
$assert(strpos($surveyPhp, "action') === 'verify_stores'") !== false
    || strpos($surveyPhp, 'action === \'verify_stores\'') !== false, 'survey.php accepts verify_stores submit');
$assert(strpos($surveyPhp, 'bakery_survey_store_verify') !== false, 'survey.php renders store-verify helpers');
$assert(strpos($surveysInc, 'survey_store_verify.php') !== false, 'surveys.php includes store-verify helpers');
$commsSrc = (string)file_get_contents($root . '/text_comms.php');
$assert(strpos($commsSrc, 'surveyComposerDate') !== false, 'Text Comms survey date defaults to next delivery day');
$helperSrc = (string)file_get_contents($root . '/includes/survey_store_verify.php');
$assert(strpos($helperSrc, 'bakery_text_send') !== false, 'SMS still goes through bakery_text_send');
$assert(strpos($surveyPhp, "\$surveyKind === 'question'") !== false, 'question form is gated off store-verify');
$assert(strpos($helperSrc, 'standing_routes') !== false && strpos($helperSrc, 'standing_orders') !== false, 'other stores require a delivery relationship');
$assert(strpos($surveyPhp, 'bakery_survey_store_verify_hq_data') !== false, 'HQ combined page loads every driver');
$assert(strpos($surveyPhp, 'store_on[') !== false, 'HQ checkboxes are namespaced by driver id');
$assert(strpos($surveysInc, 'driver_id IS NULL OR driver_id = 0') !== false, 'ensure reuses HQ store_verify for the date');
$assert(strpos($commsSrc, 'texts.survey_driver_all') !== false, 'Text Comms offers HQ all-drivers option');
$assert(strpos($commsSrc, 'bakery_survey_ensure_store_verify') !== false, 'composer reuses HQ survey via ensure');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$keys = [
    'survey.store_verify_title',
    'survey.store_verify_sub',
    'survey.store_verify_driver',
    'survey.store_verify_assigned',
    'survey.store_verify_other',
    'survey.store_verify_on',
    'survey.store_verify_off',
    'survey.store_verify_submit',
    'survey.store_verify_done',
    'survey.store_verify_sms_failed',
    'survey.store_verify_no_stores',
    'survey.action_store_verify',
    'survey.msg_store_verify_link',
    'texts.survey_kind_stores',
    'survey.store_verify_hq_title',
    'survey.store_verify_all_drivers',
    'texts.survey_driver_all',
];
$authSrc = (string)file_get_contents($root . '/includes/auth.php');
$assert(strpos($authSrc, "'survey.php'") !== false, 'survey.php is in the public-script door so enforce_request_security does not 302');
$assert(
    preg_match('/bakery_survey_page_needs_login[\s\S]{0,400}bakery_require_role/', $surveyPhp) === 1,
    'token path decides before bakery_require_role'
);
$assert(
    preg_match('/bakery_survey_page_needs_identity[\s\S]{0,250}bakery_assert_driver_identity/', $surveyPhp) === 1,
    'identity check is gated by the public token helper'
);
foreach ($keys as $key) {
    $assert(isset($en[$key]) && trim((string)$en[$key]) !== '', "en has {$key}");
    $assert(isset($es[$key]) && trim((string)$es[$key]) !== '', "es has {$key}");
}

echo "\nStore-verify: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

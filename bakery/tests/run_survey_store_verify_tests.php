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
    bakery_survey_next_delivery_date('2026-08-22', [1, 2, 3, 4, 5, 6]) === '2026-08-24',
    'Saturday → next Mon-Sat delivery skips Sunday'
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
];
foreach ($keys as $key) {
    $assert(isset($en[$key]) && trim((string)$en[$key]) !== '', "en has {$key}");
    $assert(isset($es[$key]) && trim((string)$es[$key]) !== '', "es has {$key}");
}

echo "\nStore-verify: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Failed-stop workflow contract tests (no production connection).
 * Usage: php tests/run_failed_stop_recovery_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once dirname(__DIR__) . '/includes/delivery_recovery.php';

$passed = 0;
$failed = 0;
function recovery_assert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  {$message}\n");
    $failed++;
}

$reasons = bakery_delivery_recovery_reason_codes();
recovery_assert(isset($reasons['recipient_unavailable']), 'recipient-unavailable reason is available');
recovery_assert(isset($reasons['unsafe_conditions']), 'unsafe-condition reason is available');
recovery_assert(isset($reasons['other']), 'other reason is available');

recovery_assert(
    bakery_delivery_recovery_transition_allowed('open', 'acknowledged'),
    'open case can be acknowledged'
);
recovery_assert(
    bakery_delivery_recovery_transition_allowed('acknowledged', 'retry_scheduled'),
    'acknowledged case can schedule a retry'
);
recovery_assert(
    bakery_delivery_recovery_transition_allowed('retry_scheduled', 'reassigned'),
    'scheduled retry can be reassigned safely'
);
recovery_assert(
    !bakery_delivery_recovery_transition_allowed('closed', 'open'),
    'closed case cannot be silently reopened'
);
recovery_assert(
    !bakery_delivery_recovery_transition_allowed('open', 'closed'),
    'case cannot close before resolution'
);

$retry = bakery_delivery_recovery_validate_input('retry', [
    'manager_note' => 'Customer confirmed a later receiving window.',
    'retry_at' => '2099-08-17 14:30:00',
]);
recovery_assert($retry['state'] === 'retry_scheduled', 'retry records retry-scheduled state');
recovery_assert($retry['retry_at'] === '2099-08-17 14:30:00', 'retry keeps an explicit due time');

$invalidRetryBlocked = false;
try {
    bakery_delivery_recovery_validate_input('retry', ['manager_note' => 'No appointment time']);
} catch (InvalidArgumentException $e) {
    $invalidRetryBlocked = true;
}
recovery_assert($invalidRetryBlocked, 'retry requires a future appointment time');

$resolutionBlocked = false;
try {
    bakery_delivery_recovery_validate_input('resolve', ['manager_note' => '']);
} catch (InvalidArgumentException $e) {
    $resolutionBlocked = true;
}
recovery_assert($resolutionBlocked, 'resolution requires a manager note');

$otherReasonBlocked = false;
try {
    bakery_delivery_recovery_validate_input('report_failure', [
        'reason_code' => 'other',
        'manager_note' => '',
    ]);
} catch (InvalidArgumentException $e) {
    $otherReasonBlocked = true;
}
recovery_assert($otherReasonBlocked, 'other reason requires an explanatory note');

$retryLocal = bakery_delivery_recovery_validate_input('retry', [
    'manager_note' => 'Later window confirmed.',
    'retry_at' => '2099-08-17T14:30',
]);
recovery_assert($retryLocal['retry_at'] === '2099-08-17 14:30:00', 'retry accepts datetime-local values');

if (!function_exists('bakery_user_has_role')) {
    function bakery_current_user() {
        return [
            'id' => 9,
            'email' => 'driver@test',
            'display_name' => 'Driver',
            'role_slug' => $GLOBALS['TEST_RECOVERY_ROLE'] ?? 'driver',
            'driver_id' => 1,
        ];
    }
    function bakery_user_has_role($roles) {
        $user = bakery_current_user();
        return in_array($user['role_slug'], (array)$roles, true);
    }
}

$GLOBALS['TEST_RECOVERY_ROLE'] = 'driver';
recovery_assert(
    bakery_delivery_recovery_actor_may('report_failure'),
    'driver may report a failed stop'
);
recovery_assert(
    !bakery_delivery_recovery_actor_may('resolve'),
    'driver cannot complete recovery'
);
recovery_assert(
    !bakery_delivery_recovery_actor_may('update_handoffs'),
    'driver cannot mark invoiced or change billing handoff'
);
recovery_assert(
    !bakery_delivery_recovery_actor_may('close'),
    'driver cannot close a recovery case'
);

$handoff = bakery_delivery_recovery_validate_input('update_handoffs', [
    'manager_note' => 'Customer left before the retry window.',
    'communication_status' => 'contacted',
    'billing_handoff' => 'review_needed',
]);
recovery_assert($handoff['communication_status'] === 'contacted', 'communication status is normalized');
recovery_assert($handoff['billing_handoff'] === 'review_needed', 'billing handoff remains a review state');

echo "\nFailed-stop recovery tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

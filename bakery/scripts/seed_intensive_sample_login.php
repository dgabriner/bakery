<?php
/**
 * Create one labeled Full Week Intensive sample baker on hosted Staging.
 * Refuses every other database. Prints only that account's phone and PIN.
 *
 * Run on the Staging host:
 *   BAKERY_HOSTED_STAGE_ROOT=/home/bakeryOS/staging.sourflour.org \
 *   php /home/bakeryOS/.sourflour-stage-tools/seed_intensive_sample_login.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = rtrim((string)getenv('BAKERY_HOSTED_STAGE_ROOT'), '/');
if ($root !== '/home/bakeryOS/staging.sourflour.org') {
    fwrite(STDERR, "Refusing: this sample login is created only on hosted Staging.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
if (!bakery_load_env_file($root . '/.env', true)) {
    fwrite(STDERR, "Missing Staging .env\n");
    exit(1);
}
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/customer_portal.php';
require_once $root . '/includes/sf_baker.php';

if (!defined('DB_NAME') || DB_NAME !== 'bakerysoftware') {
    fwrite(STDERR, "Refusing: database is not bakerysoftware.\n");
    exit(1);
}

$phone = '4155550194';
$name = 'Intensive Sample';
$pinCandidates = ['4444'];

$db = check_mysql_connection();
if (!bakery_sfb_intensive_ready($db)) {
    fwrite(STDERR, "Intensive tables are not on Staging yet.\n");
    exit(1);
}

$existing = bakery_portal_find_by_phone($db, $phone, false);
if ($existing && (string)$existing['name'] !== $name) {
    fwrite(STDERR, "Refusing: that phone already belongs to another account.\n");
    exit(1);
}

$customerId = $existing ? (int)$existing['id'] : 0;
$pin = '';
foreach ($pinCandidates as $candidate) {
    $except = $customerId;
    if (bakery_portal_code_available($db, $candidate, $except)) {
        $pin = $candidate;
        break;
    }
    if ($existing && bakery_normalize_login_code($existing['portal_code'] ?? '') === $candidate) {
        $pin = $candidate;
        break;
    }
}
if ($pin === '') {
    fwrite(STDERR, "Refusing: 4444 is already the sign-in code for another account.\n");
    exit(1);
}

if (!$existing) {
    $created = bakery_portal_sign_in_or_register($db, $phone, $pin);
    if (empty($created['success'])) {
        fwrite(STDERR, "Could not create the sample account.\n");
        exit(1);
    }
    $customerId = (int)$created['customer']['id'];
    $db->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute([$name, $customerId]);
} else {
    $db->prepare(
        'UPDATE customers
         SET name = ?, portal_code = ?, portal_code_hash = ?, portal_enabled = 1, sf_baker_enabled = 1, is_active = 1
         WHERE id = ?'
    )->execute([$name, $pin, password_hash($pin, PASSWORD_DEFAULT), $customerId]);
}

$program = bakery_sfb_intensive_ensure_catalog($db);
if (!$program) {
    fwrite(STDERR, "Could not prepare the Intensive.\n");
    exit(1);
}
$open = bakery_sfb_intensive_open_enrollment($db, $customerId, (int)$program['id']);
if (!$open) {
    bakery_sfb_intensive_enroll($db, $customerId, (int)$program['id'], [
        'start_date' => date('Y-m-d'),
        'internal_note' => 'Sample baker for phone testing.',
    ]);
    $open = bakery_sfb_intensive_open_enrollment($db, $customerId, (int)$program['id']);
}
if ($open) {
    foreach (bakery_sfb_intensive_slots($db, (int)$open['id']) as $slot) {
        $db->prepare('DELETE FROM sfb_intensive_slot_batches WHERE slot_id = ?')->execute([(int)$slot['id']]);
        $db->prepare(
            'UPDATE sfb_intensive_slots
             SET batch_id = NULL, status = "upcoming", customer_feedback = NULL,
                 internal_summary = NULL, objective = NULL, reviewed_at = NULL
             WHERE id = ?'
        )->execute([(int)$slot['id']]);
    }
    $db->prepare(
        'UPDATE sfb_intensive_enrollments
         SET status = "pending", primary_goal = NULL, intake_completed_at = NULL
         WHERE id = ?'
    )->execute([(int)$open['id']]);
}

echo "SAMPLE_NAME={$name}\n";
echo "SAMPLE_PHONE={$phone}\n";
echo "SAMPLE_PIN={$pin}\n";
echo "SAMPLE_READY=yes\n";

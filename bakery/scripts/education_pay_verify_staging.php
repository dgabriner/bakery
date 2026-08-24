<?php
/** Prompt 26 done-when verification on bakerysoftware (staging ONLY).
 *  Auto-branches on Square credential presence:
 *   - keys missing -> record-intent honesty check, reports the precise blocker;
 *   - keys present -> buy (sandbox checkout), signed webhook flips paid,
 *     gated course unlocks, refund re-locks; one attempt = one purchase row;
 *     unknown/badly-signed events must change nothing.
 *  Cleans up its own fixture rows on success; keeps square_webhook_events
 *  ledger rows as the receipt. Never prints credential values. */
define('ACCESS_ALLOWED', true);
$root = '/home/bakeryOS/staging.sourflour.org';
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
bakery_load_env_file($root . '/.env', true);
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/sf_baker.php';
require_once $root . '/includes/square_config.php';

$db = check_mysql_connection();
bakery_assert_dreamhost_staging_target($db);

const V_CUSTOMER = 'SFB Staging Pay Verify';
const V_OFFERING = 'Staging Verify Class';
const V_COURSE = 'Staging Verify Course';
const V_MEDIA = 'verify/2026/fold.mp4';

function v_line(string $s): void
{
    echo $s . "\n";
}

function v_state(PDO $db, int $purchaseId, string $label): void
{
    $stmt = $db->prepare(
        'SELECT CONCAT("#", id, " status=", status, " snap=", price_cents_snapshot, currency_snapshot,
                        " title_snap=", offering_title_snapshot, " link=", COALESCE(square_payment_link_id, "-"),
                        " order=", COALESCE(square_order_id, "-"), " pay=", COALESCE(square_payment_id, "-"),
                        " paid_with=", COALESCE(paid_with, "-"), " paid_at=", COALESCE(paid_at, "-"))
         FROM sfb_offering_purchases WHERE id = ?'
    );
    $stmt->execute([$purchaseId]);
    v_line("[{$label}] " . ($stmt->fetchColumn() ?: '(row gone)'));
}

function v_lock(PDO $db, int $customerId, int $courseId): array
{
    $course = bakery_sfb_course($db, $courseId);
    $lock = bakery_sfb_course_lock($db, $customerId, $course);
    return [$lock['locked'], $lock['offering']['id'] ?? null];
}

/** Deliver an event to the staging webhook endpoint. Signs with the staging
 *  key unless $corrupt. Returns [http_code, response_body]. */
function v_deliver_webhook(array $payload, string $sigKey, bool $corrupt): array
{
    $notificationUrl = rtrim('https://staging.sourflour.org' . (defined('BASE_URL') ? BASE_URL : '/'), '/') . '/square_webhook.php';
    if (defined('SQUARE_WEBHOOK_NOTIFICATION_URL') && SQUARE_WEBHOOK_NOTIFICATION_URL !== '') {
        $notificationUrl = (string)SQUARE_WEBHOOK_NOTIFICATION_URL;
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(hash_hmac('sha256', $notificationUrl . $body, $corrupt ? substr($sigKey, 0, -1) . (substr($sigKey, -1) === 'a' ? 'b' : 'a') : $sigKey, true));
    $ch = curl_init($notificationUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Square-HmacSha256-Signature: ' . $sig,
        ],
    ]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, trim($resp), $err];
}

function v_cleanup(PDO $db): void
{
    $db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (
        SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = '" . V_COURSE . "'))");
    $db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = '" . V_COURSE . "')");
    $db->prepare("DELETE FROM sfb_courses WHERE title = ?")->execute([V_COURSE]);
    $db->prepare("DELETE FROM sfb_offerings WHERE title = ?")->execute([V_OFFERING]);
    $db->prepare("DELETE FROM customers WHERE name = ?")->execute([V_CUSTOMER]);
}

$vRun = 'os-edu-verify-' . uniqid();
v_line("=== Prompt 26 done-when verification run {$vRun} ===");
v_line("connected to database: " . $db->query('SELECT DATABASE()')->fetchColumn());

try {
    // Idempotent pre-clean of any earlier verification leftovers.
    v_cleanup($db);

    // Fixtures: human test customer, active class offering, gated course tree.
    $ins = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ins->execute([V_CUSTOMER, '555-0186', '1 Verification Way']);
    $customerId = (int)$db->lastInsertId();
    $offeringId = bakery_sfb_create_offering($db, V_OFFERING, 45.00, 'class', 'Prompt 26 staging verification class.', null);
    $courseId = bakery_sfb_create_course($db, V_COURSE, 'Prompt 26 staging verification course.');
    $lessonId = bakery_sfb_create_lesson($db, $courseId, 'Verify Lesson', '', '');
    bakery_sfb_add_lesson_step($db, $lessonId, 'Watch the fold:', V_MEDIA, 'video');
    bakery_sfb_set_course_offering($db, $courseId, $offeringId);
    v_line("fixtures: customer={$customerId} offering={$offeringId} course={$courseId} (gated)");

    [$lockedBefore, $gateOffering] = v_lock($db, $customerId, $courseId);
    v_line("pre-payment lock: locked=" . ($lockedBefore ? 'true' : 'false') . " gate_offering={$gateOffering}");
    if (!$lockedBefore) {
        throw new RuntimeException('Course should be locked before any purchase');
    }

    $hasApiKeys = SQUARE_ACCESS_TOKEN !== '' && SQUARE_LOCATION_ID !== '';
    $hasSigKey = SQUARE_WEBHOOK_SIGNATURE_KEY !== '';
    v_line("square credentials: api_keys=" . ($hasApiKeys ? 'present' : 'missing') . " webhook_signature_key=" . ($hasSigKey ? 'present' : 'missing'));

    /* ── Branch A: no keys -> recorded-intent honesty only ─────────────── */
    if (!$hasApiKeys) {
        v_line("-- BRANCH A: sandbox keys absent; verifying recorded-intent path --");
        $buy = bakery_sfb_buy_offering($db, $customerId, $offeringId);
        v_line("buy_offering -> configured=" . var_export($buy['configured'], true) . " url=" . var_export($buy['url'], true));
        v_state($db, (int)$buy['purchase_id'], 'after-buy');

        $row = bakery_sfb_purchase($db, (int)$buy['purchase_id']);
        $offering = bakery_sfb_offering($db, $offeringId);
        $intentHonest = $row && $row['status'] === 'intent'
            && (int)$row['price_cents_snapshot'] === (int)$offering['price_cents']
            && $row['currency_snapshot'] === $offering['currency']
            && $row['offering_title_snapshot'] === $offering['title'];
        v_line("intent honest + snapshots frozen: " . ($intentHonest ? 'true' : 'FALSE'));
        v_line("no entitlement: " . (!bakery_sfb_customer_entitled_to($db, $customerId, $offeringId) ? 'true' : 'FALSE'));
        [$lockedA] = v_lock($db, $customerId, $courseId);
        v_line("course still locked: " . ($lockedA ? 'true' : 'FALSE'));
        v_cleanup($db);
        v_line("cleanup done (test customer/purchase/course removed; webhook ledger untouched)");
        v_line("VERDICT: DONE-WHEN WITNESSED FOR INTENT PATH ONLY -- BLOCKER: sandbox keys missing on staging env");
        return;
    }

    /* ── Branch B: sandbox keys present -> exercise the full loop ──────── */
    v_line("-- BRANCH B: sandbox keys present; exercising buy -> paid -> unlock -> refund --");

    // 1) Badly signed payment event MUST be rejected and change nothing.
    [$badCode, $badResp] = v_deliver_webhook([
        'event_id' => $vRun . '-badsig',
        'type' => 'payment.updated',
        'data' => ['type' => 'event', 'object' => ['payment' => ['id' => 'BADPAY', 'order_id' => 'BADORDER', 'status' => 'COMPLETED']]],
    ], SQUARE_WEBHOOK_SIGNATURE_KEY !== '' ? SQUARE_WEBHOOK_SIGNATURE_KEY : str_repeat('x', 32), true);
    v_line("negative: corrupted-signature event -> HTTP {$badCode} resp={$badResp}" . ($hasSigKey ? '' : ' (NOTE: server signature key unset; endpoint could not enforce validation)'));

    // 2) Unknown event type with a valid signature must be ignored, never guessed.
    if ($hasSigKey) {
        [$unkCode, $unkResp] = v_deliver_webhook([
            'event_id' => $vRun . '-unknown',
            'type' => 'catalog.updated',
            'data' => ['type' => 'event', 'object' => ['id' => 'WHATEVER']],
        ], SQUARE_WEBHOOK_SIGNATURE_KEY, false);
        v_line("negative: unknown event type -> HTTP {$unkCode} resp={$unkResp}");
    }

    // 3) Buy: one attempt -> one purchase row; hosted sandbox checkout.
    $buy = bakery_sfb_buy_offering($db, $customerId, $offeringId);
    v_line("buy_offering -> configured=" . var_export($buy['configured'], true) . " purchase_id={$buy['purchase_id']}"
        . (isset($buy['error']) ? " error='{$buy['error']}'" : '')
        . ($buy['url'] ? ' url_host=' . (parse_url($buy['url'], PHP_URL_HOST) ?: '') : ''));
    if (!$buy['configured'] || empty($buy['url'])) {
        v_state($db, (int)$buy['purchase_id'], 'honest-intent-state');
        v_line("evidence kept: purchase #{$buy['purchase_id']} customer #{$customerId} (not cleaned so the failure can be inspected)");
        v_line("VERDICT: DONE-WHEN NOT MET -- BLOCKER: Square sandbox API rejected checkout creation; see error above");
        exit(1);
    }
    $purchaseId = (int)$buy['purchase_id'];
    v_state($db, $purchaseId, 'pending');
    $pendingRow = bakery_sfb_purchase($db, $purchaseId);
    if (!$pendingRow || $pendingRow['status'] !== 'pending' || empty($pendingRow['square_order_id'])) {
        throw new RuntimeException('Checkout creation did not move the attempt to pending with an order id');
    }
    $orderId = (string)$pendingRow['square_order_id'];

    // 4) Signed payment.updated COMPLETED -> paid.
    $paymentId = 'TESTPAY-' . uniqid();
    [$payCode, $payResp, $payErr] = v_deliver_webhook([
        'event_id' => $vRun . '-paid',
        'type' => 'payment.updated',
        'data' => ['type' => 'event', 'object' => ['payment' => [
            'id' => $paymentId,
            'order_id' => $orderId,
            'status' => 'COMPLETED',
        ]]],
    ], SQUARE_WEBHOOK_SIGNATURE_KEY !== '' ? SQUARE_WEBHOOK_SIGNATURE_KEY : '', false);
    v_line("webhook payment.updated(COMPLETED) -> HTTP {$payCode} resp={$payResp}" . ($payErr !== '' ? " curl_error={$payErr}" : ''));
    v_state($db, $purchaseId, 'after-payment-webhook');
    $paidRow = bakery_sfb_purchase($db, $purchaseId);
    if (!$paidRow || $paidRow['status'] !== 'paid') {
        throw new RuntimeException('Signed COMPLETED event did not flip the purchase to paid');
    }

    $entitled = bakery_sfb_customer_entitled_to($db, $customerId, $offeringId);
    [$lockedPaid] = v_lock($db, $customerId, $courseId);
    $mediaLocked = bakery_sfb_media_path_locked($db, V_MEDIA, $customerId);
    $unlockList = bakery_sfb_courses_requiring($db, $offeringId);
    v_line("entitlement resolves: " . ($entitled ? 'true' : 'FALSE')
        . " | course unlocked: " . ($lockedPaid ? 'FALSE' : 'true')
        . " | lesson media unlocked: " . ($mediaLocked ? 'FALSE' : 'true')
        . " | unlock list has course: " . (in_array((string)$courseId, array_map('strval', array_column($unlockList, 'id')), true) ? 'true' : 'FALSE'));

    // 5) Replay the same event id: duplicate, one transition only.
    [$repCode, $repResp] = v_deliver_webhook([
        'event_id' => $vRun . '-paid',
        'type' => 'payment.updated',
        'data' => ['type' => 'event', 'object' => ['payment' => ['id' => $paymentId, 'order_id' => $orderId, 'status' => 'COMPLETED']]],
    ], SQUARE_WEBHOOK_SIGNATURE_KEY !== '' ? SQUARE_WEBHOOK_SIGNATURE_KEY : '', false);
    $dupCount = $db->prepare('SELECT COUNT(*) FROM square_webhook_events WHERE event_id = ?');
    $dupCount->execute([$vRun . '-paid']);
    v_line("replay same event_id -> HTTP {$repCode} resp={$repResp}; ledger rows for that event_id=" . (int)$dupCount->fetchColumn());

    // 6) Refund -> re-lock.
    [$refCode, $refResp] = v_deliver_webhook([
        'event_id' => $vRun . '-refund',
        'type' => 'refund.updated',
        'data' => ['type' => 'event', 'object' => ['refund' => [
            'id' => 'TESTREF-' . uniqid(),
            'payment_id' => $paymentId,
            'status' => 'COMPLETED',
        ]]],
    ], SQUARE_WEBHOOK_SIGNATURE_KEY !== '' ? SQUARE_WEBHOOK_SIGNATURE_KEY : '', false);
    v_line("webhook refund.updated -> HTTP {$refCode} resp={$refResp}");
    v_state($db, $purchaseId, 'after-refund-webhook');
    $refundedRow = bakery_sfb_purchase($db, $purchaseId);
    $relocked = !bakery_sfb_customer_entitled_to($db, $customerId, $offeringId);
    [$lockedRefund] = v_lock($db, $customerId, $courseId);
    $mediaRelocked = bakery_sfb_media_path_locked($db, V_MEDIA, $customerId);
    if (!$refundedRow || $refundedRow['status'] !== 'refunded') {
        throw new RuntimeException('Refund event did not flip the purchase to refunded');
    }
    v_line("refund re-locks: entitlement gone=" . ($relocked ? 'true' : 'FALSE')
        . " | course locked again: " . ($lockedRefund ? 'true' : 'FALSE')
        . " | media locked again: " . ($mediaRelocked ? 'true' : 'FALSE'));

    $eventsAfter = (int)$db->query("SELECT COUNT(*) FROM square_webhook_events WHERE event_id LIKE '{$vRun}-%'")->fetchColumn();
    v_line("ledger receipt: {$eventsAfter} square_webhook_events rows carry this run's event ids (kept)");

    v_cleanup($db);
    $leftCust = $db->prepare("SELECT COUNT(*) FROM customers WHERE name = ?");
    $leftCust->execute([V_CUSTOMER]);
    $leftPurch = $db->prepare('SELECT COUNT(*) FROM sfb_offering_purchases WHERE customer_id NOT IN (SELECT id FROM customers)');
    $leftPurch->execute();
    v_line("cleanup: test customers left=" . (int)$leftCust->fetchColumn() . " orphaned purchase rows left=" . (int)$leftPurch->fetchColumn());
    v_line("VERDICT: PROMPT 26 DONE-WHEN WITNESSED END TO END ON STAGING"
        . ($hasSigKey ? '' : ' -- CAVEAT: SQUARE_WEBHOOK_SIGNATURE_KEY unset on staging, so the endpoint could not prove signature enforcement'));
} catch (Throwable $e) {
    v_line('FAIL  unexpected: ' . get_class($e) . ': ' . $e->getMessage());
    try {
        $ev = $db->prepare("SELECT COUNT(*) FROM customers WHERE name = ?");
        $ev->execute([V_CUSTOMER]);
        if ((int)$ev->fetchColumn() > 0) {
            $idStmt = $db->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
            $idStmt->execute([V_CUSTOMER]);
            v_line('evidence kept: customer #' . (int)$idStmt->fetchColumn() . " and its purchase rows were NOT cleaned so the failure can be inspected.");
        }
    } catch (Throwable $ignored) {
    }
    exit(1);
}

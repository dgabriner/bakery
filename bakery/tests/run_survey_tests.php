<?php
/**
 * Survey tests — creation/token lifecycle, message building, text-reply
 * matching, route-review payload, and response recording.
 *
 * Runs against bakerysf_test. Never touches local/staging/live data.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require __DIR__ . '/isolate_test_db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

// The snapshot source may predate migrations 061/062 — apply the same DDL in place.
foreach (['061_surveys.sql', '062_surveys_custom.sql'] as $ddlFile) {
    $ddl = (string)file_get_contents(dirname(__DIR__) . '/database/schema/' . $ddlFile);
    $ddlNoComments = implode("\n", array_filter(
        explode("\n", $ddl),
        static function (string $line): bool {
            return strpos(ltrim($line), '--') !== 0;
        }
    ));
    foreach (array_filter(array_map('trim', explode(';', $ddlNoComments))) as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            // Column/table already present (idempotent re-run): safe to ignore.
            if (strpos($e->getMessage(), '1060') === false && strpos($e->getMessage(), '1050') === false) {
                throw $e;
            }
        }
    }
}

require_once __DIR__ . '/../includes/twilio_config.php';
require_once __DIR__ . '/../includes/text_comms.php';
require_once __DIR__ . '/../includes/surveys.php';
require_once __DIR__ . '/../includes/auth.php';

/**
 * @return array<string, string>
 */
function survey_db_write_fingerprint(PDO $db): array
{
    $out = [];
    $tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $table) {
        $name = (string)($table[0] ?? '');
        $kind = (string)($table[1] ?? '');
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            continue;
        }
        if ($kind !== '' && stripos($kind, 'BASE TABLE') === false) {
            continue;
        }
        $sum = $db->query('CHECKSUM TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
        $out[$name] = (string)($sum['Checksum'] ?? '');
    }
    ksort($out);
    return $out;
}

$GLOBALS['bakery_text_force_record_only'] = true;

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

$surveyIds = [];
$messageIds = [];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    // ---- Schema -------------------------------------------------------------
    $assert(bakery_surveys_ready($db), 'surveys + survey_responses tables exist');

    // ---- Driver fixture -----------------------------------------------------
    $db->exec("INSERT INTO drivers (name) VALUES ('Survey Probe Driver')");
    $driverId = (int)$db->lastInsertId();

    // ---- Creation validation ------------------------------------------------
    foreach ([
        [['mode' => 'carrier_pigeon', 'audience' => 'driver', 'driver_id' => $driverId], 'unknown mode rejected'],
        [['mode' => 'link', 'kind' => 'route_review', 'audience' => 'driver', 'driver_id' => 0], 'driver survey without driver rejected'],
        [['mode' => 'text_reply', 'audience' => 'staff'], 'text-reply without phone rejected'],
        [['mode' => 'link', 'kind' => 'question', 'audience' => 'staff', 'target_phone' => '+14155550001'], 'question survey without question text rejected'],
        [['mode' => 'link', 'kind' => 'route_review', 'audience' => 'driver', 'driver_id' => 999999], 'archived/missing driver rejected'],
        [['mode' => 'link', 'kind' => 'route_review', 'audience' => 'staff', 'driver_id' => $driverId], 'route review restricted to driver audience'],
    ] as $case) {
        try {
            bakery_survey_create($db, $case[0]);
            $assert(false, $case[1]);
        } catch (RuntimeException $e) {
            $assert(true, $case[1]);
        }
    }

    // ---- Link-mode route review ---------------------------------------------
    $survey = bakery_survey_create($db, [
        'mode' => 'link',
        'kind' => 'route_review',
        'audience' => 'driver',
        'driver_id' => $driverId,
        'target_phone' => '(415) 555-0107',
        'delivery_date' => '2026-08-25',
        'created_by' => 1,
    ]);
    $surveyIds[] = (int)$survey['id'];
    $assert($survey !== [] && strlen((string)$survey['token']) === 32, 'link survey persists with a 32-char token');
    $assert(bakery_survey_find_by_token($db, (string)$survey['token'])['id'] === (int)$survey['id'], 'token lookup round-trips');
    $assert(bakery_survey_find_by_token($db, 'nope') === [], 'bogus token finds nothing');

    $body = bakery_survey_build_message($survey);
    $assert(strpos($body, '2026-08-25') !== false && strpos($body, 'survey.php?t=') !== false, 'link message carries date and token URL');
    $url = bakery_survey_link_url((string)$survey['token']);
    $assert(strpos($url, 'survey.php?t=' . $survey['token']) !== false, 'link URL embeds raw token');
    $dated = bakery_survey_link_url((string)$survey['token'], '2026-09-01');
    $assert(strpos($dated, 'date=2026-09-01') !== false, 'optional delivery date is appended');
    $_SERVER['HTTP_HOST'] = 'staging.sourflour.org';
    $_SERVER['HTTPS'] = 'on';
    $absoluteUrl = bakery_survey_link_url((string)$survey['token']);
    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    $assert(preg_match('#^https://staging\.sourflour\.org/[a-z0-9_./-]*survey\.php\?t=' . $survey['token'] . '$#', $absoluteUrl) === 1, 'under a request context the link is fully absolute and https');

    $_SERVER['HTTP_HOST'] = 'bakery.sourflour.org';
    $_SERVER['HTTPS'] = 'on';
    $liveAbsolute = bakery_survey_link_url((string)$survey['token'], '2026-09-01');
    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    $assert(
        strpos($liveAbsolute, 'https://bakery.sourflour.org/') === 0
        && strpos($liveAbsolute, 'survey.php?t=' . $survey['token']) !== false
        && strpos($liveAbsolute, 'date=2026-09-01') !== false,
        'Live host builds an absolute https survey URL with date'
    );
    if (defined('BASE_URL') && BASE_URL === '/bake/') {
        $assert(strpos($liveAbsolute, 'https://bakery.sourflour.org/bake/survey.php?') === 0, 'Live /bake/ BASE_URL is preserved in survey links');
    }

    $send = bakery_survey_send($db, $survey, 1);
    $messageIds[] = (int)$send['send']['id'];
    $assert(!empty($send['send']['recorded_only']) && $send['send']['id'] > 0, 'survey send records through bakery_text_send even when unconfigured');
    $sentRows = $db->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ? AND action = 'sent'");
    $sentRows->execute([(int)$survey['id']]);
    $assert((int)$sentRows->fetchColumn() === 1, 'send leaves exactly one sent marker row');

    // ---- Text-reply matching -------------------------------------------------
    $replySurvey = bakery_survey_create($db, [
        'mode' => 'text_reply',
        'kind' => 'question',
        'audience' => 'driver',
        'driver_id' => $driverId,
        'target_phone' => '+14155550188',
        'question' => 'Are Sunday starts working for you?',
        'created_by' => 1,
    ]);
    $surveyIds[] = (int)$replySurvey['id'];
    $matched = bakery_survey_open_for_phone($db, '+1 (415) 555-0188');
    $assert((int)$matched['id'] === (int)$replySurvey['id'], 'open reply survey matches normalized phone');
    $replyBody = bakery_survey_build_message($replySurvey);
    $assert(strpos($replyBody, 'Sunday') !== false && strpos($replyBody, 'http') === false, 'reply-mode message has no link');

    $rowId = bakery_text_send($db, '+14155550188', 'placeholder outbound', ['context_type' => 'general'])['id'];
    $messageIds[] = $rowId;
    $responseId = bakery_survey_record_inbound_reply($db, '(415) 555-0188', $rowId, 'Sundays are fine but not Saturdays');
    $check = $db->prepare('SELECT * FROM survey_responses WHERE id = ?');
    $check->execute([$responseId]);
    $resp = $check->fetch(PDO::FETCH_ASSOC);
    $assert($resp && (int)$resp['survey_id'] === (int)$replySurvey['id'] && (int)$resp['text_message_id'] === $rowId, 'inbound reply ties message row to open survey');
    $assert(bakery_survey_open_for_phone($db, '+14155559999') === [], 'unknown phone matches nothing');

    // ---- Route review payload -------------------------------------------------
    // Act as a signed-in administrator, exactly like the nav tests do.
    $_SESSION = [
        'user_id' => 1,
        'user_role_slug' => 'administrator',
        'user_display_name' => 'Survey Test',
    ];
    $data = bakery_survey_route_review_data($db, $driverId, '2026-08-25');
    $assert(isset($data['stops'], $data['unassigned']) && is_array($data['stops']), 'route review payload renders empty route safely');

    // ---- Phase 2: multi-question custom surveys -------------------------------
    try {
        bakery_survey_create($db, [
            'mode' => 'link',
            'kind' => 'question',
            'audience' => 'staff',
            'target_phone' => '+14155550444',
            'questions' => [
                ['text' => 'Choice with no options', 'type' => 'choice'],
            ],
        ]);
        $assert(false, 'choice question without options rejected');
    } catch (RuntimeException $e) {
        $assert(true, 'choice question without options rejected');
    }

    $multi = bakery_survey_create($db, [
        'mode' => 'link',
        'kind' => 'question',
        'audience' => 'driver',
        'driver_id' => $driverId,
        'target_phone' => '+14155550111',
        'title' => 'Sunday plan',
        'questions' => [
            ['text' => 'Can you drive next Sunday?', 'type' => 'yes_no'],
            ['text' => 'Which van works best?', 'type' => 'choice', 'options' => ["Van 1", "Van 2"]],
            ['text' => 'Anything else?', 'type' => 'text'],
        ],
        'created_by' => 1,
    ]);
    $surveyIds[] = (int)$multi['id'];
    $multiQs = bakery_survey_questions($multi);
    $assert(count($multiQs) === 3 && $multiQs[0]['key'] === 'q1', 'multi-question survey stores three keyed questions');
    $assert($multiQs[1]['options'] === ['Van 1', 'Van 2'], 'choice options round-trip in order');
    $msg = bakery_survey_build_message($multi);
    $assert(strpos($msg, 'Sunday plan') !== false || strpos($msg, 'Can you drive') !== false, 'multi-question message includes headline or questions');

    foreach ($multiQs as $q) {
        $answer = $q['type'] === 'yes_no' ? 'yes' : ($q['type'] === 'choice' ? 'Van 2' : 'All good from my side');
        bakery_survey_record_response($db, [
            'survey_id' => (int)$multi['id'],
            'action' => 'answer',
            'question_key' => $q['key'],
            'respondent' => 'Probe Driver',
            'response' => $answer,
        ]);
    }
    $results = bakery_survey_results($db, (int)$multi['id']);
    $assert(count($results['questions']) === 3, 'results return all three questions');
    $yn = $results['questions'][0];
    $assert(($yn['tally']['Yes'] ?? 0) === 1, 'yes/no tally normalizes to Yes label');
    $ch = $results['questions'][1];
    $assert(($ch['tally']['Van 2'] ?? 0) === 1, 'choice tally counts the picked option');
    $tx = $results['questions'][2];
    $assert(count($tx['free']) === 1 && $tx['free'][0]['respondent'] === 'Probe Driver', 'free-text answers keep respondent attribution');
    $assert($results['respondents'] >= 1, 'distinct respondent count computed');

    // Legacy single-question rows still parse.
    $legacy = bakery_survey_questions([
        'questions_json' => null,
        'question' => 'Do you have truck keys?',
    ]);
    $assert(count($legacy) === 1 && $legacy[0]['type'] === 'text' && $legacy[0]['key'] === 'q1', 'legacy single question parses as one text question');

    $probeDate = '2099-01-15';
    $beforeLookup = (int)$db->query('SELECT COUNT(*) FROM surveys')->fetchColumn();
    $missingHq = bakery_survey_find_store_verify($db, 0, $probeDate);
    $missingOrder = bakery_survey_find_route_order($db, 0, $probeDate);
    $afterLookup = (int)$db->query('SELECT COUNT(*) FROM surveys')->fetchColumn();
    $assert($missingHq === [] && $missingOrder === [], 'lookup of a missing HQ day returns no row');
    $assert($afterLookup === $beforeLookup, 'reading survey.php date does not insert HQ surveys');
    $createdHq = bakery_survey_ensure_store_verify($db, 0, $probeDate, 0);
    $surveyIds[] = (int)$createdHq['id'];
    $foundHq = bakery_survey_find_store_verify($db, 0, $probeDate);
    $assert((int)($foundHq['id'] ?? 0) === (int)$createdHq['id'], 'find returns the HQ store-verify row once it exists');
    $again = bakery_survey_ensure_store_verify($db, 0, $probeDate, 0);
    $assert((int)$again['id'] === (int)$createdHq['id'], 'ensure reuses the open HQ survey');
    $stillNoOrder = bakery_survey_find_route_order($db, 0, $probeDate);
    $assert($stillNoOrder === [], 'route-order lookup stays read-only');

    $assert(function_exists('bakery_unassigned_for_date'), 'shared unassigned-for-date query exists');
    if (function_exists('bakery_unassigned_for_date')) {
        $gapDate = '2026-10-10';
        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date = ?')->execute([$gapDate]);
        $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$gapDate]);
        $db->prepare('DELETE FROM standing_routes WHERE customer_id = 3 AND day_of_week = 6')->execute();
        $db->prepare(
            'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (6, 1, 3, 9)'
        )->execute();
        $insertGap = $db->prepare(
            "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
        );
        $insertGap->execute([1, $gapDate]);
        $assignedGapId = (int)$db->lastInsertId();
        $insertGap->execute([2, $gapDate]);
        $db->prepare(
            'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
             VALUES (?, 1, ?, 1, \'pending\')'
        )->execute([$assignedGapId, $gapDate]);
        $gapRows = bakery_unassigned_for_date($db, $gapDate);
        $gapNames = array_map(static function (array $row): string {
            return (string)($row['name'] ?? '');
        }, $gapRows);
        $assert(in_array('Demo Market Beta', $gapNames, true), 'Sat 10/10 unassigned list names the dated store with no driver');
        $assert(!in_array('Demo Cafe Alpha', $gapNames, true), 'an assigned dated store is not unassigned');
        $assert(!in_array('Demo Spot Gamma', $gapNames, true), 'a standing-only store is not counted as unassigned for the date');
        $assert(
            bakery_driver_unassigned_count($db, $gapDate) === count($gapRows),
            'driver assignment count uses the same unassigned-for-date rows'
        );
        $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$gapDate]);
        $db->prepare('DELETE FROM standing_routes WHERE customer_id = 3 AND day_of_week = 6 AND route_order = 9')->execute();
    }

    $todayLinkDate = date('Y-m-d');
    $todaySurvey = bakery_survey_ensure_store_verify($db, 0, $todayLinkDate, 0);
    $surveyIds[] = (int)$todaySurvey['id'];
    $todayBody = bakery_survey_build_message($todaySurvey);
    $assert(
        strpos($todayBody, 'date=' . rawurlencode($todayLinkDate)) !== false
            && strpos($todayBody, (string)$todaySurvey['token']) !== false,
        'a today survey text carries a link for today'
    );
    $assert(
        preg_match('/^[0-9a-f]{32}$/', (string)$todaySurvey['token']) === 1,
        'today token stays a 32 character hex secret'
    );
    $assert(
        function_exists('bakery_survey_token_is_expired')
            && bakery_survey_token_is_expired($todaySurvey, $todayLinkDate) === false,
        'today token is not expired on its own day'
    );
    $dayAfter = (new DateTime($todayLinkDate))->modify('+1 day')->format('Y-m-d');
    $assert(
        function_exists('bakery_survey_token_is_expired')
            && bakery_survey_token_is_expired($todaySurvey, $dayAfter) === true,
        'today token expires after its delivery day'
    );

    $surveyPhp = (string)file_get_contents(dirname(__DIR__) . '/survey.php');
    $routeOrderRender = (string)file_get_contents(dirname(__DIR__) . '/includes/survey_route_order_render.php');
    $assert(strpos($surveyPhp, "=== 'open_hub'") !== false, 'HQ survey creation is the open_hub POST action');
    $assert(strpos($surveyPhp, 'bakery_survey_dual_hub_existing') !== false, 'survey page renders from existing hub rows');
    $assert(strpos($surveyPhp, 'bakery_survey_find_store_verify') !== false, 'driver copy links look up surveys');
    $assert(strpos($surveyPhp, 'bakery_survey_token_is_expired') !== false, 'survey page rejects an expired token');
    $assert(strpos($surveyPhp, '!$expiredPublicToken && bakery_survey_page_needs_login') !== false, 'an expired public link shows the expired page without a login wall');
    $assert(strpos($surveyPhp, 'bakery_survey_bound_delivery_date') !== false, 'survey page keeps the token on its delivery date');
    $assert(strpos($surveyPhp, 'bakery_survey_record_interaction') === false, 'a survey page view does not write an open interaction');
    $assert(substr_count($surveyPhp, 'bakery_survey_dual_hub_links') === 1, 'creating hub rows happens in one POST path');
    $assert(strpos($routeOrderRender, 'bakery_survey_ensure_') === false, 'route-order render does not create surveys');
    $assert(strpos($routeOrderRender, 'bakery_survey_dual_hub_existing') !== false, 'route-order sibling link is a lookup');

    $commsPage = (string)file_get_contents(dirname(__DIR__) . '/text_comms.php');
    $assert(strpos($commsPage, 'texts.survey_today_create') !== false, 'Survey Center offers a Create today link button');
    $assert(substr_count($commsPage, 'bakery_survey_dual_hub_links') === 2, 'hub rows are created only by the send POST and the today-link POST');
    $getRegion = substr($commsPage, (int)strpos($commsPage, 'Coverage radar'));
    $assert(strpos($getRegion, 'bakery_survey_dual_hub_links') === false, 'the Survey Center view does not mint hub rows');

    if (isset($todaySurvey['id'])) {
        $db->prepare('DELETE FROM surveys WHERE id = ?')->execute([(int)$todaySurvey['id']]);
    }
    $_SESSION['user_id'] = 1;
    $_SESSION['user_email'] = 'survey-get@example.test';
    $_SESSION['user_display_name'] = 'Survey Get';
    $_SESSION['user_role_slug'] = 'administrator';
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['login_audit_id'] = 1;
    $_SESSION['csrf_token'] = 'survey-get-csrf';
    $_GET = ['view' => 'surveys'];
    $_POST = [];
    $_REQUEST = ['view' => 'surveys'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/bakery/text_comms.php';
    $_SERVER['REQUEST_URI'] = '/bakery/text_comms.php?view=surveys';
    $beforeGet = survey_db_write_fingerprint($db);
    ob_start();
    include dirname(__DIR__) . '/text_comms.php';
    $surveyCenterHtml = (string)ob_get_clean();
    $afterGet = survey_db_write_fingerprint($db);
    $changedTables = [];
    foreach ($beforeGet as $tableName => $checksum) {
        if (($afterGet[$tableName] ?? '') !== $checksum) {
            $changedTables[] = $tableName;
        }
    }
    $assert($changedTables === [], 'a GET of text_comms.php?view=surveys writes nothing' . ($changedTables === [] ? '' : ' (' . implode(', ', $changedTables) . ')'));
    $assert(strpos($surveyCenterHtml, 'Create today link') !== false, 'GET shows Create today link without inserting a hub');
    } catch (Throwable $e) {
    echo 'FAIL  unexpected exception: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    if (isset($db) && $db instanceof PDO) {
        if ($surveyIds !== []) {
            $placeholders = implode(',', array_fill(0, count($surveyIds), '?'));
            $db->prepare("DELETE FROM surveys WHERE id IN ($placeholders)")->execute($surveyIds);
        }
        if ($messageIds !== []) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $db->prepare("DELETE FROM text_messages WHERE id IN ($placeholders)")->execute($messageIds);
        }
        if (!empty($driverId)) {
            $db->prepare('DELETE FROM drivers WHERE id = ?')->execute([$driverId]);
        }
    }
}

echo "\nSurveys: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

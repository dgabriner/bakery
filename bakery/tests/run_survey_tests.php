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

// The snapshot source may predate migration 061 — apply the same DDL in place.
$ddl = (string)file_get_contents(dirname(__DIR__) . '/database/schema/061_surveys.sql');
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
    $db->exec($statement);
}

require_once __DIR__ . '/../includes/twilio_config.php';
require_once __DIR__ . '/../includes/text_comms.php';
require_once __DIR__ . '/../includes/surveys.php';

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

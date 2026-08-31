<?php
/**
 * Survey interaction tracking — opens, submits, identity matching, dedupe.
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

foreach (['061_surveys.sql', '062_surveys_custom.sql', '073_survey_interactions.sql'] as $ddlFile) {
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
            if (strpos($e->getMessage(), '1060') === false && strpos($e->getMessage(), '1050') === false) {
                throw $e;
            }
        }
    }
}

require_once __DIR__ . '/../includes/text_comms.php';
require_once __DIR__ . '/../includes/surveys.php';

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

try {
    $assert(bakery_survey_interactions_ready($db), 'survey_interactions table exists');

    $db->exec("INSERT INTO drivers (name) VALUES ('Interaction Probe Driver')");
    $driverId = (int)$db->lastInsertId();

    $survey = bakery_survey_create($db, [
        'mode' => 'link',
        'kind' => 'store_verify',
        'audience' => 'driver',
        'driver_id' => $driverId,
        'target_phone' => '+14155550123',
        'delivery_date' => date('Y-m-d', strtotime('+1 day')),
        'created_by' => null,
    ]);
    $surveyIds[] = (int)$survey['id'];

    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';
    $_SERVER['HTTP_REFERER'] = 'https://example.com/sms';
    $_SERVER['REQUEST_URI'] = '/bake/survey.php?t=' . $survey['token'];

    $openId = bakery_survey_record_interaction($db, $survey, 'open', ['driver_id' => $driverId]);
    $assert($openId > 0, 'records open interaction');

    $dupId = bakery_survey_record_interaction($db, $survey, 'open', ['driver_id' => $driverId]);
    $assert($dupId === 0, 'dedupes rapid repeat opens from same IP');

    bakery_survey_track_submit($db, $survey, 'store_verify', null, $driverId);
    $summary = bakery_survey_interactions_for_survey($db, (int)$survey['id']);
    $assert($summary['opens'] === 1, 'summary counts one open');
    $assert($summary['submits'] === 1, 'summary counts one submit');
    $assert(count($summary['rows']) === 2, 'detail feed has open + submit');

    $actor = bakery_survey_interactions_guess_actor($db, $survey, null, $driverId);
    $assert($actor['match_source'] === 'session', 'driver session match source');
    $assert($actor['guessed_name'] === 'Interaction Probe Driver', 'driver session guessed name');

    $actorSurvey = bakery_survey_interactions_guess_actor($db, $survey, null, null);
    $assert($actorSurvey['match_source'] === 'survey_driver', 'falls back to survey driver');

    $recent = bakery_survey_interactions_recent($db, 10);
    $assert(count($recent) >= 2, 'recent feed includes interactions');

    $who = bakery_survey_interaction_who_label($summary['rows'][0]);
    $assert($who !== '', 'who label is non-empty');

} finally {
    foreach ($surveyIds as $sid) {
        $db->exec('DELETE FROM surveys WHERE id = ' . (int)$sid);
    }
    if (isset($driverId) && $driverId > 0) {
        $db->exec('DELETE FROM drivers WHERE id = ' . (int)$driverId);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

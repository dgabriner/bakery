<?php
/**
 * Synthetic Studio clock: paced journal / loaf / share / post actions
 * for synthetic bakers, plus the manager action log.
 *
 * Writes go through bakery_sfb_* only. Humans are never ticked.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sf_baker.php';
require_once __DIR__ . '/sfb_agent.php';
require_once __DIR__ . '/sfb_personas.php';
require_once __DIR__ . '/sfb_synthetic_eval.php';

function bakery_sfb_studio_schema_ready(PDO $db) {
    return table_exists($db, 'sfb_studio_settings')
        && table_exists($db, 'sfb_studio_clock')
        && table_exists($db, 'sfb_studio_action_log');
}

function bakery_sfb_studio_ensure_schema(PDO $db) {
    if (bakery_sfb_studio_schema_ready($db)) {
        return true;
    }
    $ddlOk = !function_exists('bakery_runtime_schema_ddl_allowed') || bakery_runtime_schema_ddl_allowed();
    if (!$ddlOk) {
        return bakery_sfb_studio_schema_ready($db);
    }
    $schema = dirname(__DIR__) . '/database/schema/041_sfb_studio_clock.sql';
    if (is_readable($schema) && function_exists('bakery_run_sql_file_safe')) {
        bakery_run_sql_file_safe($db, $schema);
    }
    foreach (['sfb_studio_settings', 'sfb_studio_clock', 'sfb_studio_action_log'] as $table) {
        if (function_exists('bakery_forget_table_exists')) {
            bakery_forget_table_exists($table);
        }
    }
    return bakery_sfb_studio_schema_ready($db);
}

/**
 * CLI cron guard: unforced ticks run on DreamHost (bakerysf) only.
 * Local --force is a one-shot against the local/test database.
 * Laptop-to-prod (USE_PROD_DB) is never allowed through this entry.
 */
function bakery_sfb_studio_assert_tick_cli(PDO $db, bool $force = false): void {
    $name = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    $isLocal = defined('IS_LOCAL') && IS_LOCAL;
    $useProdDb = defined('USE_PROD_DB') && USE_PROD_DB;

    if ($isLocal && $useProdDb) {
        throw new RuntimeException(
            'Studio clock cron runs on DreamHost only. Do not tick production from this machine.'
        );
    }

    if ($isLocal) {
        if (!$force) {
            throw new RuntimeException(
                'Studio clock cron is DreamHost-only. Use Synthetic Manager → Run tick now, or pass --force for a one-shot local tick.'
            );
        }
        return;
    }

    if ($name !== 'bakerysf') {
        throw new RuntimeException('Studio clock cron requires database bakerysf, got ' . $name . '.');
    }
}

/** @return array{clock_enabled:int,min_interval_minutes:int,max_interval_minutes:int,max_actions_per_baker:int,max_bakers_per_tick:int,updated_at:?string,updated_by_user_id:?int} */
function bakery_sfb_studio_settings(PDO $db) {
    bakery_sfb_studio_ensure_schema($db);
    $defaults = [
        'clock_enabled' => 1,
        'min_interval_minutes' => 6,
        'max_interval_minutes' => 10,
        'max_actions_per_baker' => 3,
        'max_bakers_per_tick' => 20,
        'updated_at' => null,
        'updated_by_user_id' => null,
    ];
    if (!table_exists($db, 'sfb_studio_settings')) {
        return $defaults;
    }
    $row = $db->query('SELECT * FROM sfb_studio_settings WHERE id = 1 LIMIT 1')->fetch();
    if (!$row) {
        $db->exec(
            'INSERT IGNORE INTO sfb_studio_settings
             (id, clock_enabled, min_interval_minutes, max_interval_minutes, max_actions_per_baker, max_bakers_per_tick)
             VALUES (1, 1, 6, 10, 3, 20)'
        );
        $row = $db->query('SELECT * FROM sfb_studio_settings WHERE id = 1 LIMIT 1')->fetch();
    }
    return $row ? array_merge($defaults, $row) : $defaults;
}

function bakery_sfb_studio_save_settings(PDO $db, array $input, $userId = 0) {
    bakery_sfb_studio_ensure_schema($db);
    if (!table_exists($db, 'sfb_studio_settings')) {
        throw new RuntimeException('Synthetic Manager tables are not installed yet');
    }
    $min = max(1, min(240, (int)($input['min_interval_minutes'] ?? 6)));
    $max = max($min, min(240, (int)($input['max_interval_minutes'] ?? 10)));
    $actions = max(1, min(6, (int)($input['max_actions_per_baker'] ?? 3)));
    $bakers = max(1, min(100, (int)($input['max_bakers_per_tick'] ?? 20)));
    $enabled = empty($input['clock_enabled']) ? 0 : 1;
    $stmt = $db->prepare(
        'INSERT INTO sfb_studio_settings
         (id, clock_enabled, min_interval_minutes, max_interval_minutes, max_actions_per_baker, max_bakers_per_tick, updated_by_user_id)
         VALUES (1, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            clock_enabled = VALUES(clock_enabled),
            min_interval_minutes = VALUES(min_interval_minutes),
            max_interval_minutes = VALUES(max_interval_minutes),
            max_actions_per_baker = VALUES(max_actions_per_baker),
            max_bakers_per_tick = VALUES(max_bakers_per_tick),
            updated_by_user_id = VALUES(updated_by_user_id)'
    );
    $stmt->execute([$enabled, $min, $max, $actions, $bakers, (int)$userId > 0 ? (int)$userId : null]);
    bakery_sfb_studio_enroll($db, $enabled ? 'stagger' : 'keep');
    return bakery_sfb_studio_settings($db);
}

function bakery_sfb_studio_synthetic_bakers(PDO $db) {
    if (!bakery_sfb_origin_column_ready($db) || !column_exists($db, 'customers', 'sf_baker_enabled')) {
        return [];
    }
    return $db->query(
        "SELECT c.id, c.name, c.sfb_origin, c.sf_baker_enabled
         FROM customers c
         WHERE c.is_active = 1 AND c.sf_baker_enabled = 1 AND c.sfb_origin = 'synthetic'
         ORDER BY c.name"
    )->fetchAll();
}

function bakery_sfb_studio_interval_seconds(array $settings) {
    $min = max(1, (int)$settings['min_interval_minutes']) * 60;
    $max = max($min, (int)$settings['max_interval_minutes'] * 60);
    return random_int($min, $max);
}

function bakery_sfb_studio_schedule_at(array $settings, $mode = 'interval') {
    $now = time();
    if ($mode === 'soon') {
        return date('Y-m-d H:i:s', $now + random_int(0, 90));
    }
    if ($mode === 'stagger') {
        $max = max(60, (int)$settings['max_interval_minutes'] * 60);
        return date('Y-m-d H:i:s', $now + random_int(0, $max));
    }
    return date('Y-m-d H:i:s', $now + bakery_sfb_studio_interval_seconds($settings));
}

function bakery_sfb_studio_enroll(PDO $db, $mode = 'stagger') {
    bakery_sfb_studio_ensure_schema($db);
    if (!table_exists($db, 'sfb_studio_clock')) {
        return 0;
    }
    $settings = bakery_sfb_studio_settings($db);
    $count = 0;
    foreach (bakery_sfb_studio_synthetic_bakers($db) as $baker) {
        $id = (int)$baker['id'];
        $exists = $db->prepare('SELECT customer_id FROM sfb_studio_clock WHERE customer_id = ? LIMIT 1');
        $exists->execute([$id]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $at = bakery_sfb_studio_schedule_at($settings, $mode);
        $db->prepare(
            'INSERT INTO sfb_studio_clock (customer_id, next_action_at, paused, actions_taken)
             VALUES (?, ?, 0, 0)'
        )->execute([$id, $at]);
        $count++;
    }
    return $count;
}

function bakery_sfb_studio_set_baker_paused(PDO $db, $customerId, $paused) {
    bakery_sfb_studio_ensure_schema($db);
    bakery_sfb_studio_enroll($db, 'stagger');
    $stmt = $db->prepare('UPDATE sfb_studio_clock SET paused = ? WHERE customer_id = ?');
    $stmt->execute([$paused ? 1 : 0, (int)$customerId]);
}

function bakery_sfb_studio_persona_for_customer(PDO $db, array $customer) {
    $name = (string)($customer['name'] ?? '');
    $profile = null;
    if (table_exists($db, 'sfb_persona_profiles')) {
        $stmt = $db->prepare('SELECT * FROM sfb_persona_profiles WHERE customer_id = ? LIMIT 1');
        $stmt->execute([(int)$customer['id']]);
        $profile = $stmt->fetch() ?: null;
    }
    foreach (bakery_sfb_persona_catalog() as $persona) {
        if ($persona['name'] === $name || ((string)$persona['key'] === (string)($profile['persona_key'] ?? ''))) {
            return $persona;
        }
    }
    $cohort = (string)($profile['cohort'] ?? 'beginner');
    $locale = (string)($profile['locale'] ?? 'en');
    $defaults = bakery_sfb_persona_defaults($cohort, (int)($customer['id'] ?? 0));
    $copy = bakery_sfb_persona_copy($cohort, $locale, $defaults, 0, $name !== '' ? $name : 'baker');
    return array_merge($defaults, $copy, [
        'name' => $name !== '' ? $name : 'Synthetic baker',
        'cohort' => $cohort,
        'locale' => $locale,
        'mentor' => !empty($profile['is_mentor']),
        'template' => bakery_sfb_persona_template_for_cohort($cohort),
        'flour' => $defaults['flour'],
        'temp_f' => $defaults['temp_f'],
        'hydration' => $defaults['hydration'],
        'feed_ratio' => $defaults['feed_ratio'],
        'loaves' => $defaults['loaves'],
        'turn_type' => $defaults['turn_type'],
        'oven_temp' => $defaults['oven_temp'],
        'topic_title' => $copy['topic_title'],
        'topic_body' => $copy['topic_body'],
        'coach_ask' => $copy['coach_ask'],
        'reply_body' => $copy['reply_body'],
        'final_notes' => $copy['final_notes'],
        'peak_notes' => $copy['peak_notes'],
        'topic_category' => $cohort === 'weekend' ? 'weekend_schedule' : 'fermentation',
        'category' => $cohort === 'weekend' ? 'weekend_schedule' : 'fermentation',
    ]);
}

function bakery_sfb_studio_baker_state(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    $customer = bakery_sfb_require_community_baker($db, $customerId);
    $named = $db->prepare('SELECT id, name, sfb_origin, sf_baker_enabled FROM customers WHERE id = ? LIMIT 1');
    $named->execute([$customerId]);
    $namedRow = $named->fetch();
    if (is_array($namedRow)) {
        $customer = array_merge($customer, $namedRow);
    }
    $persona = bakery_sfb_studio_persona_for_customer($db, $customer);
    $starters = bakery_sfb_starters($db, $customerId);
    $starter = $starters[0] ?? bakery_sfb_ensure_starter(
        $db,
        $customerId,
        (string)($persona['starter_name'] ?? $persona['starter'] ?? 'Home starter'),
        (string)($persona['starter_blend'] ?? $persona['flour'] ?? 'bread flour'),
        100,
        ''
    );
    $feedings = $starter ? bakery_sfb_starter_feedings($db, (int)$starter['id'], 1) : [];
    $lastFeed = $feedings[0] ?? null;
    $formulas = bakery_sfb_formulas($db, $customerId);
    $active = bakery_sfb_active_batch($db, $customerId);
    $recent = bakery_sfb_batches($db, $customerId, 8);
    $latestCompleted = null;
    foreach ($recent as $batch) {
        if (($batch['status'] ?? '') === 'completed') {
            $latestCompleted = $batch;
            break;
        }
    }
    $turns = $active ? bakery_sfb_batch_turns($db, (int)$active['id']) : [];
    $temps = $active ? bakery_sfb_batch_temps($db, (int)$active['id']) : [];
    $messages = ($active && function_exists('bakery_sfb_batch_messages'))
        ? bakery_sfb_batch_messages($db, (int)$active['id'])
        : [];
    $hasQuestion = false;
    foreach ($messages as $message) {
        if (($message['message_type'] ?? '') === 'question' && ($message['author_type'] ?? '') === 'baker') {
            $hasQuestion = true;
            break;
        }
    }
    $share = $latestCompleted ? bakery_sfb_batch_share($db, (int)$latestCompleted['id']) : null;
    $topicId = $latestCompleted ? bakery_sfb_persona_find_topic_for_batch($db, $customerId, (int)$latestCompleted['id']) : 0;
    $feedAge = $lastFeed ? max(0, time() - strtotime((string)$lastFeed['fed_at'])) : PHP_INT_MAX;
    $tempPhases = [];
    foreach ($temps as $temp) {
        $tempPhases[(string)$temp['phase']] = true;
    }
    return [
        'customer' => $customer,
        'persona' => $persona,
        'starter' => $starter,
        'last_feed' => $lastFeed,
        'feed_age' => $feedAge,
        'formulas' => $formulas,
        'active' => $active,
        'turns' => $turns,
        'temps' => $temps,
        'temp_phases' => $tempPhases,
        'has_question' => $hasQuestion,
        'latest_completed' => $latestCompleted,
        'share' => $share,
        'topic_id' => $topicId,
    ];
}

/**
 * @return array<int,string>
 */
function bakery_sfb_studio_choose_actions(PDO $db, array $state) {
    $actions = [];
    $persona = $state['persona'];
    if (!$state['last_feed'] || (int)$state['feed_age'] > 6 * 3600) {
        $actions[] = 'feed_starter';
    }
    if (!$state['formulas']) {
        $actions[] = 'copy_formula';
    }
    if ($state['active']) {
        $turns = count($state['turns']);
        $temps = count($state['temps']);
        $phases = $state['temp_phases'];
        if (empty($phases['mix'])) {
            $actions[] = 'log_temp';
        }
        if ($turns < 2) {
            $actions[] = 'log_turn';
        }
        if ($temps >= 1 && empty($phases['development'])) {
            $actions[] = 'log_temp';
        }
        if (!$state['has_question']) {
            $actions[] = 'ask_coach';
        }
        if ($turns >= 1 && $temps >= 1 && empty($state['active']['bake_notes'])) {
            $actions[] = 'bake_note';
        }
        if ($turns >= 2 && $temps >= 2) {
            $actions[] = 'complete_batch';
        }
    } else {
        if ($state['latest_completed'] && !$state['share']) {
            $actions[] = 'share_batch';
        } elseif ($state['latest_completed'] && $state['share'] && (int)$state['topic_id'] <= 0) {
            $actions[] = 'post_topic';
        } else {
            $actions[] = 'start_batch';
        }
    }
    if (!empty($persona['mentor'])) {
        $actions[] = 'reply_topic';
    }
    $out = [];
    foreach ($actions as $action) {
        if (!in_array($action, $out, true)) {
            $out[] = $action;
        }
    }
    return $out;
}

function bakery_sfb_studio_open_reply_topic(PDO $db, $customerId) {
    if (!bakery_sfb_community_ready($db)) {
        return 0;
    }
    $stmt = $db->prepare(
        "SELECT t.id
         FROM sfb_community_topics t
         LEFT JOIN customers c ON c.id = t.author_customer_id
         LEFT JOIN sfb_community_replies mine
           ON mine.topic_id = t.id AND mine.author_customer_id = ?
         WHERE t.author_customer_id IS NOT NULL
           AND t.author_customer_id <> ?
           AND COALESCE(c.sfb_origin, '') = 'synthetic'
           AND mine.id IS NULL
           AND t.created_at > DATE_SUB(NOW(), INTERVAL 21 DAY)
         ORDER BY t.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$customerId, (int)$customerId]);
    return (int)$stmt->fetchColumn();
}

/**
 * @return array{action:string,status:string,summary:string,batch_id:int,topic_id:int,detail:array}
 */
function bakery_sfb_studio_perform_action(PDO $db, array $state, $action) {
    $customer = $state['customer'];
    $customerId = (int)$customer['id'];
    $persona = $state['persona'];
    $temp = (int)($persona['temp_f'] ?? 76);
    $hydration = (int)($persona['hydration'] ?? 75);
    $flour = (string)($persona['flour'] ?? 'bread flour');
    $result = [
        'action' => $action,
        'status' => 'ok',
        'summary' => '',
        'batch_id' => (int)($state['active']['id'] ?? ($state['latest_completed']['id'] ?? 0)),
        'topic_id' => (int)($state['topic_id'] ?? 0),
        'detail' => ['flour' => $flour, 'temp_f' => $temp, 'hydration' => $hydration],
    ];

    switch ($action) {
        case 'feed_starter':
            $starter = $state['starter'] ?: bakery_sfb_ensure_starter($db, $customerId);
            $grams = bakery_sfb_persona_feed_grams((string)($persona['feed_ratio'] ?? '1:2:2'));
            $id = bakery_sfb_add_starter_feeding(
                $db,
                $customerId,
                (int)$starter['id'],
                $grams[0],
                $grams[1],
                $grams[2],
                date('Y-m-d H:i:s'),
                (string)($persona['peak_notes'] ?? ''),
                $flour . ' at ' . $temp . 'F, ' . $hydration . '% dough planned'
            );
            $result['summary'] = 'Fed starter ' . ($persona['feed_ratio'] ?? '1:2:2') . ' with notes';
            $result['detail']['feeding_id'] = $id;
            break;

        case 'copy_formula':
            $formulaId = bakery_sfb_agent_ensure_formula($db, $customerId, (string)($persona['template'] ?? ''));
            $result['summary'] = 'Copied formula into the journal';
            $result['detail']['formula_id'] = $formulaId;
            break;

        case 'start_batch':
            $formulaId = bakery_sfb_agent_ensure_formula($db, $customerId, (string)($persona['template'] ?? ''));
            $name = trim((string)($persona['name'] ?? 'Baker')) . ' loaf ' . date('M j g:ia');
            if (strlen($name) > 120) {
                $name = substr($name, 0, 120);
            }
            $batchId = bakery_sfb_start_batch($db, $customerId, $formulaId, $name, date('Y-m-d H:i:s'));
            $result['batch_id'] = $batchId;
            $result['summary'] = 'Started ' . $name;
            $result['detail']['formula_id'] = $formulaId;
            break;

        case 'log_turn':
            if (!$state['active']) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open batch for a fold';
                break;
            }
            $turnType = (string)($persona['turn_type'] ?? 'stretch_fold');
            $id = bakery_sfb_add_batch_turn(
                $db,
                $customerId,
                (int)$state['active']['id'],
                $turnType,
                $temp,
                date('Y-m-d H:i:s'),
                $temp . 'F dough, ' . $hydration . '% ' . $flour
            );
            $result['summary'] = 'Logged a ' . str_replace('_', ' ', $turnType) . ' at ' . $temp . 'F';
            $result['detail']['turn_id'] = $id;
            break;

        case 'log_temp':
            if (!$state['active']) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open batch for a temperature';
                break;
            }
            $phase = empty($state['temp_phases']['mix']) ? 'mix' : (empty($state['temp_phases']['development']) ? 'development' : 'bake');
            $id = bakery_sfb_add_batch_temp(
                $db,
                $customerId,
                (int)$state['active']['id'],
                $phase === 'bake' ? $temp + 2 : $temp,
                $phase,
                date('Y-m-d H:i:s'),
                $flour . ' at ' . $hydration . '%'
            );
            $result['summary'] = 'Logged ' . $phase . ' temp ' . $temp . 'F';
            $result['detail']['temp_id'] = $id;
            $result['detail']['phase'] = $phase;
            break;

        case 'bake_note':
            if (!$state['active']) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open batch for bake notes';
                break;
            }
            $oven = (int)($persona['oven_temp'] ?? 475);
            bakery_sfb_save_batch_bake(
                $db,
                $customerId,
                (int)$state['active']['id'],
                $oven,
                date('Y-m-d H:i:s'),
                '',
                $oven . 'F oven, steam on, ' . $flour . ' at ' . $hydration . '%'
            );
            $result['summary'] = 'Added bake notes at ' . $oven . 'F';
            $result['detail']['oven_temp'] = $oven;
            break;

        case 'ask_coach':
            if (!$state['active']) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open batch to ask about';
                break;
            }
            $id = bakery_sfb_add_batch_message(
                $db,
                (int)$state['active']['id'],
                'baker',
                (string)($customer['name'] ?? $persona['name'] ?? 'Baker'),
                (string)($persona['coach_ask'] ?? ('Bulk at ' . $temp . 'F with ' . $flour . ' at ' . $hydration . '%. What would you change?')),
                'question',
                $customerId
            );
            $result['summary'] = 'Asked the coach privately';
            $result['detail']['message_id'] = $id;
            break;

        case 'complete_batch':
            if (!$state['active']) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open batch to complete';
                break;
            }
            $loaves = max(1, (int)($persona['loaves'] ?? 2));
            bakery_sfb_complete_batch(
                $db,
                $customerId,
                (int)$state['active']['id'],
                $loaves,
                (string)($persona['final_notes'] ?? ($loaves . ' loaves at ' . $temp . 'F, ' . $hydration . '%, ' . $flour))
            );
            $result['summary'] = 'Completed ' . $loaves . ' loaf' . ($loaves === 1 ? '' : 'es');
            $result['detail']['loaves'] = $loaves;
            break;

        case 'share_batch':
            $batch = $state['latest_completed'];
            if (!$batch) {
                $result['status'] = 'skip';
                $result['summary'] = 'No completed bake to share';
                break;
            }
            bakery_sfb_share_batch($db, $customerId, (int)$batch['id']);
            $result['batch_id'] = (int)$batch['id'];
            $result['summary'] = 'Shared bake card for ' . (string)$batch['name'];
            break;

        case 'post_topic':
            $batch = $state['latest_completed'];
            if (!$batch) {
                $result['status'] = 'skip';
                $result['summary'] = 'No bake card to attach';
                break;
            }
            $category = (string)($persona['topic_category'] ?? $persona['category'] ?? 'fermentation');
            if (!in_array($category, bakery_sfb_community_categories($db), true)) {
                $category = 'fermentation';
            }
            $title = (string)($persona['topic_title'] ?? ('Bulk at ' . $temp . 'F'));
            $body = (string)($persona['topic_body'] ?? ('Bulk at ' . $temp . 'F, ' . $hydration . '% with ' . $flour . '.'));
            $topicId = bakery_sfb_persona_post($db, $customer, $persona, $title, $body, $category, (int)$batch['id']);
            $result['batch_id'] = (int)$batch['id'];
            $result['topic_id'] = $topicId;
            $result['summary'] = 'Posted in ' . $category;
            break;

        case 'reply_topic':
            $topicId = bakery_sfb_studio_open_reply_topic($db, $customerId);
            if ($topicId <= 0) {
                $result['status'] = 'skip';
                $result['summary'] = 'No open circle post to answer';
                break;
            }
            $replyId = bakery_sfb_persona_reply_once($db, $topicId, [
                'customer_id' => $customerId,
                'customer' => $customer,
                'body' => (string)($persona['reply_body'] ?? ('At ' . $temp . 'F I would keep ' . $hydration . '% with ' . $flour . '.')),
            ]);
            if ($replyId <= 0) {
                $result['status'] = 'skip';
                $result['summary'] = 'Already answered that post';
                break;
            }
            $result['topic_id'] = $topicId;
            $result['summary'] = 'Replied in the circle';
            $result['detail']['reply_id'] = $replyId;
            break;

        default:
            $result['status'] = 'skip';
            $result['summary'] = 'Unknown action';
    }

    return $result;
}

function bakery_sfb_studio_log(PDO $db, $tickId, $customerId, array $result) {
    if (!table_exists($db, 'sfb_studio_action_log')) {
        return 0;
    }
    $stmt = $db->prepare(
        'INSERT INTO sfb_studio_action_log
         (tick_id, customer_id, action, status, summary, detail_json, batch_id, topic_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $detail = json_encode($result['detail'] ?? [], JSON_UNESCAPED_UNICODE);
    $stmt->execute([
        (string)$tickId,
        (int)$customerId,
        (string)($result['action'] ?? 'unknown'),
        (string)($result['status'] ?? 'ok'),
        substr((string)($result['summary'] ?? ''), 0, 255),
        $detail !== false ? $detail : null,
        (int)($result['batch_id'] ?? 0) > 0 ? (int)$result['batch_id'] : null,
        (int)($result['topic_id'] ?? 0) > 0 ? (int)$result['topic_id'] : null,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_studio_due_bakers(PDO $db, $limit, $customerId = 0) {
    $limit = max(1, min(100, (int)$limit));
    $sql = "SELECT clk.*, c.name, c.sfb_origin
            FROM sfb_studio_clock clk
            JOIN customers c ON c.id = clk.customer_id
            WHERE clk.paused = 0
              AND clk.next_action_at <= NOW()
              AND c.is_active = 1
              AND c.sf_baker_enabled = 1
              AND c.sfb_origin = 'synthetic'";
    $params = [];
    if ((int)$customerId > 0) {
        $sql .= ' AND clk.customer_id = ?';
        $params[] = (int)$customerId;
    }
    $sql .= ' ORDER BY clk.next_action_at ASC, clk.customer_id ASC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Advance due synthetic bakers. Safe to run every minute.
 *
 * @return array{ok:bool,tick_id:string,clock_enabled:bool,enrolled:int,bakers:int,actions:int,skipped:int,errors:int,results:array}
 */
function bakery_sfb_studio_tick(PDO $db, array $opts = []) {
    bakery_ensure_sfb_schema($db);
    bakery_sfb_studio_ensure_schema($db);
    $tickId = substr(bin2hex(random_bytes(8)), 0, 16);
    $settings = bakery_sfb_studio_settings($db);
    $enrolled = bakery_sfb_studio_enroll($db, 'stagger');
    $forcedBaker = (int)($opts['customer_id'] ?? 0);
    $force = !empty($opts['force']);

    $out = [
        'ok' => true,
        'tick_id' => $tickId,
        'clock_enabled' => (int)$settings['clock_enabled'] === 1,
        'enrolled' => $enrolled,
        'bakers' => 0,
        'actions' => 0,
        'skipped' => 0,
        'errors' => 0,
        'results' => [],
    ];

    if ((int)$settings['clock_enabled'] !== 1 && !$force) {
        $out['ok'] = true;
        $out['results'][] = ['summary' => 'Clock is paused in Synthetic Manager'];
        return $out;
    }

    $lock = $db->query("SELECT GET_LOCK('sfb_studio_tick', 0)")->fetchColumn();
    if ((int)$lock !== 1 && !$force) {
        $out['ok'] = true;
        $out['results'][] = ['summary' => 'Another tick is already running'];
        return $out;
    }

    try {
        if ($force && $forcedBaker > 0) {
            $db->prepare('UPDATE sfb_studio_clock SET next_action_at = NOW() WHERE customer_id = ? AND paused = 0')
                ->execute([$forcedBaker]);
        } elseif ($force && $forcedBaker <= 0) {
            $db->exec('UPDATE sfb_studio_clock SET next_action_at = NOW() WHERE paused = 0');
        }

        $due = bakery_sfb_studio_due_bakers(
            $db,
            $force && $forcedBaker > 0 ? 1 : (int)$settings['max_bakers_per_tick'],
            $forcedBaker
        );
        foreach ($due as $clock) {
            $customerId = (int)$clock['customer_id'];
            $bakerResults = [];
            try {
                if (!bakery_sfb_is_synthetic($clock)) {
                    throw new RuntimeException('Refusing to tick a non-synthetic baker');
                }
                $planned = (int)$settings['max_actions_per_baker'];
                $take = $force ? max(1, $planned) : random_int(1, max(1, $planned));
                $taken = 0;
                for ($i = 0; $i < $take; $i++) {
                    $state = bakery_sfb_studio_baker_state($db, $customerId);
                    $choices = bakery_sfb_studio_choose_actions($db, $state);
                    if (!$choices) {
                        break;
                    }
                    $action = $choices[0];
                    $result = bakery_sfb_studio_perform_action($db, $state, $action);
                    bakery_sfb_studio_log($db, $tickId, $customerId, $result);
                    $bakerResults[] = $result;
                    if (($result['status'] ?? '') === 'ok') {
                        $out['actions']++;
                        $taken++;
                    } elseif (($result['status'] ?? '') === 'error') {
                        $out['errors']++;
                    } else {
                        $out['skipped']++;
                    }
                    if (($result['status'] ?? '') !== 'ok') {
                        array_shift($choices);
                        if (!$choices) {
                            break;
                        }
                    }
                }
                $next = bakery_sfb_studio_schedule_at($settings, 'interval');
                $lastAction = $bakerResults ? (string)$bakerResults[count($bakerResults) - 1]['action'] : $clock['last_action'];
                $db->prepare(
                    'UPDATE sfb_studio_clock
                     SET next_action_at = ?, last_action_at = NOW(), last_action = ?, actions_taken = actions_taken + ?
                     WHERE customer_id = ?'
                )->execute([$next, $lastAction, $taken, $customerId]);
                $out['bakers']++;
                $out['results'][] = [
                    'customer_id' => $customerId,
                    'name' => (string)$clock['name'],
                    'next_action_at' => $next,
                    'actions' => $bakerResults,
                ];
            } catch (Throwable $e) {
                $out['errors']++;
                $errorRow = [
                    'action' => 'tick',
                    'status' => 'error',
                    'summary' => $e->getMessage(),
                    'batch_id' => 0,
                    'topic_id' => 0,
                    'detail' => ['exception' => $e->getMessage()],
                ];
                bakery_sfb_studio_log($db, $tickId, $customerId, $errorRow);
                $next = bakery_sfb_studio_schedule_at($settings, 'interval');
                $db->prepare('UPDATE sfb_studio_clock SET next_action_at = ? WHERE customer_id = ?')
                    ->execute([$next, $customerId]);
                $out['results'][] = [
                    'customer_id' => $customerId,
                    'name' => (string)($clock['name'] ?? ''),
                    'error' => $e->getMessage(),
                ];
            }
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('sfb_studio_tick')");
    }

    return $out;
}

function bakery_sfb_studio_logs(PDO $db, $customerId = 0, $limit = 80) {
    if (!table_exists($db, 'sfb_studio_action_log')) {
        return [];
    }
    $limit = max(1, min(300, (int)$limit));
    $sql = 'SELECT l.*, c.name AS baker_name, c.sfb_origin
            FROM sfb_studio_action_log l
            JOIN customers c ON c.id = l.customer_id';
    $params = [];
    if ((int)$customerId > 0) {
        $sql .= ' WHERE l.customer_id = ?';
        $params[] = (int)$customerId;
    }
    $sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function bakery_sfb_studio_log_row(PDO $db, $logId) {
    if (!table_exists($db, 'sfb_studio_action_log')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT l.*, c.name AS baker_name, c.sfb_origin
         FROM sfb_studio_action_log l
         JOIN customers c ON c.id = l.customer_id
         WHERE l.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$logId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_studio_roster(PDO $db) {
    bakery_sfb_studio_enroll($db, 'stagger');
    if (!table_exists($db, 'sfb_studio_clock')) {
        return [];
    }
    return $db->query(
        "SELECT c.id, c.name, c.sfb_origin,
                clk.next_action_at, clk.last_action_at, clk.last_action, clk.paused, clk.actions_taken,
                p.cohort, p.locale, p.is_mentor,
                (SELECT COUNT(*) FROM sfb_batches b WHERE b.customer_id = c.id AND b.status = 'in_progress') AS active_batches,
                (SELECT COALESCE(SUM(b.loaf_count), 0) FROM sfb_batches b WHERE b.customer_id = c.id AND b.status = 'completed') AS loaf_total
         FROM customers c
         JOIN sfb_studio_clock clk ON clk.customer_id = c.id
         LEFT JOIN sfb_persona_profiles p ON p.customer_id = c.id
         WHERE c.sfb_origin = 'synthetic' AND c.sf_baker_enabled = 1
         ORDER BY clk.paused ASC, clk.next_action_at ASC, c.name ASC"
    )->fetchAll();
}

function bakery_sfb_studio_baker_detail(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    bakery_sfb_studio_enroll($db, 'stagger');
    $stmt = $db->prepare(
        "SELECT c.id, c.name, c.sfb_origin, c.sf_baker_enabled,
                clk.next_action_at, clk.last_action_at, clk.last_action, clk.paused, clk.actions_taken,
                p.cohort, p.locale, p.is_mentor, p.persona_key
         FROM customers c
         LEFT JOIN sfb_studio_clock clk ON clk.customer_id = c.id
         LEFT JOIN sfb_persona_profiles p ON p.customer_id = c.id
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    if (!$row || !bakery_sfb_is_synthetic($row)) {
        return null;
    }
    $state = bakery_sfb_studio_baker_state($db, $customerId);
    return [
        'baker' => $row,
        'state' => $state,
        'planned' => bakery_sfb_studio_choose_actions($db, $state),
        'logs' => bakery_sfb_studio_logs($db, $customerId, 60),
        'batches' => bakery_sfb_batches($db, $customerId, 12),
    ];
}

function bakery_sfb_studio_action_label($action) {
    $labels = [
        'feed_starter' => 'Feed starter',
        'copy_formula' => 'Copy formula',
        'start_batch' => 'Start batch',
        'log_turn' => 'Log fold',
        'log_temp' => 'Log temperature',
        'bake_note' => 'Bake notes',
        'ask_coach' => 'Ask coach',
        'complete_batch' => 'Complete loaves',
        'share_batch' => 'Share bake card',
        'post_topic' => 'Post in circle',
        'reply_topic' => 'Reply in circle',
        'tick' => 'Tick',
    ];
    $action = (string)$action;
    return $labels[$action] ?? str_replace('_', ' ', $action);
}

<?php
/**
 * Non-GUI operator for the agent-controlled SFAdmin user.
 *
 * Local/test database only unless --allow-production AND USE_PROD_DB.
 *
 *   php scripts/sfb_agent.php ensure-admin
 *   php scripts/sfb_agent.php create-baker --name=Mina --code=1110 --origin=synthetic --persona=beginner --locale=en
 *   php scripts/sfb_agent.php act-as --customer=Mina
 *   php scripts/sfb_agent.php feed-starter --customer=Mina
 *   php scripts/sfb_agent.php copy-formula --customer=Mina --formula="Basic Sourdough"
 *   php scripts/sfb_agent.php start-batch --customer=Mina --name="Test batch"
 *   php scripts/sfb_agent.php log-turn --customer=Mina --temp=76
 *   php scripts/sfb_agent.php log-temp --customer=Mina --temp=76
 *   php scripts/sfb_agent.php complete-batch --customer=Mina --loaves=2
 *   php scripts/sfb_agent.php share-batch --customer=Mina
 *   php scripts/sfb_agent.php post-topic --customer=Mina --category=fermentation --title="..." --body="..." --batch=1
 *   php scripts/sfb_agent.php reply --customer=Mina --topic=1 --body="..."
 *   php scripts/sfb_agent.php ask-coach --customer=Mina --body="..."
 *   php scripts/sfb_agent.php status --origin=synthetic
 *   php scripts/sfb_agent.php seed-studio --limit=20
 *
 * Aliases: ensure, create-customer, login-as
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/sfb_agent.php';

function bakery_sfb_agent_cli_help() {
    echo <<<TXT
SFAdmin agent operator (local/test unless --allow-production AND USE_PROD_DB)

Commands:
  ensure-admin                   Create/refresh SFAdmin and log in
  create-baker                   Create a synthetic SF Baker (no zone, no standing orders)
  act-as                         Open a portal session as that baker
  feed-starter                   Log a starter feeding
  copy-formula                   Copy a standard formula into the baker's journal
  start-batch                    Start a batch as the acting baker
  log-turn                       Log a stretch/fold (or other turn)
  log-temp                       Log a dough temperature
  complete-batch                 Complete the open batch
  share-batch                    Publish the bake card
  post-topic                     Post in a community circle (process fact required for synthetics)
  reply                          Reply to a topic as the acting baker
  ask-coach                      Private batch question (not public)
  status                         Show SFAdmin, acting baker, and bakers
  seed-studio                    Seed 20 personas (bakerysf_test, or production with dual-key)
  verify-studio                  Assert wave-1 bakers, origin, and eval
  tick-studio                    Advance due synthetics (DreamHost cron; local needs --force)
  demo                           Create Customer1 + Customer2 and start test batches (test DB only)

Aliases: ensure, create-customer, login-as

Options:
  --name=Customer1               Baker display name (create-baker) or batch name (start-batch)
  --batch-name=Saturday          Batch name for start-batch
  --code=1101                    Preferred 4-digit portal code
  --customer=Customer1           Baker name or id
  --origin=synthetic|human       Identity (create-baker defaults to synthetic)
  --persona=beginner             Persona key stored on the studio profile
  --locale=en|es                 Journal/post language
  --formula=Basic Sourdough      Template name or id
  --batch=12                     Batch id (defaults to the open batch)
  --topic=12                     Community topic id
  --category=fermentation        Community circle
  --title=...                    Topic title
  --body=...                     Topic, reply, or coach message
  --temp=76                      Dough temperature F
  --loaves=2                     Completed loaf count
  --starter=Home starter         Starter name
  --limit=20                     seed-studio / verify-studio size
  --refresh                      Enrich already-seeded wave-1 journals
  --force                        tick-studio: act now, even if the clock is paused
  --json                         Print JSON instead of text
  --allow-production             Required with USE_PROD_DB for production writes

TXT;
}

function bakery_sfb_agent_cli_args(array $argv) {
    $command = '';
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if ($arg === '--refresh') {
            $opts['refresh'] = true;
            continue;
        }
        if ($arg === '--force') {
            $opts['force'] = true;
            continue;
        }
        if ($arg === '--allow-production') {
            $opts['allow-production'] = true;
            continue;
        }
        if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $m)) {
            $opts[strtolower($m[1])] = $m[2];
            continue;
        }
        if ($command === '' && $arg !== '' && $arg[0] !== '-') {
            $command = $arg;
        }
    }
    return [$command, $opts];
}

function bakery_sfb_agent_cli_emit($opts, array $payload, $text) {
    if (!empty($opts['json'])) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }
    echo $text;
}

function bakery_sfb_agent_cli_require_customer(array $opts, $allowName = false) {
    $who = trim((string)($opts['customer'] ?? ''));
    if ($who === '' && $allowName) {
        $who = trim((string)($opts['name'] ?? ''));
    }
    if ($who === '') {
        throw new InvalidArgumentException('--customer is required');
    }
    return $who;
}

$opts = [];
try {
    [$command, $opts] = bakery_sfb_agent_cli_args($argv);
    if ($command === '' || in_array($command, ['help', '-h', '--help'], true)) {
        bakery_sfb_agent_cli_help();
        exit($command === '' ? 1 : 0);
    }

    $aliases = [
        'ensure' => 'ensure-admin',
        'create-customer' => 'create-baker',
        'login-as' => 'act-as',
    ];
    if (isset($aliases[$command])) {
        $command = $aliases[$command];
    }

    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    if (!empty($opts['allow-production']) && (!defined('USE_PROD_DB') || !USE_PROD_DB)) {
        throw new RuntimeException('--allow-production requires USE_PROD_DB=true (php scripts/switch_db.php prod).');
    }
    $allowProdStudio = !empty($opts['allow-production']) && defined('USE_PROD_DB') && USE_PROD_DB;
    if ($command === 'tick-studio') {
        require_once $root . '/includes/sfb_studio_clock.php';
        bakery_sfb_studio_assert_tick_cli($db, !empty($opts['force']) || !empty($opts['customer']));
    } elseif ($command === 'demo') {
        bakery_sfb_agent_assert_local($db);
    } elseif (in_array($command, ['seed-studio', 'verify-studio'], true) && $allowProdStudio) {
        $prodName = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
        if ($prodName !== 'bakerysf') {
            throw new RuntimeException('Production studio commands require database bakerysf, got ' . $prodName . '.');
        }
        fwrite(STDERR, "Production studio target: {$prodName}@" . DB_HOST . "\n");
    } elseif (empty($opts['allow-production']) || !defined('USE_PROD_DB') || !USE_PROD_DB) {
        bakery_sfb_agent_assert_local($db);
    }

    switch ($command) {
        case 'ensure-admin':
            $admin = bakery_sfb_agent_login($db);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true, 'admin' => $admin],
                "SFAdmin ready id={$admin['id']} email={$admin['email']} role=administrator code={$admin['login_code']}\n"
            );
            break;

        case 'create-baker':
            $name = trim((string)($opts['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('--name is required');
            }
            bakery_sfb_agent_login($db);
            $created = bakery_sfb_agent_create_baker($db, $name, (string)($opts['code'] ?? ''), [
                'phone' => (string)($opts['phone'] ?? ''),
                'origin' => (string)($opts['origin'] ?? 'synthetic'),
                'persona' => (string)($opts['persona'] ?? ''),
                'locale' => (string)($opts['locale'] ?? 'en'),
                'adopt_reserved' => in_array($name, bakery_sfb_agent_reserved_reuse_names(), true),
            ]);
            $c = $created['customer'];
            $verb = $created['created'] ? 'created' : 'updated';
            $origin = $created['origin'] ?? ($c['sfb_origin'] ?? 'synthetic');
            bakery_sfb_agent_cli_emit($opts, ['ok' => true, 'action' => $verb] + $created,
                "Baker {$verb} id={$c['id']} name={$c['name']} portal_code={$created['portal_code']} origin={$origin} sf_baker=1\n"
            );
            break;

        case 'act-as':
            $who = bakery_sfb_agent_cli_require_customer($opts, true);
            bakery_sfb_agent_login($db);
            $customer = bakery_sfb_agent_login_as_customer($db, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true, 'customer' => $customer],
                "SFAdmin is now logged in as {$customer['name']} (customer id={$customer['id']})\n"
            );
            break;

        case 'feed-starter':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $fed = bakery_sfb_agent_feed_starter($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $fed,
                "Fed starter id={$fed['starter']['id']} ratio={$fed['ratio']} as {$fed['customer']['name']}\n"
            );
            break;

        case 'copy-formula':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            bakery_sfb_agent_login_as_customer($db, $who);
            $formulaId = bakery_sfb_agent_copy_formula(
                $db,
                (string)($opts['formula'] ?? $opts['template'] ?? ''),
                $who
            );
            bakery_sfb_agent_cli_emit($opts, ['ok' => true, 'formula_id' => $formulaId],
                "Copied formula id={$formulaId} for {$who}\n"
            );
            break;

        case 'start-batch':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $formulaOpt = trim((string)($opts['formula'] ?? ''));
            $formulaId = 0;
            if ($formulaOpt !== '') {
                $formulaId = ctype_digit($formulaOpt)
                    ? (int)$formulaOpt
                    : bakery_sfb_agent_copy_formula($db, $formulaOpt, $who);
            }
            $batch = bakery_sfb_agent_start_batch(
                $db,
                (string)($opts['batch-name'] ?? $opts['name'] ?? ''),
                $formulaId,
                $who
            );
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $batch,
                "Started batch id={$batch['batch_id']} name={$batch['name']} as {$batch['customer']['name']} formula={$batch['formula_name']}\n"
            );
            break;

        case 'log-turn':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $turn = bakery_sfb_agent_log_turn($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $turn,
                "Logged turn id={$turn['turn_id']} on batch {$turn['batch_id']}\n"
            );
            break;

        case 'log-temp':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $temp = bakery_sfb_agent_log_temp($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $temp,
                "Logged temp id={$temp['temp_id']} on batch {$temp['batch_id']}\n"
            );
            break;

        case 'complete-batch':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $done = bakery_sfb_agent_complete_batch($db, $opts, $who);
            $b = $done['batch'];
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $done,
                "Completed batch id={$b['id']} loaves={$b['loaf_count']} as {$done['customer']['name']}\n"
            );
            break;

        case 'share-batch':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $share = bakery_sfb_agent_share_batch($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $share,
                "Shared batch id={$share['batch_id']} as {$share['customer']['name']}\n"
            );
            break;

        case 'post-topic':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $posted = bakery_sfb_agent_post_topic($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $posted,
                "Posted topic id={$posted['topic_id']} as {$posted['customer']['name']}\n"
            );
            break;

        case 'reply':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $reply = bakery_sfb_agent_reply($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $reply,
                "Replied id={$reply['reply_id']} on topic {$reply['topic_id']} as {$reply['customer']['name']}\n"
            );
            break;

        case 'ask-coach':
            $who = bakery_sfb_agent_cli_require_customer($opts);
            bakery_sfb_agent_login($db);
            $asked = bakery_sfb_agent_ask_coach($db, $opts, $who);
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $asked,
                "Asked coach message id={$asked['message_id']} on batch {$asked['batch_id']} (private)\n"
            );
            break;

        case 'demo':
            $results = bakery_sfb_agent_run_customer_batch_demo($db);
            $lines = "SFAdmin id={$results['admin']['id']} email={$results['admin']['email']} code={$results['admin']['login_code']}\n";
            foreach ($results['customers'] as $row) {
                $lines .= sprintf(
                    "Logged in as %s (id=%d, portal_code=%s) and started batch id=%d (%s)\n",
                    $row['name'],
                    $row['id'],
                    $row['portal_code'],
                    $row['batch']['batch_id'],
                    $row['batch']['name']
                );
            }
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $results, $lines);
            break;

        case 'seed-studio':
            require_once $root . '/includes/sfb_personas.php';
            @ini_set('max_execution_time', '0');
            $limit = (int)($opts['limit'] ?? 20);
            $seeded = bakery_sfb_persona_seed(
                $db,
                $limit > 0 ? $limit : 20,
                !empty($opts['refresh']),
                $allowProdStudio
            );
            $dbName = $seeded['database'] ?? 'unknown';
            $lines = "Seeded {$seeded['seeded']} personas on {$dbName} (reused {$seeded['reused']}, skipped {$seeded['skipped']}, enriched {$seeded['enriched']}, failed {$seeded['failed']}, pinned {$seeded['pinned']}, catalog {$seeded['catalog']})\n";
            foreach ($seeded['bakers'] as $baker) {
                $lines .= sprintf(
                    "  - %s id=%d origin=%s cohort=%s locale=%s batch=%d topic=%d%s%s\n",
                    $baker['name'],
                    $baker['id'],
                    $baker['origin'],
                    $baker['cohort'],
                    $baker['locale'],
                    $baker['batch_id'],
                    $baker['topic_id'],
                    $baker['reused'] ? ' reused' : '',
                    !empty($baker['enriched']) ? ' enriched' : ''
                );
            }
            foreach ($seeded['errors'] as $err) {
                $lines .= '  ! ' . $err['name'] . ': ' . $err['error'] . "\n";
            }
            bakery_sfb_agent_cli_emit($opts, ['ok' => empty($seeded['errors'])] + $seeded, $lines);
            if (!empty($seeded['errors'])) {
                exit(1);
            }
            break;

        case 'verify-studio':
            require_once $root . '/includes/sfb_personas.php';
            $limit = (int)($opts['limit'] ?? 20);
            $verified = bakery_sfb_persona_verify($db, $limit > 0 ? $limit : 20, $allowProdStudio);
            $text = $verified['ok']
                ? "Studio OK bakers={$verified['bakers']} topics={$verified['topics']} standing_orders={$verified['standing_orders']}\n"
                : "Studio FAILED: " . implode('; ', $verified['errors']) . "\n";
            bakery_sfb_agent_cli_emit($opts, $verified, $text);
            if (!$verified['ok']) {
                exit(1);
            }
            break;

        case 'tick-studio':
            require_once $root . '/includes/sfb_studio_clock.php';
            bakery_sfb_agent_login($db);
            $tickOpts = [];
            if (!empty($opts['force'])) {
                $tickOpts['force'] = true;
            }
            if (!empty($opts['customer'])) {
                $who = bakery_sfb_agent_find_customer($db, (string)$opts['customer']);
                if (!$who) {
                    throw new InvalidArgumentException('Unknown baker for --customer');
                }
                $tickOpts['customer_id'] = (int)$who['id'];
                $tickOpts['force'] = true;
            }
            $ticked = bakery_sfb_studio_tick($db, $tickOpts);
            $text = sprintf(
                "Studio tick %s bakers=%d actions=%d skipped=%d errors=%d enrolled=%d clock=%s\n",
                $ticked['tick_id'],
                (int)$ticked['bakers'],
                (int)$ticked['actions'],
                (int)$ticked['skipped'],
                (int)$ticked['errors'],
                (int)$ticked['enrolled'],
                !empty($ticked['clock_enabled']) ? 'on' : 'off'
            );
            foreach ($ticked['results'] as $row) {
                if (!empty($row['name'])) {
                    $text .= '  - ' . $row['name'];
                    if (!empty($row['error'])) {
                        $text .= ' ERROR ' . $row['error'];
                    } elseif (!empty($row['actions'])) {
                        $acts = [];
                        foreach ($row['actions'] as $act) {
                            $acts[] = ($act['action'] ?? '') . ':' . ($act['status'] ?? '');
                        }
                        $text .= ' ' . implode(', ', $acts);
                    }
                    $text .= "\n";
                } elseif (!empty($row['summary'])) {
                    $text .= '  ' . $row['summary'] . "\n";
                }
            }
            bakery_sfb_agent_cli_emit($opts, $ticked, $text);
            if ((int)$ticked['errors'] > 0) {
                exit(1);
            }
            break;

        case 'status':
            bakery_sfb_agent_login($db);
            $status = bakery_sfb_agent_status($db, (string)($opts['origin'] ?? 'all'));
            $admin = $status['admin'];
            $acting = $status['acting_customer'];
            $text = $admin
                ? "SFAdmin id={$admin['id']} email={$admin['email']} code={$admin['login_code']}\n"
                : "SFAdmin is not in the database yet.\n";
            if ($acting) {
                $text .= 'Acting as ' . ($acting['name'] ?? $acting['customer_name'] ?? 'customer') . "\n";
            }
            $text .= 'SF Bakers (' . ($status['origin'] ?? 'all') . '): ' . count($status['bakers']) . "\n";
            foreach ($status['bakers'] as $baker) {
                $origin = $baker['sfb_origin'] ?? 'human';
                $text .= "  - {$baker['name']} (id={$baker['id']}, origin={$origin}, batches={$baker['batch_count']})\n";
            }
            bakery_sfb_agent_cli_emit($opts, ['ok' => true] + $status, $text);
            break;

        default:
            throw new InvalidArgumentException('Unknown command: ' . $command);
    }
} catch (Throwable $e) {
    if (!empty($opts['json'])) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, 'SFAdmin agent failed: ' . $e->getMessage() . "\n");
    }
    exit(1);
}

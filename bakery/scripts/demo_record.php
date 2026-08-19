<?php
/**
 * Record a Sour Flour OS usage walkthrough as an MP4 on bakerysf_local.
 *
 *   php scripts/demo_record.php list
 *   php scripts/demo_record.php login --locale=en --publish
 *   php scripts/demo_record.php all --publish
 *
 * Local production-data mirror only. Never live production. Does not print codes.
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
bakery_load_env_file($root . DIRECTORY_SEPARATOR . '.env');
require_once $root . '/includes/config.php';
require_once $root . '/includes/demo_recorder.php';

function bakery_demo_record_cli_help(): void
{
    echo <<<TXT
Demo recorder — walk bakery usage on bakerysf_local and write MP4s

Commands:
  list                      Named scenarios
  all                       Every scenario
  drivers                   Driver Spanish walkthroughs (guias.php catalog)
  login                     Staff 4-digit login
  daily-run                 Daily Run checklist
  admin-route-build         Build dated demand and the daily route
  admin-route-reorder       Reorder the dated route on Driver Assignment
  admin-route-verify        Verify the route in My Route
  driver-assignment         Driver Assignment
  adjust-route              Adjust remaining stop order
  <file.json>               Custom scenario

Options:
  --locale=en|es|both       UI + captions (default both)
  --publish                 Copy into assets/walkthroughs/ for the website gallery
  --headed                  Show the browser
  --dry-run                 Validate only
  --out=path.mp4            Output file (single locale only)
  --json                    Machine-readable result
  --port=8099               PHP server port

Uses bakerysf_local (production-data mirror). Live production is refused.
Route-reorder, adjust-route, and skip-stop recordings restore their route after each locale.
Admin route-build uses a clean future date and removes the generated dated rows afterward.

TXT;
}

function bakery_demo_record_cli_args(array $argv): array
{
    $command = '';
    $opts = [
        'headed' => false,
        'dry-run' => false,
        'json' => false,
        'publish' => false,
        'out' => '',
        'port' => '8099',
        'locale' => 'both',
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--headed', '--dry-run', '--json', '--publish', '--help', '-h'], true)) {
            if ($arg === '--help' || $arg === '-h') {
                $command = 'help';
            } else {
                $opts[ltrim($arg, '-')] = true;
            }
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

function bakery_demo_record_locale_list(string $raw): array
{
    $raw = strtolower(trim($raw));
    if ($raw === 'both' || $raw === 'all' || $raw === '') {
        return bakery_demo_recorder_locales();
    }
    return [bakery_demo_recorder_normalize_locale($raw)];
}

[$command, $opts] = bakery_demo_record_cli_args($argv);

try {
    bakery_demo_recorder_assert_local();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($command === '' || $command === 'help') {
    bakery_demo_record_cli_help();
    exit($command === 'help' ? 0 : 1);
}

if ($command === 'list') {
    $rows = bakery_demo_recorder_list_scenarios();
    if (!empty($opts['json'])) {
        echo json_encode(['scenarios' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    foreach ($rows as $row) {
        $title = bakery_demo_recorder_localized($row['title'], 'en');
        echo $row['id'] . "\t" . $title . "\n";
    }
    exit(0);
}

$targets = [];
if ($command === 'all' || $command === 'drivers') {
    $driverIds = bakery_demo_recorder_driver_scenario_ids();
    foreach (bakery_demo_recorder_list_scenarios() as $row) {
        if ($command === 'drivers' && !in_array((string)$row['id'], $driverIds, true)) {
            continue;
        }
        $targets[] = $row['id'];
    }
} else {
    $targets[] = $command;
}

$locales = bakery_demo_record_locale_list((string)$opts['locale']);
$python = null;
$results = [];
$failed = false;

foreach ($targets as $target) {
    try {
        $scenario = bakery_demo_recorder_load_scenario($target);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $codes = [
        'admin_code' => bakery_demo_recorder_admin_code(),
        'driver_code' => '',
        'driver_id' => 0,
        'date' => date('Y-m-d'),
        'route_snapshot' => [],
        'cleanup_generated_date' => false,
        'event_watermark' => 0,
    ];
    $db = null;
    try {
        $codes = bakery_demo_recorder_prepare($root, $scenario);
        $db = bakery_demo_recorder_connect($root);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Prepare failed (' . $scenario['id'] . '): ' . $e->getMessage() . "\n");
        $failed = true;
        if (in_array($command, ['all', 'drivers'], true)) {
            continue;
        }
        exit(1);
    }

    if (!empty($opts['dry-run'])) {
        $results[] = [
            'ok' => true,
            'dry_run' => true,
            'scenario' => $scenario['id'],
            'steps' => count($scenario['steps']),
            'date' => $codes['date'],
            'driver_id' => (int)$codes['driver_id'],
        ];
        continue;
    }

    if ($python === null) {
        try {
            $python = bakery_demo_recorder_bootstrap_python($root);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }

    $outDir = bakery_demo_recorder_output_dir();
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        fwrite(STDERR, "Could not create {$outDir}\n");
        exit(1);
    }

    foreach ($locales as $locale) {
        $out = trim((string)$opts['out']);
        if ($out === '' || count($locales) > 1 || count($targets) > 1) {
            $out = $outDir . DIRECTORY_SEPARATOR . $scenario['id'] . '-' . $locale . '-' . date('Ymd-His') . '.mp4';
        }
        $cmd = [
            $python,
            $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'demo-recorder' . DIRECTORY_SEPARATOR . 'record.py',
            '--scenario', $scenario['_path'],
            '--out', $out,
            '--php', PHP_BINARY,
            '--root', $root,
            '--port', (string)$opts['port'],
        ];
        if (!empty($opts['headed'])) {
            $cmd[] = '--headed';
        }
        $env = [
            'USE_PROD_DB' => 'false',
            'APP_ENV' => 'local',
            'DB_NAME' => defined('DB_NAME') ? (string)DB_NAME : 'bakerysf_local',
            'DEMO_ADMIN_CODE' => $codes['admin_code'],
            'DEMO_DRIVER_CODE' => (string)$codes['driver_code'],
            'DEMO_DRIVER_ID' => (string)(int)$codes['driver_id'],
            'DEMO_DATE' => (string)$codes['date'],
            'DEMO_TODAY' => (string)($codes['today'] ?? date('Y-m-d')),
            'DEMO_TOMORROW' => (string)($codes['tomorrow'] ?? date('Y-m-d', strtotime('+1 day'))),
            'DEMO_LOCALE' => $locale,
        ];
        try {
            $code = bakery_demo_recorder_run_command($cmd, $root, $env);
            if ($code !== 0) {
                fwrite(STDERR, "Recorder exited {$code} for {$scenario['id']} ({$locale})\n");
                $failed = true;
                if (!in_array($command, ['all', 'drivers'], true)) {
                    exit(1);
                }
                continue;
            }
            $published = '';
            if (!empty($opts['publish'])) {
                $published = bakery_demo_recorder_publish($out, $scenario['id'], $locale);
            }
            $results[] = [
                'ok' => true,
                'scenario' => $scenario['id'],
                'locale' => $locale,
                'mp4' => $out,
                'published' => $published,
            ];
            if (empty($opts['json'])) {
                echo "MP4 {$locale} {$out}\n";
                if ($published !== '') {
                    echo "PUBLISHED {$published}\n";
                }
            }
        } finally {
            if ($db instanceof PDO && !empty($codes['cleanup_generated_date'])) {
                try {
                    bakery_demo_recorder_cleanup_generated_date(
                        $db,
                        (string)$codes['date'],
                        (int)($codes['event_watermark'] ?? 0)
                    );
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Generated-date cleanup failed (' . $scenario['id'] . '): ' . $e->getMessage() . "\n");
                }
            } elseif ($db instanceof PDO && !empty($codes['route_snapshot'])) {
                try {
                    bakery_demo_recorder_restore_route(
                        $db,
                        (int)$codes['driver_id'],
                        (string)$codes['date'],
                        $codes['route_snapshot'] ?? []
                    );
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Restore failed (' . $scenario['id'] . '): ' . $e->getMessage() . "\n");
                }
            }
        }
    }
}

if (!empty($opts['json'])) {
    echo json_encode(['ok' => !$failed, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
exit($failed ? 1 : 0);

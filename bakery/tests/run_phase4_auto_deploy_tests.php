<?php
/**
 * Phase 4 contracts: staging-only auto-push, live /bake unreachable from hooks.
 * Usage: php tests/run_phase4_auto_deploy_tests.php
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__);
$fail = 0;
$assert = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$queue = file_get_contents($root . '/scripts/queue_sftp_push.ps1');
$worker = file_get_contents($root . '/scripts/sftp_push_worker.ps1');
$hook = file_get_contents($root . '/.cursor/hooks/auto-push.ps1');
$control = file_get_contents($root . '/includes/auto_push_control.php');
$controlJs = file_get_contents($root . '/includes/auto_push_control.js');
$header = file_get_contents($root . '/includes/header.php');
$api = file_get_contents($root . '/auto_push_api.php');
$pushLive = file_get_contents($root . '/scripts/push_sftp.ps1');
$pushStage = file_get_contents($root . '/scripts/push_sftp_stage.ps1');
$sftp = file_get_contents($root . '/scripts/sftp_upload.py');
$en = file_get_contents($root . '/lang/en.php');
$es = file_get_contents($root . '/lang/es.php');
$gitignore = file_get_contents($root . '/.gitignore');

$assert(strpos($queue, '.env.sftp.stage') !== false, 'queue requires .env.sftp.stage');
$assert(strpos($queue, 'SKIP missing .env.sftp (') === false, 'queue no longer requires live .env.sftp');
$assert(strpos($queue, 'refusing live /bake in .env.sftp.stage') !== false, 'queue refuses /bake inside stage env');
$assert(strpos($worker, 'push_sftp_stage.ps1') !== false, 'worker runs staging push script');
$assert(strpos($worker, 'scripts\\push_sftp.ps1') === false, 'worker does not assign live push_sftp.ps1');
$assert(strpos($hook, 'push_sftp_stage.ps1') !== false, 'hook documents staging push');
$assert(strpos($hook, 'Auto-push never calls scripts/push_sftp.ps1') !== false, 'hook refuses live push script');
$assert(strpos($control, 'push_sftp_stage.ps1') !== false, 'UI sync runs staging push');
$assert(strpos($control, "Missing scripts/push_sftp.ps1") === false, 'UI sync does not require live push script');
$assert(strpos($control, 'https://staging.sourflour.org/') !== false, 'status URL is staging');
$assert(strpos($control, 'https://bakery.sourflour.org/bake/') === false, 'status URL is not live /bake');
$assert(strpos($header, 'Promote approved to Live') === false, 'local sync banner does not expose a second Live promotion path');
$assert(strpos($header, 'Local directly to Live') === false, 'local sync banner does not expose direct-to-Live recovery tooling');
$assert(strpos($controlJs, "api(action, { confirm_phrase: phrase })") === false, 'local sync script does not invoke a Live promotion');
$assert(strpos($api, 'Sync to staging') !== false, 'API disable message is staging');
$assert(strpos($pushLive, 'dreamhost-live') !== false, 'live push requires dreamhost-live');
$assert(strpos($pushLive, 'live push cannot use bakeryOS') !== false, 'live push refuses bakeryOS');
$assert(strpos($pushLive, 'live push cannot use staging.sourflour.org') !== false, 'live push refuses staging root');
$assert(strpos($pushLive, 'Auto-push never calls this script') !== false, 'live push documents auto-push exclusion');
$assert(strpos($pushStage, 'Never loads .env.sftp') !== false, 'staging push documents live env exclusion');
$assert(strpos($pushStage, 'Do not re-upload remote .env on incremental auto-push') !== false, 'staging incremental skips remote .env');
$assert(strpos($pushStage, 'php -l') !== false || strpos($pushStage, 'Assert-BakeryPhpLint') !== false, 'staging push lints PHP');
$assert(strpos($pushStage, 'https://staging.sourflour.org/login.php') !== false, 'staging push smokes staging login');
$assert(strpos($pushStage, 'bakery.sourflour.org/bake was not targeted') !== false, 'staging push logs live exclusion');
$assert(strpos($sftp, 'remote root is staging.sourflour.org') !== false, 'live SFTP refuses staging root');
$assert(strpos($en, 'Staging auto-push') !== false, 'English staging auto-push label');
$assert(strpos($es, 'Auto-envío a staging') !== false, 'Spanish staging auto-push label');
$assert(strpos($en, 'Sync to staging') !== false, 'English sync to staging');
$assert(strpos($es, 'Sincronizar a staging') !== false, 'Spanish sync to staging');
$assert(is_file($root . '/.env.sftp.live.example'), '.env.sftp.live.example exists');
$assert(strpos($gitignore, '!.env.sftp.live.example') !== false, 'live example is not gitignored');

$pushBat = file_get_contents($root . '/push.bat');
$migrations = file_get_contents($root . '/scripts/run_migrations.php');
$snapshot = file_get_contents($root . '/scripts/snapshot_dreamhost_staging.php');
$guard = file_get_contents($root . '/includes/test_target_guard.php');
$assert(strpos($pushBat, 'push_sftp_stage.ps1') !== false, 'push.bat calls staging push');
$assert(strpos($pushBat, 'scripts\\push_sftp.ps1') === false && strpos($pushBat, 'scripts/push_sftp.ps1') === false, 'push.bat does not call live push_sftp.ps1');
$assert(strpos($migrations, '--mode=dreamhost-stage') !== false, 'migration runner has dreamhost-stage mode');
$assert(strpos($migrations, 'bakerysoftware') !== false, 'staging migrations name bakerysoftware');
$assert(strpos($guard, 'function bakery_assert_dreamhost_staging_target') !== false, 'staging migration target guard exists');
$assert(strpos($snapshot, 'bakerysoftware') !== false, 'staging snapshot names bakerysoftware');
$assert(strpos($snapshot, '--confirm-snapshot-staging') !== false, 'staging snapshot requires confirm');
$assert(strpos($snapshot, 'will not dump production bakerysf') !== false, 'staging snapshot refuses bakerysf');
$assert(strpos($migrations, "BAKERY_HOSTED_STAGE_ROOT") !== false && strpos($migrations, '/home/bakeryOS/staging.sourflour.org') !== false, 'hosted runner requires the exact Staging application root');
$assert(strpos($migrations, "/.sourflour-migration-source") !== false && strpos($migrations, 'glob($newMigrationsDir') !== false, 'hosted runner consumes the private Staging migration vault');
$assert(strpos($pushStage, '--run-hosted-stage-migrations') !== false, 'staging push delegates checkpoint and migrations to the hosted account');
$assert(strpos($pushStage, '$hostedOutput = @(Invoke-BakerySftpPython') !== false, 'hosted command diagnostics do not pollute migrations_applied');
$assert(strpos($sftp, 'snapshot_dreamhost_staging.php --confirm-snapshot-staging') !== false, 'hosted Staging command takes a bakerysoftware checkpoint');
$assert(strpos($sftp, 'run_migrations.php --mode=hosted-stage') !== false, 'hosted Staging command runs the canonical migration runner');
$hostedSnapshotAt = strpos($sftp, 'snapshot_dreamhost_staging.php --confirm-snapshot-staging');
$hostedMigrationAt = strpos($sftp, 'run_migrations.php --mode=hosted-stage');
$assert($hostedSnapshotAt !== false && $hostedMigrationAt !== false && $hostedSnapshotAt < $hostedMigrationAt, 'hosted checkpoint must succeed before Staging migrations');
$assert(strpos($sftp, 'production bakerysf was not targeted') !== false, 'hosted Staging failure names the protected production target');
$assert(strpos($manifest = file_get_contents($root . '/scripts/deploy_manifest.ps1'), '-ge 50') !== false, 'schema change detector is 050+ only');
$assert(strpos($manifest, 'pan_dulce_quantities.php') !== false, 'deploy root whitelist includes pan_dulce_quantities.php');
$assert(strpos($manifest, "ToUniversalTime().ToString('o')") !== false, 'deploy baseline normalizes JSON timestamps before incremental comparison');
$assert(strpos($pushStage, '([datetime]$baseline.recorded_at).ToUniversalTime()') !== false, 'staging push preserves the UTC baseline timestamp');
$assert(strpos($pushLive, '([datetime]$baseline.recorded_at).ToUniversalTime()') !== false, 'recovery-only Live push preserves the UTC baseline timestamp');
$assert(strpos($pushStage, 'AlsoInclude root pages must stick') !== false, 'staging baseline keeps AlsoInclude uploads');
$assert(strpos($snapshot, 'Cannot connect to bakerysoftware') !== false, 'staging snapshot reports bakerysoftware connect failure');
$assert(strpos($pushStage, '--run-hosted-stage-migrations') !== false, 'staging push runs migrations beside bakerysoftware');
$assert(strpos($pushStage, 'login page did not name bakerysoftware') !== false, 'staging smoke requires bakerysoftware');

$python = $root . '/scripts/sftp_upload.py';
$pythonBin = null;
foreach (['py -3', 'py', 'python'] as $candidate) {
    $probe = [];
    $probeCode = 1;
    exec($candidate . ' --version 2>&1', $probe, $probeCode);
    if ($probeCode === 0) {
        $pythonBin = $candidate;
        break;
    }
}
$assert($pythonBin !== null, 'python launcher is available for live SFTP target checks');

$cases = [
    [
        'label' => 'live dh_dp755h + /bake + dreamhost-live is allowed',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'dh_dp755h',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'bakery.sourflour.org/bake',
            'SFTP_TARGET' => 'dreamhost-live',
        ],
        'expectOk' => true,
    ],
    [
        'label' => 'live target refuses staging.sourflour.org',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'dh_dp755h',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'staging.sourflour.org',
            'SFTP_TARGET' => 'dreamhost-live',
        ],
        'expectOk' => false,
    ],
    [
        'label' => 'live target refuses bakeryOS',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'bakeryOS',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'bakery.sourflour.org/bake',
            'SFTP_TARGET' => 'dreamhost-live',
        ],
        'expectOk' => false,
    ],
];

if ($pythonBin !== null) {
    $keys = ['SFTP_HOST', 'SFTP_USER', 'SFTP_PASSWORD', 'SFTP_REMOTE_ROOT', 'SFTP_TARGET'];
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = getenv($key);
    }
    foreach ($cases as $case) {
        foreach ($keys as $key) {
            $value = $case['env'][$key] ?? '';
            if ($value === '') {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        $out = [];
        $code = 0;
        exec($pythonBin . ' ' . escapeshellarg($python) . ' --local-root ' . escapeshellarg($root) . ' --check-target 2>&1', $out, $code);
        $ok = $case['expectOk'] ? $code === 0 : $code !== 0;
        $assert($ok, $case['label']);
    }
    foreach ($keys as $key) {
        if ($saved[$key] === false || $saved[$key] === null || $saved[$key] === '') {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv($key . '=' . $saved[$key]);
            $_ENV[$key] = $saved[$key];
        }
    }
}

exit($fail === 0 ? 0 : 1);

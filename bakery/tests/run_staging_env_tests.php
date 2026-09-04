<?php
/**
 * Static contracts for DreamHost staging isolation (Gate 2).
 * Usage: php tests/run_staging_env_tests.php
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

$config = file_get_contents($root . '/includes/config.php');
$header = file_get_contents($root . '/includes/header.php');
$sftp = file_get_contents($root . '/scripts/sftp_upload.py');
$pushStage = file_get_contents($root . '/scripts/push_sftp_stage.ps1');
$en = file_get_contents($root . '/lang/en.php');
$es = file_get_contents($root . '/lang/es.php');
$square = file_get_contents($root . '/includes/square_config.php');

$assert(strpos($config, "define('IS_STAGING'") !== false, 'config defines IS_STAGING');
$assert(strpos($config, "staging.sourflour.org") !== false, 'config knows staging hostname');
$assert(strpos($config, "bakerysoftware") !== false, 'config names staging database');
$assert(strpos($config, 'staging cannot use production database bakerysf') !== false, 'staging refuses bakerysf');
$assert(strpos($config, 'live bakery host cannot use staging database bakerysoftware') !== false, 'live host refuses bakerysoftware');
$assert(strpos($config, 'Staging must use MAIL_DRIVER=log') !== false, 'staging forces mail log');
$assert(strpos(file_get_contents($root . '/login.php'), 'staging-env-banner') !== false, 'login page shows staging banner');
$assert(strpos($header, "bakery_t('env.staging'") !== false, 'header uses env.staging string');
$assert(strpos($en, "'env.staging'") !== false, 'English staging banner key');
$assert(strpos($es, "'env.staging'") !== false, 'Spanish staging banner key');
$assert(strpos($square, 'IS_STAGING') !== false, 'Square sandbox on staging');
$assert(strpos($sftp, 'dreamhost-stage') !== false, 'SFTP uploader knows staging target');
$assert(strpos($sftp, 'bakeryOS cannot target bakery.sourflour.org/bake') !== false, 'bakeryOS cannot write /bake');
$assert(strpos($sftp, 'remote root must be staging.sourflour.org') !== false, 'staging root is explicit');
$assert(strpos($sftp, 'Hosted Staging migrations require bakeryOS at exactly staging.sourflour.org') !== false, 'hosted migration transport has an exact account and root guard');
$assert(strpos($sftp, 'BAKERY_HOSTED_STAGE_ROOT=/home/bakeryOS/staging.sourflour.org') !== false, 'hosted migration command fixes the Staging application root');
$assert(strpos($pushStage, '.env.sftp.stage') !== false, 'staging push loads stage env only');
$assert(strpos($pushStage, '.env.sftp') !== false && strpos($pushStage, 'Never loads .env.sftp') !== false, 'staging push documents live env exclusion');
$assert(strpos($pushStage, 'bakery.sourflour.org/bake') !== false, 'staging push refuses live root');
$assert(strpos($pushStage, 'https://staging.sourflour.org/login.php') !== false, 'staging push prints staging URL');

$python = $root . '/scripts/sftp_upload.py';
$pythonBin = null;
foreach (['py -3', 'py', 'python', 'python3'] as $candidate) {
    $probe = [];
    $probeCode = 1;
    exec($candidate . ' --version 2>&1', $probe, $probeCode);
    if ($probeCode === 0) {
        $pythonBin = $candidate;
        break;
    }
}
$assert($pythonBin !== null, 'python launcher is available for SFTP target checks');

$cases = [
    [
        'label' => 'staging bakeryOS + staging root is allowed',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'bakeryOS',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'staging.sourflour.org',
            'SFTP_TARGET' => 'dreamhost-stage',
        ],
        'expectOk' => true,
    ],
    [
        'label' => 'staging target refuses live /bake',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'bakeryOS',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'bakery.sourflour.org/bake',
            'SFTP_TARGET' => 'dreamhost-stage',
        ],
        'expectOk' => false,
    ],
    [
        'label' => 'bakeryOS refuses live /bake even without target',
        'env' => [
            'SFTP_HOST' => 'iad1-shared-b7-08.dreamhost.com',
            'SFTP_USER' => 'bakeryOS',
            'SFTP_PASSWORD' => 'dummy',
            'SFTP_REMOTE_ROOT' => 'bakery.sourflour.org/bake',
            'SFTP_TARGET' => '',
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

$refresh = file_get_contents($root . '/scripts/refresh_dreamhost_staging_from_snapshot.php');
$assert(strpos($refresh, 'bakerysoftware') !== false, 'staging DB refresh names bakerysoftware');
$assert(strpos($refresh, '--confirm-refresh-staging') !== false, 'staging DB refresh requires explicit confirm');
$assert(strpos($refresh, 'must be bakerysoftware') !== false, 'staging refresh refuses other DB names');
exit($fail === 0 ? 0 : 1);

<?php
/**
 * SFTP login preflight. Mocked paramiko only.
 * Does not open a database and does not send network traffic.
 * DB_NAME, when set, must be bakerysf_test.
 * Usage: php tests/run_sftp_preflight_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$dbName = getenv('DB_NAME');
if ($dbName !== false && $dbName !== '' && $dbName !== 'bakerysf_test') {
    fwrite(STDERR, "Refusing: SFTP preflight tests require bakerysf_test when DB_NAME is set.\n");
    exit(1);
}
putenv('DB_NAME=bakerysf_test');

$root = dirname(__DIR__);
$script = $root . '/tests/sftp_preflight_cases.py';
$out = [];
$code = 0;
exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
echo implode(PHP_EOL, $out) . PHP_EOL;

$checked = [
    $root . '/scripts/sftp_upload.py',
    $root . '/scripts/cloud_agent_stage.py',
    $root . '/tests/sftp_preflight_cases.py',
    $root . '/tests/run_sftp_preflight_tests.php',
];
$dashFail = 0;
foreach ($checked as $path) {
    $text = (string)file_get_contents($path);
    if (strpos($text, "\u{2013}") !== false || strpos($text, "\u{2014}") !== false) {
        fwrite(STDERR, 'FAIL  en or em dash in ' . basename($path) . PHP_EOL);
        $dashFail++;
    }
}
if ($dashFail > 0) {
    exit(1);
}
exit($code === 0 ? 0 : 1);

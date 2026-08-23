<?php
/**
 * Stable Live cron entrypoint for one approved additive migration.
 *
 * Install this wrapper at /home/dh_dp755h/bin/hosted_migration_worker.php.
 * Worker behavior then ships with tested application files through
 * includes/hosted_migration_runtime.php.
 */
if (PHP_SAPI !== 'cli') exit(1);

$arguments = array_slice($argv, 1);
$preflight = in_array('--preflight', $arguments, true);
$configArguments = array_values(array_filter(
    $arguments,
    static fn(string $argument): bool => $argument !== '--preflight'
));
if (count($configArguments) > 1 || (isset($configArguments[0]) && str_starts_with($configArguments[0], '--'))) {
    fwrite(STDERR, "Usage: hosted_migration_worker.php [config-path] [--preflight]\n");
    exit(64);
}
$configPath = $configArguments[0] ?? '/home/dh_dp755h/.bakery-hosted-migration.env';
$config = [];
foreach ((array)@file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $config[trim($key)] = trim(trim($value), "\"'");
}
$liveRoot = rtrim((string)($config['LIVE_ROOT'] ?? ''), '/');
if ($liveRoot !== '/home/dh_dp755h/bakery.sourflour.org/bake') {
    fwrite(STDERR, "MIGRATION CONFIG ERROR: refusing unexpected Live root.\n");
    exit(3);
}
$runtime = $liveRoot . '/includes/hosted_migration_runtime.php';
if (!is_file($runtime)) {
    fwrite(STDERR, "MIGRATION CONFIG ERROR: hosted migration runtime is missing.\n");
    exit(2);
}
require_once $runtime;
if ($preflight) {
    try {
        echo json_encode(bakery_hosted_migration_preflight($configPath), JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'MIGRATION PREFLIGHT FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(4);
    }
}
exit(bakery_hosted_migration_worker_main($configPath));

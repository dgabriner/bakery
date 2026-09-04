<?php
/**
 * Parse a kitchen note and save Production Center Planned for a delivery date.
 *
 * Default: local bakerysf_stage_local.
 * Live bakerysf: --allow-production (owner-authorized; uses .env.production.pull).
 *
 *   php scripts/save_kitchen_note_plan.php --date=2026-08-25
 *   php scripts/save_kitchen_note_plan.php --date=2026-08-25 --allow-production
 *   php scripts/save_kitchen_note_plan.php --date=2026-08-25 --allow-production --commit
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
$allowProduction = in_array('--allow-production', array_slice($argv, 1), true);
$commitPlan = in_array('--commit', array_slice($argv, 1), true);

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/product_pack_yields.php';
require_once $root . '/includes/production_plan.php';
require_once $root . '/includes/operational_timeline.php';

$date = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Usage: php scripts/save_kitchen_note_plan.php --date=YYYY-MM-DD [--allow-production] [--commit]\n");
    exit(1);
}

if ($allowProduction) {
    require_once $root . '/scripts/prod_db_cli.php';
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    if (strtolower((string)$prod['name']) !== 'bakerysf') {
        fwrite(STDERR, "Refusing: PROD_DB_NAME must be bakerysf\n");
        exit(1);
    }
    $stage = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);
    $actual = strtolower((string)$stage->query('SELECT DATABASE()')->fetchColumn());
    if ($actual !== 'bakerysf') {
        fwrite(STDERR, "Refusing: expected bakerysf, got {$actual}\n");
        exit(1);
    }
    $targetLabel = 'bakerysf (live)';
} else {
    if (!IS_LOCAL || USE_PROD_DB) {
        fwrite(STDERR, "Refusing: local USE_PROD_DB=false only (or pass --allow-production)\n");
        exit(1);
    }
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $stage = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=bakerysf_stage_local;charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]
    );
    $actual = strtolower((string)$stage->query('SELECT DATABASE()')->fetchColumn());
    if ($actual !== 'bakerysf_stage_local') {
        fwrite(STDERR, "Refusing: expected bakerysf_stage_local, got {$actual}\n");
        exit(1);
    }
    $targetLabel = 'bakerysf_stage_local';
}

$kitchen = <<<'TXT'
Buenos días ☀️

3.0 de concha
3.0 de fino

120 barras
25 cortadillos
15 colchones
25 queiquitos
10 pudin
1.5 de nuez
2 taco / gragea
1. 5 puerco
1 de amarilla
1 de rosada
1 de chocolate

1 picón
TXT;

bakery_pack_ensure_defaults($stage);
$parsed = bakery_pack_parse_kitchen_note($stage, $kitchen);
if ($parsed['unknown'] !== []) {
    fwrite(STDERR, "Unknown kitchen lines:\n" . implode("\n", $parsed['unknown']) . "\n");
}
if ($parsed['by_product'] === []) {
    fwrite(STDERR, "Parse produced no products\n");
    exit(1);
}

$names = $stage->query('SELECT id, name FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
$planQtys = bakery_pack_kitchen_plan_with_zeros($stage, $parsed['by_product']);
$allowed = array_fill_keys(array_map('intval', array_keys($planQtys)), true);
$saved = bakery_production_plan_save_targets($stage, [$date => $planQtys], $allowed, null);
if (function_exists('bakery_record_operational_event')) {
    bakery_record_operational_event($stage, BAKERY_OP_PRODUCTION_PLAN_SAVED,
        'Saved kitchen-note production targets for ' . $date, [
            'operational_date' => $date,
            'metadata' => [
                'targets_saved' => $saved,
                'delivery_date' => $date,
                'source' => 'kitchen_note_cli',
                'unknown' => $parsed['unknown'],
            ],
        ]);
}

echo "Saved {$saved} planned SKUs for delivery {$date} on {$targetLabel}\n";
foreach ($parsed['by_product'] as $id => $qty) {
    $label = $names[$id] ?? ('#' . $id);
    echo sprintf("  %4d  %s\n", $qty, $label);
}
$zeroed = [];
foreach ($planQtys as $id => $qty) {
    if ((int)$qty === 0 && !isset($parsed['by_product'][$id])) {
        $zeroed[] = $names[$id] ?? ('#' . $id);
    }
}
if ($zeroed !== []) {
    echo "Zeroed omitted kitchen SKUs: " . implode(', ', $zeroed) . "\n";
}
if ($commitPlan) {
    $commit = bakery_production_plan_commit($stage, $date, null);
    echo "Committed {$commit['products_count']} SKUs / {$commit['units_count']} pieces for {$date}\n";
}
exit(0);

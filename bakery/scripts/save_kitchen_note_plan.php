<?php
/**
 * Parse a kitchen note and save Production Center Planned for a delivery date.
 *
 * Default: local bakerysf_stage_local.
 * Live bakerysf: --allow-production (owner-authorized; uses .env.production.pull).
 *
 *   php scripts/save_kitchen_note_plan.php --date=2026-08-25
 *   php scripts/save_kitchen_note_plan.php --date=2026-08-25 --allow-production
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
$allowProduction = in_array('--allow-production', array_slice($argv, 1), true);

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
    fwrite(STDERR, "Usage: php scripts/save_kitchen_note_plan.php --date=YYYY-MM-DD [--allow-production]\n");
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
3.0 de concha
3.0 de fino
1 de picón
120 barras
25 cortadillos
15 colchones
25 queiquitos
10 pudin
1.nuez
1 de guayaba
2 taco / gragea 1 y 1
1.0 puerco
2 de amarilla
2 de rosada
2 de chocolate
1 bolillo
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
$allowed = array_fill_keys(array_map('intval', array_keys($parsed['by_product'])), true);
$saved = bakery_production_plan_save_targets($stage, [$date => $parsed['by_product']], $allowed, null);
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
exit(0);

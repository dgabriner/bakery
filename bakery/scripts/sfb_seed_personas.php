<?php
/**
 * Seed the first 20 Synthetic Studio personas on bakerysf_test.
 *
 * Never targets bakerysf_local or production. Customer1/Customer2 are reused.
 *
 *   php scripts/sfb_seed_personas.php
 *   php scripts/sfb_seed_personas.php --limit=20 --json
 *   php scripts/sfb_seed_personas.php --refresh
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/sfb_personas.php';

$json = in_array('--json', $argv, true);
$refresh = in_array('--refresh', $argv, true);
$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    }
}

try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    $results = bakery_sfb_persona_seed($db, $limit, $refresh);
    if ($json) {
        echo json_encode(['ok' => true] + $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    echo "Seeded {$results['seeded']} personas on bakerysf_test (reused {$results['reused']}, skipped {$results['skipped']}, enriched {$results['enriched']})\n";
    foreach ($results['bakers'] as $baker) {
        echo sprintf(
            "  - %s id=%d origin=%s cohort=%s locale=%s%s\n",
            $baker['name'],
            $baker['id'],
            $baker['origin'],
            $baker['cohort'],
            $baker['locale'],
            $baker['reused'] ? ' reused' : ''
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Persona seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

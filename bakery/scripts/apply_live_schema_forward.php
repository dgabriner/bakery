<?php
/**
 * Apply the hosted-safe Live schema files Staging already has (054, 056–060)
 * onto bakerysf. Records 052/053 as applied after 054 (portable pack yields).
 *
 *   php scripts/apply_live_schema_forward.php
 *   php scripts/apply_live_schema_forward.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/hosted_migration_runtime.php';

$confirm = in_array('--confirm', $argv, true);

$files = [
    '054_live_product_pack_yields_mysql_compat',
    '056_square_webhook_invoice_index',
    '057_text_messages',
    '058_text_media',
    '059_bolillo_and_gallon_estimates',
    '060_mantecada_batch_and_piece_weights',
];
$recordAlso = [
    '052_product_pack_yields',
    '053_live_product_pack_yields',
];

try {
    $config = prod_db_load_envs($root);
    prod_db_validate_targets($config['prod'], $config['local']);
    $prod = $config['prod'];
    if (strtolower((string)$prod['name']) !== 'bakerysf') {
        throw new RuntimeException('Refusing: PROD_DB_NAME must be bakerysf.');
    }
    $db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);
    $selected = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if ($selected !== 'bakerysf') {
        throw new RuntimeException('Refusing: connected database is not bakerysf.');
    }

    $hasMigrations = (bool)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    )->fetchColumn();
    $applied = [];
    if ($hasMigrations) {
        foreach ($db->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $applied[(string)$id] = true;
        }
    }

    echo "Production: {$prod['name']}@{$prod['host']}\n\n";
    foreach ($files as $id) {
        $path = $root . '/database/schema/' . $id . '.sql';
        if (!is_readable($path)) {
            throw new RuntimeException('Missing ' . $path);
        }
        [$safe, $message] = bakery_hosted_migration_sql_safe((string)file_get_contents($path));
        $already = isset($applied[$id]);
        echo ($already ? 'HAVE ' : 'NEED ') . $id . ($safe ? '' : (' REFUSED: ' . $message)) . "\n";
        if (!$safe) {
            throw new RuntimeException($id . ' is not hosted-safe: ' . $message);
        }
    }
    foreach ($recordAlso as $id) {
        echo (isset($applied[$id]) ? 'HAVE ' : 'MARK ') . $id . " (ledger only after 054)\n";
    }

    if (!$confirm) {
        echo "\nRe-run with --confirm to apply NEED files to bakerysf.\n";
        exit(0);
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $mark = $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)');

    foreach ($files as $id) {
        if (isset($applied[$id])) {
            echo "Skip already recorded {$id}\n";
            continue;
        }
        $path = $root . '/database/schema/' . $id . '.sql';
        echo "Applying {$id}...\n";
        foreach (bakery_parse_sql_file($path) as $statement) {
            bakery_hosted_migration_exec_statement($db, $statement);
        }
        $mark->execute([$id]);
        $applied[$id] = true;
    }
    foreach ($recordAlso as $id) {
        $mark->execute([$id]);
    }

    echo "Done. Refresh Staging Manager → Staging → Live database card.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

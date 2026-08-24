<?php
/** Pure/runtime contracts for the hosted Live additive-migration worker. */
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
require_once $root . '/includes/hosted_migration_runtime.php';
$failed = 0;
$assert = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) $failed++;
};

[$safe053, $message053] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/053_live_product_pack_yields.sql'));
$assert(!$safe053 && stripos($message053, 'TEXT/BLOB') !== false, 'the original 053 Live compatibility failure is rejected before approval');
$assert(bakery_hosted_migration_superseded_by((string)file_get_contents($root . '/database/schema/053_live_product_pack_yields.sql')) === '054_live_product_pack_yields_mysql_compat', '053 explicitly names its portable successor');
[$safe059] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/059_bolillo_and_gallon_estimates.sql'));
$assert($safe059, '059 bolillo catalog seed is hosted-safe');
[$safe060] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/060_mantecada_batch_and_piece_weights.sql'));
$assert($safe060, '060 mantecada formula and piece weights is hosted-safe');
[$safe065] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/065_product_pack_boxes.sql'));
$assert($safe065, '065 pack box conversion column is hosted-safe');
[$dropSafe] = bakery_hosted_migration_sql_safe('DROP TABLE customers;');
$assert(!$dropSafe, 'destructive SQL remains refused');

$direct = bakery_hosted_migration_database_config([
    'DB_HOST' => 'mysql.example', 'DB_NAME' => 'bakerysf', 'DB_USER' => 'live', 'DB_PASS' => 'secret',
]);
$assert($direct['host'] === 'mysql.example' && $direct['name'] === 'bakerysf', 'worker accepts the Live app DB_* environment shape');
$pull = bakery_hosted_migration_database_config([
    'PROD_DB_HOST' => 'mysql.example', 'PROD_DB_NAME' => 'bakerysf', 'PROD_DB_USER' => 'pull', 'PROD_DB_PASS' => 'secret',
]);
$assert($pull['user'] === 'pull', 'worker accepts the guarded PROD_DB_* environment shape');
$wrongTargetRejected = false;
try {
    bakery_hosted_migration_database_config(['DB_HOST' => 'localhost', 'DB_NAME' => 'bakerysf_test', 'DB_USER' => 'x', 'DB_PASS' => 'x']);
} catch (RuntimeException $e) {
    $wrongTargetRejected = true;
}
$assert($wrongTargetRejected, 'worker refuses every database name except bakerysf');

$assert(bakery_hosted_migration_backup_succeeded('{"status":"success"}'), 'compact backup receipt is accepted');
$assert(bakery_hosted_migration_backup_succeeded("{\n  \"status\": \"success\"\n}"), 'pretty backup receipt is accepted');
$assert(!bakery_hosted_migration_backup_succeeded('{"status":"failed"}'), 'failed backup receipt is rejected');
$command = bakery_hosted_migration_run([PHP_BINARY, '-r', 'fwrite(STDOUT, "worker-ok");'], 10);
$assert($command['exit'] === 0 && $command['stdout'] === 'worker-ok', 'worker command runner captures output and exit status');

$worker = (string)file_get_contents($root . '/scripts/hosted_migration_worker.php');
$runtime = (string)file_get_contents($root . '/includes/hosted_migration_runtime.php');
$manager = (string)file_get_contents($root . '/manager.php');
$status = (string)file_get_contents($root . '/migration_status.php');
$runner = (string)file_get_contents($root . '/scripts/run_migrations.php');
$transport = (string)file_get_contents($root . '/scripts/sftp_upload.py');
$assert(strpos($runtime, 'proc_terminate($process, 9)') !== false
    && strpos($runtime, 'Migration command timed out.') !== false,
    'Live Linux worker has graceful and forced timeout cleanup');
$assert(strpos($worker, '/includes/hosted_migration_runtime.php') !== false, 'stable cron wrapper loads deployable worker behavior');
$assert(strpos($worker, '--preflight') !== false && strpos($worker, 'bakery_hosted_migration_preflight') !== false, 'stable wrapper has a non-mutating installation preflight');
$assert(strpos($worker, "static fn(string \$argument): bool => \$argument !== '--preflight'") !== false
    && strpos($worker, "\$configPath = \$configArguments[0] ??") !== false,
    'preflight flag is never mistaken for the environment config path');
$assert(strpos($transport, '--bootstrap-live-migration-worker') !== false
    && strpos($transport, 'bootstrap-backup-') !== false
    && strpos($transport, 'bakery.sourflour.org/bake') !== false,
    'controlled Live bootstrap is exact-targeted, atomic, and recoverable');
$preflightStart = strpos($runtime, 'function bakery_hosted_migration_preflight');
$execStart = strpos($runtime, 'function bakery_hosted_migration_exec_statement');
$preflightSource = substr($runtime, $preflightStart, $execStart - $preflightStart);
$assert(strpos($preflightSource, "SELECT DATABASE()") !== false
    && strpos($preflightSource, 'bakery_hosted_migration_run') === false
    && strpos($preflightSource, '->exec(') === false
    && strpos($preflightSource, 'schema_changed') !== false,
    'installation preflight checks identity without running backup or DDL');
$backupAt = strpos($runtime, "bakery_hosted_migration_run(['/bin/bash', '-lc'");
$ledgerCreateAt = strpos($runtime, "pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations");
$assert($backupAt !== false && $ledgerCreateAt !== false && $backupAt < $ledgerCreateAt, 'verified backup occurs before worker-owned schema DDL');
$assert(strpos($runtime, 'completed_statements') !== false, 'worker records statement-level progress for forward repair');
$assert(strpos($runtime, 'bakery_hosted_migration_exec_statement') !== false
    && strpos($runtime, 'information_schema.COLUMNS') !== false,
    'worker resumes only verified already-present additive objects');
$squareMigration = (string)file_get_contents($root . '/database/schema/055_square_invoices.sql');
$assert(substr_count($squareMigration, 'ALTER TABLE daily_orders') === 9, '055 isolates each resumable column addition');
$squareIndexRepair = (string)file_get_contents($root . '/database/schema/056_square_webhook_invoice_index.sql');
$assert(strpos($squareIndexRepair, 'ALTER TABLE square_webhook_events') !== false
    && strpos($squareIndexRepair, 'ADD INDEX idx_square_webhook_invoice') !== false,
    '056 additively reconciles the webhook invoice lookup index');
$squareRuntime = (string)file_get_contents($root . '/includes/square_invoices.php');
$assert(strpos($squareRuntime, 'KEY idx_square_webhook_invoice (square_invoice_id)') !== false,
    'runtime-created webhook tables include the canonical invoice index');
$assert(strpos($runtime, 'Migration stopped while applying schema') !== false, 'partial DDL failure is reported honestly');
$assert(strpos($manager, 'bakery_hosted_migration_approve_recommended') !== false, 'Manager can approve only the remaining recommended migrations');
$assert(strpos($manager, 'manager.live_send_db_all') !== false, 'Manager offers a full remaining Live database update');
$catchupSql = bakery_hosted_migration_catchup_sql([
    ['id' => '056_square', 'sql' => 'CREATE TABLE IF NOT EXISTS square_invoices (id INT NOT NULL PRIMARY KEY);'],
    ['id' => '057_text', 'sql' => 'ALTER TABLE customers ADD COLUMN notes VARCHAR(255) NULL;'],
]);
[$catchupSafe] = bakery_hosted_migration_sql_safe($catchupSql);
$assert($catchupSafe && strpos($catchupSql, "INSERT IGNORE INTO schema_migrations (id) VALUES ('057_text')") !== false, 'catch-up SQL records remaining ids after the first applied file');
$assert(bakery_hosted_migration_ids_from_approval([
    'migration_id' => '056_square',
    'migration_ids' => ['056_square', '057_text'],
]) === ['056_square', '057_text'], 'approval lists every remaining Live migration id');
$assert(strpos($manager, '<select name="migration_file"') === false, 'Manager no longer asks the operator to guess among migrations');
$assert(strpos($manager, '[data-live-promotion-status], [data-live-migration-status]') !== false, 'Manager polls both file and database workers');
$assert(strpos($manager, 'manager-live-history') !== false, 'Manager history is collapsed under the live board');
$assert(strpos($status, "'release_id'") !== false && strpos($status, "'completed_statements'") !== false, 'public status identifies the queued release and progress');
$assert(strpos($status, "'history'") !== false, 'public migration status includes compact history');
$assert(strpos($runtime, 'bakery_hosted_status_history_append') !== false, 'migration worker appends terminal outcomes to history');
[$safe061] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/061_surveys.sql'));
$assert($safe061, '061 surveys CREATE TABLE is hosted-safe');
[$safe067] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/067_bread_education_offerings_v2.sql'));
$assert($safe067, '067 offerings v2 enum widen and credit ledger is hosted-safe');
$assert(strpos($runtime, 'bakery_schema_inventory_cache_path') !== false, 'worker busts the Live schema report cache after apply');
$assert(strpos($runtime, 'contained no executable statements') !== false, 'worker refuses to record an empty SQL file');
$assert(strpos($manager, 'schema_compare=1') !== false && strpos($manager, 'stale_after_apply') !== false, 'Manager refreshes Live schema after a succeeded update');
$assert(strpos($manager, "if (\$schemaState === 'unknown'):") === false, 'Refresh and compare again stays visible on Stop');
$assert(strpos($manager, 'confirm_phrase') === false, 'Manager no longer asks for a typed confirm phrase');
$assert(strpos($manager, "separator + 'schema_compare=1'") === false, 'Manager does not auto-navigate after worker success');
$assert(strpos($manager, 'data-poll-workers') !== false && strpos($manager, 'IN_FLIGHT') !== false, 'Manager polls Live workers only while a job is running');
$assert(strpos($manager, 'live_queued') !== false, 'successful Live actions mark the board to follow that job');
$approvalSource = (string)file_get_contents($root . '/includes/hosted_migration_approval.php');
$assert(strpos($approvalSource, '?refresh=1') !== false, 'Staging asks Live to rebuild the schema report after an update');
$assert(strpos($approvalSource, 'bakery_staging_live_relax_067_kind_stop') !== false, 'board relaxes 067 kind-only Stop');
$assert(strpos($approvalSource, 'bakery_hosted_migration_queue_from_board') !== false, 'approve uses the board queue helper');
$assert(strpos($approvalSource, 'bakery_staging_live_board($db, false, true)') !== false, 'approve does not re-fetch Live schema');
$assert(strpos($approvalSource, 'The selected migration is not the first remaining update') === false, 'approve no longer rejects a drifted first-file name');
$assert(strpos($manager, 'bakery_staging_live_relax_067_kind_stop') !== false, 'Manager reopens 067 when kind is the only type clash');
$assert(strpos($manager, 'bakery_hosted_migration_approve($fallbackFile)') !== false, 'Manager can queue the shown safe file if older approve still throws');

echo "{$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

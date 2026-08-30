<?php
/** Fail-closed guard for local test/reset commands. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_assert_local_connection(PDO $db, array $allowedNames, bool $allowNoSelectedDatabase = false): void
{
    $host = strtolower(trim((string)(defined('DB_HOST') ? DB_HOST : '')));
    $configuredName = strtolower(trim((string)(defined('DB_NAME') ? DB_NAME : '')));
    if (!defined('IS_LOCAL') || !IS_LOCAL || (defined('USE_PROD_DB') && USE_PROD_DB)) {
        throw new RuntimeException('Refusing test target: local mode with USE_PROD_DB=false is required');
    }
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing test target: configured DB_HOST is not loopback');
    }
    if (!in_array($configuredName, $allowedNames, true)) {
        throw new RuntimeException('Refusing test target: configured DB_NAME is not an allowed target (' . implode(', ', $allowedNames) . ')');
    }
    $actualName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!$allowNoSelectedDatabase && $actualName === '') {
        throw new RuntimeException('Refusing test target: PDO connection has no selected database');
    }
    $actualNameLower = strtolower($actualName);
    // Empty DATABASE() is already handled above when a selected DB is required.
    if ($actualName !== '' && !in_array($actualNameLower, $allowedNames, true)) {
        throw new RuntimeException('Refusing test target: PDO is not connected to an allowed target (' . implode(', ', $allowedNames) . ')');
    }
    $connection = strtolower((string)$db->getAttribute(PDO::ATTR_CONNECTION_STATUS));
    if ($connection !== '' && !str_contains($connection, '127.0.0.1') && !str_contains($connection, 'localhost') && !str_contains($connection, '::1')) {
        throw new RuntimeException('Refusing test target: PDO connection is not loopback');
    }
}

/** Database-backed tests and resets must use the disposable production clone. */
function bakery_assert_local_test_target(PDO $db, bool $allowNoSelectedDatabase = false): void
{
    bakery_assert_local_connection($db, ['bakerysf_test'], $allowNoSelectedDatabase);
}

/** Read-only local tools such as walkthrough recording may use the mirror/stage. */
function bakery_assert_local_mirror_target(PDO $db, bool $allowNoSelectedDatabase = false): void
{
    bakery_assert_local_connection($db, ['bakerysf_local', 'bakerysf_stage_local'], $allowNoSelectedDatabase);
}

/**
 * Agent Homebase writes operational notes.
 * Actual PDO database must be everyday local staging or the disposable test clone.
 * Configured DB_NAME may still be the nightly mirror after a CLI hop.
 * Never write Homebase into bakerysf_local.
 */
function bakery_assert_homebase_target(PDO $db, bool $allowNoSelectedDatabase = false): void
{
    if (!defined('IS_LOCAL') || !IS_LOCAL || (defined('USE_PROD_DB') && USE_PROD_DB)) {
        throw new RuntimeException('Refusing Homebase: local mode with USE_PROD_DB=false is required');
    }
    $host = strtolower(trim((string)(defined('DB_HOST') ? DB_HOST : '')));
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing Homebase: configured DB_HOST is not loopback');
    }
    $allowed = ['bakerysf_stage_local', 'bakerysf_test'];
    $actualName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!$allowNoSelectedDatabase && $actualName === '') {
        throw new RuntimeException('Refusing Homebase: PDO connection has no selected database');
    }
    $actualNameLower = strtolower($actualName);
    if (!in_array($actualNameLower, $allowed, true)) {
        throw new RuntimeException(
            'Refusing Homebase: PDO is ' . $actualName . '. Durable craft lives on bakerysf_stage_local. '
            . 'bakerysf_test is for isolated tests only (the test gate wipes it). Never the nightly mirror bakerysf_local.'
        );
    }
    $connection = strtolower((string)$db->getAttribute(PDO::ATTR_CONNECTION_STATUS));
    if ($connection !== '' && !str_contains($connection, '127.0.0.1') && !str_contains($connection, 'localhost') && !str_contains($connection, '::1')) {
        throw new RuntimeException('Refusing Homebase: PDO connection is not loopback');
    }
}

/**
 * Open the durable Homebase database. If this process is on the nightly mirror,
 * hop to bakerysf_stage_local with the same local credentials. Isolated tests
 * already on bakerysf_test stay there.
 */
function bakery_homebase_durable_connection(PDO $db): PDO
{
    $actual = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if (in_array($actual, ['bakerysf_stage_local', 'bakerysf_test'], true)) {
        return $db;
    }
    if ($actual !== 'bakerysf_local') {
        return $db;
    }
    if (!defined('IS_LOCAL') || !IS_LOCAL || (defined('USE_PROD_DB') && USE_PROD_DB)) {
        throw new RuntimeException('Homebase hop requires local USE_PROD_DB=false');
    }
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    try {
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
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Homebase needs bakerysf_stage_local (durable). The nightly mirror bakerysf_local is read-only. Create/refresh staging: '
            . 'php scripts/refresh_local_from_snapshot.php --target=bakerysf_stage_local. '
            . 'Do not store craft on bakerysf_test — the local test gate wipes it. '
            . '(' . $e->getMessage() . ')'
        );
    }
    return $stage;
}

/**
 * CLI migrations against DreamHost staging. Never bakerysf. Never local DBs.
 */
function bakery_assert_dreamhost_staging_target(PDO $db): void
{
    if (!defined('IS_STAGING') || !IS_STAGING) {
        throw new RuntimeException('Refusing: dreamhost-stage requires APP_ENV=staging');
    }
    if (defined('USE_PROD_DB') && USE_PROD_DB) {
        throw new RuntimeException('Refusing: USE_PROD_DB is not allowed for staging migrations');
    }
    if (defined('IS_LOCAL') && IS_LOCAL) {
        throw new RuntimeException('Refusing: staging migrations cannot run with local APP_ENV');
    }
    $host = strtolower(trim((string)(defined('DB_HOST') ? DB_HOST : '')));
    $name = strtolower(trim((string)(defined('DB_NAME') ? DB_NAME : '')));
    if ($name === 'bakerysf') {
        throw new RuntimeException('Refusing: will not migrate production bakerysf');
    }
    if ($name !== 'bakerysoftware') {
        throw new RuntimeException('Refusing: staging migrations require bakerysoftware, got ' . (defined('DB_NAME') ? DB_NAME : ''));
    }
    if (strpos($host, 'sourflour') === false && strpos($host, 'dreamhost') === false) {
        throw new RuntimeException('Refusing: staging DB host must be DreamHost MySQL');
    }
    $actual = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if ($actual !== 'bakerysoftware') {
        throw new RuntimeException('Refusing: PDO is not connected to bakerysoftware');
    }
}

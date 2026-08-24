<?php
/** Read-only Prompt 26 readiness probe for bakerysoftware: SQUARE_* presence,
 *  offerings/courses inventory, webhook endpoint liveness. Prints no secrets. */
define('ACCESS_ALLOWED', true);
$root = '/home/bakeryOS/staging.sourflour.org';
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
bakery_load_env_file($root . '/.env', true);
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
$db = check_mysql_connection();
bakery_assert_dreamhost_staging_target($db);

echo "connected to database: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "APP_ENV=" . APP_ENV . "  IS_STAGING=" . (IS_STAGING ? 'true' : 'false') . "  BASE_URL=" . BASE_URL . "\n";

require_once $root . '/includes/square_config.php';

function probe_report(string $label, bool $ok): void
{
    echo ($ok ? 'PRESENT' : 'MISSING') . "  {$label}\n";
}

echo "\n-- Square credential presence (values never printed) --\n";
echo 'SQUARE_ENV=' . SQUARE_ENV . "\n";
probe_report('SQUARE_ACCESS_TOKEN (len=' . strlen(SQUARE_ACCESS_TOKEN) . ')', SQUARE_ACCESS_TOKEN !== '');
probe_report('SQUARE_LOCATION_ID (len=' . strlen(SQUARE_LOCATION_ID) . ')', SQUARE_LOCATION_ID !== '');
probe_report('SQUARE_APPLICATION_ID (len=' . strlen(SQUARE_APPLICATION_ID) . ')', SQUARE_APPLICATION_ID !== '');
probe_report('SQUARE_WEBHOOK_SIGNATURE_KEY (len=' . strlen(SQUARE_WEBHOOK_SIGNATURE_KEY) . ')', SQUARE_WEBHOOK_SIGNATURE_KEY !== '');

echo "\n-- sfb_offerings --\n";
$rows = $db->query('SELECT id, title, kind, price_cents, currency, is_active FROM sfb_offerings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo $rows ? implode("\n", array_map(static function ($r) {
    return "#{$r['id']} {$r['title']} kind={$r['kind']} price={$r['price_cents']}{$r['currency']} active={$r['is_active']}";
}, $rows)) : "(none)";

echo "\n\n-- sfb_courses (gating column) --\n";
$gateStmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'sfb_courses' AND column_name = 'required_offering_id'"
);
$gateStmt->execute();
if ((int)$gateStmt->fetchColumn() > 0) {
    $courseLines = $db->query('SELECT CONCAT("#", id, " ", title, " gate=", COALESCE(required_offering_id, "free"), " active=", is_active) FROM sfb_courses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    echo $courseLines ? implode("\n", $courseLines) : "(none)";
} else {
    echo "(068 required_offering_id column missing)";
}

echo "\n\n-- sfb_offering_purchases --\n";
echo "total rows: " . (int)$db->query('SELECT COUNT(*) FROM sfb_offering_purchases')->fetchColumn() . "\n";
foreach ($db->query('SELECT CONCAT("#", id, " cust=", customer_id, " offering=", COALESCE(offering_id, "-"), " status=", status, " snap=", price_cents_snapshot, currency_snapshot, " at=", created_at) FROM sfb_offering_purchases ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_COLUMN) as $line) {
    echo $line . "\n";
}

echo "\n-- square_webhook_events ledger --\n";
$stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'square_webhook_events'");
$stmt->execute();
if ((int)$stmt->fetchColumn() > 0) {
    echo "total events: " . (int)$db->query('SELECT COUNT(*) FROM square_webhook_events')->fetchColumn() . "\n";
    foreach ($db->query('SELECT CONCAT(event_type, " @ ", processed_at) FROM square_webhook_events ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_COLUMN) as $line) {
        echo $line . "\n";
    }
} else {
    echo "(table missing)\n";
}

echo "\n-- leftover verification rows --\n";
$left = $db->prepare("SELECT COUNT(*) FROM customers WHERE name = 'SFB Staging Pay Verify'");
$left->execute();
echo "test customers present: " . (int)$left->fetchColumn() . "\n";

echo "\n-- webhook endpoint liveness (loopback GET) --\n";
$url = rtrim('https://staging.sourflour.org' . BASE_URL, '/') . '/square_webhook.php';
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "GET {$url} -> HTTP {$code}" . ($err !== '' ? " curl_error={$err}" : '') . " body=" . substr(trim($body), 0, 120) . "\n";
echo "\n";

<?php
/** Read-only sandbox credential probe: GET /v2/locations. Prints locations, never tokens. */
define('ACCESS_ALLOWED', true);
require __DIR__ . '/../includes/env_loader.php';
bakery_load_env_file(__DIR__ . '/../.env.staging.dreamhost', true);
require __DIR__ . '/../includes/square_config.php';

echo 'SQUARE_ENV=' . SQUARE_ENV . "\n";
echo 'token present: ' . (SQUARE_ACCESS_TOKEN !== '' ? 'yes' : 'no') . "\n";
echo 'location configured: ' . SQUARE_LOCATION_ID . "\n";

try {
    $resp = square_api_request('GET', '/v2/locations');
    echo "API call: OK\n";
    foreach (($resp['locations'] ?? []) as $loc) {
        $mark = ((string)$loc['id'] === SQUARE_LOCATION_ID) ? '  <-- configured SQUARE_LOCATION_ID' : '';
        echo '- ' . $loc['id'] . '  ' . ($loc['name'] ?? '(unnamed)') . $mark . "\n";
    }
} catch (Throwable $e) {
    echo 'API call FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

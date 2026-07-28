<?php
/**
 * Debug utility disabled (Checkpoint 0B) — previously hardcoded DB credentials.
 */
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Debug endpoint disabled. Use local bakerysf_local via bakery/.env and scripts/verify_local_env.php.\n";
exit;

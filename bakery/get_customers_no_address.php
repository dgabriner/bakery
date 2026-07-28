<?php
/**
 * Utility disabled pending auth + shared config (Checkpoint 0B).
 * Previously contained hardcoded production database credentials.
 */
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "This utility is disabled. Use authenticated app pages with bakery/.env (local) or server env (production).\n";
exit;

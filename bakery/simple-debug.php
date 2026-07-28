<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Debug endpoint disabled (Checkpoint 0B). Hardcoded credentials removed.\n";
exit;

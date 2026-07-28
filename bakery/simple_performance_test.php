<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Performance test disabled (Checkpoint 0B). Hardcoded credentials removed.\n";
exit;

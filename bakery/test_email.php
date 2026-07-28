<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Email test disabled in local hardening (Checkpoint 0B). MAIL_DRIVER=log is used instead.\n";
exit;

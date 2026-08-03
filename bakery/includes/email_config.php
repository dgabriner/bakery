<?php
/**
 * Email configuration — values come from environment (via config.php / .env).
 * No hardcoded SMTP passwords (Checkpoint 0B).
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER', 'log');
}

define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?: '127.0.0.1');
define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 1025));
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD') ?: '');
define('SMTP_ENCRYPTION', $_ENV['SMTP_ENCRYPTION'] ?? getenv('SMTP_ENCRYPTION') ?: 'tls');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL') ?: 'noreply@localhost');
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?: 'Sour Flour Bakery');
define('REPLY_TO_EMAIL', $_ENV['REPLY_TO_EMAIL'] ?? getenv('REPLY_TO_EMAIL') ?: SMTP_FROM_EMAIL);
define('REPLY_TO_NAME', $_ENV['REPLY_TO_NAME'] ?? getenv('REPLY_TO_NAME') ?: SMTP_FROM_NAME);
// Temporary invoice test recipient. Replace with customer delivery routing when the payment module is ready.
define('INVOICE_TEST_RECIPIENT', 'danny@sourflour.org');

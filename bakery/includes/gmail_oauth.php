<?php
// Gmail OAuth 2.0 implementation — credentials from environment only (Checkpoint 0B)
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/OAuth.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;

class GmailOAuth {

    private const SCOPE = 'https://mail.google.com/';
    private const TOKEN_FILE = __DIR__ . '/../oauth_tokens.json';

    private static function clientId() {
        return (string)($_ENV['GMAIL_OAUTH_CLIENT_ID'] ?? getenv('GMAIL_OAUTH_CLIENT_ID') ?: '');
    }

    private static function clientSecret() {
        return (string)($_ENV['GMAIL_OAUTH_CLIENT_SECRET'] ?? getenv('GMAIL_OAUTH_CLIENT_SECRET') ?: '');
    }

    private static function getRedirectUri() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['REQUEST_URI']);
        return $protocol . $host . $path . '/oauth_callback.php';
    }

    public static function getAuthUrl() {
        $params = [
            'client_id' => self::clientId(),
            'redirect_uri' => self::getRedirectUri(),
            'scope' => self::SCOPE,
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
    }

    public static function exchangeCodeForTokens($code) {
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::getRedirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $tokens = json_decode($response, true);
            self::saveTokens($tokens);
            return $tokens;
        }

        return false;
    }

    public static function refreshAccessToken() {
        $tokens = self::loadTokens();
        if (!$tokens || !isset($tokens['refresh_token'])) {
            return false;
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $newTokens = json_decode($response, true);
            if (!isset($newTokens['refresh_token'])) {
                $newTokens['refresh_token'] = $tokens['refresh_token'];
            }
            self::saveTokens($newTokens);
            return $newTokens;
        }

        return false;
    }

    public static function getValidAccessToken() {
        $tokens = self::loadTokens();
        if (!$tokens) {
            return false;
        }

        $expiryTime = $tokens['created_at'] + $tokens['expires_in'] - 300;
        if (time() >= $expiryTime) {
            $tokens = self::refreshAccessToken();
        }

        return $tokens ? $tokens['access_token'] : false;
    }

    public static function sendEmail($to, $subject, $body, $fromName = 'Sour Flour Bakery', $attachments = []) {
        if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'log') {
            error_log("[MAIL_DRIVER=log] OAuth send skipped to={$to} subject={$subject}");
            return true;
        }

        $accessToken = self::getValidAccessToken();
        if (!$accessToken) {
            throw new Exception('No valid OAuth token available. Please re-authorize.');
        }

        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@localhost';
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->Port = 587;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            $mail->AuthType = 'XOAUTH2';

            $mail->setOAuth(
                new OAuth([
                    'provider' => 'Google',
                    'userName' => $fromEmail,
                    'clientId' => self::clientId(),
                    'clientSecret' => self::clientSecret(),
                    'refreshToken' => self::loadTokens()['refresh_token'],
                    'accessToken' => $accessToken
                ])
            );

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            if (defined('REPLY_TO_EMAIL')) {
                $mail->addReplyTo(REPLY_TO_EMAIL, defined('REPLY_TO_NAME') ? REPLY_TO_NAME : $fromName);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;

            foreach ($attachments as $attachment) {
                if (isset($attachment['path']) && isset($attachment['name'])) {
                    $mail->addAttachment($attachment['path'], $attachment['name']);
                }
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("OAuth email failed: " . $e->getMessage());
            throw $e;
        }
    }

    public static function isAuthorized() {
        if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'log') {
            return false;
        }
        $tokens = self::loadTokens();
        return $tokens && isset($tokens['access_token']) && isset($tokens['refresh_token']);
    }

    private static function saveTokens($tokens) {
        $tokens['created_at'] = time();
        file_put_contents(self::TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
        @chmod(self::TOKEN_FILE, 0600);
    }

    private static function loadTokens() {
        if (!file_exists(self::TOKEN_FILE)) {
            return false;
        }
        $content = file_get_contents(self::TOKEN_FILE);
        return json_decode($content, true);
    }

    public static function clearTokens() {
        if (file_exists(self::TOKEN_FILE)) {
            unlink(self::TOKEN_FILE);
        }
    }
}

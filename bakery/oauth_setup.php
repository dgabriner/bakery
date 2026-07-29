<?php
// OAuth setup and configuration page
require_once 'includes/gmail_oauth.php';

echo "<h2>🔐 Gmail OAuth 2.0 Setup</h2>";

// Check if already authorized
if (GmailOAuth::isAuthorized()) {
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "✅ <strong>Already Authorized!</strong> Your Gmail OAuth is working.<br>";
    echo "You can send emails or re-authorize if needed.";
    echo "</div>";
    
    echo "<p>";
    echo "<a href='test_oauth_email.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>🧪 Test Email</a>";
    echo "<a href='?clear=1' style='background: #dc3545; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🗑️ Clear & Re-authorize</a>";
    echo "</p>";
} else {
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "⚠️ <strong>OAuth Not Configured</strong><br>";
    echo "You need to complete the setup process to send emails.";
    echo "</div>";
}

// Handle clear request
if (isset($_GET['clear'])) {
    GmailOAuth::clearTokens();
    echo "<div style='background: #e2f3ff; color: #004085; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "🗑️ <strong>Tokens Cleared</strong><br>";
    echo "Please complete the authorization process again.";
    echo "</div>";
}

echo "<hr>";
echo "<h3>📋 Setup Instructions</h3>";

echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 15px 0;'>";
echo "<h4>Step 1: Google Cloud Console Setup</h4>";
echo "<ol>";
echo "<li><strong>Go to:</strong> <a href='https://console.cloud.google.com' target='_blank'>console.cloud.google.com</a></li>";
echo "<li><strong>Create a project:</strong> 'Sour Flour Bakery Email'</li>";
echo "<li><strong>Enable Gmail API:</strong> APIs & Services → Library → Gmail API</li>";
echo "<li><strong>Create OAuth credentials:</strong> APIs & Services → Credentials → Create Credentials → OAuth client ID</li>";
echo "<li><strong>Configure OAuth consent screen</strong> (if prompted)</li>";
echo "<li><strong>Set redirect URI:</strong> <code>" . (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/oauth_callback.php</code></li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #e3f2fd; padding: 20px; border-radius: 5px; margin: 15px 0;'>";
echo "<h4>Step 2: Update Configuration</h4>";
echo "<p>After creating OAuth credentials, you'll get:</p>";
echo "<ul>";
echo "<li><strong>Client ID:</strong> something.apps.googleusercontent.com</li>";
echo "<li><strong>Client Secret:</strong> a long string</li>";
echo "</ul>";
echo "<p><strong>Update these values in:</strong> <code>includes/gmail_oauth.php</code></p>";
echo "<pre style='background: #f1f3f4; padding: 10px; border-radius: 3px; font-size: 12px;'>";
echo "private const CLIENT_ID = 'YOUR_CLIENT_ID.apps.googleusercontent.com';\n";
echo "private const CLIENT_SECRET = 'YOUR_CLIENT_SECRET';\n";
echo "private const REDIRECT_URI = '" . (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/oauth_callback.php';</pre>";
echo "</div>";

echo "<div style='background: #e8f5e9; padding: 20px; border-radius: 5px; margin: 15px 0;'>";
echo "<h4>Step 3: Start Authorization</h4>";
echo "<p>Once you've updated the configuration:</p>";

// Check if configuration looks updated
$authUrl = GmailOAuth::getAuthUrl();
if (strpos($authUrl, 'YOUR_CLIENT_ID') !== false) {
    echo "<div style='background: #fff3cd; color: #856404; padding: 10px; border-radius: 3px; margin: 10px 0;'>";
    echo "⚠️ <strong>Configuration needed:</strong> Please update CLIENT_ID and CLIENT_SECRET first.";
    echo "</div>";
} else {
    echo "<a href='" . htmlspecialchars($authUrl) . "' style='background: #007bff; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>";
    echo "🚀 Start OAuth Authorization";
    echo "</a>";
    echo "<p style='font-size: 14px; color: #666; margin-top: 10px;'>";
    echo "This will redirect you to Google to authorize email access for your bakery system.";
    echo "</p>";
}
echo "</div>";

echo "<hr>";
echo "<h3>🔧 Current Configuration Status</h3>";

echo "<table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
echo "<tr style='background: #f8f9fa;'>";
echo "<td style='padding: 10px; border: 1px solid #dee2e6; font-weight: bold;'>Setting</td>";
echo "<td style='padding: 10px; border: 1px solid #dee2e6; font-weight: bold;'>Status</td>";
echo "</tr>";

// Check configuration
$configStatus = [
    'Client ID' => strpos($authUrl, 'YOUR_CLIENT_ID') === false ? '✅ Configured' : '❌ Needs update',
    'Redirect URI' => '✅ Auto-detected',
    'OAuth Tokens' => GmailOAuth::isAuthorized() ? '✅ Authorized' : '❌ Not authorized',
    'PHPMailer' => class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? '✅ Available' : '❌ Missing'
];

foreach ($configStatus as $setting => $status) {
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid #dee2e6;'>" . htmlspecialchars($setting) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid #dee2e6;'>" . $status . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<hr>";
echo "<p><a href='orders.php'>← Back to Orders</a></p>";
?> 
<?php
// OAuth callback handler — Google redirects the signed-in administrator here.
// Bootstrapping through database.php runs bakery_enforce_request_security, so a
// stranger cannot complete (or replay) a token exchange against this host.
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once 'includes/gmail_oauth.php';

echo "<h2>🔐 OAuth Authorization</h2>";

if (isset($_GET['code'])) {
    // Exchange authorization code for tokens
    $tokens = GmailOAuth::exchangeCodeForTokens($_GET['code']);
    
    if ($tokens) {
        echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "✅ <strong>SUCCESS!</strong> OAuth authorization completed!<br>";
        echo "Your bakery system can now send emails via Gmail.<br>";
        echo "Tokens have been securely stored.";
        echo "</div>";
        
        echo "<p><a href='test_oauth_email.php'>🧪 Test OAuth Email</a> | <a href='orders.php'>📋 Back to Orders</a></p>";
    } else {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ <strong>ERROR:</strong> Failed to exchange authorization code for tokens.";
        echo "</div>";
        
        echo "<p><a href='oauth_setup.php'>🔄 Try Again</a></p>";
    }
} elseif (isset($_GET['error'])) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "❌ <strong>Authorization Denied:</strong> " . htmlspecialchars($_GET['error']);
    if (isset($_GET['error_description'])) {
        echo "<br>" . htmlspecialchars($_GET['error_description']);
    }
    echo "</div>";
    
    echo "<p><a href='oauth_setup.php'>🔄 Try Again</a></p>";
} else {
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "⚠️ <strong>No authorization code received.</strong><br>";
    echo "Please start the OAuth process from the setup page.";
    echo "</div>";
    
    echo "<p><a href='oauth_setup.php'>🚀 Start OAuth Setup</a></p>";
}
?> 
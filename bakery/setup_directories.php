<?php
/**
 * Directory Setup Script for Photo Functionality (administrator only)
 * 
 * Run this script once to create the required directory structure
 * for driver photo uploads.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

echo "<h1>🗂️ Setting up Photo Upload Directories</h1>";

$baseDir = __DIR__ . '/uploads/driver_photos/';
$currentYear = date('Y');
$currentMonth = date('m');

$directories = [
    $baseDir,
    $baseDir . 'thumbs/',
    $baseDir . $currentYear . '/',
    $baseDir . $currentYear . '/' . $currentMonth . '/',
    $baseDir . 'thumbs/' . $currentYear . '/',
    $baseDir . 'thumbs/' . $currentYear . '/' . $currentMonth . '/'
];

echo "<ul>";

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "<li style='color: green;'>✅ Created: " . str_replace(__DIR__, '.', $dir) . "</li>";
        } else {
            echo "<li style='color: red;'>❌ Failed to create: " . str_replace(__DIR__, '.', $dir) . "</li>";
        }
    } else {
        echo "<li style='color: blue;'>📁 Already exists: " . str_replace(__DIR__, '.', $dir) . "</li>";
    }
}

echo "</ul>";

// Create a test .htaccess file for security
$htaccessContent = "# Prevent direct access to uploaded files
Options -Indexes
# Allow only image files
<FilesMatch \"\\.(jpg|jpeg|png|webp)$\">
    Order Allow,Deny
    Allow from all
</FilesMatch>
# Deny everything else
<FilesMatch \"\\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$\">
    Order Allow,Deny
    Deny from all
</FilesMatch>";

$htaccessPath = $baseDir . '.htaccess';
if (!file_exists($htaccessPath)) {
    if (file_put_contents($htaccessPath, $htaccessContent)) {
        echo "<p style='color: green;'>✅ Created security .htaccess file</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Could not create .htaccess file (check permissions)</p>";
    }
} else {
    echo "<p style='color: blue;'>📄 .htaccess file already exists</p>";
}

// Test write permissions
$testFile = $baseDir . $currentYear . '/' . $currentMonth . '/test_permissions.txt';
if (file_put_contents($testFile, 'test')) {
    unlink($testFile);
    echo "<p style='color: green;'>✅ Directory permissions are correct</p>";
} else {
    echo "<p style='color: red;'>❌ Write permission error - check directory permissions</p>";
}

echo "<h2>📋 Next Steps:</h2>";
echo "<ol>";
echo "<li>Run the SQL script: <code>docs/archive/sql-patches/setup_photo_functionality.sql</code></li>";
echo "<li>Make sure your server has GD extension enabled for image processing</li>";
echo "<li>The photo functionality is now ready to use!</li>";
echo "</ol>";

echo "<h3>📁 Directory Structure Created:</h3>";
echo "<pre>";
echo "uploads/\n";
echo "└── driver_photos/\n";
echo "    ├── .htaccess (security)\n";
echo "    ├── thumbs/\n";
echo "    │   └── " . $currentYear . "/\n";
echo "    │       └── " . $currentMonth . "/\n";
echo "    └── " . $currentYear . "/\n";
echo "        └── " . $currentMonth . "/\n";
echo "</pre>";
?> 
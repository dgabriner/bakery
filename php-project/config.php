<?php
// Database configuration (for future use)
$db_host = 'localhost';
$db_name = 'my_database';
$db_user = 'root';
$db_pass = '';

// Application settings
$app_version = '1.0.0';
$debug_mode = true;

// Function to get configuration value
function getConfig($key) {
    $config = [
        'db_host' => 'localhost',
        'db_name' => 'my_database',
        'db_user' => 'root',
        'db_pass' => '',
        'app_version' => '1.0.0',
        'debug_mode' => true
    ];
    return isset($config[$key]) ? $config[$key] : null;
}
?>

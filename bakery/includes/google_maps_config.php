<?php
/**
 * Google Maps configuration — key from environment only.
 * Local development should set MAPS_ENABLED=false.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!defined('MAPS_ENABLED')) {
    define('MAPS_ENABLED', false);
}

define(
    'GOOGLE_MAPS_API_KEY',
    $_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: ''
);

define('GOOGLE_GEOCODING_API_URL', 'https://maps.googleapis.com/maps/api/geocode/json');
define('GOOGLE_DIRECTIONS_API_URL', 'https://maps.googleapis.com/maps/api/directions/json');
define('GOOGLE_MAPS_JS_API_URL', 'https://maps.googleapis.com/maps/api/js');
define('GOOGLE_API_DELAY_MS', 100);
define('GOOGLE_API_MAX_WAYPOINTS', 25);

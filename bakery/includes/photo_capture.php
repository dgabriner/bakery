<?php
/**
 * Capture time and GPS for a driver photo.
 * Browser values win. EXIF fills whatever the phone did not send.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_photo_far_meters(): float
{
    return 250.0;
}

function bakery_photo_normalize_captured_at($raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * @param mixed $value
 */
function bakery_photo_exif_number($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (strpos($text, '/') !== false) {
        [$numerator, $denominator] = explode('/', $text, 2);
        if ((float)$denominator == 0.0) {
            return null;
        }
        return (float)$numerator / (float)$denominator;
    }
    if (!is_numeric($text)) {
        return null;
    }
    return (float)$text;
}

/**
 * @param mixed $parts
 */
function bakery_photo_exif_coord($parts, $ref): ?float
{
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }
    $degrees = bakery_photo_exif_number($parts[0]);
    $minutes = bakery_photo_exif_number($parts[1]);
    $seconds = bakery_photo_exif_number($parts[2]);
    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    $hemisphere = strtoupper(trim((string)$ref));
    if ($hemisphere === 'S' || $hemisphere === 'W') {
        $decimal = -$decimal;
    }
    if ($decimal < -180 || $decimal > 180) {
        return null;
    }
    return round($decimal, 8);
}

/**
 * @return array{captured_at:?string,latitude:?float,longitude:?float}
 */
function bakery_photo_exif_capture(string $path): array
{
    $empty = ['captured_at' => null, 'latitude' => null, 'longitude' => null];
    if ($path === '' || !is_file($path) || !function_exists('exif_read_data')) {
        return $empty;
    }
    $exif = @exif_read_data($path, 'ANY_TAG', true);
    if (!is_array($exif)) {
        return $empty;
    }
    $date = $exif['EXIF']['DateTimeOriginal']
        ?? $exif['EXIF']['DateTimeDigitized']
        ?? $exif['IFD0']['DateTime']
        ?? null;
    $capturedAt = null;
    if (is_string($date) && preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}:\d{2}:\d{2})$/', trim($date), $match)) {
        $capturedAt = bakery_photo_normalize_captured_at($match[1] . '-' . $match[2] . '-' . $match[3] . ' ' . $match[4]);
    }
    $gps = isset($exif['GPS']) && is_array($exif['GPS']) ? $exif['GPS'] : [];
    return [
        'captured_at' => $capturedAt,
        'latitude' => bakery_photo_exif_coord($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N'),
        'longitude' => bakery_photo_exif_coord($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E'),
    ];
}

function bakery_photo_distance_meters($lat1, $lng1, $lat2, $lng2): ?float
{
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
        return null;
    }
    if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) {
        return null;
    }
    $lat1 = (float)$lat1;
    $lng1 = (float)$lng1;
    $lat2 = (float)$lat2;
    $lng2 = (float)$lng2;
    if (abs($lat1) < 0.000001 && abs($lng1) < 0.000001) {
        return null;
    }
    if (abs($lat2) < 0.000001 && abs($lng2) < 0.000001) {
        return null;
    }
    $earth = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLng / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function bakery_photo_is_far_from_store($photoLat, $photoLng, $storeLat, $storeLng): bool
{
    $meters = bakery_photo_distance_meters($photoLat, $photoLng, $storeLat, $storeLng);
    if ($meters === null) {
        return false;
    }
    return $meters > bakery_photo_far_meters();
}

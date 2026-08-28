<?php
/**
 * Sour Flour Google tag — same Site Kit snippet as sourflour.org.
 * Destinations behind GT-5MGVGM88: GA4 G-FEZ1KFZKPK, Google Ads AW-987675312.
 * Configure gtag with the Google tag ID only. Do not emit Universal Analytics.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_google_analytics_tag_id(): string
{
    return 'GT-5MGVGM88';
}

function bakery_google_analytics_normalize_host(?string $host): string
{
    if ($host === null) {
        if (function_exists('bakery_request_host')) {
            return bakery_request_host();
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    }
    $host = strtolower(trim($host));
    return (string)preg_replace('/:\d+$/', '', $host);
}

function bakery_google_analytics_should_load(?string $host = null): bool
{
    $hostWasSet = array_key_exists('HTTP_HOST', $_SERVER);
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    if ($host !== null) {
        $_SERVER['HTTP_HOST'] = $host;
    }
    try {
        $normalized = bakery_google_analytics_normalize_host(null);
        if ($normalized === '' || in_array($normalized, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return false;
        }
        if ($normalized === 'bakery.sourflour.org' || $normalized === 'www.bakery.sourflour.org') {
            return true;
        }
        return function_exists('bakery_is_staging_host') && bakery_is_staging_host();
    } finally {
        if ($host !== null) {
            if ($hostWasSet) {
                $_SERVER['HTTP_HOST'] = $previousHost;
            } else {
                unset($_SERVER['HTTP_HOST']);
            }
        }
    }
}

function bakery_google_analytics_render(?string $host = null): void
{
    if (!empty($GLOBALS['BAKERY_GOOGLE_ANALYTICS_RENDERED'])) {
        return;
    }
    if (!bakery_google_analytics_should_load($host)) {
        return;
    }
    $GLOBALS['BAKERY_GOOGLE_ANALYTICS_RENDERED'] = true;
    $id = htmlspecialchars(bakery_google_analytics_tag_id(), ENT_QUOTES, 'UTF-8');
    echo '<script src="https://www.googletagmanager.com/gtag/js?id=' . $id . '" async></script>' . "\n";
    echo "<script>\n";
    echo "window.dataLayer = window.dataLayer || [];\n";
    echo "function gtag(){dataLayer.push(arguments);}\n";
    echo "gtag('js', new Date());\n";
    echo "gtag('config', '" . $id . "');\n";
    echo "</script>\n";
}

bakery_google_analytics_render();

<?php
/**
 * Minimal baker i18n — loads lang/en.php and lang/es.php.
 * Language is ?lang=en|es (remembered in session). Default English.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_allowed_langs()
{
    return ['en', 'es'];
}

function bakery_current_lang()
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $allowed = bakery_allowed_langs();
    $requested = isset($_GET['lang']) ? strtolower(trim((string) $_GET['lang'])) : '';
    if (in_array($requested, $allowed, true)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['bakery_lang'] = $requested;
        }
        $resolved = $requested;
        return $resolved;
    }

    if (
        session_status() === PHP_SESSION_ACTIVE
        && !empty($_SESSION['bakery_lang'])
        && in_array($_SESSION['bakery_lang'], $allowed, true)
    ) {
        $resolved = $_SESSION['bakery_lang'];
        return $resolved;
    }

    $resolved = 'en';
    return $resolved;
}

function bakery_lang_catalog($lang)
{
    static $cache = [];
    $lang = (string) $lang;
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $path = dirname(__DIR__) . '/lang/' . $lang . '.php';
    $rows = is_file($path) ? include $path : [];
    $cache[$lang] = is_array($rows) ? $rows : [];
    return $cache[$lang];
}

function bakery_t($key)
{
    $key = (string) $key;
    $primary = bakery_lang_catalog(bakery_current_lang());
    if (isset($primary[$key]) && $primary[$key] !== '') {
        return $primary[$key];
    }
    $fallback = bakery_lang_catalog('en');
    return $fallback[$key] ?? $key;
}

function bakery_lang_switch_query($lang)
{
    $params = $_GET;
    $params['lang'] = $lang;
    return '?' . http_build_query($params);
}

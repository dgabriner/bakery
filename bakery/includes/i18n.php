<?php
/**
 * Internationalization — English / Spanish with role-based defaults.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

define('BAKERY_LOCALES', ['en', 'es']);
define('BAKERY_LOCALE_COOKIE', 'bakery_locale');
define('BAKERY_LOCALE_COOKIE_DAYS', 365);

/**
 * Default locale by staff role or portal context.
 */
function bakery_default_locale_for_role(?string $roleSlug, bool $isPortalCustomer = false): string {
    if ($isPortalCustomer) {
        return 'en';
    }
    if (in_array($roleSlug, ['baker', 'driver', 'manager'], true)) {
        return 'es';
    }
    return 'en';
}

/**
 * Resolve locale from explicit choice, role, Accept-Language, or fallback.
 */
function bakery_locale(bool $forceRefresh = false): string {
    static $resolved = null;
    if ($forceRefresh) {
        $resolved = null;
        return bakery_locale();
    }
    if ($resolved !== null) {
        return $resolved;
    }

    if (!empty($_SESSION['locale']) && in_array($_SESSION['locale'], BAKERY_LOCALES, true)) {
        return $resolved = $_SESSION['locale'];
    }

    if (!empty($_COOKIE[BAKERY_LOCALE_COOKIE]) && in_array($_COOKIE[BAKERY_LOCALE_COOKIE], BAKERY_LOCALES, true)) {
        return $resolved = $_COOKIE[BAKERY_LOCALE_COOKIE];
    }

    $roleSlug = null;
    $isPortal = false;
    if (function_exists('bakery_current_user')) {
        $user = bakery_current_user();
        if ($user) {
            $roleSlug = $user['role_slug'] ?? null;
        }
    }
    if (function_exists('bakery_portal_customer_id') && bakery_portal_customer_id() > 0) {
        $isPortal = true;
    }
    if ($roleSlug !== null || $isPortal) {
        return $resolved = bakery_default_locale_for_role($roleSlug, $isPortal);
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    // Phone surveys (drivers/managers) and login default to Spanish.
    if ($script === 'login' || $script === 'baker' || $script === 'survey') {
        return $resolved = 'es';
    }
    if ($script === 'customer_portal_login' || $script === 'customer_login') {
        return $resolved = 'en';
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (strpos($accept, 'es') === 0 || preg_match('/(?:^|[,;])\s*es(?:[-_]|$)/', $accept)) {
        return $resolved = 'es';
    }

    return $resolved = 'en';
}

/**
 * Persist locale to session and optionally cookie.
 */
function bakery_set_locale(string $locale, bool $persistCookie = true): void {
    if (!in_array($locale, BAKERY_LOCALES, true)) {
        return;
    }
    $_SESSION['locale'] = $locale;
    bakery_reset_locale_cache();
    if ($persistCookie && PHP_SAPI !== 'cli') {
        $path = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
        setcookie(
            BAKERY_LOCALE_COOKIE,
            $locale,
            [
                'expires' => time() + (BAKERY_LOCALE_COOKIE_DAYS * 86400),
                'path' => $path,
                'secure' => function_exists('isHTTPS') && isHTTPS(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[BAKERY_LOCALE_COOKIE] = $locale;
    }
}

/**
 * Apply role default on login when user has not chosen a locale.
 */
function bakery_apply_locale_default_for_user(?string $roleSlug, bool $isPortalCustomer = false): void {
    if (
        (!empty($_SESSION['locale']) && in_array($_SESSION['locale'], BAKERY_LOCALES, true))
        || (!empty($_COOKIE[BAKERY_LOCALE_COOKIE]) && in_array($_COOKIE[BAKERY_LOCALE_COOKIE], BAKERY_LOCALES, true))
    ) {
        return;
    }
    bakery_set_locale(bakery_default_locale_for_role($roleSlug, $isPortalCustomer), false);
}

/** @var array<string, array<string, string>>|null */
$GLOBALS['bakery_i18n_catalog'] = null;

function bakery_reset_locale_cache(): void {
    $GLOBALS['bakery_i18n_catalog'] = null;
    // Clear bakery_locale() static via reflection-free hack: re-enter with forced session
    bakery_locale(true);
}

function bakery_load_lang_catalog(string $locale): array {
    $root = dirname(__DIR__) . '/lang';
    $candidates = [$root . '/' . $locale . '.php'];
    if ($locale !== 'en') {
        $candidates[] = $root . '/en.php';
    }
    foreach ($candidates as $file) {
        if (!is_readable($file)) {
            continue;
        }
        $strings = require $file;
        if (is_array($strings)) {
            return $strings;
        }
    }
    return [];
}

function bakery_i18n_catalog(): array {
    if ($GLOBALS['bakery_i18n_catalog'] === null) {
        $GLOBALS['bakery_i18n_catalog'] = bakery_load_lang_catalog(bakery_locale());
    }
    return $GLOBALS['bakery_i18n_catalog'];
}

/**
 * Translate a key. Falls back to English, then the key itself.
 *
 * @param array<string, scalar|null> $params Placeholders like :name
 */
function bakery_t(string $key, array $params = []): string {
    $catalog = bakery_i18n_catalog();
    $text = $catalog[$key] ?? null;
    if ($text === null && bakery_locale() !== 'en') {
        static $enCatalog = null;
        if ($enCatalog === null) {
            $enCatalog = bakery_load_lang_catalog('en');
        }
        $text = $enCatalog[$key] ?? $key;
    }
    if ($text === null) {
        $text = $key;
    }
    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string)$value, $text);
    }
    return $text;
}

/** Echo translated string (HTML-escaped). */
function bakery_te(string $key, array $params = []): void {
    echo htmlspecialchars(bakery_t($key, $params), ENT_QUOTES, 'UTF-8');
}

function bakery_day_names(bool $short = false): array {
    if ($short) {
        return [
            1 => bakery_t('day.mon_short'),
            2 => bakery_t('day.tue_short'),
            3 => bakery_t('day.wed_short'),
            4 => bakery_t('day.thu_short'),
            5 => bakery_t('day.fri_short'),
            6 => bakery_t('day.sat_short'),
            7 => bakery_t('day.sun_short'),
        ];
    }
    return [
        1 => bakery_t('day.monday'),
        2 => bakery_t('day.tuesday'),
        3 => bakery_t('day.wednesday'),
        4 => bakery_t('day.thursday'),
        5 => bakery_t('day.friday'),
        6 => bakery_t('day.saturday'),
        7 => bakery_t('day.sunday'),
    ];
}

function bakery_standing_day_labels_localized(): array {
    return bakery_day_names(true);
}

/**
 * Localized "Today" label for nav/date hints.
 */
function bakery_today_label(): string {
    return bakery_t('common.today');
}

/**
 * Format a calendar date using the active locale without relying on the server locale.
 */
function bakery_localized_date_label(DateTimeInterface $date, bool $withYear = false): string {
    $month = (int)$date->format('n');
    $day = $date->format('j');
    $year = $date->format('Y');
    $months = bakery_locale() === 'es'
        ? ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
        : ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $label = $months[$month] . ' ' . $day;
    return $withYear ? $label . ', ' . $year : $label;
}

function bakery_localized_month_day(DateTimeInterface $date): string {
    return bakery_localized_date_label($date, false);
}

function bakery_localized_month_short(DateTimeInterface $date): string {
    $months = bakery_locale() === 'es'
        ? ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
        : ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $months[(int)$date->format('n')];
}

/**
 * Handle ?locale=en|es on any page (GET redirect back).
 */
function bakery_handle_locale_request(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $locale = $_GET['locale'] ?? $_POST['locale'] ?? null;
    if ($locale === null || !in_array($locale, BAKERY_LOCALES, true)) {
        return;
    }
    bakery_set_locale($locale, true);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['locale'])) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['locale']);
        $redirect = $path . ($query ? ('?' . http_build_query($query)) : '');
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Build locale switch URL for current page.
 */
function bakery_locale_switch_url(string $locale): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['locale'] = $locale;
    return $path . '?' . http_build_query($query);
}

/** Aliases used by baker formula-unit markup (PR b9941e8). */
function bakery_current_lang(): string {
    return bakery_locale();
}

function bakery_lang_catalog($lang): array {
    return bakery_load_lang_catalog((string) $lang);
}

function bakery_lang_switch_query($lang): string {
    return bakery_locale_switch_url((string) $lang);
}

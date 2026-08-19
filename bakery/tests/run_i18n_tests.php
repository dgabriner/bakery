<?php
/**
 * Basic i18n smoke tests (CLI).
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

$_SERVER['SCRIPT_NAME'] = '/login.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/i18n.php';

$failures = 0;

function i18n_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL: $label\n";
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

// Staff login page defaults to Spanish before auth.
$_SESSION = [];
$_COOKIE = [];
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
i18n_assert('Spanish login title', bakery_t('login.title') === 'Código para entrar');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
i18n_assert('English login title', bakery_t('login.title') === 'Sign in code');

// Role defaults
i18n_assert('Baker default es', bakery_default_locale_for_role('baker', false) === 'es');
i18n_assert('Driver default es', bakery_default_locale_for_role('driver', false) === 'es');
i18n_assert('Manager default es', bakery_default_locale_for_role('manager', false) === 'es');
i18n_assert('Admin default en', bakery_default_locale_for_role('administrator', false) === 'en');
i18n_assert('Customer default en', bakery_default_locale_for_role(null, true) === 'en');

// Navigation translation
require_once dirname(__DIR__) . '/includes/navigation_catalog.php';
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
$groups = bakery_navigation_groups_for_role('manager');
i18n_assert('Nav groups returned', count($groups) > 0);
i18n_assert('Spanish nav group label', $groups[0]['label'] === 'Jornada');
i18n_assert('Spanish driver guides page', bakery_t('page.driver_guides') === 'Guías del repartidor');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
i18n_assert('English walkthroughs page', bakery_t('page.walkthroughs') === 'Walkthroughs');

exit($failures > 0 ? 1 : 0);

<?php
/**
 * Language switch partial — EN / ES toggle.
 *
 * Expects $langSwitchVariant: 'nav' | 'inline' | 'portal' (default: inline)
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$currentLocale = bakery_locale();
$otherLocale = $currentLocale === 'es' ? 'en' : 'es';
$variant = $langSwitchVariant ?? 'inline';
?>
<div class="bakery-lang-switch bakery-lang-switch--<?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>" role="group" aria-label="<?php bakery_te('lang.switch_aria'); ?>">
  <a class="bakery-lang-switch__btn<?php echo $currentLocale === 'en' ? ' bakery-lang-switch__btn--active' : ''; ?>"
     href="<?php echo htmlspecialchars(bakery_locale_switch_url('en'), ENT_QUOTES, 'UTF-8'); ?>"
     hreflang="en"
     lang="en"
     aria-current="<?php echo $currentLocale === 'en' ? 'true' : 'false'; ?>"><?php bakery_te('lang.en'); ?></a>
  <a class="bakery-lang-switch__btn<?php echo $currentLocale === 'es' ? ' bakery-lang-switch__btn--active' : ''; ?>"
     href="<?php echo htmlspecialchars(bakery_locale_switch_url('es'), ENT_QUOTES, 'UTF-8'); ?>"
     hreflang="es"
     lang="es"
     aria-current="<?php echo $currentLocale === 'es' ? 'true' : 'false'; ?>"><?php bakery_te('lang.es'); ?></a>
</div>

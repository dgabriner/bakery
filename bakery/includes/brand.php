<?php
/**
 * Sour Flour brand assets shared across customer-facing surfaces.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Cache-busted URL for the main Sour Flour wordmark logo. */
function bakery_sour_flour_logo_url() {
    if (function_exists('bakery_asset_url')) {
        return bakery_asset_url('assets/logos/sour-flour-full.png');
    }
    return BASE_URL . 'assets/logos/sour-flour-full.png';
}

/**
 * Render the main Sour Flour logo <img>.
 *
 * @param string $class CSS class(es) for the img element
 * @param string $alt Alt text
 */
function bakery_sour_flour_logo_img($class = 'sour-flour-logo', $alt = 'Sour Flour') {
    return '<img class="' . htmlspecialchars((string)$class, ENT_QUOTES, 'UTF-8') . '"'
        . ' src="' . htmlspecialchars(bakery_sour_flour_logo_url(), ENT_QUOTES, 'UTF-8') . '"'
        . ' alt="' . htmlspecialchars((string)$alt, ENT_QUOTES, 'UTF-8') . '"'
        . ' decoding="async">';
}

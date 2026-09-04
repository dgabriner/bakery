<?php
/**
 * Shared chrome touch targets ≥ 44px and portal accents from tokens.
 * Usage: php tests/run_touch_target_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$tokens = (string)file_get_contents($root . '/css/tokens.css');
$assert(strpos($tokens, '--sf-touch-min: 44px') !== false, 'tokens define --sf-touch-min at 44px');
foreach ([
    '--sf-portal-ink',
    '--sf-portal-cream',
    '--sf-portal-terracotta',
    '--sf-portal-muted',
    '--sf-portal-border',
    '--sf-portal-green',
    '--sf-portal-amber',
    '--sf-portal-sand',
] as $token) {
    $assert(strpos($tokens, $token) !== false, "tokens define $token");
}

$portal = (string)file_get_contents($root . '/includes/portal_styles.php');
$assert(strpos($portal, 'var(--sf-portal-ink') !== false, 'portal_styles aliases --sf-portal-ink');
$assert(strpos($portal, 'var(--sf-touch-min') !== false, 'portal More/notify use --sf-touch-min');
$assert(preg_match('/\.portal-top__more\s*\{[^}]*min-height:\s*36px/s', $portal) !== 1, 'portal More is not 36px');

$base = (string)file_get_contents($root . '/css/base.css');
$assert(strpos($base, "min-height: 100vh;\n  min-height: 100dvh;") !== false
    || strpos($base, "min-height: 100vh;\r\n  min-height: 100dvh;") !== false
    || (strpos($base, 'min-height: 100vh') !== false && strpos($base, 'min-height: 100dvh') !== false),
    'body uses 100dvh with 100vh fallback');
$assert(strpos($base, '.sf-btn--sm') !== false && strpos($base, 'min-height: var(--sf-touch-min)') !== false, 'small buttons use touch-min');

$sfb = (string)file_get_contents($root . '/includes/sfb_styles.php');
$assert(preg_match('/\.sfb-tabs a(?:,\s*\.sfb-tabs__more)?\s*\{[^}]*min-height:\s*var\(--(?:sf-touch-min|nav-h)/s', $sfb) === 1, 'SFB tabs use touch-sized min-height');

$chromeFiles = [
    'css/nav.css',
    'css/base.css',
    'css/manager_phone.css',
    'includes/portal_styles.php',
    'includes/sfb_styles.php',
];

$interactive = '/(button|\.btn\b|a\.|\.tab\b|nav__|sfb-tabs|portal-top__|portal-tabs|preset-links)/i';
$decorative = '/(badge|dot|icon|swatch|mark|spacer|handle|fill|progress|logo|svg|section-header)/i';

$violations = [];
foreach ($chromeFiles as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    // Strip comments
    $src = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
    if (!preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $src, $blocks, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($blocks as $block) {
        $selector = trim($block[1]);
        $body = $block[2];
        if ($selector === '' || !preg_match($interactive, $selector)) {
            continue;
        }
        if (preg_match($decorative, $selector)) {
            continue;
        }
        // Skip visually hidden / clipped chrome labels
        if (strpos($body, 'clip:') !== false || strpos($body, 'position: absolute') !== false && strpos($body, 'height: 1px') !== false) {
            continue;
        }
        if (!preg_match_all('/\b(min-height|height|min-width)\s*:\s*(\d+(?:\.\d+)?)px\b/i', $body, $props, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($props as $prop) {
            $name = strtolower($prop[1]);
            $px = (float)$prop[2];
            if ($px <= 1 || $px >= 44) {
                continue;
            }
            if ($name === 'min-width' && !preg_match('/portal-top__more|portal-top__notify|alerts-toggle/i', $selector)) {
                continue;
            }
            if ($name === 'height' && $px < 16) {
                continue;
            }
            $violations[] = "$rel :: $selector :: {$prop[1]}: {$prop[2]}px";
        }
    }
}

$assert($violations === [], $violations === []
    ? 'shared chrome interactive sizes are ≥ 44px'
    : ('undersized chrome: ' . implode(' | ', array_slice($violations, 0, 8))));

$nav = (string)file_get_contents($root . '/css/nav.css');
$assert(strpos($nav, 'min-height: 40px') === false, 'nav.css has no leftover 40px min-heights');
$assert(strpos($nav, 'min-height: var(--sf-touch-min)') !== false, 'nav.css uses --sf-touch-min');

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

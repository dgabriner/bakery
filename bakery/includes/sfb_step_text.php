<?php
/**
 * Pure step-body renderer for SF Baker education surfaces (Oxlet 18).
 *
 * Escape-first contract: htmlspecialchars, then bare http(s):// URLs
 * (capped near 200 chars, stopping at whitespace / angle brackets)
 * become target="_blank" rel="noopener noreferrer" anchors, then nl2br.
 * No other markup (bold, markdown, inline HTML) is supported by design.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_sfb_render_step_text(string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // Runs on the ESCAPED string: &lt; / &gt; terminate candidates (raw <>
    // in source), while &amp; stays eligible so query strings survive.
    $linked = preg_replace_callback(
        '/https?:\/\/(?:&amp;|[^\s<>&]){1,200}/i',
        static function (array $m): string {
            return '<a href="' . $m[0] . '" target="_blank" rel="noopener noreferrer">'
                . $m[0] . '</a>';
        },
        $escaped
    );
    return nl2br((string)$linked);
}

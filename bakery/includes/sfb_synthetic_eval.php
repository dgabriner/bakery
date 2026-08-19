<?php
/**
 * Synthetic Studio eval hook.
 *
 * Prompt 3 owns bakery_sfb_eval_synthetic_text() in includes/sfb_library.php
 * (see docs/sfb_synthetic_eval.md). This wrapper is what the agent CLI and
 * persona seed call before any community insert.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sfb_library.php';

/**
 * @param array{
 *   body?:string,
 *   title?:string,
 *   origin?:string,
 *   customer?:array,
 *   author_kind?:string,
 *   author_type?:string,
 *   is_mentor?:bool
 * } $context
 * @return array{ok:bool, reasons:array<int,string>}
 */
function bakery_sfb_synthetic_eval_post(array $context) {
    $title = trim((string)($context['title'] ?? ''));
    $body = trim((string)($context['body'] ?? ''));
    $combined = trim($title . "\n" . $body);
    $customer = is_array($context['customer'] ?? null) ? $context['customer'] : [];
    $origin = bakery_sfb_normalize_origin($context['origin'] ?? ($customer['sfb_origin'] ?? ''));
    $authorKind = strtolower(trim((string)($context['author_kind'] ?? '')));
    $authorType = strtolower(trim((string)($context['author_type'] ?? '')));
    $isMentor = !empty($context['is_mentor']);

    $result = bakery_sfb_eval_synthetic_text($combined, [
        'origin' => $origin,
        'customer' => $customer,
        'is_mentor' => $isMentor,
    ]);
    $reasons = $result['reasons'];

    if ($origin !== 'synthetic') {
        $reasons[] = 'origin_not_synthetic';
    }
    if ($isMentor && ($authorKind === 'coach' || $authorType === 'admin')) {
        $reasons[] = 'mentor posted as administrator';
    }

    $reasons = array_values(array_unique($reasons));
    return [
        'ok' => $reasons === [],
        'reasons' => $reasons,
    ];
}

function bakery_sfb_synthetic_eval_assert_post(array $context) {
    $result = bakery_sfb_synthetic_eval_post($context);
    if ($result['ok']) {
        return $result;
    }
    throw new InvalidArgumentException(
        'Synthetic post rejected: ' . implode(', ', $result['reasons'])
    );
}

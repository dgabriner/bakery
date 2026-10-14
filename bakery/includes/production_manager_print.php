<?php
/**
 * Letter-size Manager bake sheet (batches + quantities).
 * Used by production_manager.php?print=1 — not a second page.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_cadence.php';

/**
 * Letter links for one delivery date.
 * The bake sheet is the released plan (or dated demand when nothing is released).
 * The draft link is that same date's saved targets, never a later date's plan.
 *
 * @return array{bake_sheet:string,draft:string}
 */
function bakery_pmd_print_links(string $date): array
{
    if (function_exists('bakery_pmd_resolve_date')) {
        $date = bakery_pmd_resolve_date($date);
    }
    return [
        'bake_sheet' => 'production_manager.php?' . http_build_query([
            'date' => $date,
            'view' => 'batches',
            'print' => '1',
        ]),
        'draft' => 'production_manager.php?' . http_build_query([
            'date' => $date,
            'view' => 'batches',
            'source' => 'last_entered',
            'entered_date' => $date,
            'print' => '1',
        ]),
    ];
}

/**
 * Cadence lines for a delivery date: which bake covers it, and which other
 * delivery days share that bake. Does not move the sheet onto those days.
 *
 * @return list<string>
 */
function bakery_pmd_print_cover_lines(string $deliveryDate): array
{
    $lines = [];
    foreach (bakery_production_cadence_delivery_legs($deliveryDate) as $leg) {
        $dayLabels = [];
        foreach ($leg['cover_dates'] as $coverDate) {
            $dayLabels[] = date('D n/j', strtotime((string)$coverDate));
        }
        $lines[] = bakery_t('production_cadence.day_chip', [
            'family' => bakery_t('production_cadence.family.' . (string)$leg['family']),
            'bake' => date('l, F j', strtotime((string)$leg['bake_date'])),
            'days' => implode(', ', $dayLabels),
        ]);
    }
    return $lines;
}

function bakery_pmd_print_route_label(string $deliveryDate): string
{
    return implode(' · ', bakery_pmd_print_cover_lines($deliveryDate));
}

function bakery_pmd_print_title_key(array $board): string
{
    if (($board['bake_source'] ?? '') === 'last_entered') {
        return 'production_manager.print_title_draft';
    }
    if (!empty($board['committed'])) {
        return 'production_manager.print_title_released';
    }
    return 'production_manager.print_title_uncommitted';
}

function bakery_pmd_print_footer_key(array $board): string
{
    if (($board['bake_source'] ?? '') === 'last_entered') {
        return 'production_manager.print_footer_draft';
    }
    if (!empty($board['committed'])) {
        return 'production_manager.print_footer_released';
    }
    return 'production_manager.print_footer_demand';
}

/**
 * @param array<string,mixed> $board
 */
function bakery_pmd_print_source_label(array $board): string
{
    if (($board['bake_source'] ?? '') === 'last_entered' && !empty($board['entered_plan']['date_display'])) {
        return bakery_t('production_manager.print_source_entered', [
            'date' => (string)$board['entered_plan']['date_display'],
        ]);
    }
    if (($board['bake_source'] ?? '') === 'committed_plan') {
        return bakery_t('production_manager.source_committed');
    }
    return bakery_t('production_manager.source_demand');
}

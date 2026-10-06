<?php
/**
 * Letter-size Manager bake sheet (batches + quantities).
 * Used by production_manager.php?print=1 — not a second page.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @param array<string,mixed> $board
 */
function bakery_pmd_print_route_label(string $bakeDate): string
{
    $route = date('Y-m-d', strtotime($bakeDate . ' +1 day'));
    return date('l, F j', strtotime($route));
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

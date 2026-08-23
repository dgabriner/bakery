<?php
/**
 * Shared kitchen workflow strip for Production Center, Daily Production, Pack List.
 * Stages come from Daily Run so manager, baker, and packer see the same truth.
 */

require_once __DIR__ . '/daily_run.php';

/**
 * First four Daily Run stages for one delivery date (Demand → Plan → Produce → Pack).
 *
 * @return list<array{key:string,label:string,ui_state:string,summary:string,href:string,action_label:string}>
 */
function bakery_production_workflow_kitchen_stages(PDO $db, string $date): array
{
    $run = bakery_daily_run_build($db, $date);
    $wanted = ['confirm_demand' => true, 'production_plan' => true, 'produce' => true, 'pack' => true];
    $out = [];
    foreach ($run['stages'] as $stage) {
        $key = (string)($stage['key'] ?? '');
        if (!isset($wanted[$key])) {
            continue;
        }
        $out[] = [
            'key' => $key,
            'label' => (string)($stage['label'] ?? $key),
            'ui_state' => (string)($stage['ui_state'] ?? 'not_started'),
            'summary' => (string)($stage['summary'] ?? ''),
            'href' => (string)($stage['href'] ?? ''),
            'action_label' => (string)($stage['action_label'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param list<array{key:string,label:string,ui_state:string,summary:string,href:string,action_label?:string}> $stages
 * @param array{current?:string, compact?:bool, title?:string, lead?:string} $options
 */
function bakery_production_workflow_strip_html(array $stages, array $options = []): string
{
    if ($stages === []) {
        return '';
    }
    $current = (string)($options['current'] ?? '');
    $compact = !empty($options['compact']);
    $title = (string)($options['title'] ?? (function_exists('bakery_t') ? bakery_t('production_workflow.title') : 'Kitchen day'));
    $lead = (string)($options['lead'] ?? (function_exists('bakery_t') ? bakery_t('production_workflow.lead') : ''));
    $aria = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $html = '<section class="pw-strip' . ($compact ? ' pw-strip--compact' : '') . '" aria-label="' . $aria . '">';
    if ($title !== '') {
        $html .= '<div class="pw-strip__head"><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>';
        if ($lead !== '') {
            $html .= '<span>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '<ol class="pw-strip__list">';
    foreach ($stages as $stage) {
        $key = (string)$stage['key'];
        $state = (string)$stage['ui_state'];
        $isCurrent = $current !== '' && $current === $key;
        $href = trim((string)$stage['href']);
        $label = (string)$stage['label'];
        $summary = (string)$stage['summary'];
        $classes = 'pw-strip__step pw-strip__step--' . preg_replace('/[^a-z0-9_-]+/i', '', $state);
        if ($isCurrent) {
            $classes .= ' is-current';
        }
        $inner = '<span class="pw-strip__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        if (!$compact && $summary !== '') {
            $inner .= '<span class="pw-strip__summary">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '<li class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">';
        if ($href !== '' && !$isCurrent) {
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>';
        } else {
            $html .= '<div>' . $inner . '</div>';
        }
        $html .= '</li>';
    }
    $html .= '</ol></section>';
    return $html;
}

/**
 * Shared CSS for the kitchen workflow strip (inline once per page).
 */
function bakery_production_workflow_strip_css(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    return <<<'CSS'
<style>
.pw-strip{margin:0 0 16px;padding:14px 16px;border:1px solid #d7e4da;border-radius:12px;background:linear-gradient(180deg,#f7fbf8 0%,#eef6f0 100%)}
.pw-strip__head{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:baseline;margin:0 0 10px}
.pw-strip__head strong{color:#1d3f2c;font-size:.95rem}
.pw-strip__head span{color:#5a6f61;font-size:.85rem}
.pw-strip__list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.pw-strip__step{border:1px solid #c9d9ce;border-radius:10px;background:#fff;min-width:0}
.pw-strip__step>a,.pw-strip__step>div{display:flex;flex-direction:column;gap:4px;padding:10px 12px;text-decoration:none;color:inherit;min-height:100%;box-sizing:border-box}
.pw-strip__step>a:hover{background:#f3faf5}
.pw-strip__label{font-weight:700;font-size:.86rem;color:#1f4630}
.pw-strip__summary{font-size:.75rem;line-height:1.35;color:#617268}
.pw-strip__step.is-current{border-color:#2f7a4a;box-shadow:0 0 0 2px rgba(47,122,74,.18)}
.pw-strip__step--complete{border-color:#8fceb0;background:#f1faf4}
.pw-strip__step--needs_attention,.pw-strip__step--in_progress{border-color:#e2b46a;background:#fffaf0}
.pw-strip__step--not_started{border-color:#d0d8d2}
.pw-strip__step--unavailable,.pw-strip__step--empty{opacity:.78}
.pw-strip--compact .pw-strip__summary{display:none}
.pw-strip--compact .pw-strip__step>a,.pw-strip--compact .pw-strip__step>div{padding:8px 10px}
@media (max-width:900px){.pw-strip__list{grid-template-columns:1fr 1fr}}
@media (max-width:560px){.pw-strip__list{grid-template-columns:1fr}}
</style>
CSS;
}

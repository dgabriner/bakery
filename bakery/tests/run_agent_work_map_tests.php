<?php
/**
 * File → test → invariant map contracts (no database).
 * Usage: php tests/run_agent_work_map_tests.php
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/agent_work_map.php';
require_once $root . '/includes/agent_craft.php';

$failures = 0;

function map_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL  $label\n";
        $failures++;
        return;
    }
    echo "PASS  $label\n";
}

$map = bakery_agent_work_map();
map_assert('map has production-plan', isset($map['production-plan']));
map_assert('map has invoice-send', isset($map['invoice-send']));
map_assert('map has agent-os', isset($map['agent-os']));
map_assert('map has general', isset($map['general']));

map_assert('resolve canonical slug', bakery_agent_work_map_resolve('production-plan') === 'production-plan');
map_assert('resolve alias', bakery_agent_work_map_resolve('commit-production-plan') === 'production-plan');
map_assert('resolve numbered prompt', bakery_agent_work_map_resolve('20-commit-production-plan') === 'production-plan');
map_assert('resolve family prefix', bakery_agent_work_map_resolve('agent-os-meta') === 'agent-os');
map_assert('resolve unknown is null', bakery_agent_work_map_resolve('totally-new-chat') === null);

$packet = bakery_agent_work_map_packet('invoice-send');
map_assert('packet slug', $packet['slug'] === 'invoice-send');
map_assert('packet has tests', in_array('tests/run_invoice_send_tests.php', $packet['tests'], true));
map_assert('packet has files', in_array('billing_center.php', $packet['files'], true));

$unknown = bakery_agent_work_map_packet(bakery_agent_work_map_resolve('nope'));
map_assert('unknown packet is general', $unknown['slug'] === 'general');

$fromFiles = bakery_agent_work_map_for_files(['bakery/billing_center.php', 'lang/en.php']);
map_assert('files map to invoice-send', in_array('invoice-send', $fromFiles['missions'], true));
map_assert('lang files add i18n test', in_array('tests/run_i18n_tests.php', $fromFiles['tests'], true));

$trust = bakery_agent_doc_trust_order();
map_assert('trust order starts with product context', strpos($trust[0], 'BAKERY_PRODUCT_CONTEXT.md') === 0);
map_assert('trust order mentions archive', strpos(implode(' ', $trust), 'docs/archive/') !== false);

$slugs = bakery_agent_work_map_canonical_slugs();
map_assert('canonical list includes exception-desktop', in_array('exception-desktop', $slugs, true));

map_assert('map has manager-phone', isset($map['manager-phone']));
map_assert('map has customer-portal', isset($map['customer-portal']));
map_assert('resolve manager-phone alias', bakery_agent_work_map_resolve('laura-manager-phone') === 'manager-phone');

$missing = bakery_agent_work_map_unmapped_suites($root . DIRECTORY_SEPARATOR . 'tests');
map_assert('every tests/run_*.php is mapped (' . implode(', ', $missing) . ')', $missing === []);

$scoreFull = bakery_agent_homebase_score_handoff("1. Read files\n2. Decided X\n3. a.php\n4. Baker sees chip\n5. Dated beats standing\n6. run_x_tests.php pass\n7. None\n8. Next: map patch");
map_assert('complete handoff scores 8/8', $scoreFull['complete'] === true && $scoreFull['score'] === 8);
$scoreThin = bakery_agent_homebase_score_handoff("1. Investigated homebase\n8. Next: keep using brief");
map_assert('thin handoff is incomplete', $scoreThin['complete'] === false && $scoreThin['score'] === 2);

$poem = bakery_agent_craft_poem();
map_assert('poem is in the development manual', $poem !== '' && strpos($poem, 'Chat is steam') !== false);
map_assert('stanza is first paragraph', strpos(bakery_agent_craft_stanza(), 'Do not add a morning') !== false);

$suggest = bakery_agent_work_map_suggest('manager.php,lang/en.php');
map_assert('tests-for hits manager-phone', in_array('manager-phone', $suggest['missions'], true));
map_assert('tests-for includes i18n', in_array('tests/run_i18n_tests.php', $suggest['tests'], true));
map_assert('lang files are not map holes', $suggest['unmapped_files'] === []);

$craft = bakery_agent_work_map_suggest('css/agent_homebase.css,AGENTS.md,.cursor/hooks.json,.cursor/hooks/handoff-reminder.ps1,.cursor/hooks/session-brief.ps1,.cursor/skills/agent-homebase/SKILL.md');
map_assert('craft files map to agent-os', in_array('agent-os', $craft['missions'], true));
map_assert('craft files are not map holes', $craft['unmapped_files'] === []);

$manuals = bakery_agent_work_map_suggest('BAKERY_PRODUCT_CONTEXT.md,docs/AGENT_DEVELOPMENT_MANUAL.md');
map_assert('product and craft manuals are not map holes', $manuals['unmapped_files'] === []);

$invoice = bakery_agent_work_map_packet('invoice-send');
map_assert('invoice prompt is shipped', ($invoice['prompt_status'] ?? '') === 'shipped');

// Agent program 2026-09 (docs/prompts/30–64) merged from includes/agent_program_map.php.
$program = bakery_agent_program_work_map();
$core = bakery_agent_work_map_core();
map_assert('program map has 26 missions', count($program) === 26);
map_assert('program slugs do not collide with core slugs', array_intersect_key($program, $core) === []);
map_assert('resolve numbered program prompt', bakery_agent_work_map_resolve('30-agent-env') === 'agent-env');
map_assert('resolve program alias', bakery_agent_work_map_resolve('prompt-44') === 'manager-phone-closeout');
map_assert('program slug beats core family prefix', bakery_agent_work_map_resolve('driver-fast-path') === 'driver-fast-path');
map_assert('manager-phone-closeout does not fold into manager-phone', bakery_agent_work_map_resolve('manager-phone-closeout') === 'manager-phone-closeout');
$programPromptsOk = true;
$programInvariantsOk = true;
foreach ($program as $slug => $mission) {
    if (!is_readable($root . '/' . $mission['prompt'])) {
        $programPromptsOk = false;
        echo "NOTE  missing prompt for $slug: {$mission['prompt']}\n";
    }
    if (!in_array('Close loops; do not add modules or new staff home pages', $mission['invariants'], true)) {
        $programInvariantsOk = false;
    }
}
map_assert('every program mission has a readable prompt file', $programPromptsOk);
map_assert('every program mission carries the common invariants', $programInvariantsOk);
$agentEnv = bakery_agent_work_map_packet('agent-env');
map_assert('agent-env is shipped', ($agentEnv['prompt_status'] ?? '') === 'shipped');
map_assert('gate files map to agent-env', in_array('agent-env', bakery_agent_work_map_for_files(['scripts/run_test_gate.sh'])['missions'], true));

echo $failures === 0 ? "\nAll work-map checks passed\n" : "\n$failures failed\n";
exit($failures > 0 ? 1 : 0);

<?php
/**
 * Agent program 2026-09 — mission lanes for the reliability / mobile navigation /
 * scalability / integration waves (docs/prompts/30–64). Merged into
 * bakery_agent_work_map() so `brief --agent=<slug>` returns a packet per mission.
 *
 * Each entry: files (leased lane), tests (proving suites), invariants, prompt.
 * prompt_status: open | shipped | partial | owner-decision.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_agent_program_common_invariants(): array
{
    return [
        'Close loops; do not add modules or new staff home pages',
        'i18n in lang/en.php and lang/es.php in the same change',
        'Tests on bakerysf_test only; register new suites in includes/agent_work_map.php',
        'Pushing Git ≠ Staging ≠ Live; say what was actually synced',
    ];
}

/**
 * @return array<string, array{
 *   title: string, aliases: list<string>, files: list<string>, tests: list<string>,
 *   invariants: list<string>, bugs: list<string>, prompt: ?string, prompt_status: string
 * }>
 */
function bakery_agent_program_work_map(): array
{
    $common = bakery_agent_program_common_invariants();
    $m = function (string $title, array $aliases, array $files, array $tests, array $invariants, string $prompt, string $status = 'open', array $bugs = []) use ($common): array {
        return [
            'title' => $title,
            'aliases' => $aliases,
            'files' => $files,
            'tests' => $tests,
            'invariants' => array_values(array_unique(array_merge($invariants, $common))),
            'bugs' => $bugs,
            'prompt' => $prompt,
            'prompt_status' => $status,
        ];
    };

    return [
        // ---------------------------------------------------------------- Wave 0
        'agent-env' => $m(
            'Cloud agents can run the test gate',
            ['30-agent-env', 'prompt-30', 'cloud-env', 'test-gate-linux'],
            ['.cursor/environment.json', 'scripts/cloud_agent_install.sh', 'scripts/cloud_agent_start.sh', 'scripts/run_test_gate.sh', 'tests/isolate_test_db.php', 'scripts/run_migrations.php', 'docs/GROK_AND_CLOUD_AGENT_DEPLOY.md', '.cursor/skills/test-gate/SKILL.md'],
            ['tests/run_auth_tests.php', 'tests/run_navigation_tests.php', 'tests/run_schema_compare_tests.php', 'tests/run_local_test_target_guard_tests.php'],
            ['Tests target exactly bakerysf_test on loopback', 'Fixture reset is the cloud fallback; snapshot reset stays the desktop path', 'Desktop-only suites are skipped by name and reported'],
            'docs/prompts/30-agent-env.md',
            'shipped'
        ),
        'docs-truth' => $m(
            'Top-level docs describe the real stack; program missions registered',
            ['31-docs-truth', 'prompt-31', 'readme', 'architecture-doc'],
            ['README.md', 'ARCHITECTURE.md', 'docs/archive/README.md', 'docs/prompts/README.md', 'docs/AGENT_PROGRAM_HANDOFF.md', 'includes/agent_program_map.php', 'includes/agent_work_map.php'],
            ['tests/run_agent_work_map_tests.php', 'tests/run_agent_homebase_tests.php', 'tests/run_deploy_surface_tests.php'],
            ['Code wins over a stale paragraph; then fix the paragraph', 'No Composer / PHPUnit / MVC claims in top-level docs'],
            'docs/prompts/31-docs-truth.md',
            'shipped'
        ),

        // ---------------------------------------------------------------- Wave 1
        'webhook-fail-closed' => $m(
            'Square and Twilio webhooks refuse unsigned traffic',
            ['32-webhook-fail-closed', 'prompt-32', 'square-webhook', 'twilio-webhook'],
            ['square_webhook.php', 'twilio_webhook.php', 'includes/square_invoices.php', 'includes/twilio_config.php'],
            ['tests/run_webhook_fail_closed_tests.php', 'tests/run_square_invoice_tests.php', 'tests/run_text_comms_tests.php', 'tests/run_bread_education_gating_tests.php'],
            ['Signature-checked webhook is the only payment truth', 'Unconfigured signature key → 503, nothing written'],
            'docs/prompts/32-webhook-fail-closed.md',
            'shipped'
        ),
        'edge-entrypoints' => $m(
            'Every root script passes through bakery_enforce_request_security',
            ['33-edge-entrypoints', 'prompt-33', 'oauth-gate', 'ping-leak'],
            ['oauth_setup.php', 'oauth_callback.php', 'setup_directories.php', 'ping.php', 'assets/api/get_route.php', 'includes/auth.php'],
            ['tests/run_edge_entrypoint_tests.php', 'tests/run_auth_tests.php', 'tests/run_deploy_surface_tests.php', 'tests/run_navigation_tests.php'],
            ['Menu hiding is never the only control', 'Diagnostics are administrator-only', '*_api.php answers JSON on auth/CSRF failure'],
            'docs/prompts/33-edge-entrypoints.md',
            'shipped'
        ),
        'error-boundary' => $m(
            'One error boundary; no raw exception text reaches a browser',
            ['34-error-boundary', 'prompt-34', 'exception-handler', 'safe-execute'],
            ['includes/error_boundary.php', 'includes/config.php', 'includes/production_errors.php', 'includes/database.php', 'includes/common_functions.php', 'customers.php', 'daily_orders.php', 'production_center.php', 'complete_delivery.php'],
            ['tests/run_error_boundary_tests.php', 'tests/run_i18n_tests.php', 'tests/run_integrity_tests.php'],
            ['PDO messages are logged with an error_id, never shown', 'safe_execute cannot return false on a failed write', 'BAKERY_SHOW_ERRORS only when IS_LOCAL'],
            'docs/prompts/34-error-boundary.md',
            'shipped'
        ),
        'money-transactions' => $m(
            'Invoice send and Square writes are transactional (outbox pattern)',
            ['35-money-transactions', 'prompt-35', 'invoice-outbox'],
            ['includes/billing.php', 'includes/square_invoices.php', 'billing_center.php'],
            ['tests/run_invoice_send_tests.php', 'tests/run_square_invoice_tests.php', 'tests/run_customer_billing_tests.php'],
            ['Never price historical invoices from live products.price', 'Billing Center marks invoiced; it does not invent amounts', 'A send row exists for every mail attempt; status never claims sent without an attempt'],
            'docs/prompts/35-money-transactions.md',
            'partial'
        ),
        'js-safety-net' => $m(
            'Global browser error reporting and visible fetch failures',
            ['36-js-safety-net', 'prompt-36', 'unhandledrejection', 'client-errors'],
            ['includes/shell.js', 'client_error_api.php', 'includes/driver_delivery.js', 'includes/driver_route_prep.js', 'includes/global_tracking.js', 'includes/portal_orders.js', 'login_history.php'],
            ['tests/run_driver_photo_ui_tests.php', 'tests/run_driver_workflow_tests.php', 'tests/run_login_history_tests.php', 'tests/run_i18n_tests.php'],
            ['Every await fetch has a catch and a visible status', 'Beacon endpoint is login-gated and rate-limited'],
            'docs/prompts/36-js-safety-net.md'
        ),
        'characterize-core' => $m(
            'Characterization suites for daily orders, standing orders, production center, delivery confirm',
            ['37-characterize-core', 'prompt-37', 'characterization-core'],
            ['tests/run_daily_orders_page_tests.php', 'tests/run_standing_orders_manager_tests.php', 'tests/run_production_center_tests.php', 'tests/run_complete_delivery_tests.php'],
            ['tests/run_daily_orders_page_tests.php', 'tests/run_standing_orders_manager_tests.php', 'tests/run_production_center_tests.php', 'tests/run_complete_delivery_tests.php'],
            ['Dated beats standing per customer', 'Re-generation preserves dated edits unless overwrite_changed', 'Confirm is one transaction; door credits return once'],
            'docs/prompts/37-characterize-core.md'
        ),

        // ---------------------------------------------------------------- Wave 2
        'nav-catalog-roles' => $m(
            'Navigation catalog drives role allowlists; manager More ≤ 8',
            ['40-nav-catalog-roles', 'prompt-40', 'catalog-roles', 'default-deny'],
            ['includes/navigation_catalog.php', 'includes/nav.php', 'includes/auth.php', 'includes/header.php', 'cashier_add_product.php', 'module_guide.php', 'docs/MODULE_ACCESS_GUIDE.md'],
            ['tests/run_navigation_tests.php', 'tests/run_auth_tests.php', 'tests/run_cashier_role_tests.php', 'tests/run_i18n_tests.php'],
            ['Server-side bakery_require_role; menu hiding is not security', 'Unlisted scripts default to administrator only'],
            'docs/prompts/40-nav-catalog-roles.md'
        ),
        'touch-tokens' => $m(
            '44px touch targets and one token set across staff, portal, SFB',
            ['41-touch-tokens', 'prompt-41', 'tap-targets', 'tokens'],
            ['css/tokens.css', 'css/base.css', 'css/nav.css', 'css/manager_phone.css', 'includes/portal_styles.php', 'includes/sfb_styles.php', 'includes/sfb_tabs.php', 'tests/run_touch_target_tests.php'],
            ['tests/run_touch_target_tests.php', 'tests/run_navigation_tests.php', 'tests/run_manager_phone_tests.php'],
            ['--sf-touch-min is the floor for every interactive control in shared chrome', 'Do not reintroduce viewport scroll listeners (mobile shake fix)'],
            'docs/prompts/41-touch-tokens.md'
        ),
        'driver-fast-path' => $m(
            'Driver stop wizard: photo → confirm → next',
            ['42-driver-fast-path', 'prompt-42', 'stop-wizard'],
            ['driver.php', 'includes/driver_delivery.js', 'css/driver.css', 'includes/global_tracking.js'],
            ['tests/run_driver_workflow_tests.php', 'tests/run_driver_photo_ui_tests.php', 'tests/run_credit_return_tests.php'],
            ['Every write through bakery_confirm_delivery', 'billable_pieces = delivered_pieces - credits_taken_back', 'Driver UX is the reference implementation — change it surgically'],
            'docs/prompts/42-driver-fast-path.md'
        ),
        'driver-offline-queue' => $m(
            'IndexedDB outbox with idempotent photo/confirm endpoints',
            ['43-driver-offline-queue', 'prompt-43', 'offline-driver', 'outbox'],
            ['includes/driver_offline_outbox.js', 'includes/driver_delivery.js', 'upload_driver_photo.php', 'complete_delivery.php', 'database/schema/077_delivery_client_request_id.sql'],
            ['tests/run_driver_workflow_tests.php', 'tests/run_driver_photo_ui_tests.php', 'tests/run_credit_return_tests.php', 'tests/run_schema_compare_tests.php'],
            ['Same client_request_id twice → one confirmation, one set of movements', 'No service-worker page caching'],
            'docs/prompts/43-driver-offline-queue.md'
        ),
        'manager-phone-closeout' => $m(
            'Manager phone Routes / Closeout cards; route_manager.php desktop-only',
            ['44-manager-phone-closeout', 'prompt-44', 'phone-closeout'],
            ['includes/manager_phone.php', 'css/manager_phone.css', 'manager.php', 'route_manager.php', 'route_closeout.php'],
            ['tests/run_manager_phone_tests.php', 'tests/run_route_manager_cash_tests.php', 'tests/run_route_manager_pickup_tests.php'],
            ['loaded = net delivered + van leftover + waste + door credits', 'Reuse bakery_inventory_reconcile_driver_load; no second closeout path'],
            'docs/prompts/44-manager-phone-closeout.md'
        ),
        'kitchen-one-screen' => $m(
            'Baker Today with Mix / Bake / Pack segments; Pack List phone mode',
            ['45-kitchen-one-screen', 'prompt-45', 'baker-today'],
            ['includes/kitchen_segments.php', 'includes/nav.php', 'baker_mix.php', 'production.php', 'pack_list.php', 'includes/pack_list.php'],
            ['tests/run_baker_mix_tests.php', 'tests/run_production_confirm_tests.php', 'tests/run_pack_list_tests.php'],
            ['Bakers never open Production Center', 'Pack check-off semantics and inventory math unchanged'],
            'docs/prompts/45-kitchen-one-screen.md'
        ),
        'sfb-bottom-nav' => $m(
            'SF Baker bottom tabs + More replacing the eight-tab strip',
            ['46-sfb-bottom-nav', 'prompt-46', 'sfb-nav'],
            ['includes/sfb_tabs.php', 'includes/sfb_styles.php', 'includes/portal_nav.js'],
            ['tests/run_sf_baker_tests.php', 'tests/run_sfb_content_trust_tests.php'],
            ['Origin badges and gating unchanged'],
            'docs/prompts/46-sfb-bottom-nav.md'
        ),

        // ---------------------------------------------------------------- Wave 3
        'extract-assets' => $m(
            'Move inline CSS/JS out of the six largest pages',
            ['50-extract-assets', 'prompt-50', 'inline-css', 'god-pages'],
            ['route_manager.php', 'standing_orders_manager.php', 'driver_overview.php', 'driver_assignment.php', 'customer_schedule.php', 'standing_routes.php', 'css/route_manager.css', 'css/standing_orders_manager.css', 'css/driver_overview.css', 'css/driver_assignment.css', 'css/customer_schedule.css', 'css/standing_routes.css', 'includes/route_manager.js', 'includes/standing_orders_manager.js', 'includes/driver_overview.js', 'includes/driver_assignment.js', 'includes/customer_schedule.js', 'includes/standing_routes.js', 'scripts/deploy_manifest.ps1'],
            ['tests/run_route_manager_cash_tests.php', 'tests/run_route_manager_pickup_tests.php', 'tests/run_deploy_surface_tests.php', 'tests/run_status_alignment_tests.php'],
            ['Zero behavior change per extraction', 'New assets listed in scripts/deploy_manifest.ps1'],
            'docs/prompts/50-extract-assets.md'
        ),
        'split-actions' => $m(
            'Action handlers become includes/<page>_actions.php + thin *_api.php',
            ['51-split-actions', 'prompt-51', 'actions-split'],
            ['includes/daily_orders_actions.php', 'includes/standing_orders_manager_actions.php', 'includes/driver_assignment_actions.php', 'includes/production_center_actions.php', 'daily_orders_api.php', 'standing_orders_api.php', 'driver_assignment_api.php', 'production_center_api.php'],
            ['tests/run_daily_orders_page_tests.php', 'tests/run_standing_orders_manager_tests.php', 'tests/run_production_center_tests.php', 'tests/run_deploy_surface_tests.php'],
            ['Pages authorize, validate, call includes/, render', 'Characterization suites stay green unchanged'],
            'docs/prompts/51-split-actions.md'
        ),
        'one-mutation-path' => $m(
            'Single helpers for standing upsert, daily find-or-create, recompute total',
            ['52-one-mutation-path', 'prompt-52', 'order-mutations'],
            ['includes/customer_order_mutations.php', 'includes/daily_order_generation.php', 'standing_orders.php', 'bread_distribution.php', 'product_distribution.php', 'includes/customer_portal.php', 'includes/driver_assignments.php', 'includes/production_assign.php', 'includes/pan_dulce_standards.php', 'includes/survey_store_verify.php'],
            ['tests/run_operating_demand_tests.php', 'tests/run_customer_order_power_tests.php', 'tests/run_golden_day_qa.php', 'tests/run_tomorrow_confirmed_tests.php', 'tests/run_integrity_tests.php'],
            ['Dated beats standing per customer', 'Standing edits never rewrite past dated orders; dated edits never write standing'],
            'docs/prompts/52-one-mutation-path.md'
        ),
        'hot-path-queries' => $m(
            'Batch N+1 loops; standing_routes day index; shared PDO',
            ['53-hot-path-queries', 'prompt-53', 'n-plus-one', 'indexes'],
            ['includes/driver_assignments.php', 'driver_load.php', 'production.php', 'database/schema/078_standing_routes_day_index.sql'],
            ['tests/run_driver_workflow_tests.php', 'tests/run_status_alignment_tests.php', 'tests/run_production_confirm_tests.php', 'tests/run_schema_compare_tests.php'],
            ['(driver_id, delivery_date, route_order) stays unique', 'Route build issues O(1) statements'],
            'docs/prompts/53-hot-path-queries.md'
        ),
        'gate-scaling' => $m(
            'Mapped-suite gate mode and CI without the laptop',
            ['54-gate-scaling', 'prompt-54', 'ci', 'github-actions'],
            ['scripts/run_test_gate.sh', '.github/workflows/test-gate.yml', 'includes/agent_work_map.php', 'docs/GROK_AND_CLOUD_AGENT_DEPLOY.md'],
            ['tests/run_agent_work_map_tests.php', 'tests/run_local_test_target_guard_tests.php'],
            ['CI never deploys, never holds SFTP secrets', 'CI green ≠ Staging ≠ Live'],
            'docs/prompts/54-gate-scaling.md'
        ),
        'product-boundaries' => $m(
            'Prefixed tables with FKs to customers; no new core columns without owner approval',
            ['55-product-boundaries', 'prompt-55', 'schema-boundaries'],
            ['BAKERY_PRODUCT_CONTEXT.md', 'ARCHITECTURE.md', '.opencode/agent/ox-reviewer.md', 'tests/run_schema_compare_tests.php', 'includes/schema_migration_numbers.php'],
            ['tests/run_schema_compare_tests.php', 'tests/run_agent_work_map_tests.php'],
            ['customers is the identity hub; product families add sfb_*/square_*/text_* style tables'],
            'docs/prompts/55-product-boundaries.md'
        ),

        // ---------------------------------------------------------------- Wave 4
        'overnight-cron' => $m(
            'Overnight demand + digest cron verified from the app',
            ['60-overnight-cron', 'prompt-60', 'cron', 'demand-cron'],
            ['scripts/demand_scheduler.php', 'scripts/staff_alert_digest.php', 'health_deploy.php', 'includes/dashboard_command_center.php', 'includes/staff_alerts.php', 'docs/CRON_KIT.md'],
            ['tests/run_demand_scheduler_tests.php', 'tests/run_staff_alert_tests.php', 'tests/run_tomorrow_confirmed_tests.php'],
            ['Page load still fills the horizon without the cron', 'Dashboard is honest about stale overnight runs'],
            'docs/prompts/60-overnight-cron.md'
        ),
        'settlement-story' => $m(
            'One settlement row per delivery; COD turn-in recorded',
            ['61-settlement-story', 'prompt-61', 'cod-turnin', 'money-phase-2'],
            ['billing_center.php', 'includes/billing.php', 'includes/billing_aging.php', 'includes/billing_panel_invoices.php', 'includes/cod_turnins.php', 'route_manager.php', 'includes/manager_phone.php'],
            ['tests/run_invoice_send_tests.php', 'tests/run_square_invoice_tests.php', 'tests/run_route_manager_cash_tests.php', 'tests/run_customer_billing_tests.php'],
            ['Billing Center does not invent amounts', 'Ledger table only after an owner Decided'],
            'docs/prompts/61-settlement-story.md',
            'owner-decision'
        ),
        'engagement-writeback' => $m(
            'Survey → Driver Assignment; failed stop → text + credit handoff',
            ['62-engagement-writeback', 'prompt-62', 'writeback'],
            ['includes/survey_store_verify.php', 'includes/surveys.php', 'survey.php', 'includes/driver_assignments.php', 'includes/delivery_recovery.php', 'includes/exception_desk.php', 'includes/text_comms.php', 'includes/customer_delivery_issues.php'],
            ['tests/run_survey_store_verify_tests.php', 'tests/run_survey_route_order_tests.php', 'tests/run_failed_stop_recovery_tests.php', 'tests/run_text_comms_tests.php', 'tests/run_exception_desk_tests.php'],
            ['Failed-stop rules in docs/FAILED_STOP_RECOVERY_MODEL.md are law', 'Texts send only through the Command Center send path', 'No invoice/credit mutation from the desk'],
            'docs/prompts/62-engagement-writeback.md'
        ),
        'ingredient-light' => $m(
            'Ordered / received notes and light stock adjust for ingredients',
            ['63-ingredient-light', 'prompt-63', 'ingredient-notes'],
            ['ingredient_requirements.php', 'includes/ingredient_requirements.php', 'ingredients.php', 'includes/ingredient_purchase_notes.php'],
            ['tests/run_ingredient_planner_tests.php', 'tests/run_formula_units_tests.php'],
            ['No PO, receiving, or lot tracking', 'Step B (stock adjust) only after owner confirms'],
            'docs/prompts/63-ingredient-light.md',
            'owner-decision'
        ),
        'retail-scope-decision' => $m(
            'Owner decides retail cashier scope; agent records it',
            ['64-retail-scope-decision', 'prompt-64', 'retail-scope'],
            ['BAKERY_PRODUCT_CONTEXT.md', 'docs/prompts/64-retail-scope-decision.md'],
            ['tests/run_cashier_role_tests.php'],
            ['Cashier stays photos + catalog until the owner decides otherwise'],
            'docs/prompts/64-retail-scope-decision.md',
            'owner-decision'
        ),
    ];
}

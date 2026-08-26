<?php
/**
 * File → test → invariant map for Cursor missions.
 * Consumed by Agent Homebase brief and the test-gate skill.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_agent_doc_trust_order(): array
{
    return [
        'BAKERY_PRODUCT_CONTEXT.md — product manual',
        'Agent Homebase whiteboard Decided and open bugs — living choices',
        'docs/AGENT_DEVELOPMENT_MANUAL.md — how agents develop',
        'docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md — data, Git, deploy, DreamHost',
        'docs/prompts/ — file-ownership missions; confirm loops against §6–7',
        'docs/archive/ — historical evidence; do not brief from it',
    ];
}

function bakery_agent_work_map_normalize(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/\s+/', '-', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9\-]+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    $s = preg_replace('/^\d{2}-/', '', $s) ?? $s;
    return $s;
}

/**
 * @return array<string, array{
 *   title: string,
 *   aliases: list<string>,
 *   files: list<string>,
 *   tests: list<string>,
 *   invariants: list<string>,
 *   bugs: list<string>,
 *   prompt: ?string
 * }>
 */
function bakery_agent_work_map(): array
{
    return [
        'production-plan' => [
            'title' => 'Committed production plan reaches Daily Production',
            'aliases' => ['commit-production-plan', '20-commit-production-plan', 'prompt-20'],
            'files' => [
                'production_center.php',
                'production.php',
                'pack_list.php',
                'pan_dulce_quantities.php',
                'product_manager_plan.php',
                'includes/production_plan.php',
                'includes/production_assign.php',
                'includes/production_cadence.php',
                'includes/production_workflow_strip.php',
                'includes/demand_confirmation.php',
                'includes/daily_run.php',
                'includes/dashboard_command_center.php',
                'includes/navigation_catalog.php',
                'includes/operational_timeline.php',
                'includes/product_pack_yields.php',
                'includes/product_manager_plan.php',
                'database/schema/052_product_pack_yields.sql',
                'database/schema/059_bolillo_and_gallon_estimates.sql',
                'database/schema/060_mantecada_batch_and_piece_weights.sql',
                'scripts/save_kitchen_note_plan.php',
                'css/dashboard.css',
                'docs/prompts/20-commit-production-plan.md',
            ],
            'tests' => [
                'tests/run_production_plan_commit_tests.php',
                'tests/run_production_assign_tests.php',
                'tests/run_production_cut_tests.php',
                'tests/run_production_cadence_tests.php',
                'tests/run_golden_day_qa.php',
                'tests/run_production_confirm_tests.php',
                'tests/run_product_pack_yield_tests.php',
                'tests/run_product_manager_plan_tests.php',
            ],
            'invariants' => [
                '§4 dated beats standing per customer',
                'Demand via bakery_operating_demand_*',
                'Post-commit demand raises production_plan_drift; bake sheet does not auto-rewrite',
                'Friday pan dulce bake covers Sat-Sun-Mon; Sour Flour is Tue/Fri plus Sunday-for-Monday',
                'Production Center assigns from the bake: standing default, dated one-off optional',
                'Produce stage measures against committed bake when committed',
                'Production Center is the Production Manager hub — no second master page',
            ],
            'bugs' => ['plan-not-on-bake-sheet', 'additive-production'],
            'prompt' => 'docs/prompts/20-commit-production-plan.md',
            'prompt_status' => 'shipped',
        ],
        'invoice-send' => [
            'title' => 'Canonical invoice send from Billing Center',
            'aliases' => ['canonical-invoice-send', '21-canonical-invoice-send', 'prompt-21', 'billing'],
            'files' => [
                'billing_center.php',
                'includes/billing.php',
                'includes/square_invoices.php',
                'database/schema/055_square_invoices.sql',
                'database/schema/056_square_webhook_invoice_index.sql',
                'includes/billing_aging.php',
                'includes/billing_panel_invoices.php',
                'customer_record.php',
                'docs/SQUARE_INVOICING.md',
                'billing_api.php',
                'square_webhook.php',
                'customer_invoice.php',
            ],
            'tests' => [
                'tests/run_invoice_send_tests.php',
                'tests/run_square_invoice_tests.php',
                'tests/run_customer_billing_tests.php',
            ],
            'invariants' => [
                'Never price historical invoices from live products.price',
                'Billing Center marks invoiced; it does not invent amounts',
                'Do not extend quarantined generators',
            ],
            'bugs' => ['invoice-send-gap', 'legacy-invoice-live-price'],
            'prompt' => 'docs/prompts/21-canonical-invoice-send.md',
            'prompt_status' => 'shipped',
        ],
        'credits-returns' => [
            'title' => 'Door credits as finished-goods returns',
            'aliases' => ['credits-as-returns', '22-credits-as-returns', 'prompt-22'],
            'files' => [
                'complete_delivery.php',
                'includes/product_inventory.php',
                'route_closeout.php',
            ],
            'tests' => [
                'tests/run_credit_return_tests.php',
                'tests/run_golden_day_qa.php',
            ],
            'invariants' => [
                'Delivery confirmation creates the billable snapshot',
                'Credits taken back post FG return at confirm; closeout must not double-count',
            ],
            'bugs' => ['credits-not-returned'],
            'prompt' => 'docs/prompts/22-credits-as-returns.md',
            'prompt_status' => 'shipped',
        ],
        'exception-connections' => [
            'title' => 'Exception chips and round-trips on existing screens',
            'aliases' => ['10-exception-connections', 'prompt-10'],
            'files' => [
                'includes/operational_exceptions.php',
                'includes/manager_mode.php',
                'includes/dashboard_command_center.php',
                'index.php',
                'daily_run.php',
            ],
            'tests' => [
                'tests/run_exception_connection_tests.php',
                'tests/run_manager_mode_tests.php',
            ],
            'invariants' => [
                'Completing exception work never hides a still-true operational fact',
                'Chips on the screen where the decision happens',
            ],
            'bugs' => ['no-staff-alerts'],
            'prompt' => 'docs/prompts/10-exception-connections.md',
            'prompt_status' => 'shipped',
        ],
        'exception-mobile' => [
            'title' => 'Thumb-first mobile exception desk',
            'aliases' => ['11-exception-mobile', 'exception-desk', 'prompt-11'],
            'files' => [
                'includes/manager_mode.php',
                'includes/operational_exceptions.php',
            ],
            'tests' => [
                'tests/run_exception_desk_tests.php',
                'tests/run_failed_stop_recovery_tests.php',
            ],
            'invariants' => [
                'Completing exception work never hides a still-true operational fact',
                'Failed delivery deep-links to Manager recovery',
            ],
            'bugs' => ['no-staff-alerts'],
            'prompt' => 'docs/prompts/11-exception-mobile.md',
            'prompt_status' => 'shipped',
        ],
        'exception-desktop' => [
            'title' => 'Desktop exception workshop',
            'aliases' => ['12-exception-desktop', 'exception-workshop', 'prompt-12'],
            'files' => [
                'includes/exception_workshop.php',
                'includes/manager_mode.php',
            ],
            'tests' => [
                'tests/run_exception_workshop_tests.php',
                'tests/run_manager_mode_tests.php',
            ],
            'invariants' => [
                'Completing exception work never hides a still-true operational fact',
                'Do not invent a ticketing product',
            ],
            'bugs' => ['no-staff-alerts'],
            'prompt' => 'docs/prompts/12-exception-desktop.md',
            'prompt_status' => 'shipped',
        ],
        'staff-alerts' => [
            'title' => 'Staff alert bell over live exceptions and owned work',
            'aliases' => ['alerts-bell', 'no-staff-alerts'],
            'files' => [
                'includes/staff_alerts.php',
                'includes/staff_alerts.js',
                'staff_alerts_api.php',
                'includes/nav.php',
                'includes/header.php',
                'includes/auth.php',
                'includes/dashboard_command_center.php',
                'css/nav.css',
                'scripts/staff_alert_digest.php',
            ],
            'tests' => [
                'tests/run_staff_alert_tests.php',
                'tests/run_i18n_tests.php',
            ],
            'invariants' => [
                'Alerts derive from LIVE exceptions; completing work never suppresses a still-true fact',
                'An assignment whose fact is gone stops pinging',
                'Bell is progressive enhancement — hidden without data, never a broken control',
            ],
            'bugs' => ['no-staff-alerts'],
        ],
        'text-comms' => [
            'title' => 'Twilio texting command center over one SMS ledger',
            'aliases' => ['texting-command-center', 'sms-center', 'twilio'],
            'files' => [
                'includes/twilio_config.php',
                'includes/text_comms.php',
                'includes/text_comms_media.php',
                'includes/surveys.php',
                'text_comms.php',
                'text_comms_api.php',
                'text_media.php',
                'survey.php',
                'twilio_webhook.php',
                'database/schema/057_text_messages.sql',
                'database/schema/058_text_media.sql',
                'database/schema/061_surveys.sql',
                'database/schema/062_surveys_custom.sql',
                'scripts/test_twilio_connection.php',
                'scripts/text_send.php',
                'scripts/deploy_manifest.ps1',
                '.env.example',
                'lang/en.php',
                'lang/es.php',
            ],
            'tests' => [
                'tests/run_text_comms_tests.php',
                'tests/run_text_comms_media_tests.php',
                'tests/run_survey_tests.php',
                'tests/run_i18n_tests.php',
            ],
            'invariants' => [
                'No credentials means recorded-only ledger rows, never a silent pretend-send',
                'Every outbound attempt (sent or failed) leaves exactly one ledger row',
                'Sending happens only through text_comms.php; the API is read-only',
                'Ops one-off sends go through scripts/text_send.php, never temp scripts or raw curl',
                'Webhook signature validation defaults on whenever an auth token exists',
                'Command Center shows customer, test, and general texts from the same ledger',
                'MMS media is stored under storage/text_media and served only through role-gated text_media.php',
                'History sync upserts by twilio sid; re-running never duplicates',
            ],
            'bugs' => [],
        ],
        'sfb-origin' => [
            'title' => 'SF Baker origin column and ops firewall',
            'aliases' => ['chief-engineer', '00-chief-engineer', 'prompt-00'],
            'files' => [
                'includes/sf_baker.php',
                'docs/sfb_origin_contract.md',
            ],
            'tests' => [
                'tests/run_sfb_origin_tests.php',
                'tests/run_sf_baker_tests.php',
            ],
            'invariants' => [
                'Single write path bakery_sfb_*',
                'Synthetics never receive standing orders, zones, routes, or invoices',
            ],
            'bugs' => [],
            'prompt' => 'docs/prompts/00-chief-engineer.md',
        ],
        'sfb-agent' => [
            'title' => 'SFAdmin CLI and synthetic studio',
            'aliases' => ['01-agent-synthetic-world', 'agent-synthetic-world', 'prompt-01'],
            'files' => [
                'scripts/sfb_agent.php',
                'includes/sfb_agent.php',
                'includes/sfb_personas.php',
            ],
            'tests' => [
                'tests/run_sfb_agent_tests.php',
                'tests/run_sfb_studio_clock_tests.php',
            ],
            'invariants' => [
                'Default bakerysf_test; never seed bakerysf_local',
                'Eval rejects unlabeled-human claims and wholesale secrets',
            ],
            'bugs' => [],
            'prompt' => 'docs/prompts/01-agent-synthetic-world.md',
        ],
        'sfb-community' => [
            'title' => 'SF Baker community UI',
            'aliases' => ['02-community-product', 'prompt-02'],
            'files' => [
                'includes/sf_baker.php',
            ],
            'tests' => [
                'tests/run_sf_baker_tests.php',
                'tests/run_sfb_content_trust_tests.php',
            ],
            'invariants' => [
                'Humans use the portal; synthetics never need the GUI',
                'Do not add a second write path around bakery_sfb_*',
            ],
            'bugs' => [],
            'prompt' => 'docs/prompts/02-community-product.md',
        ],
        'sfb-trust' => [
            'title' => 'SF Baker content, trust, and eval',
            'aliases' => ['03-content-trust-quality', 'prompt-03'],
            'files' => [
                'includes/sfb_library.php',
                'includes/sfb_synthetic_eval.php',
                'docs/sfb_synthetic_eval.md',
            ],
            'tests' => [
                'tests/run_sfb_content_trust_tests.php',
            ],
            'invariants' => [
                'Process facts required on synthetic posts',
                'No invented wholesale secrets',
            ],
            'bugs' => [],
            'prompt' => 'docs/prompts/03-content-trust-quality.md',
        ],
        'bread-education' => [
            'title' => 'Community Bread Education Center (batch builder, learning center, onboarding, payments)',
            'aliases' => ['community-bread-education', 'bread-edu', 'cbec'],
            'files' => [
                'includes/sf_baker.php',
                'sfb_dashboard.php',
                'sfb_formulas.php',
                'sfb_batch.php',
                'sfb_batches.php',
                'sfb_shared_batch.php',
                'includes/sfb_community_bake_card.php',
                'includes/sfb_photo_handler.php',
                'sfb_resources.php',
                'includes/sfb_library.php',
                'includes/sfb_library_panel.php',
                'customer_login.php',
                'qr_login.php',
                'includes/customer_portal.php',
                'includes/square_invoices.php',
                'includes/square_config.php',
                'square_webhook.php',
                'billing_center.php',
                'database/schema/062_bread_education.sql',
                'database/schema/063_bread_education_learning.sql',
                'database/schema/064_bread_education_invites.sql',
                'database/schema/066_bread_education_payments.sql',
                'database/schema/068_bread_education_gating.sql',
                'database/schema/069_course_template_formula.sql',
                'sfb_lesson.php',
                'sfb_media.php',
                'sfb_admin_learn.php',
                'sfb_join.php',
                'sfb_offerings.php',
                'includes/sfb_tabs.php',
                'starter.php',
                'database/schema/070_starter_jar_orders.sql',
                'database/schema/071_bread_education_purchase_home.sql',
                'database/schema/072_first_loaf_kit.sql',
                'breadeducation/index.html',
                'breadeducation/start/first-loaf-shopping.html',
                'breadeducation/classes/classes.html',
                'breadeducation/classes/corporate-workshops.html',
                'breadeducation/',
                'scripts/push_breadeducation_sftp.ps1',
                'scripts/seed_education_demo.php',
                'scripts/export_education_content.php',
                'docs/prompts/23-bread-education-batch-builder.md',
                'docs/prompts/24-bread-education-learning-center.md',
                'docs/prompts/25-home-base-onboarding.md',
                'docs/prompts/26-education-payments-connect.md',
            ],
            'tests' => [
                'tests/run_sf_baker_tests.php',
                'tests/run_sfb_content_trust_tests.php',
                'tests/run_customer_phone_pin_signup_tests.php',
                'tests/run_invoice_send_tests.php',
                'tests/run_bread_education_tests.php',
                'tests/run_bread_education_gating_tests.php',
                'tests/run_breadeducation_static_surface_tests.php',
                'tests/run_education_copy_parity_tests.php',
                'tests/run_synthetic_refusal_tests.php',
                'tests/run_starter_jar_tests.php',
                'tests/run_purchase_home_tests.php',
            ],
            'invariants' => [
                'One write path: every education mutation goes through includes/sf_baker.php bakery_sfb_* helpers; no second write path',
                'Media lives under storage/ and streams only through a role-gated page with realpath containment (text_media.php pattern)',
                'Humans only in education surfaces; synthetics never enroll, pay, post progress, or count as students',
                'Signup never creates standing orders, zones, routes, or invoices for anyone',
                'Money honesty: Square refs only, signature-checked webhooks, snapshot pricing at purchase, no card data stored locally',
                'Missing Square credentials record intent only - never a pretend paid state',
                'Fail closed with clear notices when sfb tables or columns are missing',
                'i18n in lang/en.php and lang/es.php under sfb.*',
            ],
            'bugs' => [],
            'prompt' => 'docs/prompts/23-bread-education-batch-builder.md',
            'prompt_status' => 'shipped',
        ],
        'agent-os' => [
            'title' => 'Agent Homebase and development agent OS',
            'aliases' => ['agent-os-meta', 'agent-homebase', 'homebase', 'learning-studio'],
            'files' => [
                'includes/agent_homebase.php',
                'includes/agent_work_map.php',
                'includes/agent_homebase_seed.php',
                'includes/agent_craft.php',
                'scripts/agent_homebase.php',
                'agent_homebase.php',
                'css/agent_homebase.css',
                'docs/AGENT_DEVELOPMENT_MANUAL.md',
                'AGENTS.md',
                '.cursor/hooks.json',
                '.cursor/hooks/handoff-reminder.ps1',
                '.cursor/hooks/session-brief.ps1',
                '.cursor/hooks/session-brief.cmd',
                '.cursor/skills/agent-homebase/SKILL.md',
                '.opencode/opencode.json',
                '.opencode/skills/test-gate/SKILL.md',
            ],
            'tests' => [
                'tests/run_agent_homebase_tests.php',
                'tests/run_agent_work_map_tests.php',
                'tests/run_i18n_tests.php',
                'tests/run_characterization.php',
                'tests/run_client_cache_tests.php',
            ],
            'invariants' => [
                'Chat is not the system of record',
                'Do not add a new module for agent craft',
                'Craft ledger is bakerysf_stage_local; tests wipe bakerysf_test',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'data-environment' => [
            'title' => 'Data environment, backups, staging, Git',
            'aliases' => ['data-env', 'stabilization', 'staging', 'deploy'],
            'files' => [
                'includes/test_target_guard.php',
                'scripts/verify_local_env.php',
                'includes/config.php',
                'includes/header.php',
                'includes/client_refresh.php',
                'includes/client_refresh.js',
                'includes/auto_push_control.php',
                'includes/auto_push_control.js',
                'auto_push_api.php',
                'includes/schema_inventory.php',
                'includes/schema_migration_numbers.php',
                'includes/hosted_migration_approval.php',
                'includes/hosted_migration_runtime.php',
                'schema_status.php',
                'migration_status.php',
                'database/schema/051_live_ops_catchup.sql',
                'database/schema/053_live_product_pack_yields.sql',
                'database/schema/054_live_product_pack_yields_mysql_compat.sql',
                'database/schema/056_square_webhook_invoice_index.sql',
                'scripts/apply_production_ops_catchup.php',
                'scripts/apply_live_schema_forward.php',
                'scripts/run_migrations.php',
                'scripts/next_schema_migration.php',
                'scripts/hosted_migration_worker.php',
                'manager.php',
                'css/manager.css',
                'scripts/refresh_local_from_snapshot.php',
                'docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md',
                'docs/HOSTED_PROMOTION.md',
                'docs/GROK_AND_CLOUD_AGENT_DEPLOY.md',
                'docs/DEV_WORKFLOW.md',
                'docs/PRODUCTION_DEPLOY.md',
                'includes/staging_live_approval.php',
                'scripts/deploy_manifest.ps1',
            'scripts/push_sftp_stage.ps1',
            'scripts/sftp_upload.py',
                'scripts/push_sftp.ps1',
                'scripts/snapshot_dreamhost_staging.php',
                'scripts/prod_db_cli.php',
                'docs/PHASE4_STAGING_AUTO_DEPLOY.md',
                '.cursor/hooks/auto-push.ps1',
                'deploy_status.php',
                'scripts/hosted_promotion_worker.php',
            ],
            'tests' => [
                'tests/run_local_test_target_guard_tests.php',
                'tests/run_snapshot_workflow_tests.php',
                'tests/run_phase4_auto_deploy_tests.php',
                'tests/run_deploy_surface_tests.php',
                'tests/run_staging_env_tests.php',
                'tests/run_hosted_promotion_tests.php',
                'tests/run_client_cache_tests.php',
                'tests/run_hosted_migration_worker_tests.php',
                'tests/run_release_promotion_tests.php',
                'tests/run_schema_compare_tests.php',
                'tests/run_live_product_pack_yields_migration_tests.php',
            ],
            'invariants' => [
                'Tests target bakerysf_test only',
                'Full copies production → local/staging only',
                'Editor hooks never target live /bake',
                'New schema files take the next unused NNN; do not rename applied ids',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'demo-recorder' => [
            'title' => 'Usage walkthrough MP4s',
            'aliases' => ['walkthroughs', 'demo'],
            'files' => [
                'includes/demo_recorder.php',
                'scripts/demo_record.php',
                'tools/demo-recorder',
            ],
            'tests' => [
                'tests/run_demo_recorder_tests.php',
            ],
            'invariants' => [
                'Record against bakerysf_local only; never bakerysf_test or live',
                'Do not print 4-digit codes',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'driver' => [
            'title' => 'Driver route, confirm, and loads',
            'aliases' => ['driver-workflow', 'driver-contract', 'my-route'],
            'files' => [
                'driver.php',
                'complete_delivery.php',
                'driver_assignment.php',
                'includes/driver_assignments.php',
                'includes/delivery_skip.php',
                'driver_load.php',
            ],
            'tests' => [
                'tests/run_driver_workflow_tests.php',
                'tests/run_driver_photo_ui_tests.php',
                'tests/run_failed_stop_recovery_tests.php',
                'tests/run_status_alignment_tests.php',
            ],
            'invariants' => [
                'Driver Assignment is the canonical route board',
                'Loads move custody, not ownership',
            ],
            'bugs' => ['status-divergence'],
            'prompt' => null,
        ],
        'demand' => [
            'title' => 'Standing vs dated demand',
            'aliases' => ['tomorrow-confirmed', 'demand-flip', 'standing'],
            'files' => [
                'daily_orders.php',
                'includes/demand_review.php',
                'includes/demand_confirmation.php',
                'includes/daily_order_generation.php',
                'includes/production_cadence.php',
                'product_distribution.php',
                'scripts/demand_scheduler.php',
            ],
            'tests' => [
                'tests/run_tomorrow_confirmed_tests.php',
                'tests/run_demand_scheduler_tests.php',
                'tests/run_operating_demand_tests.php',
                'tests/run_demand_visit_compare_tests.php',
            ],
            'invariants' => [
                'Dated beats standing per customer, never all-or-nothing per date',
                'Generation preserves dated quantity edits unless overwrite_changed',
            ],
            'bugs' => ['demand-flip'],
            'prompt' => null,
        ],
        'auth' => [
            'title' => 'Login, CSRF, roles',
            'aliases' => ['login', 'csrf'],
            'files' => [
                'includes/auth.php',
                'login.php',
            ],
            'tests' => [
                'tests/run_auth_tests.php',
                'tests/run_login_history_tests.php',
                'tests/run_navigation_tests.php',
            ],
            'invariants' => [
                'Menu hiding is never the only control',
                'CSRF on POSTs; bakery_require_role on the server',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'manager-phone' => [
            'title' => 'Manager role as a focused phone workspace',
            'aliases' => ['laura-manager-phone', 'manager-phone', 'hq-phone'],
            'files' => [
                'manager.php',
                'includes/manager_phone.php',
                'css/manager_phone.css',
            ],
            'tests' => [
                'tests/run_manager_phone_tests.php',
                'tests/run_manager_mode_tests.php',
            ],
            'invariants' => [
                'Do not invent a second OS for the manager',
                'Completing exception work never hides a still-true operational fact',
            ],
            'bugs' => ['no-staff-alerts'],
            'prompt' => null,
        ],
        'customer-portal' => [
            'title' => 'Customer portal self-service',
            'aliases' => ['portal', 'customer-account'],
            'files' => [
                'customer_portal.php',
                'customer_portal_tip.php',
                'customer_upcoming.php',
                'includes/customer_portal.php',
                'includes/square_config.php',
                'customer_login.php',
                'qr_login.php',
                'starter.php',
            ],
            'tests' => [
                'tests/run_customer_account_tests.php',
                'tests/run_customer_delivery_tests.php',
                'tests/run_customer_notifications_tests.php',
                'tests/run_customer_order_power_tests.php',
                'tests/run_customer_phone_pin_signup_tests.php',
                'tests/run_customer_qr_login_tests.php',
                'tests/run_starter_jar_tests.php',
            ],
            'invariants' => [
                'Portal edits go through the same demand model staff use',
                'Dated beats standing per customer',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'pack-list' => [
            'title' => 'Pack List check-offs and shortages',
            'aliases' => ['pack', 'packing'],
            'files' => [
                'pack_list.php',
                'includes/pack_list.php',
                'includes/product_inventory.php',
                'includes/product_pack_yields.php',
                'database/schema/065_product_pack_boxes.sql',
            ],
            'tests' => [
                'tests/run_pack_list_tests.php',
                'tests/run_product_pack_yield_tests.php',
                'tests/run_i18n_tests.php',
                'tests/run_golden_day_qa.php',
                'tests/run_integrity_tests.php',
            ],
            'invariants' => [
                'Baker home is Daily Production + Pack List',
                'Shortage display uses on-hand + loaded',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'route-tools' => [
            'title' => 'Route summary, manager, and COD cash',
            'aliases' => ['route-manager', 'route-summary', 'cod'],
            'files' => [
                'route_manager.php',
            'includes/route_manager.php',
            'includes/product_pack_yields.php',
            'route_summary.php',
            'includes/zones_catalog.php',
            'driver_overview.php',
            'driver_list.php',
            'customers.php',
            'customer_schedule.php',
            ],
            'tests' => [
                'tests/run_route_manager_cash_tests.php',
                'tests/run_route_summary_tests.php',
                'tests/run_route_manager_pickup_tests.php',
            ],
            'invariants' => [
                'Driver Assignment is the canonical route board',
                'Do not resurrect quarantined route variants',
            ],
            'bugs' => ['status-divergence'],
            'prompt' => null,
        ],
        'ingredient' => [
            'title' => 'Ingredient planner (hints, not POs)',
            'aliases' => ['ingredient-planner', 'formulas'],
            'files' => [
                'ingredient_requirements.php',
                'includes/ingredient_requirements.php',
                'includes/formula_units.php',
            ],
            'tests' => [
                'tests/run_ingredient_planner_tests.php',
                'tests/run_formula_units_tests.php',
            ],
            'invariants' => [
                'Purchase numbers are hints, not purchase orders',
                'No generic ERP receiving module',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'general' => [
            'title' => 'Unscoped bakery coding mission',
            'aliases' => ['cursor-agent', 'anonymous-agent', 'admin', 'broken-windows'],
            'files' => [
                'leads.php',
                'map.php',
            ],
            'tests' => [
                'tests/run_i18n_tests.php',
                'tests/run_integrity_tests.php',
            ],
            'invariants' => [
                'Close loops; do not add modules',
                'i18n in lang/en.php and lang/es.php',
                'Local/test DB only',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
        'surface-hygiene' => [
            'title' => 'Deploy-surface hygiene: quarantine, archive, and root cleanup',
            'aliases' => ['quarantine', 'cleanup', 'root-cleanup'],
            'files' => [
                '.gitignore',
                'docs/QUARANTINE_INVENTORY.md',
                'docs/archive/sql-patches/',
                'includes/nav_historical.php',
                'includes/navigation_catalog.php',
                'includes/auth.php',
                'includes/staging_live_approval.php',
                'setup_directories.php',
                'health_prod.php',
                'health_deploy.php',
            ],
            'tests' => [
                'tests/run_surface_hygiene_tests.php',
                'tests/run_navigation_tests.php',
                'tests/run_i18n_tests.php',
                'tests/run_integrity_tests.php',
                'tests/run_release_promotion_tests.php',
                'tests/run_invoice_send_tests.php',
            ],
            'invariants' => [
                'Legacy invoice redirects stay redirect-only',
                'Do not rename schema migration files (schema_migrations keyed by filename)',
                'No agent deletion without owner approval and a zip archive',
                'i18n in lang/en.php and lang/es.php',
            ],
            'bugs' => [],
            'prompt' => null,
        ],
    ];
}

function bakery_agent_work_map_canonical_slugs(): array
{
    return array_keys(bakery_agent_work_map());
}

function bakery_agent_work_map_resolve(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $norm = bakery_agent_work_map_normalize($raw);
    if ($norm === '') {
        return null;
    }
    $map = bakery_agent_work_map();
    if (isset($map[$norm])) {
        return $norm;
    }
    foreach ($map as $slug => $mission) {
        foreach ($mission['aliases'] as $alias) {
            if ($norm === bakery_agent_work_map_normalize((string)$alias)) {
                return $slug;
            }
        }
    }
    foreach ($map as $slug => $mission) {
        if ($slug === 'general') {
            continue;
        }
        if (strpos($norm, $slug . '-') === 0) {
            return $slug;
        }
        foreach ($mission['aliases'] as $alias) {
            $a = bakery_agent_work_map_normalize((string)$alias);
            if ($a !== '' && strpos($norm, $a . '-') === 0) {
                return $slug;
            }
        }
    }
    return null;
}

function bakery_agent_work_map_packet(?string $slug): array
{
    $map = bakery_agent_work_map();
    if ($slug === null || !isset($map[$slug])) {
        $slug = 'general';
    }
    $mission = $map[$slug];
    $prompt = $mission['prompt'] ?? null;
    $status = $mission['prompt_status'] ?? ($prompt ? 'open' : null);
    return [
        'slug' => $slug,
        'title' => $mission['title'],
        'files' => $mission['files'],
        'tests' => $mission['tests'],
        'invariants' => $mission['invariants'],
        'bugs' => $mission['bugs'],
        'prompt' => $prompt,
        'prompt_status' => $status,
    ];
}

function bakery_agent_work_map_path_matches(string $path, string $pattern): bool
{
    $path = str_replace('\\', '/', strtolower($path));
    $pattern = str_replace('\\', '/', strtolower($pattern));
    $base = basename($pattern);
    if ($base !== '' && substr($path, -strlen($base)) === $base) {
        return true;
    }
    return strpos($path, $pattern) !== false;
}

/**
 * @param list<string> $paths
 */
function bakery_agent_work_map_for_files(array $paths): array
{
    $tests = [];
    $invariants = [];
    $slugs = [];
    foreach (bakery_agent_work_map() as $slug => $mission) {
        if ($slug === 'general') {
            continue;
        }
        foreach ($mission['files'] as $pattern) {
            foreach ($paths as $path) {
                if (bakery_agent_work_map_path_matches((string)$path, (string)$pattern)) {
                    $slugs[] = $slug;
                    $tests = array_merge($tests, $mission['tests']);
                    $invariants = array_merge($invariants, $mission['invariants']);
                    break 2;
                }
            }
        }
    }
    $langTouched = false;
    foreach ($paths as $path) {
        $p = str_replace('\\', '/', strtolower((string)$path));
        if (strpos($p, 'lang/') !== false || preg_match('/lang\\\\(en|es)\.php$/', (string)$path)) {
            $langTouched = true;
            break;
        }
        if (basename($p) === 'en.php' || basename($p) === 'es.php') {
            $langTouched = true;
            break;
        }
    }
    if ($langTouched) {
        $tests[] = 'tests/run_i18n_tests.php';
        $invariants[] = 'i18n in lang/en.php and lang/es.php';
    }
    return [
        'missions' => array_values(array_unique($slugs)),
        'tests' => array_values(array_unique($tests)),
        'invariants' => array_values(array_unique($invariants)),
    ];
}

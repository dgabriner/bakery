# Manager Routes Mission + Login History Trail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strengthen Manager Routes into a mobile mission board (stop order + timing) and make Login History’s full action/activity trail easy to scan.

**Architecture:** Close loops on existing screens only. Add a pure helper that shapes driver mission cards from assignment rows; enrich the phone stop query with times; rewrite Routes card markup/CSS. For Login History, add URL-backed timeline filters, higher caps + offset pagination, session trail loader, and denser action styling.

**Tech Stack:** Flat PHP + MariaDB, existing manager phone + login history helpers, i18n EN/ES, `tests/run_*.php` gate.

## Global Constraints

- Close loops; do not add modules or top-level pages.
- No schema migration for this change.
- i18n in both `lang/en.php` and `lang/es.php` (additive keys only).
- Tests on `bakerysf_test` only.
- Staging SFTP ok; Live only via `--queue-live` (never SFTP `/bake`).

---

### Task 1: Manager phone mission helper + stop query

**Files:**
- Modify: `includes/manager_phone.php`
- Test: `tests/run_manager_phone_tests.php`

**Interfaces:**
- Produces: `bakery_manager_phone_format_stop_time(?string $time): string`
- Produces: `bakery_manager_phone_mission_from_stops(array $stops): array` with keys `phase`, `done`, `left`, `current`, `done_count`, `left_count`, `total`, `last_done_time`, `progress_label`
- Consumes: stop rows with `customer_name`, `delivery_status`, `route_order`, `actual_delivery_time`, `scheduled_delivery_time`

- [ ] **Step 1:** Enrich `bakery_manager_phone_driver_stops` SELECT with times + route_order (already has status).
- [ ] **Step 2:** Add mission helper; sort done vs left; pick current = in_transit else first pending.
- [ ] **Step 3:** Add unit assertions in manager phone tests for the helper.
- [ ] **Step 4:** Commit.

### Task 2: Routes card UI + CSS + i18n

**Files:**
- Modify: `includes/manager_phone.php` (`bakery_manager_phone_render_routes`)
- Modify: `css/manager_phone.css`
- Modify: `lang/en.php`, `lang/es.php`
- Test: `tests/run_manager_phone_tests.php`, `tests/run_i18n_tests.php`

- [ ] **Step 1:** Rewrite driver cards: glance + open Done/Left lists; demote COD below timeline.
- [ ] **Step 2:** Sort driver rows by phase.
- [ ] **Step 3:** Add CSS for mission glance, current stop, done/left sections.
- [ ] **Step 4:** Add EN/ES keys; extend required key list in tests.
- [ ] **Step 5:** Commit.

### Task 3: Login History timeline filters + pagination + session trail

**Files:**
- Modify: `includes/login_history_insights.php`
- Modify: `login_history.php`
- Modify: `css/login_history.css`
- Modify: `lang/en.php`, `lang/es.php`
- Test: `tests/run_login_history_tests.php`

**Interfaces:**
- Produces: filters keys `timeline` (`all|session|navigation|action`), `timeline_offset` (int ≥ 0)
- Produces: `bakery_login_history_load_session_trail(PDO $db, int $auditId, array $ready, int $limit = 200): array`
- Investigation: higher fetch caps; slice window uses offset; server-side kind filter when not `all`

- [ ] **Step 1:** Parse/preserve `timeline` + `timeline_offset` in filters/url.
- [ ] **Step 2:** Adjust investigation loader for kind-aware limits + offset + timeline_has_more.
- [ ] **Step 3:** Wire filter links + Load older in `login_history.php`; denser action CSS.
- [ ] **Step 4:** Session trail helper + expandable UI on records/live rows.
- [ ] **Step 5:** Tests + commit.

### Task 4: Gate, PR, Staging, Live queue

- [ ] Run `bash scripts/run_test_gate.sh --files=includes/manager_phone.php,login_history.php,includes/login_history_insights.php,lang/en.php,lang/es.php,css/manager_phone.css,css/login_history.css`
- [ ] Push branch; open/update PR.
- [ ] `python3 scripts/cloud_agent_stage.py --files … --smoke`
- [ ] `python3 scripts/cloud_agent_stage.py --queue-live --files-live` (no migration).
- [ ] Homebase handoff.

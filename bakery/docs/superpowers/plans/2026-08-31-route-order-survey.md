# Route-order survey Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add no-login `route_order` surveys so drivers (and managers) tap locked-in stores into delivery order and Save writes dated `route_order`.

**Architecture:** New kind + `includes/survey_route_order.php` helpers; `survey.php` dense tap UI; token-safe apply mirroring `bakery_driver_reorder_remaining_stops`; Survey Center mint/copy; EN+ES strings.

**Tech Stack:** PHP, existing `surveys` / `survey_responses` / `daily_order_assignments`, `survey.php` token auth.

## Global Constraints

- No-login when token is open `route_order` (extend public allowlist).
- Spanish-primary survey page; dual-write `lang/en.php` + `lang/es.php`.
- Persist only on Save; standing routes untouched.
- Absolute links via `bakery_survey_link_url` (preserve Live `/bake/`).
- Close loops in Survey Center; no new top-level module.
- Staging-first; Live only via hosted promote when owner asks.

---

## File map

| File | Responsibility |
|------|----------------|
| `includes/survey_route_order.php` | Partition locked/movable, collect order, validate full order, apply routes, SMS/log payload |
| `includes/surveys.php` | kind list, ensure mint, public token, message body |
| `includes/auth.php` | already allows `survey.php` |
| `survey.php` | UI + POST `order_route` |
| `text_comms.php` | Survey Center composer/coverage links |
| `lang/en.php`, `lang/es.php` | Copy |
| `tests/run_survey_route_order_tests.php` | Unit + page contract tests |

---

### Task 1: Pure helpers + failing tests

**Files:**
- Create: `bakery/includes/survey_route_order.php`
- Create: `bakery/tests/run_survey_route_order_tests.php`

- [ ] **Step 1:** Write tests for: partition locked vs movable; default display order; collect tap sequence must include each movable id exactly once; reject duplicates/partial; SMS payload shape.
- [ ] **Step 2:** Run `php tests/run_survey_route_order_tests.php` — expect fail (missing include).
- [ ] **Step 3:** Implement helpers (no DB apply yet beyond pure functions; stub apply signature).
- [ ] **Step 4:** Re-run tests — pure cases pass.
- [ ] **Step 5:** Commit.

### Task 2: Wire kind + ensure + public token

**Files:**
- Modify: `bakery/includes/surveys.php`
- Modify: `bakery/includes/survey_store_verify.php` (`bakery_survey_token_allows_public`) **or** centralize allow in surveys.php if already there

- [ ] **Step 1:** Add `route_order` to `bakery_survey_kinds()`; create/ensure mint like store-verify (HQ driver_id=0 + per driver); allow public token for open `route_order`.
- [ ] **Step 2:** Extend tests for kind + allow-public.
- [ ] **Step 3:** Commit.

### Task 3: Token-safe apply + submit

**Files:**
- Modify: `bakery/includes/survey_route_order.php`
- Possibly read: `bakery/includes/driver_assignments.php` (mirror reorder logic without `bakery_require_role`)

- [ ] **Step 1:** Implement `bakery_survey_route_order_apply` (transaction, locked front, renumber movable).
- [ ] **Step 2:** Implement `bakery_survey_route_order_submit` (record response, apply, HQ SMS).
- [ ] **Step 3:** Add tests with PDO mock or skip-if-no-DB; prefer logic tests on renumber planning if full DB unavailable.
- [ ] **Step 4:** Commit.

### Task 4: survey.php UI + POST

**Files:**
- Modify: `bakery/survey.php`
- Modify: `bakery/lang/en.php`, `bakery/lang/es.php`

- [ ] **Step 1:** Render driver + HQ collapsible dense tap UI; Undo / Start over / Save; `bakery_survey_link_url` for copies.
- [ ] **Step 2:** POST `order_route` → submit → redirect with flash.
- [ ] **Step 3:** Page-source contract tests (strings, JS hooks, no origin+/+ copy bug).
- [ ] **Step 4:** Commit.

### Task 5: Survey Center + i18n nav

**Files:**
- Modify: `bakery/text_comms.php`
- Modify: `bakery/lang/en.php`, `bakery/lang/es.php`
- Modify: `bakery/includes/navigation_catalog.php` descriptions if needed

- [ ] **Step 1:** Composer kind option + ensure links / coverage row for route_order.
- [ ] **Step 2:** Run store-verify + route_order + navigation tests.
- [ ] **Step 3:** Commit + push; Staging SFTP of changed deploy files; do not Live-promote unless asked.

---

## Done when

- Driver and Manager can open token links, tap full sequence, Save, reopen and edit.
- Dated `route_order` matches saved sequence for movable stops.
- 20-store-dense layout: remaining list dominates; Undo/Start over always visible.

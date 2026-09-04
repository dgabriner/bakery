# Agent Program 2026-09 — Handoff (program closed)

Written 2026-09-04. Trust order: `BAKERY_PRODUCT_CONTEXT.md` → Homebase Decided → `docs/AGENT_DEVELOPMENT_MANUAL.md` → this file → `docs/prompts/30..64`.

## Verdict

**All 26 missions (30–64) are closed.** Wave 4 remaining owner judgments were recorded as Decideds on wrap-up. Branch `cursor/sour-flour-agent-program-a061` (PR #20) is the ship vehicle onto `main`, then Staging, then Live.

## Wrap Decideds (2026-09-04)

| Topic | Decision |
|---|---|
| 61 AR/payments ledger | **Computed-only** — no ledger table |
| 61 weekly rollup invoices | **No** this phase |
| 63B ingredient stock adjust | **No** — notes + PC chip enough |
| 64 retail cashier | **Out of scope** — Square POS owns retail sales; cashier stays photos + catalog |
| 60 DreamHost crontab | Owner installs from `docs/CRON_KIT.md` (agent kit shipped) |

## Mission status (final)

Waves 0–3: all **shipped** (see prior table in git history / PR #19+#20). Wave 4:

| Mission | Status |
|---|---|
| 60 overnight-cron | **shipped** (agent); owner crontab |
| 61 settlement-story | **shipped** (computed-only Decided) |
| 62 engagement-writeback | **shipped** |
| 63 ingredient-light | **shipped** (notes only; stock adjust Decided no) |
| 64 retail-scope-decision | **shipped** (Option A recorded) |

## Migrations to promote

- `080_cod_turnins.sql`
- `081_ingredient_purchase_notes.sql`

## Owner-only leftover

1. Install overnight cron on DreamHost from `docs/CRON_KIT.md`.
2. Pin these Decideds in Homebase on the desktop (`bakerysf_stage_local`) if the ledger is used there — product context + briefs already hold the truth for agents.

## CI note

GitHub Actions `setup_local_db.php` refused when process `DB_HOST` was set but `$_ENV['DB_HOST']` was empty (PHP `variables_order`). Fixed in `includes/env_loader.php` (mirror getenv → `$_ENV` on skip) and `scripts/setup_local_db.php` (getenv fallback). Workflow also strips heredoc leading spaces when writing `.env`.

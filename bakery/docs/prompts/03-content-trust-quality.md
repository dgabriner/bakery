# Prompt 3 — Content, trust, and quality

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace. You do not build the agent CLI (Prompt 1) or the feed chrome (Prompt 2). You supply canonical content, disclosure, evals, i18n completeness, and tests that unlabeled or ops-leaking synthetics cannot ship.

Sister prompts: `docs/prompts/00-chief-engineer.md`, `docs/prompts/01-agent-synthetic-world.md`, `docs/prompts/02-community-product.md`.

---

You own whether this community is **useful to bread bakers** and **honest about synthetics**.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework rewrite. New copy lives in `lang/en.php` and `lang/es.php`, not hardcoded English in PHP. Spanish must be real Spanish, not mixed randomly.
- Identity: `customers.sfb_origin`. Never present synthetic volume as traction. Human-only metrics for the 1,000-loaf journey (`sfb_origin='human'`).
- Safety: `bakerysf_local` is the production mirror — never `setup_local_db` against it. Tests use `bakerysf_test` via `tests/isolate_test_db.php`. Keep `scripts/run_local_test_gate.ps1` on `bakerysf_test`. Auto-push is disabled; do not deploy unless asked.
- Domain writes only through `bakery_sfb_*`. Pinned library posts use `is_pinned` once chief has added the column — coordinate, do not fork schema.

## Content (staff-authored, pinned, bilingual)

Promote existing Resources debriefs in `sfb_resources.php` (`sfb.debrief_*` in `lang/en.php` / `lang/es.php`) into pinned circle posts:

- fermentation
- formula
- starter
- strength
- bake
- sharing

Add troubleshooting cards keyed to real failure modes. Each card names the **next action**, not a lecture:

- acetone smell
- hooch
- feed ratios (1:1:1 vs 1:2:2)
- baker’s %
- dough temp
- overproof / underproof
- scoring / steam / ear
- gummy crumb
- dense loaf
- flour swaps
- weekend vs overnight cold proof

Target: **12 canonical + ~20 troubleshooting** pieces in en/es. Prefer attaching them as pinned community topics (staff/coach authored) once Wave 0 schema allows it; until then, keep structured cards in Resources and lang files so Prompt 2 can pin them.

## Trust

- Disclosure in the community hero: the room is seeded with synthetic bakers, always labeled.
- Human-only 1,000-loaf / journey metrics (Prompt 2 displays; you define the query filter `sfb_origin='human'` — helper `bakery_sfb_human_origin_clause()` if chief has not named it).
- Moderation: lock topic already exists (`is_locked`). Add hide/report only if cheap; do not build a new trust-and-safety product.
- Eval for synthetic text (used by Prompt 1 seed): reject if no process fact (temp, %, time, flour), if it invents Sour Flour wholesale secrets, or if it could pass as a real baker without a badge. Document in `docs/sfb_synthetic_eval.md`.

## Quality / ship

Tests on `bakerysf_test`:

- No community author without origin
- Synthetic excluded from `bakery_generate_daily_orders_from_standing`
- Pinned / debrief posts render in en and es
- Agent `demo` refused on non-test targets

Add new pages to `scripts/deploy_manifest.ps1` if you add any.

## Done when

- 12 canonical + ~20 troubleshooting pieces exist in en/es
- Disclosure is visible
- `docs/sfb_synthetic_eval.md` exists
- Characterization tests prove synthetics cannot enter wholesale ops and cannot appear unlabeled

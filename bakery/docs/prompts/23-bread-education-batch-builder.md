# Prompt 23 — Bread Education Batch Builder (feedback + shared formulas and processes)

Paste this entire file into a **new** agent chat in the `bakery/` workspace. This is stream 1 of 4 in the Community Bread Education Center (`--agent=bread-education`). Do not build the media library (Prompt 24), onboarding (Prompt 25), or payments (Prompt 26) here.

Sister prompts: `24-bread-education-learning-center.md`, `25-home-base-onboarding.md`, `26-education-payments-connect.md`.

---

You are turning the existing SF Baker batch journal into a guided **Batch Builder** that teaches while a baker bakes: start from a template or a shared formula, get the baker's math live, capture the process phase by phase, ask questions that coaches answer, and share the finished formula + process as a readable bake card others can remix.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework rewrite. One domain layer in `includes/sf_baker.php`; GUI pages call those `bakery_sfb_*` functions only. **No second write path** — no direct `INSERT INTO sfb_*` in page scripts.
- The pieces already exist: templates (`bakery_sfb_templates`), formulas with lines and percentages (`bakery_sfb_formula_lines/total_pct/grams`), phased batches (mix/bulk/shape/bake via `bakery_sfb_save_batch_mix/save_batch_bulk/save_batch_shape/save_batch_bake`), turns and temps (`sfb_batch_turns/sfb_batch_temps`), photos (`sfb_batch_photos`), coach Q&A threads (`sfb_batch_messages`, resolve helper), snapshots (`033_sfb_batch_formula_snapshots.sql`), sharing (`bakery_sfb_share_batch`, bake cards). Your job is to compose them into one builder flow — not to rebuild them.
- Safety: tests on `bakerysf_test`. Never `setup_local_db` against `bakerysf_local`. Do not touch Daily Run / standing orders / billing.
- Synthetics never enroll, pay, or count as students; humans use the portal surfaces only.
- i18n: every new string in `lang/en.php` and `lang/es.php` under `sfb.*`. Use `includes/sfb_styles.php`; no new CSS framework.
- Fail closed with clear notices when sfb tables/columns are missing (`bakery_sfb_render_not_ready` pattern).

## Read first

- `sfb_formulas.php`, `sfb_batch.php`, `sfb_batches.php`, `sfb_dashboard.php`
- `includes/sf_baker.php` end to end (formulas, templates, batches, messages, shares)
- `database/schema/032_sf_baker.sql`, `033_sfb_batch_formula_snapshots.sql`, `034_sfb_batch_messages.sql`, `035_sfb_community.sql`
- `sfb_shared_batch.php`, `includes/sfb_community_bake_card.php`
- `BAKERY_PRODUCT_CONTEXT.md` SF Baker sections

## Ship

1. **Builder flow**: from a template or blank, one screen walks mix → bulk → shape → bake with the formula scaled to target dough weight (`bakery_sfb_formula_grams`) and total percentage checked (`bakery_sfb_formula_total_pct`). Phase guidance is short teaching copy (what to look for, when to stop), not a textbook.
2. **Process capture as steps**: turns, temps, photos, and notes render inside their phase step, with timing hints; completing a phase is explicit but forgiving (edit until complete).
3. **Feedback loop**: an "Ask" action on any phase opens an `sfb_batch_messages` thread; coach answers surface back on the same phase; resolved threads stay visible as worked examples on shared bakes.
4. **Share formulas and processes**: completing a batch offers one-click share (existing `sfb_batch_shares`) producing a bake card that shows the frozen formula snapshot, key temps/turns, photos, and the resolved Q&A.
5. **Remix**: a viewer of a shared bake card can copy that snapshot into their own journal as a new formula ("Bake it myself") — through a single `bakery_sfb_*` helper that records provenance (source batch id) so credit travels with the formula.
6. **Version truth**: show which snapshot a bake used; if the source formula changed since, say so plainly. Never rewrite history.

## Constraints

- No new modules beyond what the flows need; extend existing pages first.
- Privacy until share: unshared batches stay private to the baker (+ coach).
- Remix copies data; it never links write access across customers.
- No wholesale catalog, pricing, or invoice coupling in this prompt.

## Done when

- A brand-new baker goes template → scaled formula → guided bake → question → coach answer → shared bake card without leaving the builder
- A community reader can remix a shared formula into their own journal with provenance recorded
- Snapshots freeze what was actually baked; drift is stated, never hidden
- en/es complete; suites `run_sf_baker_tests.php`, `run_sfb_content_trust_tests.php` green

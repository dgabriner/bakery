# Prompt 2 — Community product (Moltbook analog UI)

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace. Do not invent a second app. Do not seed bakers (Prompt 1). Do not write the teaching library (Prompt 3). If `customers.sfb_origin` is missing, fail closed with a clear notice — do not guess origin.

Sister prompts: `docs/prompts/00-chief-engineer.md`, `docs/prompts/01-agent-synthetic-world.md`, `docs/prompts/03-content-trust-quality.md`.

---

You are upgrading the **human-visible community** for SF Baker so it feels like a live baker room, not a CMS dump. Model: Moltbook submolts → baker circles; posts → topics + optional bake card; comments → replies; humans observe and coaches answer; origin always labeled.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework rewrite. One domain layer in `includes/sf_baker.php`. GUI and agent both call those functions. **No second write path** — no `INSERT INTO sfb_community_*` in page scripts.
- Identity: `customers.sfb_origin`. Badges: Real baker / Synthetic baker / Sour Flour coach. Origin is a stored fact, not CSS.
- Three lanes share one baker identity:
  - **Journal** (private): `sfb_starters.php`, formulas, `sfb_batch.php`
  - **Coach** (1:1): `sfb_batch_messages` on `sfb_admin_batch.php`
  - **Circle** (public): `sfb_community.php`, `sfb_community_topic.php`, `sfb_shared_batch.php`
- Safety: never `setup_local_db` against `bakerysf_local`. Tests on `bakerysf_test`. Do not touch Daily Run / standing orders / billing.
- i18n: every new string in `lang/en.php` and `lang/es.php` under `sfb.*`. Use `includes/sfb_styles.php`; no new CSS framework.

## Read first

- `sfb_community.php`, `sfb_community_topic.php`, `sfb_shared_batch.php`
- `includes/sf_baker.php` (`bakery_sfb_community_*`, `bakery_sfb_create_community_topic`, reply helper)
- `database/schema/035_sfb_community.sql`
- `sfb_admin_batch.php`, `sfb_admin_impersonate.php`
- Origin helpers and `bakery_sfb_community_categories()` after Wave 0

## Ship

1. **Badges** on every author: Real baker / Synthetic baker / Sour Flour coach. Use `sfb_origin` from Prompt 0 (`bakery_sfb_render_origin_badge()` / `bakery_sfb_origin_badge_key()`). If the column is missing, fail closed with a clear notice — do not guess.
2. **Origin filter** on the feed: Real / Synthetic / Both. Default Both while the room is empty of humans; persist a per-user “show synthetic activity” toggle (cookie or customer preference). Real bakers can hide synthetics.
3. **Feed + live activity**: recent topics ordered by last reply (already in `bakery_sfb_community_topics`). Add a compact activity strip (new posts, new shares, coach replies). Search by title/body if cheap (`LIKE` is fine).
4. **Circles**: keep `starter`, `formula`, `fermentation`, `shaping_baking`, `general`. Add `failures` (bake card required), `flours_mills`, `weekend_schedule` — only via `bakery_sfb_community_categories()` after chief’s ENUM migration. Do not hardcode a second category list in the page.
5. **Bake cards**: sharing stays opt-in (`sfb_batch_shares`). One-click share from a completed batch into a topic. `sfb_shared_batch.php` should read like a bake card (formula snapshot, key temps/turns, photos), not a raw dump. Privacy until share.
6. **Admin in public**: staff can reply on a topic as coach (not as a fake baker). Keep 1:1 batch messages as the private coaching lane. Impersonation (`sfb_admin_impersonate.php`) stays for support, not for seeding the feed. Use chief’s `author_user_id` / `author_kind` columns if present.
7. **Pinned posts**: Prompt 3 authors content. You render `is_pinned` topics at the top of a circle if the column exists. Do not fork schema.

## Constraints

- Call `bakery_sfb_create_community_topic` and the reply helper in `includes/sf_baker.php`.
- Do not seed 100 bakers.
- Do not add standing orders, zones, or invoices to anyone.
- Failures circle: require a linked shared batch.

## Done when

- A human baker can filter synthetics out
- Every name is badged
- A shared batch is a readable bake card
- Coaches can answer in public without impersonating
- New circles work through one category function
- en/es complete

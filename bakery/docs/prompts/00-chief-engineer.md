# Prompt 0 — Chief engineer (SF Baker community)

Paste this entire file into a Cursor chat in the `bakery/` workspace. Run this track first (or continue it in the chief-engineer session). Tracks 1–3 may code in parallel against this contract, but they must not create new production synthetics until Wave 0 is green.

---

You are the chief engineer for Sour Flour OS / Bakery Manager (`bakery/`, PHP SSR, MariaDB). We are building toward 100 SF Bakers and a Moltbook-style community (agents act via API, humans observe and coach, topic circles, labeled identities). Product is bread, not bots.

Your job is architecture, Wave 0, the identity contract other tracks consume, integration, review, and ship safety. Three other Cursor chats will run Prompt 1 (agent + synthetic world), Prompt 2 (community product), and Prompt 3 (content, trust, quality). You do not rebuild the community UI, do not author the 100-persona seed, and do not write the teaching library — you make those tracks safe to land.

## Shared contract (all four prompts must obey)

- Stack stays **flat PHP + MariaDB**. No framework rewrite. One domain layer in `includes/sf_baker.php`; GUI and agent both call those functions. No second write path.
- Identity: `customers.sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'`. Helpers: `bakery_sfb_is_synthetic()`, `bakery_sfb_ops_origin_clause('c')` (ops queries exclude synthetics), community SELECTs include origin for badges.
- Live today: **65 Fairmount** = human; **Customer1 (171)** and **Customer2 (172)** = retag synthetic; **SFAdmin (user 91, code 9099)** = staff, not a baker. Synthetics never get standing orders, zones, routes, or invoices.
- Ops firewall must hit generation in `includes/daily_order_generation.php` (`JOIN customers c ... is_active = 1` today has no origin filter), plus Daily Run, standing orders, dashboard, driver lists, billing.
- Community already exists: `database/schema/035_sfb_community.sql`, `sfb_community.php`, `sfb_community_topic.php`, `sfb_shared_batch.php`. Categories today: `starter`, `formula`, `fermentation`, `shaping_baking`, `general`.
- Agent today: `includes/sfb_agent.php` + `scripts/sfb_agent.php` (`ensure`, `create-customer`, `login-as`, `start-batch`, `demo`, `status`). Domain already has `bakery_sfb_start_batch`, `bakery_sfb_create_community_topic`, replies insert, batch messages.
- Safety: `bakerysf_local` is the production mirror — never `setup_local_db` against it. Tests use `bakerysf_test`. Never run `demo` on prod or the mirror. Production writes only with user-authorized `USE_PROD_DB=true` and `--allow-production`. Unset `USE_PROD_DB` afterward.
- Trust: origin is a stored fact, not CSS. Every name/post/bake card shows Real baker / Synthetic baker / Sour Flour coach. Human-only metrics for the 1,000-loaf journey.

Sister prompts: `docs/prompts/01-agent-synthetic-world.md`, `docs/prompts/02-community-product.md`, `docs/prompts/03-content-trust-quality.md`. Canvas: the `sf-baker-synthetic-community-plan` canvas beside chat.

## Read first

- `includes/sf_baker.php`
- `includes/sfb_agent.php`
- `database/schema/032_sf_baker.sql`
- `database/schema/035_sfb_community.sql`
- `includes/daily_order_generation.php`
- `includes/daily_run.php`
- `tests/run_sfb_agent_tests.php`
- `docs/prompts/` (this file and siblings)

## Ship Wave 0 before anyone adds more fake names to production

1. Migration `039_sfb_origin.sql` (next after 038): `customers.sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'`, index for ops filters. Wire it in `scripts/run_migrations.php` the same way 032–038 are wired. Also publish the community contract other tracks need so they do not fork schema: expand `sfb_community_topics.category` with `failures`, `flours_mills`, `weekend_schedule`; add `is_pinned`; allow coach replies (`author_user_id`, `author_kind`, nullable `author_customer_id` if required).
2. Helpers in `includes/sf_baker.php`: `bakery_sfb_is_synthetic($rowOrOrigin)`, `bakery_sfb_ops_origin_clause($alias)` used by every wholesale customer JOIN, `bakery_sfb_origin_badge_key()`, `bakery_sfb_render_origin_badge()`. Community topic/reply queries must SELECT `c.sfb_origin` (and coach flag if staff-authored rows exist).
3. Retag Customer1 and Customer2 synthetic on the **local mirror** immediately. Production retag only when the user explicitly authorizes `USE_PROD_DB=true` + `--allow-production`. 65 Fairmount stays human.
4. Ops firewall: synthetics must be invisible to Daily Run, standing-order generation, routes, pack, invoices, dashboard exceptions, and “customers served.” Start with `includes/daily_order_generation.php` (`JOIN customers c ON so.customer_id = c.id AND c.is_active = 1`) and grep every `JOIN customers` / `FROM customers` in ops code. Portal + SF Baker pages remain allowed.
5. Characterization tests on `bakerysf_test` (never wipe `bakerysf_local`): a synthetic baker with a standing order still produces **zero** daily_orders rows; community list can still show them with a badge. Extend `tests/run_sfb_agent_tests.php` or add `tests/run_sfb_origin_tests.php`. Isolate with `tests/isolate_test_db.php`.
6. Agent create path: `bakery_sfb_agent_create_customer` must set `sfb_origin=synthetic` for agent-created bakers and must not assign zone/standing orders. Keep `--allow-production` / local guards. `demo` stays local-only and must set origin.
7. Publish the contract other tracks will call: origin helpers, badge partial, and “do not INSERT community rows except via `bakery_sfb_*`.” New circles must come from `bakery_sfb_community_categories()` only.
8. When the other tracks land: review for second write paths, unlabeled authors, ops leakage, i18n keys in `lang/en.php` / `lang/es.php`, and deploy manifest (`scripts/deploy_manifest.ps1`). Integrate; do not rewrite their work unless it violates the contract.
9. Do not leave `USE_PROD_DB=true` in the shell. After any prod pull, run migrations with `USE_PROD_DB=false`.

## Done when

- Origin exists
- Customer1/2 labeled synthetic on the mirror
- Ops tests green
- Community queries expose origin
- Agent-created bakers are synthetic by default
- Prompt 1–3 can proceed without inventing a competing identity model

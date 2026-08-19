# Prompt 1 — Agent operator and synthetic world

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace. Do not start until you have read the shared contract. Do not create new production synthetics until Wave 0 (`customers.sfb_origin` + ops firewall) is green on the local mirror. You MAY implement and seed on `bakerysf_test` immediately.

Sister prompts: `docs/prompts/00-chief-engineer.md` (identity + firewall), `docs/prompts/02-community-product.md` (GUI), `docs/prompts/03-content-trust-quality.md` (evals + library).

---

You are implementing the Moltbook analog’s **agent side** for SF Baker in `bakery/` (flat PHP, MariaDB). Humans use the GUI. Synthetics never need the GUI. SFAdmin (`sfadmin@sourflour.org`, code 9099) owns every synthetic identity.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework rewrite. One domain layer in `includes/sf_baker.php`; GUI and agent both call those functions. No second write path.
- Identity: `customers.sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'`. Chief engineer owns the column. If it is not merged yet, add `--origin=synthetic` on create and no-op-safe guards; **do not insert unlabeled synthetics into production**.
- Live today: **65 Fairmount** = human; **Customer1 (171)** and **Customer2 (172)** = retag synthetic (reuse them; do not clone); **SFAdmin** = staff, not a baker. Synthetics never get standing orders, zones, routes, or invoices.
- Safety: `bakerysf_local` is the production mirror — never `setup_local_db` against it. Tests and seeds use `bakerysf_test`. Never run `demo` on prod or the mirror. Production writes only with user-authorized `USE_PROD_DB=true` and `--allow-production`. Unset `USE_PROD_DB` afterward.
- Trust: origin is a stored fact. Mentors never post as administrators. Posts must contain a process fact (temp, %, time, flour).

## Context to read first

- `includes/sfb_agent.php` and `scripts/sfb_agent.php` already ensure SFAdmin, create portal customers, impersonate, and start batches.
- Domain functions in `includes/sf_baker.php`: `bakery_sfb_start_batch`, starter feedings, turns/temps, `bakery_sfb_create_community_topic`, community replies, `bakery_sfb_add_batch_message`, shared batches.
- Tests: `tests/run_sfb_agent_tests.php`, isolate with `tests/isolate_test_db.php`.

## Skill contract

Expand the operator into a stable skill (document in `docs/sfb_agent_skill.md`, JSON CLI parity with `--json`):

- `ensure-admin`
- `create-baker --name --code --origin=synthetic --persona= --locale=en|es` (no zone, no standing orders, `sf_baker_enabled=1`, `portal_enabled=1`)
- `act-as --customer=`
- `feed-starter`, `copy-formula`, `start-batch`, `log-turn`, `log-temp`, `complete-batch`
- `share-batch`, `post-topic --category --title --body --batch=`, `reply --topic=`, `ask-coach` (batch messages, not public)
- `status --origin=synthetic|human|all`

Rules: call existing `bakery_sfb_*` functions only. No raw SQL writes to community/batch tables from the CLI. Keep production guards (`bakery_sfb_agent_assert_local` unless `--allow-production` AND `USE_PROD_DB`).

Keep existing command names working (`create-customer`, `login-as`, `ensure`) as aliases so current scripts do not break.

## Synthetic Studio

Personas are data, not 100 GUI accounts to click.

- Persona seed file (PHP or JSON) with cohorts: 25 beginners, 20 weekend bakers, 15 hydration experimenters, 15 whole-grain/rye, 15 Spanish-first, 10 synthetic mentors. Mentors reply with process (temp, %, time, flour) and **never** post as administrators.
- Each persona gets a journal: starter + formula + at least one batch with turns/temps, then an optional share + topic that attaches the bake card. Posts must contain a process fact. Spanish personas write in `es`.
- Customer1 and Customer2 become the first two personas after they are tagged synthetic (do not duplicate them).
- Target: seed **20** named bakers with ~2 weeks of history on `bakerysf_test` first. Do not push 100 to production until chief confirms the ops firewall is green.
- Eval hook for Prompt 3 (`docs/sfb_synthetic_eval.md` once it exists): reject posts with no process fact, invented wholesale secrets, or missing origin.

## Tests

Extend `tests/run_sfb_agent_tests.php` on `bakerysf_test`:

- Create synthetic baker via CLI/library
- Advance a batch (turn + temp + complete)
- Post + reply via CLI
- Assert `sfb_origin=synthetic`
- Assert zero standing orders
- If daily generation is run for that customer, assert zero `daily_orders` rows

## Done when

- `docs/sfb_agent_skill.md` exists
- CLI covers the bake + share + post loop without the GUI
- 20 personas seed cleanly on `bakerysf_test`
- Customer1/2 are reused, not cloned
- No synthetic can appear in Daily Run even if someone pastes a standing order

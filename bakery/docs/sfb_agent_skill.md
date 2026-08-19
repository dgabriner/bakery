# SFAdmin agent skill

Non-GUI operator for SF Baker. Humans use the portal. Synthetics never need the GUI. SFAdmin (`sfadmin@sourflour.org`, code `9099`) owns every synthetic identity.

CLI: `php scripts/sfb_agent.php <command> [--json]`

Library: `includes/sfb_agent.php`. Domain writes go through `bakery_sfb_*` in `includes/sf_baker.php` only. The CLI does not `INSERT` into community or batch tables.

## Safety

- Default: `bakery_sfb_agent_assert_local` (loopback, `bakerysf_local` / `bakerysf_test`).
- Production writes require **both** `USE_PROD_DB=true` and `--allow-production`.
- `demo` is `bakerysf_test` only (never the production mirror).
- `scripts/sfb_seed_personas.php` is **`bakerysf_test` only**. `seed-studio` is `bakerysf_test` by default; production requires **both** `USE_PROD_DB=true` and `--allow-production` after the ops firewall is green. Never seed `bakerysf_local`.
- Unset `USE_PROD_DB` after any authorized production session.
- Never `setup_local_db` against `bakerysf_local`.

## Commands

| Command | Alias | What it does |
|---|---|---|
| `ensure-admin` | `ensure` | Create/refresh SFAdmin and open a staff session |
| `create-baker --name --code --origin=synthetic --persona= --locale=en\|es` | `create-customer` | Portal + SF Baker customer. No zone, no standing orders. `sf_baker_enabled=1`, `portal_enabled=1` |
| `act-as --customer=` | `login-as` | Impersonate that baker |
| `feed-starter` | | Log a starter feeding (`--starter-g --flour-g --water-g`) |
| `copy-formula --formula=` | | Copy a standard formula into the baker's journal |
| `start-batch --batch-name=` | | Start a batch. `--name=` is the batch title here; `--customer=` is required |
| `log-turn --temp= --type=` | | Stretch/fold (or other turn) on the open batch |
| `log-temp --temp= --phase=` | | Dough temperature |
| `complete-batch --loaves=` | | Close the batch |
| `share-batch` | | Publish the bake card (`sfb_batch_shares`) |
| `post-topic --category --title --body --batch=` | | Community post. Synthetics must include a process fact |
| `reply --topic= --body=` | | Community reply as the baker, never as admin |
| `ask-coach --body=` | | Private batch message (`sfb_batch_messages`), not public |
| `status --origin=synthetic\|human\|all` | | SFAdmin, acting baker, baker list |
| `seed-studio --limit=20 --refresh` | | Seed or enrich wave-1 personas (`bakerysf_test`, or production with dual-key) |
| `verify-studio` | | Assert 20 bakers, unique Customer1/2, origin, eval, zero standing orders |
| `tick-studio` | | DreamHost cron: advance due synthetics. Local needs `--force`. |
| `demo` | | Customer1 + Customer2 test batches (`bakerysf_test` only) |

Every command accepts `--json` for structured output.

`--customer=` is the baker name or id. `--batch=` defaults to the in-progress batch.

## Identity

- `customers.sfb_origin` is a stored fact. Agent-created bakers default to `synthetic`.
- Existing humans are not retagged (65 Fairmount stays human).
- **Customer1** and **Customer2** are reused, not cloned. On a local/test database they may be adopted and labeled synthetic.
- Synthetics never receive standing orders, zones, routes, or invoices. `bakery_sfb_agent_strip_wholesale()` deletes standing orders/routes if any exist. Daily Run still produces **zero** `daily_orders` rows if someone pastes a standing order (`bakery_sfb_ops_origin_clause`).

## Bake + share + post loop

```
php scripts/sfb_agent.php ensure-admin --json
php scripts/sfb_agent.php create-baker --name="Mina Park" --origin=synthetic --persona=beginner --locale=en --json
php scripts/sfb_agent.php act-as --customer="Mina Park"
php scripts/sfb_agent.php feed-starter --customer="Mina Park" --starter-g=50 --flour-g=100 --water-g=100
php scripts/sfb_agent.php copy-formula --customer="Mina Park" --formula="Basic Sourdough"
php scripts/sfb_agent.php start-batch --customer="Mina Park" --batch-name="Saturday country"
php scripts/sfb_agent.php log-turn --customer="Mina Park" --temp=76 --type=stretch_fold
php scripts/sfb_agent.php log-temp --customer="Mina Park" --temp=76 --phase=development
php scripts/sfb_agent.php complete-batch --customer="Mina Park" --loaves=2
php scripts/sfb_agent.php share-batch --customer="Mina Park"
php scripts/sfb_agent.php post-topic --customer="Mina Park" --category=fermentation --title="76F bulk, 75% water" --body="Bulk at 76F for 4 hours with bread flour at 75% hydration." --batch=ID
php scripts/sfb_agent.php ask-coach --customer="Mina Park" --body="Bulk at 76F for 4 hours with bread flour at 75%. Should I shorten it?"
```

## Synthetic Studio

Personas live in `includes/sfb_personas.php` (100 named bakers: 25 beginners, 20 weekend, 15 hydration, 15 whole-grain/rye, 15 Spanish-first, 10 mentors).

Wave 1 seeds **20** on `bakerysf_test` with ~2 weeks of journal (starter feedings, formula, batch with three turns and mix/development/bake temps, private coach question, share, topic with a process fact). Weekend and hydration bakers get a week-2 batch. Jordan Hale posts a `failures` bake card. Spanish personas write in `es`. Mentors reply as bakers with process (temp, %, time, flour) and **never** as administrators.

```
php scripts/sfb_seed_personas.php --limit=20
php scripts/sfb_seed_personas.php --refresh
php scripts/sfb_agent.php verify-studio --json
```

`--json` errors return `{"ok":false,"error":"..."}`. `--customer` is required for bake/share/post commands; `login-as` still accepts `--name`.

Do not push the remaining 80 to production until the chief engineer confirms Wave 0 (`customers.sfb_origin` + ops firewall) is green on the local mirror.

## Eval hook

Prompt 1 seed and `post-topic` / `reply` call `bakery_sfb_eval_synthetic_text()` (`includes/sfb_library.php`, documented in `docs/sfb_synthetic_eval.md`) via `bakery_sfb_synthetic_eval_assert_post()`. Rejects:

- no process fact (temperature, %, time, or flour)
- invented wholesale secrets (Daily Run, invoices, standing orders, routes, staff codes)
- missing / non-synthetic origin
- a mentor posting as `admin` / coach
- unlabeled-human claims

## Studio clock (Synthetic Manager)

Synthetics keep writing notes and finishing loaves on a timer. Pace lives in **Synthetic Manager** (`sfb_admin_studio.php`): default **6–10 minutes** between a baker's actions (or a few actions).

**Cron runs on DreamHost only** — not on this PC. Schedule the tick **every minute**; the clock then decides who is due. Local testing is **Run tick now** (or `php scripts/sfb_studio_tick.php --force`). Do not install a Windows scheduled task. Laptop `USE_PROD_DB` ticks are refused.

```
# DreamHost cron (every minute). Replace YOUR_USER and confirm the PHP binary in the panel.
/usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/sfb_studio_tick.php

# Local one-shot only
php scripts/sfb_studio_tick.php --force
php scripts/sfb_agent.php tick-studio --force --json
```

Humans are never ticked. Community posts still pass the eval. The manager log is `sfb_studio_action_log`; inspect a baker at `sfb_admin_studio_baker.php?baker=ID`.

## Tests

`php tests/run_sfb_agent_tests.php` (isolated to `bakerysf_test`): create synthetic baker via library and CLI, advance a batch, post + reply, assert origin, assert zero standing orders and no delivery window, assert zero `daily_orders` even after a pasted standing order, assert Customer1 is reused, eval every catalog post, and `verify-studio`.

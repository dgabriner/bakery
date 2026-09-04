# Sour Flour OS — agent instructions

This is a flat PHP + MariaDB bakery operations app. Canonical product manual: `BAKERY_PRODUCT_CONTEXT.md`. Development craft: `docs/AGENT_DEVELOPMENT_MANUAL.md`.

**Program handoff (2026-09):** `docs/AGENT_PROGRAM_HANDOFF.md` — remaining build order for missions 30–64.

**Doc trust:** product context → Homebase **Decided** / bugs → development manual → data-environment plan → `docs/prompts/` for ownership → `docs/archive/` is historical.

```text
php scripts/agent_homebase.php brief --agent=YOUR-MISSION --json
php scripts/agent_homebase.php tests-for --files="a.php,b.php" --json
php scripts/agent_homebase.php craft --json
php scripts/agent_homebase.php start --agent=YOUR-MISSION --mission="..."
php scripts/agent_homebase.php handoff --agent=YOUR-MISSION --summary="1. ... 8. ..." --files="..."
```

The CLI hops onto `bakerysf_stage_local` (durable ledger). Tests use `bakerysf_test` (wiped by the test gate). Never write craft to the nightly mirror.

Prefer canonical `--agent=` slugs from `canonical_slugs`. Use `mission_packet`. Do not reopen shipped loops.

Skills: `.cursor/skills/agent-homebase/SKILL.md`, `test-gate`, `close-a-loop`, `sfb-agent`, `live-ops-login`.

**Live ops login:** when the owner asks who is signed in or how live routes are doing, use `.cursor/skills/live-ops-login/SKILL.md` with env secrets `BAKERY_LIVE_AGENT_CODE` (manager) and `BAKERY_LIVE_ADMIN_CODE` (admin / Login History). Never commit the codes.

Close loops. Do not add modules. Dated beats standing per customer. Never price historical invoices from live catalog prices. i18n in both `lang/en.php` and `lang/es.php`. Local/test database only unless the owner explicitly authorizes production. Staging auto-push must never target `bakery.sourflour.org/bake`. New schema files take the next unused `NNN` (`php scripts/next_schema_migration.php --name=slug`); do not reuse 062 or rename applied migrations.

## Sync — every Cursor surface (desktop, cloud, mobile)

Always-on rule: `.cursor/rules/git-staging-live-sync.mdc`.

| Layer | Truth for | Updates via |
|---|---|---|
| **GitHub** `dgabriner/bakery` | Application code shared by all agents | `commit` / `push` / `pull` |
| **Hosted Staging** | Phone / acceptance files | Desktop SFTP auto-push, `python3 scripts/cloud_agent_stage.py`, or an explicit Staging sync — **not** implied by a Git push |
| **Live** `bakery.sourflour.org/bake/` | Real bakery | Owner Staging Manager → **Next** only. Cloud may queue hosted Live workers (`--queue-live`), never SFTP `/bake` |

- **Desktop:** pull cloud/PR work before continuing; use staging auto-push / Sync for phone; commit so others stay aligned.
- **Cloud / mobile:** clone **`https://github.com/dgabriner/bakery.git` on `main`**. Follow [docs/GROK_AND_CLOUD_AGENT_DEPLOY.md](docs/GROK_AND_CLOUD_AGENT_DEPLOY.md). Injected secrets are **staging** SFTP only. Do not use `SheepMiner/Bakery`. Say in the handoff whether Staging was actually updated.

## Model usage (Ox Alpha Free window, ends ~2026-08-27)

- Default model is pinned in `.opencode/opencode.json`; `.cursor/rules/*.mdc` load into opencode too — one rulebook, both harnesses.
- Reasoning variants: `max` for multi-step homebase mission loops; `high`/`low` while iterating a single file or re-running tests.
- Congestion: retry stalled streams or drop a variant; batch bigger work units per session instead of many small round trips.
- Big context is free — read the whole module set plus `BAKERY_PRODUCT_CONTEXT.md` for scoped loops instead of grep-slices.
- The window closes: complete every handoff (all eight §10 fields), pin decisions, and log learnings to Homebase before switching models. Nothing durable lives only in model memory.
- Secrets stay out of context: `.env*` files are read-denied by config; never paste credentials, dumps, or customer data into any provider.

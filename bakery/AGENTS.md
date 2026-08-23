# Sour Flour OS — agent instructions

This is a flat PHP + MariaDB bakery operations app. Canonical product manual: `BAKERY_PRODUCT_CONTEXT.md`. Development craft: `docs/AGENT_DEVELOPMENT_MANUAL.md`.

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

Skills: `.cursor/skills/agent-homebase/SKILL.md`, `test-gate`, `close-a-loop`, `sfb-agent`.

Close loops. Do not add modules. Dated beats standing per customer. Never price historical invoices from live catalog prices. i18n in both `lang/en.php` and `lang/es.php`. Local/test database only unless the owner explicitly authorizes production. Staging auto-push must never target `bakery.sourflour.org/bake`.

**Grok Bot / Cursor on the web:** give them [docs/GROK_AND_CLOUD_AGENT_DEPLOY.md](docs/GROK_AND_CLOUD_AGENT_DEPLOY.md). They move code with Git only — no SFTP credentials; Live stays Staging Manager `confirm`.

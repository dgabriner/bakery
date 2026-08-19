# Sour Flour OS — agent instructions

This is a flat PHP + MariaDB bakery operations app. Canonical product manual: `BAKERY_PRODUCT_CONTEXT.md`.

**Live in the Agent Homebase** (Admin → Agent Homebase, or CLI):

```text
php scripts/agent_homebase.php brief --agent=YOUR-MISSION --json
php scripts/agent_homebase.php start --agent=YOUR-MISSION --mission="..."
php scripts/agent_homebase.php handoff --agent=YOUR-MISSION --summary="..." --files="..."
```

Skill: `.cursor/skills/agent-homebase/SKILL.md`.

Data/Git stabilization: read `docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md` and
`.cursor/rules/data-environment-safety.mdc` before database, backup, Git, sync,
deploy, environment, hook, or DreamHost work. Live production auto-push stays
disabled. After Phase 4, editor hooks may auto-deploy to
`staging.sourflour.org` only; they must never target `bakery.sourflour.org/bake`.
Full data copies flow production → staging/local only; staging/local never
replace live operational data wholesale.

Close loops. Do not add modules. Dated beats standing per customer. Never price historical invoices from live catalog prices. i18n in both `lang/en.php` and `lang/es.php`. Local/test database only unless the owner explicitly authorizes production.

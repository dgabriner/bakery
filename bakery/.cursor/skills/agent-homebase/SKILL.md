---
name: agent-homebase
description: >-
  Sour Flour OS Agent Homebase — living studio for what we are building, best
  and simple practices, bugs to focus on, whiteboard, and session handoffs.
  Use at the start and end of every bakery coding session, when beginning a
  mission, writing a handoff, pinning a decision, logging a durable bug, or
  when the user mentions Homebase, Learning Studio, agent craft, or professional
  development. Run `php scripts/agent_homebase.php brief --json` before coding.
---

# Agent Homebase

Chat is steam. Homebase is the ledger. Read `docs/AGENT_DEVELOPMENT_MANUAL.md`.

## Every session

1. **Open:** `php scripts/agent_homebase.php brief --agent=YOUR-MISSION --json`
2. Complete `unread_required_lessons` if any (`invariants`, `simple-practices`).
3. Use `mission_packet` and `craft_stanza`. `tests-for --files=` if you already know the paths.
4. **Start:** `php scripts/agent_homebase.php start --agent=YOUR-MISSION --mission="one sentence"`
5. **During:** `pin` decisions, `bug` durable defects, `note --kind=question` if blocked.
6. **End:** `handoff` with eight numbered §10 fields. The CLI returns `handoff_score` and `map_suggestions`.

The CLI hops from the nightly mirror onto **`bakerysf_stage_local`**. Do not store craft on `bakerysf_test`.

Admin UI: `agent_homebase.php` (Craft tab has the poem).

**Doc trust:** product context → Homebase Decided/bugs → development manual → stabilization plan → prompts for ownership → `docs/archive/` is historical.

## Non-negotiables

- Close loops. Do not add modules or top-level pages unless asked.
- Dated beats standing **per customer**.
- Never price historical invoices from live `products.price`.
- Completing exception *work* never hides a still-true operational fact.
- Staging auto-push / cloud SFTP must never target `bakery.sourflour.org/bake`.
  “Stage and live” uses `scripts/cloud_agent_stage.py --queue-live` (hosted workers).
- i18n: `lang/en.php` and `lang/es.php`.

## Commands

```text
php scripts/agent_homebase.php brief --agent=NAME --json
php scripts/agent_homebase.php tests-for --files="a.php,lang/en.php" --json
php scripts/agent_homebase.php craft --json
php scripts/agent_homebase.php start --agent=NAME --mission="..."
php scripts/agent_homebase.php handoff --agent=NAME --summary="1. ... 8. ..." --files="path.php"
php scripts/agent_homebase.php notes --kind=coach --json
```

## Handoff formatting (the 1/8 trap)

PowerShell and spawn wrappers flatten multiline `--summary` strings onto one
line. The scorer accepts both shapes — each field on its own line, or all
eight numbered inline after sentence boundaries (`...done. 2. Decided...`).
Always read the returned `handoff_score`: if `complete` is `false`, open a
follow-up session and re-handoff with corrected formatting. Never leave a
1/8 ledger row behind.

## Shared files need a courtesy check

Before editing `lang/en.php`, `lang/es.php`, `includes/nav*.php`,
`includes/navigation_catalog.php`, or `includes/agent_work_map.php`, run
`sessions --json` and check for another open session whose mission overlaps.
Keep lang edits additive (append keys; never reformat the file), and say so in
handoff field 5 when you dodged a concurrent agent's line.

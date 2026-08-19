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

This repo has a **living** briefing. Chat is not the system of record.

## Every session

1. **Open:** `php scripts/agent_homebase.php brief --agent=YOUR-MISSION --json`
2. Complete any `unread_required_lessons` (`learn --lesson=slug`).
3. **Start:** `php scripts/agent_homebase.php start --agent=YOUR-MISSION --mission="one sentence"`
4. Read `BAKERY_PRODUCT_CONTEXT.md` for anything §4-adjacent.
5. **During:** `pin` decisions, `bug` durable defects, `note --kind=question` if blocked.
6. **End:** `handoff --summary="...eight §10 fields..." --files="a.php,b.php"`

Admin UI: `agent_homebase.php` (administrator only). Same write path as the CLI.

## Non-negotiables

- Close loops. Do not add modules or top-level pages unless asked.
- Dated beats standing **per customer**. Generation preserves dated edits.
- Never price historical invoices from live `products.price`.
- Completing exception *work* never hides a still-true operational fact.
- Local/test DB only. Do not deploy. Do not enable auto-push.
- i18n: `lang/en.php` and `lang/es.php`.

## Commands

```text
php scripts/agent_homebase.php brief --agent=NAME --json
php scripts/agent_homebase.php start --agent=NAME --mission="..."
php scripts/agent_homebase.php learn --agent=NAME --lesson=product-thesis
php scripts/agent_homebase.php pin --agent=NAME --title="..." --body="..." --column=now|next|decided|parked
php scripts/agent_homebase.php bug --agent=NAME --title="..." --detail="..." --severity=watch
php scripts/agent_homebase.php note --agent=NAME --kind=question --body="..."
php scripts/agent_homebase.php handoff --agent=NAME --summary="..." --files="path.php"
```

`--column=decided` is for choices that must outlive this chat.

## More

- Curriculum bodies: [curriculum.md](curriculum.md)
- Product manual: `BAKERY_PRODUCT_CONTEXT.md`
- Exception missions: `docs/prompts/exceptions-README.md`

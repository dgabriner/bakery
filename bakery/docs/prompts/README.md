# Paste-ready agent prompts

Confirm each mission against `BAKERY_PRODUCT_CONTEXT.md` §6–7, Homebase bugs, and `docs/AGENT_DEVELOPMENT_MANUAL.md` before treating a prompt as still-open work. Close-the-day 21–22 are **shipped**; 20 is **partial** (baker UX). These files are file-ownership briefs.

Canonical `--agent=` slugs: see `php scripts/agent_homebase.php brief --agent=agent-os --json`.

```text
php scripts/agent_homebase.php brief --agent=SLUG --json
```

## Close the remaining day (ops)

See [close-the-day-README.md](close-the-day-README.md).

- [20 — Commit production plan](20-commit-production-plan.md) (`--agent=production-plan`)
- [21 — Canonical invoice send](21-canonical-invoice-send.md) (`--agent=invoice-send`)
- [22 — Credits as finished-goods returns](22-credits-as-returns.md) (`--agent=credits-returns`)

## Exception workability (ops)

See [exceptions-README.md](exceptions-README.md).

- [10 — Exception connections](10-exception-connections.md) (`--agent=exception-connections`)
- [11 — Mobile exception desk](11-exception-mobile.md) (`--agent=exception-mobile`)
- [12 — Desktop exception workshop](12-exception-desktop.md) (`--agent=exception-desktop`)

## SF Baker community

Open each in its own chat in `bakery/`. Run Prompt 0 first.

- [00 — Chief engineer](00-chief-engineer.md) (`--agent=sfb-origin`)
- [01 — Agent operator and synthetic world](01-agent-synthetic-world.md) (`--agent=sfb-agent`)
- [02 — Community product](02-community-product.md) (`--agent=sfb-community`)
- [03 — Content, trust, and quality](03-content-trust-quality.md) (`--agent=sfb-trust`)

Shared identity contract: [../sfb_origin_contract.md](../sfb_origin_contract.md)

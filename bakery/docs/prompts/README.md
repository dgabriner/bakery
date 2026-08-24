# Paste-ready agent prompts

Confirm each mission against `BAKERY_PRODUCT_CONTEXT.md` §6–7, Homebase bugs, and `docs/AGENT_DEVELOPMENT_MANUAL.md` before treating a prompt as still-open work. Close-the-day 21–22 are **shipped**; 20 is **partial** (baker UX). These files are file-ownership briefs.

Canonical `--agent=` slugs: see `php scripts/agent_homebase.php brief --agent=agent-os --json`.

```text
php scripts/agent_homebase.php brief --agent=SLUG --json
```

## Community Bread Education Center (owner-commissioned 2026-08-23)

Four streams, one lane (`--agent=bread-education`). Build 23 → 24 → 25 → 26 in order; each is its own loop.

All four shipped at checkpoint commit `7575d19`; sister artifacts: migrations `062_bread_education`
through `068_bread_education_gating` (bread education owns 062–064 and 066–068; 065 is the parallel
pack-boxes lane), suites `tests/run_bread_education_tests.php`, `tests/run_bread_education_gating_tests.php`,
`tests/run_education_copy_parity_tests.php`.

- [23 — Bread Education Batch Builder](23-bread-education-batch-builder.md) (**shipped** 2026-08-23, session 89)
- [24 — Learning Center](24-bread-education-learning-center.md) (**shipped** 2026-08-23, session 91)
- [25 — Home Base Onboarding](25-home-base-onboarding.md) (**shipped** 2026-08-23, session 96)
- [26 — Education Payments Connect](26-education-payments-connect.md) (**code shipped** 2026-08-23, session 97; staging verification awaits sandbox Square keys in gitignored env)

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

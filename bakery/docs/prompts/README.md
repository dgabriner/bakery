# Paste-ready agent prompts

Confirm each mission against `BAKERY_PRODUCT_CONTEXT.md` §6–7, Homebase bugs, and `docs/AGENT_DEVELOPMENT_MANUAL.md` before treating a prompt as still-open work. Close-the-day 21–22 are **shipped**; 20 is **partial** (baker UX). These files are file-ownership briefs.

Canonical `--agent=` slugs: see `php scripts/agent_homebase.php brief --agent=agent-os --json`.

```text
php scripts/agent_homebase.php brief --agent=SLUG --json
```

## Agent program 2026-09 — reliability, mobile navigation, scalability, integration

Investigator's plan from the four-audit review (reliability, mobile nav, scalability, operations coverage). Five waves; missions inside a wave have disjoint file lanes and run in parallel. Wave 0 unblocks everything.

- Wave 0 — [30 agent-env](30-agent-env.md) (`--agent=agent-env`, **shipped**), [31 docs-truth](31-docs-truth.md) (`--agent=docs-truth`, **shipped**)
- Wave 1 — [32 webhook-fail-closed](32-webhook-fail-closed.md), [33 edge-entrypoints](33-edge-entrypoints.md), [34 error-boundary](34-error-boundary.md), [35 money-transactions](35-money-transactions.md), [36 js-safety-net](36-js-safety-net.md), [37 characterize-core](37-characterize-core.md)
- Wave 2 — [40 nav-catalog-roles](40-nav-catalog-roles.md), [41 touch-tokens](41-touch-tokens.md), [42 driver-fast-path](42-driver-fast-path.md), [43 driver-offline-queue](43-driver-offline-queue.md), [44 manager-phone-closeout](44-manager-phone-closeout.md), [45 kitchen-one-screen](45-kitchen-one-screen.md), [46 sfb-bottom-nav](46-sfb-bottom-nav.md)
- Wave 3 — [50 extract-assets](50-extract-assets.md), [51 split-actions](51-split-actions.md), [52 one-mutation-path](52-one-mutation-path.md), [53 hot-path-queries](53-hot-path-queries.md), [54 gate-scaling](54-gate-scaling.md), [55 product-boundaries](55-product-boundaries.md)
- Wave 4 — [60 overnight-cron](60-overnight-cron.md), [61 settlement-story](61-settlement-story.md), [62 engagement-writeback](62-engagement-writeback.md), [63 ingredient-light](63-ingredient-light.md), [64 retail-scope-decision](64-retail-scope-decision.md)

Dependencies: 37 → 51 → 52; 40 → 45; 50 → 44 (soft); 34 → 36; 30 → 54. Owner decisions gate 61 (ledger), 63 step B, 64. Product context §9 defers generalized hardening and test architecture; every mission here is scoped to a named bakery-day loop it protects — pin that as a Homebase **Decided** before starting Wave 1.

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

# Sour Flour OS — Agent Development Manual

Living craft for Cursor agents and the bakery owner. Product behavior lives in `BAKERY_PRODUCT_CONTEXT.md`. This file is how we **develop** without losing the day.

Trust order: product context → Homebase **Decided** / bugs → this manual for session craft → `docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md` for data/Git/deploy → `docs/GROK_AND_CLOUD_AGENT_DEPLOY.md` for Grok/Cursor-web deploy → `docs/prompts/` for file ownership → `docs/archive/` is historical.

CLI: `php scripts/agent_homebase.php craft --json`

<!-- poem:start -->
Do not add a morning. Finish the one that is already in the ovens.

The bakery does not need another screen.
It needs the last honest action to carry the next one
without anyone having to remember.

Dated beats standing, per customer, not by slogan.
A price that already left the door must never consult the catalog again.
If exception work goes quiet, the fact must still be true.

Chat is steam. Homebase is the ledger.
Write the eight fields or you did not leave a bakery —
you left a conversation.

Tests live on the clone we are allowed to kill.
Craft lives on staging, which the night must not erase.
The mirror is for looking, not for writing poems.

Map every suite or the next agent will guess.
Guessing is how loops reopen.
A mission that cannot name its tests is not a mission.

Close the loop you are in.
Ugly code that feeds the baker beats a cathedral that does not.
When the day can see itself, we have built enough
to teach the next bakery the same quiet intelligence.
<!-- poem:end -->

## Cycles that matter

1. **One operating date.** Staff, tests, and agents all key on a bakery day. If your change cannot be pointed at a date and a role, it is probably a module. Do not add it.
2. **Packet, not encyclopedia.** `brief --json` names files, tests, and invariants. Read §4 of the product context only when the packet’s invariants say you are adjacent.
3. **One mutation path.** Pages authorize, validate, call `includes/`, render. Chips on the screen where the decision happens.
4. **Prove it on `bakerysf_test`.** `php tests/run_*.php` or `tests-for --files=`. Never PHPUnit. Never the nightly mirror.
5. **Leave the ledger.** Eight §10 fields. Pin **Decided**. If you touched a file the map does not know, the handoff will say so — patch `includes/agent_work_map.php` in the same breath.

## Three local databases

| Name | Role | Agents may write? |
|------|------|-------------------|
| `bakerysf_local` | Nightly production mirror | No. Looking only (and demo recordings). |
| `bakerysf_stage_local` | Everyday development + Homebase ledger | Yes. This is where craft accumulates. |
| `bakerysf_test` | Disposable clone for `tests/run_*.php` | Only inside a test process. The test gate wipes it. |

The Homebase CLI hops from the mirror onto `bakerysf_stage_local` automatically. If staging does not exist: `php scripts/refresh_local_from_snapshot.php --target=bakerysf_stage_local`. Live production is never a classroom.

## Commands

```text
php scripts/agent_homebase.php brief --agent=SLUG --json
php scripts/agent_homebase.php tests-for --files="manager.php,lang/en.php" --json
php scripts/agent_homebase.php craft --json
php scripts/agent_homebase.php start --agent=SLUG --mission="one sentence"
php scripts/agent_homebase.php handoff --agent=SLUG --summary="1. ... 8. ..." --files="a.php,b.php"
```

Canonical slugs come from the brief. Aliases resolve; lesson progress is stored on the family.

## What “correct” means here

- Dated beats standing **per customer**.
- Historical invoices never use live `products.price`.
- Completing exception *work* never hides a still-true operational fact.
- i18n in `lang/en.php` and `lang/es.php`.
- Staging auto-push must never target `bakery.sourflour.org/bake`.
- Do not reopen shipped loops (invoice send, credit returns, production-plan commit, demand-flip, bake-sheet confirm re-entry, load/skip status alignment) as if they were still holes. Remaining work is baker UX, bake-sheet waste, staff pings.

## Intelligence, not volume

A sophisticated change is a small one that closes a bakery-day loop and leaves a map, a test, and a handoff. A large change that adds a home page is a failure of taste. Sour Flour OS is the pattern: flat PHP, one day, one ledger — reusable because it is specific.

## Schema file numbers

`schema_migrations` is keyed by the **full id** (`062_surveys_custom`), not the digits. Two agents can both ship `062_*.sql` and both apply. That already happened (010, 021, 025, 062; Live also recorded leftover `061_bread_education` and `065_bread_education_payments`). Do not rename those files.

Before adding SQL: `php scripts/next_schema_migration.php --name=your_slug`. Today that is `068_…`. A third file that reuses a prefix fails the schema compare suite and `run_migrations.php`.

## For the next agent

If the packet is silent on your files, you are in a hole. Fill the map, run the new suite, then do the product work. Do not invent a second studio.

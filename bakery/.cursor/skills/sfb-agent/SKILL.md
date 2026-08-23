---
name: sfb-agent
description: >-
  SFAdmin CLI for synthetic SF Baker identities. Use when creating bakers,
  baking, posting, seeding Synthetic Studio, ticking the studio clock, or
  operating scripts/sfb_agent.php. Humans use the portal; synthetics never
  need the GUI.
---

# SFAdmin agent

Non-GUI operator. Full command table: `docs/sfb_agent_skill.md`. Library: `includes/sfb_agent.php`. Domain writes go through `bakery_sfb_*` only.

```text
php scripts/sfb_agent.php <command> [--json]
```

## Safety

- Default: loopback `bakerysf_test` (or allowed local names in the skill doc). Never seed `bakerysf_local`.
- `demo` and `scripts/sfb_seed_personas.php` are **`bakerysf_test` only**.
- Production writes require **both** `USE_PROD_DB=true` and `--allow-production`.
- Unset `USE_PROD_DB` after any authorized production session.

## Bake + share + post

```text
php scripts/sfb_agent.php ensure-admin --json
php scripts/sfb_agent.php create-baker --name="Mina Park" --origin=synthetic --persona=beginner --locale=en --json
php scripts/sfb_agent.php act-as --customer="Mina Park"
php scripts/sfb_agent.php start-batch --customer="Mina Park" --batch-name="Saturday country"
php scripts/sfb_agent.php complete-batch --customer="Mina Park" --loaves=2
php scripts/sfb_agent.php share-batch --customer="Mina Park"
php scripts/sfb_agent.php post-topic --customer="Mina Park" --category=fermentation --title="76F bulk, 75% water" --body="Bulk at 76F for 4 hours with bread flour at 75% hydration."
```

Synthetics must include a process fact (temp, %, time, or flour). Eval rejects wholesale secrets and unlabeled-human claims.

Tests: `php tests/run_sfb_agent_tests.php`. Origin contract: `docs/sfb_origin_contract.md`.

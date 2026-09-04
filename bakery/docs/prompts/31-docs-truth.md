# Prompt 31 — Docs truth: stop misleading new agents

Wave 0 (foundation). `--agent=docs-truth`.

---

`README.md` and `ARCHITECTURE.md` described a Laravel-style MVC app with Composer, PHPUnit, `public/`, `src/Controllers` — none of which exist. New agents briefed from them invent a second architecture. Make the top-level docs describe the real stack, and register the program missions so `brief --agent=` returns a packet for each.

## Read first

- `BAKERY_PRODUCT_CONTEXT.md` §1, §5, §8, §9
- `docs/AGENT_DEVELOPMENT_MANUAL.md`, `AGENTS.md`
- `includes/agent_work_map.php`, `tests/run_agent_work_map_tests.php`
- `docs/prompts/11-exception-mobile.md` (brief template)

## Ship

1. Rewrite `README.md` and `ARCHITECTURE.md` from the code: flat PHP, `includes/` libraries, request lifecycle through `bakery_enforce_request_security`, three local DBs, both test gates, deploy model, growth rules.
2. `git mv` `CODE_REVIEW_REPORT.md` and `ideas-for-development.md` into `docs/archive/` and list them in `docs/archive/README.md`.
3. Mission briefs `docs/prompts/30-*.md` … `64-*.md` and a program section in `docs/prompts/README.md`.
4. `includes/agent_program_map.php` with one work-map entry per mission (files, tests, invariants, prompt, prompt_status), merged into `bakery_agent_work_map()`.

## Tests

`php tests/run_agent_work_map_tests.php`, `php tests/run_agent_homebase_tests.php`, `php tests/run_surface_hygiene_tests.php` (desktop), `php tests/run_deploy_surface_tests.php`.

## Done when

- `php scripts/agent_homebase.php brief --agent=<slug> --json` returns a packet for every slug 30–64.
- No top-level doc mentions Composer, PHPUnit, Laravel, `public/`, or `src/`.

**Status: shipped (this branch).**

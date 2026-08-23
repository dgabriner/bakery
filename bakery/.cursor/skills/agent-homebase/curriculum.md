# Curriculum (read after `brief`)

Required slugs: `invariants`, `simple-practices`.

Development craft (poem + cycles): `docs/AGENT_DEVELOPMENT_MANUAL.md` and `php scripts/agent_homebase.php craft --json`. Also the Homebase **Craft** tab.

Source of truth for lesson bodies is `includes/agent_homebase_seed.php`. File → test map: `includes/agent_work_map.php`.

## Product

Sour Flour OS runs one wholesale bakery day on flat PHP + MariaDB. Spine: Daily Run 8 stages. Work is closing loops, not adding screens.

## Practices

Everyday work on `bakerysf_stage_local`. Tests on `bakerysf_test` only. Never the nightly mirror `bakerysf_local`. Map every new `tests/run_*.php` or the drift test fails.

## Bugs to keep in mind

Commit-to-bake shipped; bakers still do not open Production Center. Additive confirm. Status divergence. Demand-flip. No staff pings. Bake-sheet waste unlogged.

Shipped: canonical invoice send; credits as FG returns.

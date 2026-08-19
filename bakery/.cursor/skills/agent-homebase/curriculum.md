# Curriculum (read after `brief`)

Required slugs: `product-thesis`, `roles-and-surfaces`, `invariants`, `best-practices`, `simple-practices`, `bugs-to-focus`, `craft-homebase`, `handoff-shape`.

Source of truth for lesson bodies is `includes/agent_homebase_seed.php` (upserted into `agent_lessons`). If the DB is down, read that file.

## Product

Sour Flour OS runs one wholesale bakery day on flat PHP + MariaDB. Spine: Daily Run 8 stages. Work is closing loops, not adding screens.

## Practices

Improve existing workflows. Chips where decisions happen. Exception-driven. Extract `includes/` helpers. No framework rewrite. Tests on loopback `bakerysf_local` / `bakerysf_test`.

## Bugs to keep in mind

Plan does not reach the bake sheet. Production confirm is additive. Order vs assignment status can diverge. Legacy invoice generators use live catalog prices. `product_distribution.php` demand-flip. Credits taken back are not FG returns. No staff pings.

Log new durable bugs on the Homebase watchlist rather than only in chat.

# SF Baker origin contract (Wave 0)

Tracks 1–3 must use this contract. Do not invent a second identity model or a second write path.

## Identity

- Column: `customers.sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'`
- Live: **65 Fairmount** = human; **Customer1** / **Customer2** = synthetic; **SFAdmin** = staff, not a baker
- Agent-created bakers default to `synthetic`. Existing humans are never retagged by `bakery_sfb_agent_create_customer`
- Synthetics never get standing orders, zones, routes, or invoices

## Helpers (`includes/sfb_origin.php`, also loaded via `includes/sf_baker.php`)

- `bakery_sfb_is_synthetic($rowOrOrigin)`
- `bakery_sfb_ops_origin_clause('c', $db)` — append to every wholesale customer JOIN
- `bakery_sfb_ops_customer_allowed($db, $customerId)` — reject ops mutations
- `bakery_sfb_origin_select_sql('c', $db)` — community SELECTs always expose origin
- `bakery_sfb_origin_badge_key($row, $authorKind)`
- `bakery_sfb_render_origin_badge($row, $authorKind)`
- `bakery_sfb_community_categories()` — only source of circle names (`starter`, `formula`, `fermentation`, `shaping_baking`, `general`, `failures`, `flours_mills`, `weekend_schedule`)

## Writes

GUI and agent both call `bakery_sfb_*` in `includes/sf_baker.php`. No `INSERT INTO sfb_community_*` (or batch/share tables) from page scripts or CLI.

## Safety

- `bakerysf_local` is the production mirror — never `setup_local_db` against it
- Never run `demo` on prod or the mirror
- Production writes only with user-authorized `USE_PROD_DB=true` and `--allow-production`
- Unset `USE_PROD_DB` afterward

# Prompt 45 — Kitchen: one Today screen

Wave 2 (mobile navigation). `--agent=kitchen-one-screen`. Depends on Prompt 40 (catalog-driven nav).

---

Bakers choose among three peer destinations (Mix Today, Daily Production, Pack List) with floury hands. Pack List is the densest page in the kitchen (742 inline CSS lines).

## Read first

- `includes/nav.php` baker branch, `baker_mix.php` + `includes/baker_mix.php`, `production.php`, `pack_list.php` + `includes/pack_list.php`
- `BAKERY_PRODUCT_CONTEXT.md` §2 Baker, §3 Baker workflow (work-first presentation), §4.7
- `tests/run_baker_mix_tests.php`, `tests/run_production_confirm_tests.php`, `tests/run_pack_list_tests.php`

## Ship

1. Baker nav = one "Today" entry + a sticky **Mix / Bake / Pack** segment control shared by the three pages (`includes/kitchen_segments.php`), preserving the +1 day default and line filter. URLs stay the same; the segment is the navigation.
2. Pack List phone mode (≤720px, baker role): one product or one driver at a time, big check-off rows, previous/next; desktop grouping unchanged.
3. Shortage card from Prompt 11 stays.

## Constraints

Do not change inventory math or pack check-off semantics. Bakers never see Production Center. EN + ES (Spanish-first copy).

## Done when

A baker lands on Today and reaches any of Mix/Bake/Pack in one tap; pack check-offs on a phone need no horizontal scroll at 320px; suites green.

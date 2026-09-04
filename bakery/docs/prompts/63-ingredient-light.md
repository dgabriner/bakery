# Prompt 63 — Ingredient loop, light

Wave 4 (integration). `--agent=ingredient-light`. Owner confirms scope before building beyond notes.

---

The Ingredient Planner turns the plan into grams and purchase hints, then stops. Whether flour was ordered or received lives in someone's memory. No PO system is wanted.

## Read first

- `ingredient_requirements.php`, `includes/ingredient_requirements.php`, `ingredients.php`, `inventory.php`
- `database/schema/005_inventory.sql`, `017_ingredient_purchasing.sql` (placeholders; real columns in `run_migrations.php`)
- `tests/run_ingredient_planner_tests.php`

## Ship — step A (notes only, no decision needed)

Per ingredient per bake date: "ordered" / "received" toggles with note and user stamp (`ingredient_purchase_notes`, new `NNN`, prefixed table). Planner shows the state beside each hint; Production Center kitchen strip shows a chip when a needed ingredient is neither ordered nor received.

## Ship — step B (owner confirms)

Simple stock adjust on `ingredients` (on-hand grams/units, last counted) feeding the planner's "short by" math. Still no PO, receiving, or lots.

## Done when

Friday's shortage is on the screen where the plan is made, not in memory; planner suite green.

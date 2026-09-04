# Prompt 64 — Retail cashier scope

Wave 4 (integration). `--agent=retail-scope-decision`. Owner decision; agent records it.

---

The cashier workspace (schema 074–076) does photos and catalog, not sales. Retail sales happen in Square POS outside Sour Flour OS. Either integrate Square retail sales into product/FG truth, or state plainly that retail sales are out of scope.

## Read first

- `cashier_shop_photos.php`, `cashier_add_product.php`, `includes/cashier_catalog.php`, `product_photos.php`
- `includes/square_invoices.php`, `square_webhook.php` (type routing), `docs/SQUARE_INVOICING.md`
- `includes/product_inventory.php` (FG ledger, `delivery` movement type)

## Options to put in front of the owner

- **A — Out of scope.** Retail sales stay in Square. Sour Flour OS keeps cashier = photos + catalog. Document in `BAKERY_PRODUCT_CONTEXT.md` §5 and §9.
- **B — Sales read-back.** Nightly pull of Square orders for the retail location into a prefixed `retail_sales` table; FG ledger posts a `retail_sale` movement so on-hand is honest. No POS UI in the app.
- **C — POS in app.** Not recommended (a module, not a loop).

## Ship

The decision as a Homebase **Decided**, the product-context paragraph, and — if B — a follow-on brief `65-retail-sales-readback.md` with tables, cron, and tests.

## Done when

An agent can read one paragraph and know whether retail sales are its problem.

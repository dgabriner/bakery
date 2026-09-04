# Prompt 55 — Product boundaries

Wave 3 (scalability). `--agent=product-boundaries`.

---

Core ops, the customer portal, SF Baker, Bread Education, cashier/retail, Square, Twilio, and surveys share one schema with `customers` as the identity hub. That is the business model — but new product features keep adding columns to `customers` and `daily_orders`, coupling every deploy and migration.

## Read first

- `database/schema/` 032–076 (which added columns to `customers` vs new `sfb_*` tables)
- `includes/sf_baker.php` (`bakery_sfb_ops_origin_clause`), `docs/sfb_origin_contract.md`
- `.opencode/agent/ox-reviewer.md`, `BAKERY_PRODUCT_CONTEXT.md` §8

## Ship

1. Pin a Homebase **Decided**: "New product surfaces add prefixed tables with FKs to `customers`; they do not add columns to `customers`, `daily_orders`, `daily_order_items`, or `standing_orders` without an owner-approved exception noted in the migration header."
2. Write the rule into `BAKERY_PRODUCT_CONTEXT.md` §8 and `ARCHITECTURE.md` growth rules.
3. Add it to the ox-reviewer checklist and `tests/run_schema_compare_tests.php` (new 077+ files that `ALTER TABLE customers ADD COLUMN` fail unless the file header contains `-- owner-approved-core-column`).

## Done when

The rule is in the product context, the reviewer checklist, and a test — not only in chat.

**Status:** shipped 2026-09-04 on `cursor/sour-flour-agent-program-a061` (PR #20). Decided pinned on Homebase whiteboard; rule in `BAKERY_PRODUCT_CONTEXT.md` §8, `ARCHITECTURE.md` growth rules, ox-reviewer checklist, and `run_schema_compare_tests.php` (077+). Staging and Live were not touched.

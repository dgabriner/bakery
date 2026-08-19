# Billing / QuickBooks boundary

Sour Flour OS owns operational billing truth:

**ordered → delivered → billable snapshot**

Accounting systems (QuickBooks) own payment and general ledger truth. This document describes the export boundary for a future adapter — **no OAuth or API integration is implemented yet**.

## Canonical invoice entity

There is no separate `invoices` table. Each billable unit is a **`daily_orders` row** with delivery confirmation snapshots.

| Sour Flour field | Meaning |
|------------------|---------|
| `daily_orders.id` | Stable internal delivery/order ID |
| `INV-{order_date Ymd}-{id padded 5}` | Canonical invoice identifier (computed, not stored) |
| `daily_orders.order_date` | Delivery date |
| `daily_orders.delivery_confirmed_at` | Invoice / confirmation timestamp |
| `daily_orders.total_amount` | Billable total after credits |
| `daily_orders.delivery_order_total` | Pre-credit order basis snapshot |
| `daily_orders.delivery_pricing_label` | Pricing tier label at confirm |
| `daily_orders.credits_taken_back` | Credits/returns at delivery |
| `daily_orders.status` | Includes `invoiced` when marked in OS |
| `daily_order_items.*` | Line qty, delivered qty, unit_price, line_total snapshots |

**Do not recalculate** historical amounts from `products.price` or current customer tiers.

## Legacy invoice paths (parallel, not canonical)

| Path | Invoice # format | Pricing source |
|------|------------------|----------------|
| Billing Center | Per-delivery `INV-YYYYMMDD-#####` | Item + delivery snapshots |
| `simple_invoice.php` | Per-customer-period | **`products.price` (live catalog)** |
| `generate_invoice.php` / `_simple.php` | Per-customer-period | Live catalog / mixed |

Use Billing Center + CSV export for accounting handoff. Legacy printables remain for convenience but may disagree with snapshots.

## CSV export (`billing_export.php`)

One row per line item. Columns:

- `invoice_id`, `daily_order_id`, `customer_id`, `customer_name`
- `invoice_date`, `delivery_date`
- `product_id`, `product_name`
- `quantity_ordered`, `quantity_delivered`, `unit_price`, `line_total`, `invoice_total`
- `credits_taken_back`, `pricing_label`, `status`, `memo`

Exports are hashed and recorded in `billing_exports` / `billing_export_invoices` when migration `022_billing_center` is applied.

## Future QuickBooks mapping (adapter fields)

| Sour Flour | QuickBooks Online (typical) |
|------------|----------------------------|
| `customers.id` + `customers.name` | Customer `Id` / `DisplayName` |
| `products.id` + `products.name` | Item `Id` / `Name` |
| Canonical `invoice_id` + `daily_order_id` | Invoice `DocNumber` + custom field for OS order id |
| `daily_order_items.unit_price` | Invoice line `UnitPrice` |
| Delivered qty (or ordered if not confirmed) | Invoice line `Qty` |
| `delivery_date` | Invoice `TxnDate` or line description |
| `memo` | Private note / line description |

Store external IDs in a future `accounting_customer_map` / `accounting_item_map` table when integration is built — **not added speculatively in this phase**.

## Payment / AR

- **COD:** `amount_collected` on `daily_orders` when driver confirms — route cash, not full AR.
- **Signature / invoice customers:** payment status **unknown externally** unless marked `invoiced` in OS.
- No due dates, no overdue logic, no QuickBooks payment sync.

## Statements

Generated from confirmed deliveries in a date range. `billing_statements` records generation and optional send metadata (`sent_at`, `sent_by`, `sent_to_email`). Email send requires configured SMTP/OAuth (not `MAIL_DRIVER=log`).

## Audit

`audit_log` records: `invoice_marked_invoiced`, `statement_generated`, `statement_sent`, `accounting_export_created`.

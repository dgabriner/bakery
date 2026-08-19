# Close the remaining day — three parallel agents

Paste-ready Cursor briefs. Open **each in its own chat** in the `bakery/` workspace. All three may start the same day if they obey file ownership.

Product goal: Daily Run’s remaining open stages become true. Demand confirmation and route closeout already exist. These three close **plan → baker**, **invoice → customer**, and **credits → stock**.

- [20 — Commit production plan](20-commit-production-plan.md) — baker executes the frozen plan
- [21 — Canonical invoice send](21-canonical-invoice-send.md) — Billing Center actually sends the snapshot document
- [22 — Credits as finished-goods returns](22-credits-as-returns.md) — door credits land in the inventory ledger

Read first: `BAKERY_PRODUCT_CONTEXT.md` §§1, 3, 4, 6, 7, 8, 10.

## File ownership (do not cross)

| Prompt | Owns | Must not edit |
|--------|------|----------------|
| 20 | `production_center.php`, `production.php`, `includes/production_plan.php` (new include OK), Daily Run **stage 2 only**, new `production_plan_commits` migration, plan-drift exception types | `complete_delivery.php`, `includes/billing*.php`, `customer_invoice.php`, `billing_center.php` |
| 21 | `billing_center.php`, `includes/billing*.php`, `billing_api.php`, `customer_invoice.php`, invoice-stage helpers, legacy generator redirects | `production.php`, `production_center.php`, `complete_delivery.php`, inventory movement math |
| 22 | `complete_delivery.php`, `includes/product_inventory.php`, `route_closeout.php` only if closeout double-counts credits | Production Center, Billing send, `customer_invoice.php` |

`includes/daily_run.php` is shared: Prompt 20 may change the **Commit Production Plan** stage block; Prompt 21 may change the **Invoice** stage block. Do not restyle Daily Run. Extract shared writes into `includes/`.

`includes/operational_exceptions.php` and `lang/en.php` + `lang/es.php` may be appended by all three (new keys / new exception types only — do not rewrite existing types).

## Out of these three

Staff alerts, portal clock-lock, bake-sheet waste, demand-scheduler DreamHost cron, AR aging, Square pay. Do not start them here.

## Homebase

```text
php scripts/agent_homebase.php brief --agent=YOUR-MISSION --json
php scripts/agent_homebase.php start --agent=YOUR-MISSION --mission="..."
```

Agent names: `commit-production-plan`, `canonical-invoice-send`, `credits-as-returns`. Local/test DB only. Do not deploy. Do not enable auto-push.

# Current Module & Access Guide

The current workspace is deliberately role-aware. `includes/navigation_catalog.php` is the single source of truth for the menu and the in-app [Module Guide](../module_guide.php). The original menu implementation remains unchanged in `includes/nav_historical.php` and is available to administrators at **Administration → Historical Navigation**.

## Access model

| Role | Current workspace access |
| --- | --- |
| Administrator | Every current module, user management, historical navigation, and administrator-only diagnostics. |
| Manager | All current operational modules: production, orders, customers, drivers, routes, products, and insights. Cannot alter users or roles, and does not receive historical or diagnostic tools in the day-to-day menu. |
| Baker | **Daily Production** and **Pack List** only. |
| Driver | **My Route** and **Call HQ** only. Delivery completion, photos, and route-specific actions are contained within My Route. |

## Current manager and administrator modules

| Area | Module | Purpose |
| --- | --- | --- |
| Workday | Operations Dashboard | Today’s order, production, and delivery snapshot. |
| Production | Production Center | Weekly finished-goods planning based on orders and stock. |
| Production | Daily Production | Daily bake schedule and quantities. Bakers can access this page. |
| Production | Pack List | Production-day packing checklist. Bakers can access this page. |
| Production | Finished Goods | Finished-good availability by delivery date. |
| Production | Driver Pickup Loads | Driver load quantities before departure. |
| Orders & Customers | Daily Orders | Daily customer-order review and changes. |
| Orders & Customers | Standing Orders | Recurring orders by customer and delivery day. |
| Orders & Customers | Customers | Customer records, contact, and ordering details. |
| Orders & Customers | Customer Schedule | Planned deliveries by customer and zone. |
| Orders & Customers | Delivery Zones | Zone setup and maintenance. |
| Orders & Customers | Pan Dulce Pricing | Zone-specific Pan Dulce pricing. |
| Orders & Customers | Pan Dulce Standards | Default Pan Dulce order quantities. |
| Orders & Customers | Invoice Center | Delivery invoice review and generation. |
| Orders & Customers | Sales Leads | Prospects and follow-up. |
| Delivery | Driver Assignment | Assign daily delivery work to drivers. |
| Delivery | My Route | Choose a driver identity and use that driver’s route workflow without ending an administrator or manager session. |
| Delivery | Daily Route | View daily, monthly, or list route plans. |
| Delivery | Driver Management | Driver records and recurring route maintenance. |
| Delivery | Standing Routes | Recurring customer-to-driver route plan. |
| Delivery | Route Manager | Assigned route-stop review and maintenance. |
| Delivery | Customer Map | Customer locations and delivery zones. |
| Products & Recipes | Products | Products that can be ordered and produced. |
| Products & Recipes | Dough Types & Lines | Dough and product-line organization. |
| Products & Recipes | Formulas | Dough formulas and recipe ratios. |
| Products & Recipes | Ingredients | Ingredient catalogue. |
| Insights | Customer Overview | Customer and delivery workload by zone. |
| Insights | Customer Routes | Customer-specific route and delivery information. |
| Insights | Product Distribution | Expected product quantity exploration across customers and days. |
| Insights | Module Guide | The in-app version of this reference. |

## Administration and retained tools

Only administrators see the **Administration** area. It contains **User Management** (staff identities, roles, and sign-in codes) and **Historical Navigation** (the full prior menu). Historical Navigation retains tools that are not part of the primary day-to-day workflow, including older order summaries, bread distribution, driver overview/list, route testing, and diagnostic-adjacent pages.

Access is enforced server-side in `includes/auth.php`; hiding a menu item is never the only control.

### Retained historical-only menu entries

These pages are still reachable from the administrator Historical Navigation page but are intentionally not promoted into the current workspace:

| Module | Why it is retained rather than promoted |
| --- | --- |
| `standing_orders.php` | Earlier standing-order editor; **Standing Orders Manager** is the current management entry point. |
| `bread_distribution.php` | Older, large distribution workspace retained for reference and exceptional workflows. |
| `orders.php` | Older order-summary page; **Daily Orders** and **Invoice Center** cover the active workflow. |
| `driver_overview.php` | Legacy driver/route overview that overlaps Driver Management and Daily Route. |
| `driver_list.php` | Legacy route-list presentation. Drivers are redirected to **My Route**. |
| `route_tester.php` | Route-testing utility; not a daily operational task. |

The historical area also keeps the old grouping and duplicate entry points for continuity while staff transition to the curated workspace.

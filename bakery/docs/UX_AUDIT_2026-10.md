# Sour Flour OS UX audit — 2026-10-09

Reviewed locally against **`bakerysf_test` only** (`APP_ENV=local`, `USE_PROD_DB=false`, host `127.0.0.1`). Demo fixtures were already loaded. Dated orders for 2026-10-09 through 2026-10-15 were filled with `php scripts/demand_scheduler.php --force` so Daily Run, Pack List, Production, and the driver route had a real day to show. Staging and Live were not opened, synced, or deployed.

Staff pages were signed in as the local administrator (English, then Spanish). Driver route, delivery confirm, and survey were signed in as the local driver, whose default language is Spanish. Phone shots are a 390×844 viewport. Desktop shots are 1440×900.

Screenshots live in [`docs/ux-audit/`](ux-audit/).

## What was checked and did not show up

Page HTML came back quickly on this fixture set. Authenticated loads in the browser settled in under a second, and unauthenticated redirects returned in a few milliseconds. Nothing in this pass looked like a slow query. Re-check on a production-sized snapshot before spending a PR on query work.

Login (`login.php`) is the most consistent screen: Spanish by default, an English switch, a four-digit code, and no PHP warning. Shots: [`login_es_mobile.jpg`](ux-audit/login_es_mobile.jpg), [`login_en_desktop.jpg`](ux-audit/login_en_desktop.jpg). No ranked fix.

## Ranked fixes

Impact is what a manager or driver feels on a normal day. Effort is how small the follow-up PR is.

### 1. Spanish command center still runs in English

- **Impact:** High. Managers default to Spanish, and this is the first screen of the day.
- **Effort:** Medium. Strings already have a home in `lang/en.php` and `lang/es.php`; the stage machine never calls them.
- **Pages:** Operations Dashboard, Daily Run. The same English stage names are repeated on Pack List and Daily Production through the kitchen strip.
- **Shots:** [`dashboard_es_mobile.jpg`](ux-audit/dashboard_es_mobile.jpg), [`dashboard_es_desktop.jpg`](ux-audit/dashboard_es_desktop.jpg), [`daily_run_es_mobile.jpg`](ux-audit/daily_run_es_mobile.jpg), [`pack_list_es_desktop.jpg`](ux-audit/pack_list_es_desktop.jpg)
- **Where:**
  - `includes/dashboard_command_center.php` about lines 156, 310, 322, 562, 679, 862 — stage labels `Demand`, `Production`, `Pack`, `Load`, `Delivery`, `Invoice`
  - same file about line 362 — `2 lines · 28 units to pack` built with English plurals; line 951 — `Nothing to invoice yet`
  - same file about lines 478–482 — `Commit Production Plan` / `Commit plan` even though `daily_run.commit_plan` already exists
  - `includes/daily_run.php` about lines 283 and 450 — `Confirm Demand`, `Commit Production Plan`
  - `index.php` about line 35 — `date('l, F j, Y')` so the Spanish dashboard still says `Friday, October 9, 2026`
- **Fix:** Build stage labels, summaries, and action buttons with `bakery_t`. Use the existing `daily_run.commit_*` keys for the commit button. Format the dashboard date with `bakery_localized_date_label`. `includes/production_workflow_strip.php` prints whatever Daily Run puts in `label`, so Pack List and Production pick up the translation without their own copy.

On the Spanish dashboard the flow strip read `DEMAND / 1 daily order · 1 customer`, `PRODUCTION / 2 short`, `PACK / 2 lines · 28 units to pack`, `LOAD / 1 of 1 driver load incomplete`, `DELIVERY / 1 in progress · 0 delivered`, `INVOICE / Nothing to invoice yet`.

### 2. Daily Orders is an English page, and it opens on tomorrow

- **Impact:** High. This is the page managers use to change the day, and the default locale is Spanish.
- **Effort:** Medium. A long stretch of the page is literal English, including buttons that already have neighbors in `daily_orders.*`.
- **Page:** Daily Orders
- **Shots:** [`daily_orders_es_mobile.jpg`](ux-audit/daily_orders_es_mobile.jpg), [`daily_orders_es_desktop.jpg`](ux-audit/daily_orders_es_desktop.jpg), [`daily_orders_en_desktop.jpg`](ux-audit/daily_orders_en_desktop.jpg)
- **Where:** `daily_orders.php`
  - line 35 — default date is `strtotime('+1 day')`, so opening the page on Friday 2026-10-09 landed on Saturday, October 10
  - line 79 — `Showing customers with demand differences`
  - line 278 — `<h1>Daily Orders</h1>` while `page.daily_orders` already exists
  - lines 284–298 — `Generate from Standing Orders`, `Generate This Week`, `Change Date`, `Clear This Day`, `Daily Production`, `Driver Assignment`
  - line 320 — `date('l, F j, Y')` (`Saturday, October 10, 2026` in Spanish mode)
  - lines 334–336 — `Previous Day` / `Today` / `Next Day`
- **Fix:** Render the title and toolbar with `bakery_t` (add the missing generate/clear/date keys in the same PR). Format dates with `bakery_localized_date_label`. Keep tomorrow as the default only if the heading says it is the next delivery day; the Friday dashboard is talking about Sunday’s route, and this page quietly shows Saturday.

### 3. Driver phone: `__TIME__`, English progress, and a sideways scroll at 390px

- **Impact:** High. Drivers live on this screen, in Spanish, on a phone.
- **Effort:** Low. The translations already exist. The layout bug is one grid plus one flex row.
- **Page:** Driver route (`driver.php`), which is the complete-delivery screen. `complete_delivery.php` is the API behind the confirm step.
- **Shots:** [`driver_es_mobile.jpg`](ux-audit/driver_es_mobile.jpg), [`driver_es_desktop.jpg`](ux-audit/driver_es_desktop.jpg), [`driver_complete_confirm_es_mobile.jpg`](ux-audit/driver_complete_confirm_es_mobile.jpg)
- **Where:**
  - `driver.php` lines 1448–1451 — JS catalog is built with `bakery_t('driver.map_window_by', ['time' => '__TIME__'])`, so `:time` is replaced before the map script can fill it. The next-stop chip reads `Antes de __TIME__`.
  - `driver.php` line 296 — hardcoded `pcs` (`28 pcs`) while `driver.pcs` is `pzas`
  - `driver.php` line 640 and lines 2172–2176 — PHP and the refresh script write `0 of 1 done` / `0 / 1 done`, overwriting `driver.done_count` (`:done / :total hechas`)
  - `css/driver.css` line 824 — `.route-dashboard` is `minmax(0, 1fr) minmax(390px, 0.9fr)` with `align-items: start` (line 825). At 390px the media query switches the grid to one column (`css/driver.css` line 1493) but the stop-list heading (`css/driver.css` line 1166) does not wrap, so `.route-secondary-column` measured 426px inside a 370px column and the page scrolls sideways.
- **Fix:** Pass the `:time` templates through untranslated and let `includes/driver_route_map.js` `fillTemplate` substitute the clock. Use `driver.done_count` and `driver.pcs` in both the PHP and the progress script. Change the 390px grid minimum to `minmax(0, 1fr)` and allow `.stop-list-heading-row` to wrap (`flex-wrap: wrap; min-width: 0` on the children).

### 4. Customers is an emoji spreadsheet, in English, in Spanish mode

- **Impact:** High for how professional the back office looks. Medium for daily driving, because managers open it less often than Daily Orders.
- **Effort:** Medium. The page is hand-built English rather than a few missed keys.
- **Page:** Customers
- **Shots:** [`customers_es_desktop.jpg`](ux-audit/customers_es_desktop.jpg), [`customers_es_mobile.jpg`](ux-audit/customers_es_mobile.jpg), [`customers_en_mobile.jpg`](ux-audit/customers_en_mobile.jpg)
- **Where:** `customers.php` line 181 `<h1>👥 Customers Management</h1>`, line 198 `➕ Add New Customer`, and the matching toolbar (`Add Multiple Customers`, `Enable Quick Edit`) plus emoji column headers (`🗺️ ZONE`, `💰 PAN DULCE PRICE`). `page.customers` is already `Customers` / the Spanish catalog’s equivalent.
- **Fix:** Drop the emoji from headings and column titles. Use `page.customers` for the title and add toolbar/column keys beside the other manager strings. Keep the filter hint as a sentence, not a row of icons.

### 5. Customer Hub shows database table names and overflows a phone

- **Impact:** Medium-high. The hub is the “one customer” page, and it reads like an internal note.
- **Effort:** Low for the wording, low for the overflow.
- **Page:** Customer Hub, including the empty state
- **Shots:** [`customer_hub_en_mobile.jpg`](ux-audit/customer_hub_en_mobile.jpg), [`customer_hub_es_desktop.jpg`](ux-audit/customer_hub_es_desktop.jpg), [`customer_hub_empty_en_mobile.jpg`](ux-audit/customer_hub_empty_en_mobile.jpg)
- **Where:** `customer_record.php`
  - line 394 — fallback title `Customer Hub` (key `page.customer_record` exists) stays English in Spanish
  - line 426 — button `Refresh`
  - lines 530–560 — `Standing orders`, `Standing pattern`, `Billing & statements` as literal English
  - line 569 — `Recurring expectation from <code>standing_orders</code> and recurring route from <code>standing_routes</code>`
  - lines 97 and 349 — `min-width: 200px` / `220px` on the picker; `.cr-btn` is `white-space: nowrap` (line 114). At 390px `.cr-container` measured 521px and `.cr-card` 501px.
  - empty state around the picker does tell you to select a customer, so the gap is language and width, not a blank screen
- **Fix:** Replace the literals with `bakery_t`. Describe standing orders in a sentence, without `<code>` table names. Let the toolbar stack, set `min-width: 0` on the picker, and put `overflow-x: auto` on `.cr-history-table` (the tables near lines 637 and 707).

### 6. Survey says “tomorrow” and then opens Sunday, on a page that does not look like the app

- **Impact:** Medium. Drivers and managers get this link on the phone. Friday’s next standing *route* is Sunday, while Saturday still has a market order.
- **Effort:** Low for the title and the weekday rule. Medium if the hub should share the driver header.
- **Page:** `survey.php` (logged-in hub, no token)
- **Shots:** [`survey_driver_es_mobile.jpg`](ux-audit/survey_driver_es_mobile.jpg), [`survey_driver_es_desktop.jpg`](ux-audit/survey_driver_es_desktop.jpg), [`survey_admin_en_desktop.jpg`](ux-audit/survey_admin_en_desktop.jpg)
- **Where:**
  - `survey.php` lines 70–86 — a one-off `<style>` page (`system-ui`, no app header). The title is `survey.hub_title` (“Encuestas de ruta de mañana” / “Tomorrow’s route surveys”) while `survey.hub_sub` prints `Mismo día: 2026-10-11`.
  - `includes/survey_store_verify.php` `bakery_survey_delivery_weekdays` about lines 489–527 — weekdays come from `standing_routes` only, then Sunday is inserted if missing. Saturday has a standing order and no standing route, so it is skipped. `bakery_survey_next_delivery_date` (line 35) then jumps from Friday to Sunday.
  - `survey.php` lines 996 and 1023 — stop meta hardcoded as `pcs`
- **Fix:** Title the hub with the delivery date, not the word tomorrow. Build the weekday set from standing routes **and** standing orders so Saturday markets stay in the survey. Use `driver.pcs`. Reuse the driver page header (or the same tokens as `css/tokens.css`) so the 390px survey matches the route screen.

### 7. Opening complete-delivery shows raw JSON, and the confirm step says “Mixed Pan Dulce pricing”

- **Impact:** Medium. The confirm UI itself is the modal on `driver.php` (shot below). A GET to `complete_delivery.php` is what linked issues point at (`includes/customer_delivery_issues.php` builds `complete_delivery.php?order_id=`).
- **Effort:** Low.
- **Pages:** `complete_delivery.php`, and the confirm step on the driver route
- **Shots:** [`complete_delivery_get_en_mobile.jpg`](ux-audit/complete_delivery_get_en_mobile.jpg), [`complete_delivery_get_en_desktop.jpg`](ux-audit/complete_delivery_get_en_desktop.jpg), [`driver_complete_confirm_es_mobile.jpg`](ux-audit/driver_complete_confirm_es_mobile.jpg)
- **Where:**
  - `complete_delivery.php` lines 26–29 — every web request is `Content-Type: application/json`
  - line 762 — missing `action` throws `Action is required`, which the local error boundary renders as `{"success":false,"error":"Action is required [error_id …]"}`
  - lines 323–329 — empty pricing label becomes `Mixed Pan Dulce pricing`, `Store price`, or `Standard price`. The Spanish confirm step showed `Mixed Pan Dulce pricing` next to `28 piezas facturables`.
- **Fix:** On a browser GET with an order id, redirect to `driver.php` for that delivery date instead of JSON. Keep the POST API as JSON. Replace the three pricing labels with `bakery_t` keys.

### 8. Billing’s empty state tells you to hunt a “2099 fixture”

- **Impact:** Medium. The invoices queue is often empty at the start of the day, and the help text is written for the person who built the filter.
- **Effort:** Low. Copy only.
- **Pages:** Billing Center and the invoices panel (`invoice_center.php` only redirects to `billing_center.php?panel=invoices`)
- **Shots:** [`billing_en_mobile.jpg`](ux-audit/billing_en_mobile.jpg), [`billing_en_desktop.jpg`](ux-audit/billing_en_desktop.jpg), [`invoices_es_mobile.jpg`](ux-audit/invoices_es_mobile.jpg)
- **Where:**
  - `lang/en.php` about line 2669 — `billing.center_subtitle` mentions `2099` and `example.invalid`
  - `lang/en.php` about line 2695 — `billing.empty_queue` (“Turn on test rows under More filters if you are hunting a 2099 fixture.”)
  - `lang/es.php` about line 2564 — the Spanish string still says `fixture 2099`
- **Fix:** Say what to do next in bakery language: widen the dates, switch the queue to “All in range”, or deliver and confirm a stop before invoicing. Move the fixture hint into a code comment or the “More filters” control itself.

The queues were all zero on this fixture day because nothing had `delivery_confirmed_at`. That part is honest. The sentence around it is the problem.

### 9. Every admin page opens with a PHP warning

- **Impact:** High on this local app, because it is the first thing on every staff screenshot. The widget is local-only (`bakery_user_can_control_auto_push` returns false unless `IS_LOCAL`), so Live does not render it. It is still the PHP-notice bug this audit was asked to find.
- **Effort:** Low.
- **Pages:** Dashboard, Daily Run, Daily Orders, Pack List, Production, Customers, Customer Hub, Billing. Not the driver route.
- **Shots:** [`dashboard_en_mobile.jpg`](ux-audit/dashboard_en_mobile.jpg), [`dashboard_en_desktop.jpg`](ux-audit/dashboard_en_desktop.jpg)
- **Where:**
  - `includes/auto_push_control.php` line 176 — `proc_open()` of PowerShell emits `Warning: proc_open(): posix_spawn() failed: No such file or directory` into the response because local `display_errors` is on
  - `includes/auto_push_control.js` lines 46–52 — when that body is not JSON, the status line becomes `HTTP 200:` plus the raw warning HTML
  - `includes/header.php` lines 15 and 158–159 — drivers and managers still get the local banner, including `php scripts/pull_prod_to_local.php`, because `$showLocalDebugBanner` hides the banner for bakers only
- **Fix:** If PowerShell is missing, return `{"ok":false,"error":"…"}` and do not call `proc_open`. In the script, show a short status and never `text.slice` of an HTML body. Hide the shell-command sentence on `workspace-driver` (and keep it off the phone-width manager bar).

### 10. Pack List and Daily Production open on tomorrow with an English month

- **Impact:** Medium. On Friday the dashboard’s bake card is “Saturday bake for the Sunday route”, Daily Orders opens on Saturday, and these two pages also open on Saturday, labeled `Sábado, Oct 10, 2026`.
- **Effort:** Low.
- **Pages:** Pack List, Daily Production
- **Shots:** [`pack_list_en_desktop.jpg`](ux-audit/pack_list_en_desktop.jpg), [`pack_list_es_mobile.jpg`](ux-audit/pack_list_es_mobile.jpg), [`production_es_mobile.jpg`](ux-audit/production_es_mobile.jpg), [`production_en_desktop.jpg`](ux-audit/production_en_desktop.jpg)
- **Where:** `pack_list.php` line 60 and `production.php` line 95 — `$defaultDate = date('Y-m-d', strtotime('+1 day'))`. The visible month is `date()` (`Oct`) rather than `bakery_localized_date_label` (`oct`).
- **Fix:** Keep “bake for the next delivery” if that is the rule, and say so in the heading (`Hornear para entrega` already exists on Production). Format the date with `bakery_localized_date_label`. Point the default at the same delivery date Daily Run calls the next route, so Friday does not show three different days across Dashboard, Daily Orders, and Pack.

### 11. “1 customers” and “1 clientes”

- **Impact:** Low, and it sits on the main call to action.
- **Effort:** Low.
- **Pages:** Dashboard and Daily Run cadence card
- **Shot:** [`dashboard_en_desktop.jpg`](ux-audit/dashboard_en_desktop.jpg), [`daily_run_en_desktop.jpg`](ux-audit/daily_run_en_desktop.jpg)
- **Where:** `lang/en.php` `cadence.confirm_meta` (`:customers customers`), `lang/es.php` about line 3494 (`:customers clientes`), printed from `includes/demand_confirmation.php` about line 348. Friday’s card read `1 customers · 13 units` and, in Spanish, `1 clientes · 13 unidades`.
- **Fix:** Branch on the count (one customer / one cliente versus the plural) in that one echo.

## Parallel batches

Batches below do not share files, so they can be separate PRs at the same time. The Spanish catalog is the exception: every new key has to land in `lang/en.php` and `lang/es.php`, so those pages are one PR.

| Batch | Ships together | Files |
|---|---|---|
| A. Banner warning | PR of its own | `includes/auto_push_control.php`, `includes/auto_push_control.js`, `includes/header.php` |
| B. Driver phone | PR of its own | `driver.php`, `css/driver.css` |
| C. Survey hub | PR of its own | `survey.php`, `includes/survey_store_verify.php` |
| D. Kitchen dates | PR of its own | `pack_list.php`, `production.php` |
| E. Spanish manager copy | One PR, because of the catalogs | `lang/en.php`, `lang/es.php`, `includes/dashboard_command_center.php`, `includes/daily_run.php`, `includes/demand_confirmation.php`, `index.php`, `daily_orders.php`, `customers.php`, `customer_record.php`, `complete_delivery.php` |

Batch E covers fixes 1, 2, 4, 5, 7 (the pricing labels and the JSON GET), 8, and 11. Batches A–D cover fixes 9, 3, 6, and 10. Fix 7’s JSON redirect and its pricing strings share `complete_delivery.php`, so they stay in E rather than a second PR.

Suggested order if someone wants the smallest visible win first: **B** (driver phone), **A** (warning), **C** (survey), **D** (dates), then **E**.

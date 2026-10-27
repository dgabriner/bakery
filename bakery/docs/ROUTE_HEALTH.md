# Route health

Route health (`route_health.php`) is a read only page for administrators and managers. It is a draft. It is not approved for Staging or Live.

The page does not create orders, close routes, clock anyone in, or send email or text messages. The Build from standing link opens the existing Daily Orders screen. It does not run generation by itself.

## What it shows

- One row per driver per day in the chosen range. The menu opens the last 14 days. A date link from Bakery Manager opens that one day.
- Stops, delivered, open or never visited, delivered with an After photo, route closed, and clock-in.
- A driver with standing stops and no dated route is listed at 0 stops.
- Next sell day: dated orders compared with active standing stops. A row is marked short when dated orders are 0, or below half of the standing stops.
- Whether overnight generation (`scripts/demand_scheduler.php`) recorded a successful run for the due slot. The page only reads the cron ledger. It does not run the script.
- Stores left open or never visited 3 or more times in the range, with the driver and the dates.
- Delivered stops with no After photo, and bulk stamping. Bulk stamping is 3 or more stops closed within 5 minutes by one driver (`BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES` and `BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS` in `includes/route_stop_proof.php`).

## Photo rule

An After photo is delivery proof. A Before photo is arrival only. A receipt is not an After photo. The filename decides the type when it can be parsed, same as Route photo journey. Driver reports uses this same mark, so a Before photo is no longer "With photo".

Photo links go through `driver_photo.php`.

## Where the facts live

- Stops: `daily_order_assignments` and `daily_orders`.
- Standing stops: `standing_routes` for active customers.
- Photos: `driver_photos`.
- Clock-in: `time_clock_punches` joined to `users.driver_id` for that calendar day. There is no separate driver clock table.
- Route closed: `driver_loads.status` is `reconciled` for that driver and date. A load row that is only `loaded` is not closed. The operating-day closeout is the whole bakery day, so it is not used as a driver's route close.
- Overnight generation: the cron ledger read by `bakery_demand_scheduler_board_status` (operational events and `storage/cron/demand_scheduler.json`).

If clock punches or driver loads are not installed, those columns say they are not recorded.

## Tests

`php tests/run_route_health_tests.php` on `bakerysf_test` only.

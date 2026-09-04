# Prompt 43 — Driver offline queue

Wave 2 (mobile navigation). `--agent=driver-offline-queue`. Product context §7 names this the biggest day-of risk still deferred.

---

Photo upload and confirm are plain `fetch` calls; without signal they fail and the driver retypes. Add a browser outbox so the stop can be finished offline and synced when signal returns — without any double-write.

## Read first

- `includes/driver_delivery.js`, `upload_driver_photo.php`, `complete_delivery.php` (`bakery_confirm_delivery` re-confirm deltas)
- `includes/product_inventory.php` (`bakery_inventory_record_delivery_credit_returns` — must stay idempotent per order)
- `database/schema/013_delivery_confirmation.sql`, `014_delivery_invoice_snapshot.sql`
- `php scripts/next_schema_migration.php --name=delivery_client_request_id`

## Ship

1. Migration `NNN_delivery_client_request_id.sql`: `daily_orders.confirm_request_id VARCHAR(64) NULL` with unique index, `driver_photos.client_request_id VARCHAR(64) NULL` unique. Additive, `IF NOT EXISTS` style for the hosted gate.
2. Endpoints accept `client_request_id`; a repeat with the same id returns the original result (200, `duplicate:true`) and writes nothing.
3. `includes/driver_offline_outbox.js`: IndexedDB queue of `{id, kind, payload, photoBlob}`; `navigator.onLine` + retry with backoff; UI chips "queued" / "synced" / "failed — tap to retry" on the stop card and route header.
4. Confirm UI works with queued photos (the stop shows as done locally; the server catches up).

## Constraints

No service worker caching of pages (keeps `client_refresh.js` semantics). No changes to van math. EN + ES.

## Tests

Extend `run_driver_workflow_tests.php`: same `client_request_id` twice → one confirmation, one set of movements. `run_credit_return_tests.php` still green. String-assert outbox wiring in `run_driver_photo_ui_tests.php`.

## Done when

Airplane mode: photo + confirm queue; back online: they sync once; `inventory_movements` shows a single return set.

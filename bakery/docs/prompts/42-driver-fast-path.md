# Prompt 42 — Driver fast path

Wave 2 (mobile navigation). `--agent=driver-fast-path`. Sister: `43-driver-offline-queue.md`.

---

Completing a stop is 6–10 taps: arrival photo → activate/capture/save → quantities → invoice preview → confirm → next. GPS tracking starts only after the first photo. The driver UX is the reference implementation, so change it surgically.

## Read first

- `driver.php` (next-stop card ~571–898), `includes/driver_delivery.js` (wizard steps `photo → delivery → invoice`), `css/driver.css`
- `complete_delivery.php`, `upload_driver_photo.php`, `includes/global_tracking.js`
- `BAKERY_PRODUCT_CONTEXT.md` §3 driver workflow, §4.8–4.9
- `tests/run_driver_workflow_tests.php`, `tests/run_driver_photo_ui_tests.php`

## Ship

1. Wizard default = **Photo → Confirm → Next**: quantities pre-filled from the order, credits/COD/invoice preview collapsed under one "Adjust" disclosure. Confirm is one primary 56px button. Adjustments open inline, not as extra steps.
2. "Arrived" opens the camera directly (`capture="environment"`) and, on success, advances to Confirm without a separate Save tap.
3. GPS: start `global_tracking.js` when the route view loads for a dated route today (existing permission/localStorage gate respected), not after the first photo.
4. Keep every write through `bakery_confirm_delivery`; no new endpoint.

## Constraints

Do not change credit allocation, snapshot pricing, or COD math. Do not remove the ability to edit quantities — collapse it. EN + ES for any new copy.

## Done when

Happy path is 3 taps from "Arrived" to next stop; both driver suites green; §4.9 billable math unchanged (assert in tests).

**Status:** shipped 2026-09-04 — Photo → Confirm → Next with Adjust disclosure for quantities/COD/invoice; GPS starts on today's route load; writes still through `bakery_confirm_delivery`. Staging and Live were not touched.

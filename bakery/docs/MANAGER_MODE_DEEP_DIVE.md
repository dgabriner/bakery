# Bakery Manager — Workflow Deep Dive

## Outcome

`manager.php` is the management front door for an operating date. The **manager role** (Laura) gets a phone-first focused workspace: Today, Routes, Kitchen, Missed, and a More drawer that still holds the full operational catalog. Administrators keep the desktop command center (workshop, attention queue, recovery forms) and the ops hamburger. Completing exception *work* never hides a still-true operational fact.

The workspace centers the work a floor manager has to own:

1. Are dated orders ready?
2. Does every stop have a route and driver?
3. What is happening across drivers right now?
4. What must be reconciled before the operating day can close?

Production, packing, baker presence, and pickup are connected to the same selected date as read-first handoff signals. The manager can see saved plan targets versus demand and posted production, shared packing check-offs, the latest Daily Production or Pack List a baker opened, and a compact operating-event audit without creating a second source of truth.

## Daily manager workflow

| Moment | Manager Mode signal | Canonical next action |
| --- | --- | --- |
| Before planning | **Orders ready** plus missing standing customers | Daily Orders — review/generate dated demand |
| Before dispatch | **Routes assigned** plus unassigned stops | Driver Assignment — build/review dated routes |
| During delivery | **Driver progress** plus per-driver stop states | Driver board, My Route, or Route Manager |
| At exceptions | Ordered queue sorted critical → warning → info | The contextual deep link in the attention queue |
| End of day | **Closeout** and open-route count | Route Closeout, then Daily Run |

The attention queue uses the existing command-center exception contract, so missing orders, unassigned stops, failed deliveries, load gaps, quantity variances, route-closeout work, invoice work, and service issues are not silently reduced to healthy-looking zeroes. Links to Daily Orders, Driver Assignment, and Route Closeout preserve the selected date and return to Manager Mode.

## Current responsibility boundaries

- **Bakery Manager:** triage, supervision, production/packing handoff, baker workflow visibility, and day-level navigation. It is a read-first command surface, not another source of truth.
- **Daily Orders:** the dated commercial commitment and demand corrections.
- **Driver Assignment:** dated route ownership and route shaping.
- **Route Manager / My Route:** stop-level route work and delivery execution.
- **Route Closeout:** loaded-versus-delivered/returned/waste reconciliation.
- **Daily Run:** the authoritative operating-day checklist and final closeout gate.

This avoids the existing route-tool overlap from becoming a second set of competing edits: Manager Mode points to the established workflow rather than recreating it.

## Deep-dive findings

The OS already has substantial delivery capability: recurring routes, dated assignments, route ordering, delivery status, route cash, load/closeout controls, and a shared exception system. The operational problem was discoverability and context switching: a manager had to know which of several screens held the next answer.

The first Manager Mode iteration closes that gap with a date-scoped scorecard, action queue, and driver board. It intentionally does not add a new route status, order state, or database table. That preserves the current tested state transitions while establishing one place to make decisions.

Known limits remain:

- Driver progress is based on recorded assignment status, not a guaranteed live location or communication signal.
- The board does not yet expose vehicle capacity, stop ETA, or route duration.
- Exception acknowledgement, owner, note, and completed state live in `manager_exception_work`. Completing that row does not suppress the live operational exception.
- Production, packing, finished goods, and pickup loads remain canonical linked modules. The phone Kitchen board is read-only; edits stay on Daily Production and Pack List.
- Day-of phone sheets (move one stop, dated qty, skip) call existing helpers. Driver Assignment remains the canonical wide route board.
- Broader release/security issues in `docs/NEXT_STEPS_TODO.md` still need to be handled before a production deployment.

## Recommended next increments

1. Add a manager exception work queue: acknowledgement, owner, due time, note, and resolved state; keep the underlying operational exception source unchanged.
2. Add planning signals to the driver board: driver availability, vehicle/capacity, route stop count, estimated duration, first/last delivery window, and unassigned-zone pressure.
3. Bring production and packing into the same date view as explicit handoff readiness: demand confirmed → production planned → packed → loaded. Do not make a second production source of truth.
4. Add driver communication and recovery: “contact driver,” last check-in, failed-stop reason, retry/reassign flow, and an auditable manager override.
5. After data contracts are stable, introduce plan-versus-actual measures: on-time %, fill rate, returns/waste, route completion time, COD reconciliation, credits, and invoicing lag.

## Prompts for the next agents

> Deepen Manager Mode’s exception queue. Add acknowledgement, assigned manager, due time, resolution note, and completed state using a small migration and a shared service. Preserve the existing exception categories and deep links. Add local-only regression tests and do not deploy.

> Add a route-planning panel to Manager Mode. Show unassigned stops by zone, driver capacity/availability, route stop counts, delivery-window pressure, and safe reassignment shortcuts. Reuse Driver Assignment as the canonical mutation surface.

> Connect Manager Mode to Production and Packing as a handoff board for the selected operating date: dated demand confirmed, production planned vs demand, packed vs required, and loaded vs required. Reuse existing command-center and Daily Run metrics; do not create duplicate state fields.

> Improve delivery recovery from Manager Mode. Design an auditable failed-stop workflow with reason codes, manager notes, retry/reassign rules, customer communication status, and clear billing/credit handoff. First document the state model and add tests before modifying mutations.

> Perform the P0 security and release-gate work in docs/NEXT_STEPS_TODO.md. Keep the mixed working tree intact, do not auto-push, add a fail-closed local-test target guard, and report the exact test results.

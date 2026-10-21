<?php
/**
 * Compute COD cash totals for a driver's deliveries on a route.
 *
 * Pan Dulce routes are cash by default. When a driver finishes a stop, use the
 * recorded cash amount when available; older completed stops fall back to their
 * delivery total so the route manager can still reconcile the driver's cash.
 */

/**
 * Booked snapshot for a stop, including a real zero or a negative credit net.
 * Null means the snapshot was never written. Zero is a saved total.
 */
function route_manager_booked_amount(array $delivery): ?float
{
    if (!array_key_exists('delivery_order_total', $delivery)) {
        return null;
    }
    $snapshot = $delivery['delivery_order_total'];
    if ($snapshot === null || $snapshot === '') {
        return null;
    }
    return round((float)$snapshot, 2);
}

/**
 * Best-known dollar amount for a stop (confirmed cash, then the booked snapshot).
 *
 * A negative snapshot is a credit or return larger than the bread left that
 * day. It is not a missing total, so it is not replaced by the raw line sum.
 */
function route_manager_estimate_amount(array $delivery): float
{
    if (($delivery['amount_collected'] ?? null) !== null && $delivery['amount_collected'] !== '') {
        // Recorded cash is authoritative for that stop once collected.
        if (($delivery['delivery_status'] ?? '') === 'delivered'
            && (($delivery['payment_collection'] ?? 'cod') === 'cod')) {
            return (float)$delivery['amount_collected'];
        }
    }

    $booked = route_manager_booked_amount($delivery);
    if ($booked !== null) {
        return $booked;
    }

    $total = $delivery['total_amount'] ?? null;
    if ($total !== null && $total !== '' && abs((float)$total) > 0.00001) {
        return round((float)$total, 2);
    }

    return round((float)($delivery['order_total_estimate'] ?? 0), 2);
}

function route_manager_compute_cash_summary(array $deliveries, ?float $cashTurnedIn = null): array
{
    $collected = 0.0;
    $expectedRemaining = 0.0;
    $totalSold = 0.0;
    $codStopCount = 0;
    $codDeliveredCount = 0;
    $cashRecordedCount = 0;
    $cashUnrecordedCount = 0;
    $prepaidStopCount = 0;

    foreach ($deliveries as $delivery) {
        $status = $delivery['delivery_status'] ?? 'pending';
        if ($status === 'cancelled' || $status === 'failed') {
            continue;
        }

        $payment = $delivery['payment_collection'] ?? 'cod';
        $estimate = route_manager_estimate_amount($delivery);
        $totalSold += $estimate;

        $prepaid = !empty($delivery['square_prepaid'])
            && trim((string)($delivery['cod_override_reason'] ?? '')) === '';
        if ($prepaid) {
            $prepaidStopCount++;
        }
        if ($payment !== 'cod' || $prepaid) {
            continue;
        }

        $codStopCount++;

        if ($status === 'delivered') {
            $codDeliveredCount++;
            if ($delivery['amount_collected'] !== null && $delivery['amount_collected'] !== '') {
                $collected += (float)$delivery['amount_collected'];
                $cashRecordedCount++;
            } else {
                $collected += $estimate;
                $cashUnrecordedCount++;
            }
        } else {
            $expectedRemaining += $estimate;
        }
    }

    $cashOnHand = round($collected, 2);
    $turnedIn = $cashTurnedIn !== null ? round($cashTurnedIn, 2) : null;
    $stillOwed = $turnedIn === null ? $cashOnHand : round(max(0, $cashOnHand - $turnedIn), 2);

    return [
        'cod_stop_count' => $codStopCount,
        'cod_delivered_count' => $codDeliveredCount,
        'cash_recorded_count' => $cashRecordedCount,
        'cash_unrecorded_count' => $cashUnrecordedCount,
        'cash_on_hand' => $cashOnHand,
        'expected_remaining' => round($expectedRemaining, 2),
        'turn_in_total' => round($collected + $expectedRemaining, 2),
        'cash_turned_in' => $turnedIn,
        'cash_still_owed' => $stillOwed,
        'total_sold' => round($totalSold, 2),
        'prepaid_stop_count' => $prepaidStopCount,
    ];
}

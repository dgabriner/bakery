<?php
/**
 * Compute COD cash totals for a driver's deliveries on a route.
 *
 * Pan Dulce routes are cash by default. When a driver finishes a stop, use the
 * recorded cash amount when available; older completed stops fall back to their
 * delivery total so the route manager can still reconcile the driver's cash.
 */

/**
 * Best-known dollar amount for a stop (confirmed cash, then order totals).
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

    $amount = (float)($delivery['delivery_order_total'] ?? 0);
    if ($amount <= 0) {
        $amount = (float)($delivery['total_amount'] ?? 0);
    }
    if ($amount <= 0) {
        $amount = (float)($delivery['order_total_estimate'] ?? 0);
    }
    return $amount;
}

function route_manager_compute_cash_summary(array $deliveries): array
{
    $collected = 0.0;
    $expectedRemaining = 0.0;
    $totalSold = 0.0;
    $codStopCount = 0;
    $codDeliveredCount = 0;
    $cashRecordedCount = 0;
    $cashUnrecordedCount = 0;

    foreach ($deliveries as $delivery) {
        $status = $delivery['delivery_status'] ?? 'pending';
        if ($status === 'cancelled' || $status === 'failed') {
            continue;
        }

        $payment = $delivery['payment_collection'] ?? 'cod';
        $estimate = route_manager_estimate_amount($delivery);
        $totalSold += $estimate;

        if ($payment !== 'cod') {
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

    return [
        'cod_stop_count' => $codStopCount,
        'cod_delivered_count' => $codDeliveredCount,
        'cash_recorded_count' => $cashRecordedCount,
        'cash_unrecorded_count' => $cashUnrecordedCount,
        'cash_on_hand' => round($collected, 2),
        'expected_remaining' => round($expectedRemaining, 2),
        'turn_in_total' => round($collected + $expectedRemaining, 2),
        'total_sold' => round($totalSold, 2),
    ];
}

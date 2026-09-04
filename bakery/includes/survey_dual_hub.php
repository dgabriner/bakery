<?php
/**
 * Tonight's dual survey hub helpers: lock-stores + set-order links together.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Mint/open store_verify + route_order URLs for HQ (driverId=0) or one driver.
 *
 * @return array{
 *   delivery_date:string,
 *   verify_token:string,
 *   verify_url:string,
 *   order_token:string,
 *   order_url:string
 * }
 */
function bakery_survey_dual_hub_links(PDO $db, int $driverId, string $deliveryDate, int $createdBy = 0): array
{
    $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    $verify = bakery_survey_ensure_store_verify($db, $driverId, $deliveryDate, $createdBy);
    $order = bakery_survey_ensure_route_order($db, $driverId, $deliveryDate, $createdBy);
    $vTok = (string)($verify['token'] ?? '');
    $oTok = (string)($order['token'] ?? '');
    return [
        'delivery_date' => $deliveryDate,
        'verify_token' => $vTok,
        'verify_url' => $vTok !== '' ? bakery_survey_link_url($vTok, $deliveryDate) : '',
        'order_token' => $oTok,
        'order_url' => $oTok !== '' ? bakery_survey_link_url($oTok, $deliveryDate) : '',
    ];
}

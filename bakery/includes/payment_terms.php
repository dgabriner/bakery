<?php
/**
 * Customer payment terms decide the delivery path.
 * COD is cash the driver collects. Invoice and check stay on the invoice list.
 * payment_collection stays in sync for older COD checks: cod, or signature for the other two.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_payment_terms_normalize($value): string
{
    $term = strtolower(trim((string)$value));
    if ($term === 'invoice' || $term === 'signature') {
        return 'invoice';
    }
    if ($term === 'check') {
        return 'check';
    }
    return 'cod';
}

function bakery_payment_terms_is_cod($value): bool
{
    return bakery_payment_terms_normalize($value) === 'cod';
}

function bakery_payment_terms_is_invoice_path($value): bool
{
    $term = bakery_payment_terms_normalize($value);
    return $term === 'invoice' || $term === 'check';
}

/**
 * @param array<string, mixed> $post
 * @return array{payment_terms:string,payment_collection:string}
 */
function bakery_customer_payment_terms_from_post(array $post): array
{
    $raw = $post['payment_terms'] ?? $post['payment_collection'] ?? 'cod';
    $terms = bakery_payment_terms_normalize($raw);
    return [
        'payment_terms' => $terms,
        'payment_collection' => bakery_payment_terms_is_cod($terms) ? 'cod' : 'signature',
    ];
}

/**
 * Pan Dulce still forces cash when the account is COD.
 * Invoice and check terms keep the non-cash path.
 */
function bakery_payment_terms_pan_dulce_collection_sql(PDO $db): string
{
    $pan = "EXISTS (
        SELECT 1
        FROM daily_order_items payment_doi
        INNER JOIN products payment_p ON payment_p.id = payment_doi.product_id
        INNER JOIN dough_types payment_dt ON payment_dt.id = payment_p.dough_type_id
        INNER JOIN product_lines payment_pl ON payment_pl.id = payment_dt.product_line_id
        WHERE payment_doi.daily_order_id = do.id
          AND payment_pl.name = 'Pan Dulce'
    )";
    $fallback = "COALESCE(c.payment_collection, 'cod')";
    if (function_exists('column_exists') && column_exists($db, 'customers', 'payment_terms')) {
        return "CASE WHEN c.payment_terms IN ('invoice', 'check') THEN 'signature' WHEN {$pan} THEN 'cod' ELSE {$fallback} END";
    }
    return "CASE WHEN {$pan} THEN 'cod' ELSE {$fallback} END";
}

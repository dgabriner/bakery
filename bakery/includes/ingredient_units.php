<?php
/**
 * Explicit mass-unit helpers for ingredient planning.
 *
 * Converts only unambiguous mass units (g, kg, lb, oz). Never infers
 * volume→weight or count→weight unless the catalogue unit is exactly "each"
 * and the caller opts out of mass comparison.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Mass units this layer will convert (normalized lowercase token => grams per 1 unit).
 *
 * @return array<string, float>
 */
function bakery_ingredient_mass_unit_factors(): array
{
    return [
        'g' => 1.0,
        'gram' => 1.0,
        'grams' => 1.0,
        'kg' => 1000.0,
        'kilogram' => 1000.0,
        'kilograms' => 1000.0,
        'lb' => 453.59237,
        'lbs' => 453.59237,
        'pound' => 453.59237,
        'pounds' => 453.59237,
        'oz' => 28.349523125,
        'ounce' => 28.349523125,
        'ounces' => 28.349523125,
    ];
}

/**
 * Normalize a catalogue unit string to a canonical mass token, or null if not mass.
 */
function bakery_ingredient_normalize_mass_unit(?string $unit): ?string
{
    if ($unit === null) {
        return null;
    }
    $token = strtolower(trim($unit));
    if ($token === '') {
        return null;
    }
    $token = str_replace(['.', ' '], '', $token);
    $map = [
        'g' => 'g',
        'gram' => 'g',
        'grams' => 'g',
        'kg' => 'kg',
        'kilogram' => 'kg',
        'kilograms' => 'kg',
        'lb' => 'lb',
        'lbs' => 'lb',
        'pound' => 'lb',
        'pounds' => 'lb',
        'oz' => 'oz',
        'ounce' => 'oz',
        'ounces' => 'oz',
    ];
    return $map[$token] ?? null;
}

/**
 * Convert a quantity in a mass unit to grams, or null when the unit is not supported.
 */
function bakery_ingredient_unit_to_grams(float $quantity, ?string $unit): ?float
{
    $normalized = bakery_ingredient_normalize_mass_unit($unit);
    if ($normalized === null) {
        return null;
    }
    $factors = bakery_ingredient_mass_unit_factors();
    return $quantity * $factors[$normalized];
}

/**
 * Convert grams into a target mass unit for display, or null when unsupported.
 */
function bakery_ingredient_grams_to_unit(float $grams, ?string $unit): ?float
{
    $normalized = bakery_ingredient_normalize_mass_unit($unit);
    if ($normalized === null) {
        return null;
    }
    $factors = bakery_ingredient_mass_unit_factors();
    return $grams / $factors[$normalized];
}

/**
 * Whether an ingredient row's catalogue unit can be compared to formula grams.
 */
function bakery_ingredient_stock_unit_comparable(?string $unit): bool
{
    return bakery_ingredient_normalize_mass_unit($unit) !== null;
}

/**
 * Format a quantity with its unit for display.
 */
function bakery_ingredient_format_quantity(float $quantity, ?string $unit, int $decimals = 2): string
{
    $unit = trim((string)$unit);
    $formatted = rtrim(rtrim(number_format($quantity, $decimals, '.', ''), '0'), '.');
    return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
}

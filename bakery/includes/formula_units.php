<?php
/**
 * Baker dough-formula unit display.
 * Grams stay the source of truth; lb and gallons are display conversions only.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Grams per avoirdupois pound (baker spec). */
if (!defined('BAKERY_GRAMS_PER_LB')) {
    define('BAKERY_GRAMS_PER_LB', 453.592);
}

/**
 * Documented liquid densities in lb per US gallon.
 *
 * @return array<string, float>
 */
function bakery_formula_liquid_densities()
{
    return [
        'water' => 8.34,
        'milk' => 8.6,
        'cream' => 8.4,
        'oil' => 7.7,
        'eggs' => 8.65,
        'honey' => 12.0,
        'starter_liquido' => 8.5,
        'default_liquid' => 8.34,
    ];
}

function bakery_grams_to_lb($grams)
{
    return ((float) $grams) / BAKERY_GRAMS_PER_LB;
}

function bakery_grams_to_gal($grams, $densityLbPerGal)
{
    $density = (float) $densityLbPerGal;
    if ($density <= 0.0) {
        return null;
    }
    return bakery_grams_to_lb($grams) / $density;
}

function bakery_formula_normalize_text($text)
{
    $text = strtolower(trim((string) $text));
    return strtr($text, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
}

/**
 * Classify an ingredient for gallon display.
 *
 * @return array{liquid: bool, kind: string, density_lb_per_gal: float|null}
 */
function bakery_formula_classify_ingredient($name, $unit = '')
{
    $nameN = bakery_formula_normalize_text($name);
    $unitN = bakery_formula_normalize_text($unit);
    $densities = bakery_formula_liquid_densities();

    $dryHints = ['powder', 'polvo', 'dry', 'seco', 'dried', 'instant', 'deshidrat'];
    foreach ($dryHints as $hint) {
        if (strpos($nameN, $hint) !== false) {
            return [
                'liquid' => false,
                'kind' => 'dry',
                'density_lb_per_gal' => null,
            ];
        }
    }

    $countUnits = ['each', 'ea', 'pcs', 'pc', 'count', 'unidad', 'unidades', 'ct'];
    if (in_array($unitN, $countUnits, true)) {
        return [
            'liquid' => false,
            'kind' => 'count',
            'density_lb_per_gal' => null,
        ];
    }

    $kind = null;
    if (
        preg_match('/(starter|masa madre|levain).*(liquid|liquido)|(liquid|liquido).*(starter|masa madre|levain)/', $nameN)
        || strpos($nameN, 'starter liquido') !== false
    ) {
        $kind = 'starter_liquido';
    } elseif (strpos($nameN, 'honey') !== false || preg_match('/\bmiel\b/', $nameN) || strpos($nameN, 'jarabe') !== false || strpos($nameN, 'syrup') !== false || strpos($nameN, 'molasses') !== false) {
        $kind = 'honey';
    } elseif (strpos($nameN, 'oil') !== false || strpos($nameN, 'aceite') !== false) {
        $kind = 'oil';
    } elseif (preg_match('/\b(egg|huevo)/', $nameN)) {
        $kind = 'eggs';
    } elseif (preg_match('/\b(cream|crema|nata)\b/', $nameN)) {
        $kind = 'cream';
    } elseif (preg_match('/\b(buttermilk|milk|leche)\b/', $nameN)) {
        $kind = 'milk';
    } elseif (preg_match('/\b(water|agua)\b/', $nameN)) {
        $kind = 'water';
    } elseif (preg_match('/\b(jugo|juice|yogurt|yoghurt|whey|suero)\b/', $nameN)) {
        $kind = 'default_liquid';
    }

    $liquidUnits = ['ml', 'l', 'liter', 'litre', 'litro', 'litros', 'gal', 'gallon', 'galones', 'fl oz', 'floz', 'liquid', 'liquido'];
    if ($kind === null && in_array($unitN, $liquidUnits, true)) {
        $kind = 'default_liquid';
    }

    if ($kind === null) {
        return [
            'liquid' => false,
            'kind' => 'dry',
            'density_lb_per_gal' => null,
        ];
    }

    return [
        'liquid' => true,
        'kind' => $kind,
        'density_lb_per_gal' => $densities[$kind] ?? $densities['default_liquid'],
    ];
}

function bakery_format_formula_grams($grams)
{
    return number_format((float) $grams, 0) . ' g';
}

function bakery_format_formula_lb($grams)
{
    return number_format(bakery_grams_to_lb($grams), 2) . ' lb';
}

function bakery_format_formula_gal($grams, $densityLbPerGal)
{
    $gal = bakery_grams_to_gal($grams, $densityLbPerGal);
    if ($gal === null) {
        return '';
    }
    return number_format($gal, 2) . ' gal';
}

/**
 * Server-emitted g / lb / gal spans. CSS + a small toggle choose what is visible.
 *
 * @param array{liquid?: bool, density_lb_per_gal?: float|null} $classification
 */
function bakery_formula_amount_markup($grams, array $classification)
{
    $grams = (float) $grams;
    $html = [];
    $html[] = '<span class="qty qty-g">' . htmlspecialchars(bakery_format_formula_grams($grams), ENT_QUOTES, 'UTF-8') . '</span>';
    $html[] = '<span class="qty-sep qty-sep-lb" aria-hidden="true"> · </span>';
    $html[] = '<span class="qty qty-lb">' . htmlspecialchars(bakery_format_formula_lb($grams), ENT_QUOTES, 'UTF-8') . '</span>';
    if (!empty($classification['liquid']) && !empty($classification['density_lb_per_gal'])) {
        $galLabel = bakery_format_formula_gal($grams, $classification['density_lb_per_gal']);
        if ($galLabel !== '') {
            $html[] = '<span class="qty-sep qty-sep-gal" aria-hidden="true"> · </span>';
            $html[] = '<span class="qty qty-gal">' . htmlspecialchars($galLabel, ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    return implode('', $html);
}

/**
 * Allowed baker unit-display modes.
 *
 * @return list<string>
 */
function bakery_formula_unit_modes()
{
    return ['g', 'lb', 'gal', 'all'];
}

<?php
/**
 * Pan Dulce / product pack yields — gallon, tray, barra → sellable pieces.
 *
 * Orders, plans, and inventory stay in whole pieces. These helpers convert
 * bakery production language at input boundaries only.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Whether pack-yield tables exist.
 */
function bakery_pack_yields_ready(PDO $db): bool
{
    return table_exists($db, 'product_pack_yields')
        && table_exists($db, 'dough_type_pack_yields')
        && table_exists($db, 'product_aliases');
}

/**
 * Normalize an informal name for alias lookup.
 */
function bakery_pack_normalize_alias(string $raw): string
{
    $s = trim(mb_strtolower($raw, 'UTF-8'));
    if ($s === '') {
        return '';
    }
    // Strip combining marks after NFD when intl/normalizer available.
    if (class_exists('Normalizer')) {
        $nfd = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($nfd)) {
            $s = preg_replace('/\p{Mn}+/u', '', $nfd) ?? $s;
        }
    } else {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ];
        $s = strtr($s, $map);
    }
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Resolve informal name → product_id via product_aliases, else exact product name.
 *
 * @return int|null
 */
function bakery_pack_resolve_product(PDO $db, string $raw): ?int
{
    $key = bakery_pack_normalize_alias($raw);
    if ($key === '' || !bakery_pack_yields_ready($db)) {
        return null;
    }

    $stmt = $db->prepare('SELECT product_id FROM product_aliases WHERE alias = ? LIMIT 1');
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    // Aliases are stored normalized; also try raw lower without accent strip match on products.name
    $stmt = $db->prepare('SELECT id FROM products WHERE LOWER(name) = ? LIMIT 1');
    $stmt->execute([mb_strtolower(trim($raw), 'UTF-8')]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Round half-up to whole pieces.
 */
function bakery_pack_round_pieces(float $value): int
{
    if ($value < 0) {
        return 0;
    }
    return (int)round($value, 0, PHP_ROUND_HALF_UP);
}

/**
 * Load product pack yield row or null.
 *
 * @return array<string,mixed>|null
 */
function bakery_pack_product_yield(PDO $db, int $productId): ?array
{
    if (!bakery_pack_yields_ready($db) || $productId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM product_pack_yields WHERE product_id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Load dough-type pack yield or null.
 *
 * @return array<string,mixed>|null
 */
function bakery_pack_dough_yield(PDO $db, int $doughTypeId): ?array
{
    if (!bakery_pack_yields_ready($db) || $doughTypeId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM dough_type_pack_yields WHERE dough_type_id = ? LIMIT 1');
    $stmt->execute([$doughTypeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Convert a quantity in the given (or product-default) unit to sellable pieces.
 *
 * Supported units: piece, tray, gallon, barra.
 * Gallon uses product trays_per_gallon + pieces_per_tray, else dough-type yield.
 */
function bakery_pack_to_pieces(PDO $db, int $productId, float $qty, ?string $unit = null): int
{
    if ($qty <= 0 || $productId <= 0) {
        return 0;
    }

    $yield = bakery_pack_product_yield($db, $productId);
    $unit = $unit !== null && $unit !== ''
        ? strtolower(trim($unit))
        : strtolower((string)($yield['input_unit'] ?? 'piece'));

    if ($unit === 'piece' || $unit === 'pieces') {
        $per = $yield && $yield['pieces_per_input'] !== null
            ? (float)$yield['pieces_per_input']
            : 1.0;
        return bakery_pack_round_pieces($qty * $per);
    }

    if ($unit === 'tray' || $unit === 'trays') {
        $perTray = null;
        if ($yield) {
            if ($yield['pieces_per_input'] !== null && strtolower((string)$yield['input_unit']) === 'tray') {
                $perTray = (float)$yield['pieces_per_input'];
            } elseif ($yield['pieces_per_tray'] !== null) {
                $perTray = (float)$yield['pieces_per_tray'];
            }
        }
        if ($perTray === null || $perTray <= 0) {
            $perTray = 20.0;
        }
        return bakery_pack_round_pieces($qty * $perTray);
    }

    if ($unit === 'barra' || $unit === 'barras') {
        $per = $yield && $yield['pieces_per_input'] !== null
            ? (float)$yield['pieces_per_input']
            : 1.0;
        return bakery_pack_round_pieces($qty * $per);
    }

    if ($unit === 'gallon' || $unit === 'gallons' || $unit === 'gal') {
        $traysPerGal = $yield && $yield['trays_per_gallon'] !== null
            ? (float)$yield['trays_per_gallon']
            : null;
        $pcsPerTray = $yield && $yield['pieces_per_tray'] !== null
            ? (int)$yield['pieces_per_tray']
            : null;

        if ($traysPerGal === null || $pcsPerTray === null) {
            $stmt = $db->prepare('SELECT dough_type_id FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $doughTypeId = (int)$stmt->fetchColumn();
            $dough = bakery_pack_dough_yield($db, $doughTypeId);
            if ($dough) {
                if ($traysPerGal === null) {
                    $traysPerGal = (float)$dough['trays_per_gallon'];
                }
                if ($pcsPerTray === null) {
                    $pcsPerTray = (int)$dough['pieces_per_tray'];
                }
            }
        }

        if ($traysPerGal === null || $traysPerGal <= 0) {
            throw new InvalidArgumentException('No trays_per_gallon configured for product ' . $productId);
        }
        if ($pcsPerTray === null || $pcsPerTray <= 0) {
            $pcsPerTray = 20;
        }

        return bakery_pack_round_pieces($qty * $traysPerGal * $pcsPerTray);
    }

    throw new InvalidArgumentException('Unknown pack unit: ' . $unit);
}

/**
 * Gallons of a dough type → total pieces (before SKU split).
 */
function bakery_pack_dough_gallons_to_pieces(PDO $db, int $doughTypeId, float $gallons): int
{
    if ($gallons <= 0 || $doughTypeId <= 0) {
        return 0;
    }
    $dough = bakery_pack_dough_yield($db, $doughTypeId);
    if (!$dough) {
        throw new InvalidArgumentException('No dough_type_pack_yields for dough_type_id ' . $doughTypeId);
    }
    $trays = (float)$dough['trays_per_gallon'];
    $pcs = (int)$dough['pieces_per_tray'];
    if ($trays <= 0 || $pcs <= 0) {
        throw new InvalidArgumentException('Invalid dough pack yield for dough_type_id ' . $doughTypeId);
    }
    return bakery_pack_round_pieces($gallons * $trays * $pcs);
}

/**
 * Fino gallon batch → even split across the five Fino SKUs.
 *
 * @return array<int,int> product_id => pieces
 */
function bakery_pack_fino_split(PDO $db, float $gallons): array
{
    if ($gallons <= 0) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT dt.id
        FROM dough_types dt
        JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
        WHERE dt.name = 'Fino'
        LIMIT 1
    ");
    $stmt->execute();
    $doughTypeId = (int)$stmt->fetchColumn();
    if ($doughTypeId <= 0) {
        throw new InvalidArgumentException('Fino dough type not found');
    }

    $total = bakery_pack_dough_gallons_to_pieces($db, $doughTypeId, $gallons);

    $names = ['Elotes', 'Cuerno Azucar', 'Tostado', 'Nopal', 'Chamuco'];
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $q = $db->prepare("
        SELECT p.id, p.name
        FROM products p
        JOIN dough_types dt ON dt.id = p.dough_type_id
        WHERE dt.name = 'Fino' AND p.name IN ($placeholders)
        ORDER BY FIELD(p.name, $placeholders)
    ");
    $q->execute(array_merge($names, $names));
    $products = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($products === []) {
        throw new InvalidArgumentException('No Fino SKUs found for split');
    }

    $n = count($products);
    $base = intdiv($total, $n);
    $rem = $total % $n;
    $out = [];
    foreach ($products as $i => $row) {
        $out[(int)$row['id']] = $base + ($i < $rem ? 1 : 0);
    }
    return $out;
}

/**
 * Whole Barras count → Barra (Rebanada) pieces via cut_ratio (default 6).
 */
function bakery_pack_barra_to_rebanada(PDO $db, float $barraCount): int
{
    if ($barraCount <= 0) {
        return 0;
    }

    $stmt = $db->prepare("SELECT id FROM products WHERE name = 'Barra (Rebanada)' LIMIT 1");
    $stmt->execute();
    $rebanadaId = (int)$stmt->fetchColumn();
    if ($rebanadaId <= 0) {
        throw new InvalidArgumentException('Barra (Rebanada) product not found');
    }

    $yield = bakery_pack_product_yield($db, $rebanadaId);
    $ratio = $yield && $yield['cut_ratio'] !== null ? (float)$yield['cut_ratio'] : 6.0;
    return bakery_pack_round_pieces($barraCount * $ratio);
}

/**
 * Derived pieces for one gallon of a product (read-only UI helper).
 */
function bakery_pack_pieces_per_gallon(PDO $db, int $productId): ?int
{
    try {
        return bakery_pack_to_pieces($db, $productId, 1.0, 'gallon');
    } catch (Throwable $e) {
        return null;
    }
}

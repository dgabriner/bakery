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
 * Seed missing catalog SKUs and gallon/tray estimates (idempotent).
 */
function bakery_pack_ensure_defaults(PDO $db): void
{
    static $done = false;
    if ($done || !bakery_pack_yields_ready($db)) {
        return;
    }
    $done = true;

    $schemaDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR;
    if (function_exists('bakery_run_sql_file_safe')) {
        $path = $schemaDir . '059_bolillo_and_gallon_estimates.sql';
        if (is_readable($path)) {
            bakery_run_sql_file_safe($db, $path);
        }
    }

    $estimates = [
        'Nuez' => [80, 'gallon', 'Estimate 80 pcs/gal'],
        'Guayaba' => [80, 'gallon', 'Estimate 80 pcs/gal'],
        'Puerco' => [48, 'gallon', 'Estimate 48 pcs/gal'],
        'Taco' => [72, 'gallon', 'Estimate 72 pcs/gal'],
        'Grajea' => [80, 'gallon', 'Estimate 80 pcs/gal'],
        'Polvoron Amarilla' => [100, 'gallon', 'Estimate 100 pcs/gal'],
        'Polvoron Rosada' => [100, 'gallon', 'Estimate 100 pcs/gal'],
        'Chocolate Chip' => [100, 'gallon', 'Estimate 100 pcs/gal'],
        'Bolillo' => [80, 'gallon', 'Estimate 80 pcs/batch'],
        'Telera' => [80, 'gallon', 'Estimate 80 pcs/batch'],
        'Quequitos' => [20, 'tray', 'Default 20 pcs/tray until PM sets tray cut'],
    ];
    $find = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $has = $db->prepare('SELECT product_id, pieces_per_input, trays_per_gallon FROM product_pack_yields WHERE product_id = ? LIMIT 1');
    $ins = $db->prepare(
        'INSERT INTO product_pack_yields (product_id, input_unit, pieces_per_input, trays_per_gallon, pieces_per_tray, notes)
         VALUES (?, ?, ?, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE
           input_unit = IF(trays_per_gallon IS NULL AND (input_unit IN (\'piece\', \'pieces\') OR pieces_per_input IS NULL OR pieces_per_input <= 1), VALUES(input_unit), input_unit),
           pieces_per_input = IF(trays_per_gallon IS NULL AND (input_unit IN (\'piece\', \'pieces\') OR pieces_per_input IS NULL OR pieces_per_input <= 1), VALUES(pieces_per_input), pieces_per_input),
           pieces_per_tray = IF(VALUES(pieces_per_tray) IS NOT NULL AND (pieces_per_tray IS NULL OR pieces_per_tray = 0), VALUES(pieces_per_tray), pieces_per_tray),
           notes = IF(notes IS NULL OR notes = \'\', VALUES(notes), notes)'
    );
    foreach ($estimates as $name => [$pcs, $unit, $note]) {
        $find->execute([$name]);
        $id = (int)$find->fetchColumn();
        if ($id <= 0) {
            continue;
        }
        $has->execute([$id]);
        $row = $has->fetch(PDO::FETCH_ASSOC);
        $ppt = $unit === 'tray' ? (int)$pcs : null;
        $hasTrays = $row && $row['trays_per_gallon'] !== null && (float)$row['trays_per_gallon'] > 0;
        $needsEstimate = !$row
            || ($unit === 'gallon' && !$hasTrays && ((float)($row['pieces_per_input'] ?? 0) <= 1));
        if ($needsEstimate && !$hasTrays) {
            $ins->execute([$id, $unit, $pcs, $ppt, $note]);
        }
    }
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
        $yieldUnit = strtolower((string)($yield['input_unit'] ?? ''));
        if ($yield && $yieldUnit === 'gallon' && $yield['pieces_per_input'] !== null && (float)$yield['pieces_per_input'] > 0
            && ($yield['trays_per_gallon'] === null || (float)$yield['trays_per_gallon'] <= 0)) {
            return bakery_pack_round_pieces($qty * (float)$yield['pieces_per_input']);
        }

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

        if ($traysPerGal !== null && $traysPerGal > 0) {
            if ($pcsPerTray === null || $pcsPerTray <= 0) {
                $pcsPerTray = 20;
            }
            return bakery_pack_round_pieces($qty * $traysPerGal * $pcsPerTray);
        }

        if ($yield && $yield['pieces_per_input'] !== null && (float)$yield['pieces_per_input'] > 0) {
            return bakery_pack_round_pieces($qty * (float)$yield['pieces_per_input']);
        }

        $wStmt = $db->prepare('SELECT weight_grams FROM products WHERE id = ? LIMIT 1');
        $wStmt->execute([$productId]);
        $grams = (int)$wStmt->fetchColumn();
        if ($grams > 0) {
            return bakery_pack_round_pieces($qty * (3785 / $grams));
        }

        return bakery_pack_round_pieces($qty * 80);
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
 * Gallon batch for a Pan Dulce dough type → even split across named SKUs.
 *
 * @param list<string> $skuNames
 * @param array<string,int> $skuShares catalog name => weight (empty = even)
 * @return array<int,int> product_id => pieces
 */
function bakery_pack_even_dough_split(PDO $db, string $doughTypeName, float $gallons, array $skuNames, array $skuShares = []): array
{
    if ($gallons <= 0 || $skuNames === []) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT dt.id
        FROM dough_types dt
        JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
        WHERE dt.name = ?
        LIMIT 1
    ");
    $stmt->execute([$doughTypeName]);
    $doughTypeId = (int)$stmt->fetchColumn();
    if ($doughTypeId <= 0) {
        throw new InvalidArgumentException($doughTypeName . ' dough type not found');
    }

    try {
        $total = bakery_pack_dough_gallons_to_pieces($db, $doughTypeId, $gallons);
    } catch (InvalidArgumentException $e) {
        $conchaStmt = $db->prepare("
            SELECT dt.id FROM dough_types dt
            JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
            WHERE dt.name = 'Concha' LIMIT 1
        ");
        $conchaStmt->execute();
        $conchaId = (int)$conchaStmt->fetchColumn();
        $total = bakery_pack_dough_gallons_to_pieces($db, $conchaId, $gallons);
    }

    $placeholders = implode(',', array_fill(0, count($skuNames), '?'));
    $q = $db->prepare("
        SELECT p.id, p.name
        FROM products p
        JOIN dough_types dt ON dt.id = p.dough_type_id
        WHERE dt.name = ? AND p.name IN ($placeholders)
        ORDER BY FIELD(p.name, $placeholders)
    ");
    $q->execute(array_merge([$doughTypeName], $skuNames, $skuNames));
    $products = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($products === []) {
        throw new InvalidArgumentException('No ' . $doughTypeName . ' SKUs found for split');
    }

    $shares = $skuShares;
    if ($shares === []) {
        foreach ($products as $row) {
            $shares[(string)$row['name']] = 1;
        }
    }
    $weights = [];
    foreach ($products as $row) {
        $weights[(int)$row['id']] = (int)($shares[(string)$row['name']] ?? 0);
    }
    return bakery_pack_allocate_shares($total, $weights);
}

/**
 * Largest-remainder allocation of $total pieces across integer weights.
 *
 * @param array<int,int> $weights id => weight
 * @return array<int,int>
 */
function bakery_pack_allocate_shares(int $total, array $weights): array
{
    $sum = (int)array_sum($weights);
    if ($total <= 0 || $sum <= 0) {
        $zero = [];
        foreach (array_keys($weights) as $id) {
            $zero[$id] = 0;
        }
        return $zero;
    }
    $out = [];
    $used = 0;
    $remainders = [];
    foreach ($weights as $id => $w) {
        $raw = ($total * $w) / $sum;
        $floor = (int)floor($raw);
        $out[$id] = $floor;
        $used += $floor;
        $remainders[$id] = $raw - $floor;
    }
    arsort($remainders);
    $left = $total - $used;
    foreach (array_keys($remainders) as $id) {
        if ($left <= 0) {
            break;
        }
        $out[$id]++;
        $left--;
    }
    return $out;
}

/**
 * Fino gallon mix: mostly Elotes + Cuerno Azucar, remainder the other three.
 *
 * @return array<string,int>
 */
function bakery_pack_fino_mix_shares(): array
{
    return [
        'Elotes' => 40,
        'Cuerno Azucar' => 40,
        'Tostado' => 8,
        'Nopal' => 6,
        'Chamuco' => 6,
    ];
}

/**
 * Picón gallon mix: mostly Liso + Cocol, a handful of the rest.
 *
 * @return array<string,int>
 */
function bakery_pack_picon_mix_shares(): array
{
    return [
        'Liso' => 40,
        'Cocol' => 35,
        'Gusano' => 10,
        'Tortuga' => 8,
        'Roles de Canela' => 7,
    ];
}

/**
 * Fino gallon batch → weighted split across the five Fino SKUs.
 *
 * @return array<int,int> product_id => pieces
 */
function bakery_pack_fino_split(PDO $db, float $gallons): array
{
    $shares = bakery_pack_fino_mix_shares();
    return bakery_pack_even_dough_split($db, 'Fino', $gallons, array_keys($shares), $shares);
}

/**
 * Picón gallon batch → weighted split (Concha gallon geometry until PM overrides).
 *
 * @return array<int,int> product_id => pieces
 */
function bakery_pack_picon_split(PDO $db, float $gallons): array
{
    $shares = bakery_pack_picon_mix_shares();
    return bakery_pack_even_dough_split($db, 'Picon', $gallons, array_keys($shares), $shares);
}

/**
 * Share of whole Barras kept uncut when the kitchen lists barras.
 * The rest become Barra (Rebanada) at cut_ratio (6).
 */
function bakery_pack_barra_whole_percent(): int
{
    return 20;
}

/**
 * Kitchen "N barras" → whole Barras kept + rebanada pieces.
 *
 * @return array<int,int> product_id => pieces
 */
function bakery_pack_barra_kitchen_split(PDO $db, float $barraCount): array
{
    $count = bakery_pack_round_pieces($barraCount);
    if ($count <= 0) {
        return [];
    }
    $stmt = $db->prepare("SELECT id FROM products WHERE name = 'Barras' LIMIT 1");
    $stmt->execute();
    $barrasId = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT id FROM products WHERE name = 'Barra (Rebanada)' LIMIT 1");
    $stmt->execute();
    $rebanadaId = (int)$stmt->fetchColumn();
    if ($barrasId <= 0 || $rebanadaId <= 0) {
        throw new InvalidArgumentException('Barras catalog products missing');
    }
    $whole = (int)round($count * bakery_pack_barra_whole_percent() / 100);
    if ($whole < 0) {
        $whole = 0;
    }
    if ($whole > $count) {
        $whole = $count;
    }
    $cut = $count - $whole;
    $out = [$barrasId => $whole];
    $rebanada = bakery_pack_barra_to_rebanada($db, $cut);
    if ($rebanada > 0) {
        $out[$rebanadaId] = $rebanada;
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

/**
 * Informal kitchen names that are not always in product_aliases.
 *
 * @return array<string,string> normalized alias => catalog name
 */
function bakery_pack_kitchen_name_map(): array
{
    return [
        'amarilla' => 'Polvoron Amarilla',
        'rosada' => 'Polvoron Rosada',
        'chocolate' => 'Chocolate Chip',
        'nuez' => 'Nuez',
        'guayaba' => 'Guayaba',
        'puerco' => 'Puerco',
        'taco' => 'Taco',
        'gragea' => 'Grajea',
        'grajea' => 'Grajea',
        'bolillo' => 'Bolillo',
        'telera' => 'Telera',
        'queiquitos' => 'Quequitos',
        'queiquito' => 'Quequitos',
    ];
}

function bakery_pack_resolve_kitchen_name(PDO $db, string $raw): ?int
{
    $id = bakery_pack_resolve_product($db, $raw);
    if ($id) {
        return $id;
    }
    $key = bakery_pack_normalize_alias($raw);
    $map = bakery_pack_kitchen_name_map();
    if (!isset($map[$key])) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $stmt->execute([$map[$key]]);
    $found = $stmt->fetchColumn();
    return $found !== false ? (int)$found : null;
}

/**
 * Turn a WhatsApp / kitchen production note into sellable pieces.
 *
 * @return array{
 *   lines: list<array<string,mixed>>,
 *   by_product: array<int,int>,
 *   unknown: list<string>
 * }
 */
function bakery_pack_parse_kitchen_note(PDO $db, string $text): array
{
    bakery_pack_ensure_defaults($db);
    $linesOut = [];
    $byProduct = [];
    $unknown = [];

    $addPieces = static function (int $productId, int $pieces) use (&$byProduct): void {
        if ($productId <= 0 || $pieces <= 0) {
            return;
        }
        $byProduct[$productId] = ($byProduct[$productId] ?? 0) + $pieces;
    };

    $rawLines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    foreach ($rawLines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }
        $plain = bakery_pack_normalize_alias($line);
        if ($plain === '' || preg_match('/^(buenos|buenas|hola|good morning)/u', $plain)) {
            continue;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*taco\s*\/\s*(?:gragea|grajea)\s+(\d+)\s*y\s+(\d+)/u', $plain, $m)) {
            $tacoQty = (float)$m[2];
            $grajeaQty = (float)$m[3];
            $tacoId = bakery_pack_resolve_kitchen_name($db, 'taco');
            $grajeaId = bakery_pack_resolve_kitchen_name($db, 'gragea');
            $tacoPieces = $tacoId ? bakery_pack_to_pieces($db, $tacoId, $tacoQty, 'gallon') : 0;
            $grajeaPieces = $grajeaId ? bakery_pack_to_pieces($db, $grajeaId, $grajeaQty, 'gallon') : 0;
            if ($tacoId) {
                $addPieces($tacoId, $tacoPieces);
            }
            if ($grajeaId) {
                $addPieces($grajeaId, $grajeaPieces);
            }
            $linesOut[] = [
                'raw' => $line,
                'kind' => 'split',
                'note' => '1 gal Taco + 1 gal Grajea (estimate until piece yield is refined)',
                'pieces' => $tacoPieces + $grajeaPieces,
            ];
            continue;
        }

        $qty = null;
        $name = '';
        if (preg_match('/^(\d+)\.([^\d].+)$/u', $line, $m) && !preg_match('/^\d+\.\d+/', $line)) {
            $qty = (float)$m[1];
            $name = trim($m[2]);
        } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(?:de\s+)?(.+)$/iu', $line, $m)) {
            $qty = (float)$m[1];
            $name = trim($m[2]);
        }

        if ($qty === null || $name === '') {
            $unknown[] = $line;
            $linesOut[] = ['raw' => $line, 'kind' => 'unknown', 'note' => 'Could not parse'];
            continue;
        }

        $nameKey = bakery_pack_normalize_alias($name);
        $nameKey = preg_replace('/^(de|del|la|el)\s+/u', '', $nameKey) ?? $nameKey;

        if ($nameKey === 'barra' || $nameKey === 'barras') {
            $split = bakery_pack_barra_kitchen_split($db, $qty);
            foreach ($split as $pid => $pcs) {
                $addPieces((int)$pid, (int)$pcs);
            }
            $linesOut[] = [
                'raw' => $line,
                'kind' => 'barras',
                'qty' => $qty,
                'unit' => 'barra',
                'pieces' => array_sum($split),
                'split' => $split,
                'note' => bakery_pack_barra_whole_percent() . '% kept whole; remainder as rebanadas (6 per barra)',
            ];
            continue;
        }

        if ($nameKey === 'fino' || $nameKey === 'finos') {
            $split = bakery_pack_fino_split($db, $qty);
            foreach ($split as $pid => $pcs) {
                $addPieces((int)$pid, (int)$pcs);
            }
            $linesOut[] = [
                'raw' => $line,
                'kind' => 'fino',
                'qty' => $qty,
                'unit' => 'gallon',
                'pieces' => array_sum($split),
                'split' => $split,
                'note' => 'Mostly Elotes and Cuerno Azucar',
            ];
            continue;
        }

        if ($nameKey === 'picon' || $nameKey === 'picones') {
            $split = bakery_pack_picon_split($db, $qty);
            foreach ($split as $pid => $pcs) {
                $addPieces((int)$pid, (int)$pcs);
            }
            $linesOut[] = [
                'raw' => $line,
                'kind' => 'picon',
                'qty' => $qty,
                'unit' => 'gallon',
                'pieces' => array_sum($split),
                'split' => $split,
                'note' => 'Mostly Liso and Cocol; handful of the rest',
            ];
            continue;
        }

        $productId = bakery_pack_resolve_kitchen_name($db, $nameKey);
        if (!$productId) {
            $unknown[] = $line;
            $linesOut[] = [
                'raw' => $line,
                'kind' => 'unknown',
                'qty' => $qty,
                'name' => $name,
                'note' => 'No catalog product',
            ];
            continue;
        }

        $unit = bakery_pack_infer_kitchen_unit($db, $productId, $nameKey, $qty);
        $pieces = bakery_pack_to_pieces($db, $productId, $qty, $unit);
        $addPieces($productId, $pieces);
        $linesOut[] = [
            'raw' => $line,
            'kind' => 'product',
            'product_id' => $productId,
            'qty' => $qty,
            'unit' => $unit,
            'pieces' => $pieces,
        ];
    }

    return [
        'lines' => $linesOut,
        'by_product' => $byProduct,
        'unknown' => $unknown,
    ];
}

function bakery_pack_infer_kitchen_unit(PDO $db, int $productId, string $nameKey, float $qty): string
{
    if (in_array($nameKey, ['barra', 'barras'], true)) {
        return 'barra';
    }
    $gallonNames = [
        'concha', 'conchas', 'nuez', 'guayaba', 'puerco', 'taco', 'gragea', 'grajea',
        'amarilla', 'rosada', 'chocolate', 'bolillo', 'telera',
    ];
    if (in_array($nameKey, $gallonNames, true)) {
        return 'gallon';
    }
    if (in_array($nameKey, ['concha', 'conchas'], true) || ((string)$qty !== (string)(int)$qty)) {
        $yield = bakery_pack_product_yield($db, $productId);
        $input = strtolower((string)($yield['input_unit'] ?? ''));
        if ($input === 'gallon') {
            return 'gallon';
        }
    }
    $yield = bakery_pack_product_yield($db, $productId);
    $input = strtolower((string)($yield['input_unit'] ?? 'piece'));
    if ($input === 'gallon' || $input === 'tray' || $input === 'barra') {
        return $input;
    }
    return 'tray';
}

/**
 * Reverse a planned piece count into kitchen batch language (gal / trays / pcs).
 */
function bakery_pack_batch_label(PDO $db, int $productId, int $pieces): string
{
    if ($pieces <= 0 || $productId <= 0) {
        return '';
    }
    $yield = bakery_pack_product_yield($db, $productId);
    $unit = strtolower((string)($yield['input_unit'] ?? ''));
    $fmt = static function (float $n, int $decimals = 2): string {
        $s = number_format($n, $decimals, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    };

    if ($unit === 'gallon' && $yield && $yield['pieces_per_input'] !== null && (float)$yield['pieces_per_input'] > 0
        && ($yield['trays_per_gallon'] === null || (float)$yield['trays_per_gallon'] <= 0)) {
        $gals = $pieces / (float)$yield['pieces_per_input'];
        return $fmt($gals) . ' gal · ' . number_format($pieces) . ' pcs';
    }
    if ($unit === 'tray' && $yield) {
        $ppt = (int)round((float)($yield['pieces_per_input'] ?? $yield['pieces_per_tray'] ?? 0));
        if ($ppt > 0) {
            $trays = intdiv($pieces, $ppt);
            $loose = $pieces % $ppt;
            $label = $trays . ' tray' . ($trays === 1 ? '' : 's');
            if ($loose > 0) {
                $label .= ' + ' . $loose . ' pcs';
            }
            return $label . ' · ' . number_format($pieces) . ' pcs';
        }
    }
    if ($unit === 'gallon' && $yield && $yield['trays_per_gallon'] !== null && (int)($yield['pieces_per_tray'] ?? 0) > 0) {
        $perGal = (float)$yield['trays_per_gallon'] * (int)$yield['pieces_per_tray'];
        if ($perGal > 0) {
            return $fmt($pieces / $perGal) . ' gal · ' . number_format($pieces) . ' pcs';
        }
    }
    if ($unit === 'barra') {
        return number_format($pieces) . ' barras';
    }

    $stmt = $db->prepare('SELECT dough_type_id FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $doughTypeId = (int)$stmt->fetchColumn();
    $dough = bakery_pack_dough_yield($db, $doughTypeId);
    if ($dough) {
        $perGal = (float)$dough['trays_per_gallon'] * (int)$dough['pieces_per_tray'];
        if ($perGal > 0) {
            return $fmt($pieces / $perGal) . ' gal · ' . number_format($pieces) . ' pcs';
        }
    }

    return number_format($pieces) . ' pcs';
}

/**
 * Pack-floor conversion: pieces plus trays and boxes when yields are known.
 *
 * @return array{
 *   pieces:int,
 *   pieces_per_tray:?int,
 *   trays:int,
 *   tray_remainder:int,
 *   pieces_per_box:?int,
 *   boxes:int,
 *   box_remainder:int,
 *   label:string
 * }
 */
function bakery_pack_count_breakdown(PDO $db, int $productId, int $pieces): array
{
    $pieces = max(0, $pieces);
    $out = [
        'pieces' => $pieces,
        'pieces_per_tray' => null,
        'trays' => 0,
        'tray_remainder' => $pieces,
        'pieces_per_box' => null,
        'boxes' => 0,
        'box_remainder' => $pieces,
        'label' => $pieces > 0 ? (number_format($pieces) . ' pcs') : '',
    ];
    if ($productId <= 0) {
        return $out;
    }

    $yield = bakery_pack_product_yield($db, $productId);
    $perTray = null;
    if ($yield) {
        if ($yield['pieces_per_tray'] !== null && (int)$yield['pieces_per_tray'] > 1) {
            $perTray = (int)$yield['pieces_per_tray'];
        } elseif (strtolower((string)($yield['input_unit'] ?? '')) === 'tray'
            && $yield['pieces_per_input'] !== null
            && (float)$yield['pieces_per_input'] > 1) {
            $perTray = (int)round((float)$yield['pieces_per_input']);
        }
    }
    if ($perTray === null || $perTray <= 1) {
        $stmt = $db->prepare('SELECT dough_type_id FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $dough = bakery_pack_dough_yield($db, (int)$stmt->fetchColumn());
        if ($dough && (int)($dough['pieces_per_tray'] ?? 0) > 1) {
            $perTray = (int)$dough['pieces_per_tray'];
        }
    }
    if ($perTray !== null && $perTray > 1) {
        $out['pieces_per_tray'] = $perTray;
        $out['trays'] = intdiv($pieces, $perTray);
        $out['tray_remainder'] = $pieces % $perTray;
    }

    if ($yield && array_key_exists('pieces_per_box', $yield)
        && $yield['pieces_per_box'] !== null
        && (int)$yield['pieces_per_box'] > 1) {
        $perBox = (int)$yield['pieces_per_box'];
        $out['pieces_per_box'] = $perBox;
        $out['boxes'] = intdiv($pieces, $perBox);
        $out['box_remainder'] = $pieces % $perBox;
    }

    if ($pieces <= 0) {
        $out['label'] = '';
        return $out;
    }

    $parts = [number_format($pieces) . ' pcs'];
    if ($out['pieces_per_tray'] && ($out['trays'] > 0 || $out['tray_remainder'] !== $pieces)) {
        $trayBit = $out['trays'] . ' tray' . ($out['trays'] === 1 ? '' : 's');
        if ($out['tray_remainder'] > 0) {
            $trayBit .= ' + ' . $out['tray_remainder'] . ' pcs';
        }
        $parts[] = $trayBit . ' (' . $out['pieces_per_tray'] . '/tray)';
    }
    if ($out['pieces_per_box'] && $out['boxes'] > 0) {
        $boxBit = $out['boxes'] . ' box' . ($out['boxes'] === 1 ? '' : 'es');
        if ($out['box_remainder'] > 0) {
            $boxBit .= ' + ' . $out['box_remainder'] . ' pcs';
        }
        $parts[] = $boxBit . ' (' . $out['pieces_per_box'] . '/box)';
    }
    $out['label'] = implode(' · ', $parts);
    return $out;
}

/**
 * Persist pack-floor tray/box sizes on the product catalog (not a dated override).
 *
 * @return array{product_id:int,pieces_per_tray:?int,pieces_per_box:?int}
 */
function bakery_pack_save_count_units(PDO $db, int $productId, $piecesPerTray, $piecesPerBox): array
{
    if (!bakery_pack_yields_ready($db) || $productId <= 0) {
        throw new InvalidArgumentException('Pack yields are not available.');
    }
    $exists = $db->prepare('SELECT 1 FROM products WHERE id = ? LIMIT 1');
    $exists->execute([$productId]);
    if (!$exists->fetchColumn()) {
        throw new InvalidArgumentException('Unknown product.');
    }

    $tray = bakery_pack_normalize_count_unit($piecesPerTray);
    $box = bakery_pack_normalize_count_unit($piecesPerBox);
    $hasBox = function_exists('column_exists') && column_exists($db, 'product_pack_yields', 'pieces_per_box');
    $current = bakery_pack_product_yield($db, $productId);

    if ($current) {
        if ($hasBox) {
            $stmt = $db->prepare(
                'UPDATE product_pack_yields
                 SET pieces_per_tray = ?,
                     pieces_per_box = ?,
                     pieces_per_input = IF(input_unit = \'tray\' AND ? IS NOT NULL, ?, pieces_per_input)
                 WHERE product_id = ?'
            );
            $stmt->execute([$tray, $box, $tray, $tray, $productId]);
        } else {
            $stmt = $db->prepare(
                'UPDATE product_pack_yields
                 SET pieces_per_tray = ?,
                     pieces_per_input = IF(input_unit = \'tray\' AND ? IS NOT NULL, ?, pieces_per_input)
                 WHERE product_id = ?'
            );
            $stmt->execute([$tray, $tray, $tray, $productId]);
        }
    } else {
        if ($hasBox) {
            $stmt = $db->prepare(
                'INSERT INTO product_pack_yields (product_id, input_unit, pieces_per_tray, pieces_per_box)
                 VALUES (?, \'piece\', ?, ?)'
            );
            $stmt->execute([$productId, $tray, $box]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO product_pack_yields (product_id, input_unit, pieces_per_tray)
                 VALUES (?, \'piece\', ?)'
            );
            $stmt->execute([$productId, $tray]);
        }
    }

    $fresh = bakery_pack_count_breakdown($db, $productId, 0);
    return [
        'product_id' => $productId,
        'pieces_per_tray' => $fresh['pieces_per_tray'],
        'pieces_per_box' => $hasBox ? $fresh['pieces_per_box'] : null,
    ];
}

/** @param mixed $raw */
function bakery_pack_normalize_count_unit($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Tray and box sizes must be whole numbers.');
    }
    $value = (int)$raw;
    if ($value === 0) {
        return null;
    }
    if ($value < 2 || $value > 500) {
        throw new InvalidArgumentException('Use 2–500 pieces per tray or box, or leave blank.');
    }
    return $value;
}

/**
 * Pieces per kitchen input unit (for live proportional batch labels).
 *
 * @return array{unit:string,pcs_per:float}
 */
function bakery_pack_input_scale(PDO $db, int $productId): array
{
    $yield = bakery_pack_product_yield($db, $productId);
    $unit = strtolower((string)($yield['input_unit'] ?? 'piece'));
    if ($unit === 'gallon' && $yield && $yield['pieces_per_input'] !== null && (float)$yield['pieces_per_input'] > 0
        && ($yield['trays_per_gallon'] === null || (float)$yield['trays_per_gallon'] <= 0)) {
        return ['unit' => 'gallon', 'pcs_per' => (float)$yield['pieces_per_input']];
    }
    if ($unit === 'tray' && $yield) {
        $ppt = (float)($yield['pieces_per_input'] ?? $yield['pieces_per_tray'] ?? 0);
        if ($ppt > 0) {
            return ['unit' => 'tray', 'pcs_per' => $ppt];
        }
    }
    if ($unit === 'gallon' && $yield && $yield['trays_per_gallon'] !== null && (int)($yield['pieces_per_tray'] ?? 0) > 0) {
        return ['unit' => 'gallon', 'pcs_per' => (float)$yield['trays_per_gallon'] * (int)$yield['pieces_per_tray']];
    }
    if ($unit === 'barra') {
        $per = $yield && $yield['pieces_per_input'] !== null ? (float)$yield['pieces_per_input'] : 1.0;
        return ['unit' => 'barra', 'pcs_per' => $per > 0 ? $per : 1.0];
    }
    $stmt = $db->prepare('SELECT dough_type_id FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $dough = bakery_pack_dough_yield($db, (int)$stmt->fetchColumn());
    if ($dough) {
        $perGal = (float)$dough['trays_per_gallon'] * (int)$dough['pieces_per_tray'];
        if ($perGal > 0) {
            return ['unit' => 'gallon', 'pcs_per' => $perGal];
        }
    }
    return ['unit' => 'piece', 'pcs_per' => 1.0];
}

/**
 * Baker formula scaled to a planned piece count (dough grams = pieces × piece weight).
 *
 * @return array{product:string,dough:string,pieces:int,dough_grams:int,lines:list<array{name:string,percentage:float,grams:float}>,note:?string}
 */
function bakery_pack_formula_sheet(PDO $db, int $productId, int $pieces): array
{
    $stmt = $db->prepare(
        'SELECT p.name, p.weight_grams, p.dough_type_id, dt.name AS dough_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new InvalidArgumentException('Unknown product');
    }
    $weight = (int)($product['weight_grams'] ?? 0);
    $doughGrams = $pieces > 0 && $weight > 0 ? $pieces * $weight : 0;
    $out = [
        'product' => (string)$product['name'],
        'dough' => (string)($product['dough_name'] ?? ''),
        'pieces' => max(0, $pieces),
        'piece_weight_grams' => $weight,
        'dough_grams' => $doughGrams,
        'lines' => [],
        'note' => null,
    ];
    $doughTypeId = (int)($product['dough_type_id'] ?? 0);
    if ($doughTypeId <= 0 || !table_exists($db, 'formula_ingredients')) {
        $out['note'] = 'No formula';
        return $out;
    }
    $lineStmt = $db->prepare(
        'SELECT i.name, i.unit, fi.percentage
         FROM formula_ingredients fi
         JOIN ingredients i ON i.id = fi.ingredient_id
         WHERE fi.dough_type_id = ?
         ORDER BY fi.percentage DESC, i.name'
    );
    $lineStmt->execute([$doughTypeId]);
    $rows = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        $out['note'] = 'No formula';
        return $out;
    }
    $totalPct = 0.0;
    foreach ($rows as $row) {
        $totalPct += (float)$row['percentage'];
    }
    $flourGrams = ($doughGrams > 0 && $totalPct > 0) ? ($doughGrams / ($totalPct / 100.0)) : 0.0;
    foreach ($rows as $row) {
        $pct = (float)$row['percentage'];
        $out['lines'][] = [
            'name' => (string)$row['name'],
            'unit' => $row['unit'] !== null ? (string)$row['unit'] : '',
            'percentage' => $pct,
            'grams' => $flourGrams > 0 ? round($flourGrams * ($pct / 100.0), 1) : 0.0,
        ];
    }
    return $out;
}

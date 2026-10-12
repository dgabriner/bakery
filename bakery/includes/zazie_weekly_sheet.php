<?php
/**
 * Zazie weekly order sheet.
 *
 * Parses a weekday grid into dated quantities for the Zazie customer.
 * Prices and dated-order writes stay in bakery_resolve_customer_price and
 * bakery_demand_set_dated_quantity. This file does not copy those rules.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/customer_portal.php';

class BakeryZazieWeeklyException extends RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(public string $messageKey, public array $params = [])
    {
        parent::__construct($messageKey);
    }
}

function bakery_zazie_weekly_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'zazie_sheet_product_map');
}

function bakery_zazie_weekly_customer(PDO $db): ?array
{
    $clause = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->query(
        'SELECT c.* FROM customers c
         WHERE c.is_active = 1 AND LOWER(TRIM(c.name)) = \'zazie\' ' . $clause . '
         ORDER BY c.id ASC
         LIMIT 1'
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}

/** @return list<array{id:int,name:string}> */
function bakery_zazie_weekly_products(PDO $db): array
{
    $rows = $db->query('SELECT id, name FROM products ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    return $out;
}

function bakery_zazie_sheet_key(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, bakery_zazie_accent_map());
    $name = preg_replace('/[^a-z0-9 ]+/', '', $name) ?? $name;
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    return $name;
}

/** @return array<string, string> */
function bakery_zazie_accent_map(): array
{
    return [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    ];
}

function bakery_zazie_soft_fold(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, bakery_zazie_accent_map());
}

function bakery_zazie_token(string $value): string
{
    $value = bakery_zazie_soft_fold($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

/** @return array<string, int> */
function bakery_zazie_weekday_tokens(): array
{
    return [
        'monday' => 1, 'mon' => 1, 'lunes' => 1, 'lun' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'martes' => 2, 'mar' => 2,
        'wednesday' => 3, 'wed' => 3, 'miercoles' => 3, 'mie' => 3, 'mier' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'jueves' => 4, 'jue' => 4,
        'friday' => 5, 'fri' => 5, 'viernes' => 5, 'vie' => 5,
        'saturday' => 6, 'sat' => 6, 'sabado' => 6, 'sab' => 6,
        'sunday' => 7, 'sun' => 7, 'domingo' => 7, 'dom' => 7,
    ];
}

function bakery_zazie_excel_serial_date(int $serial): ?string
{
    if ($serial < 40000 || $serial > 60000) {
        return null;
    }
    $base = new DateTimeImmutable('1899-12-30');
    return $base->modify('+' . $serial . ' days')->format('Y-m-d');
}

function bakery_zazie_valid_date(int $year, int $month, int $day): ?string
{
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function bakery_zazie_cell_date(string $raw): ?string
{
    if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $raw, $match)) {
        return bakery_zazie_valid_date((int)$match[1], (int)$match[2], (int)$match[3]);
    }
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $raw, $match)) {
        $year = (int)$match[3];
        if ($year < 100) {
            $year += 2000;
        }
        return bakery_zazie_valid_date($year, (int)$match[1], (int)$match[2]);
    }
    $trim = trim($raw);
    if (preg_match('/^\d{5}$/', $trim)) {
        return bakery_zazie_excel_serial_date((int)$trim);
    }
    return null;
}

function bakery_zazie_monday_of(string $ymd): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$date || $date->format('Y-m-d') !== $ymd) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_week');
    }
    $offset = (int)$date->format('N') - 1;
    return $date->modify('-' . $offset . ' days')->format('Y-m-d');
}

function bakery_zazie_add_days(string $monday, int $days): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $monday);
    if (!$date) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_week');
    }
    return $date->modify('+' . $days . ' days')->format('Y-m-d');
}

function bakery_zazie_parse_qty(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    if (!preg_match('/^\d+(\.0+)?$/', $raw)) {
        return null;
    }
    $qty = (int)round((float)$raw);
    if ($qty < 0 || $qty > 100000) {
        return null;
    }
    return $qty;
}

/**
 * @param list<list<string>> $rows
 */
function bakery_zazie_find_header_index(array $rows): ?int
{
    $limit = min(count($rows), 30);
    for ($i = 0; $i < $limit; $i++) {
        $days = 0;
        foreach ($rows[$i] as $cell) {
            if (bakery_zazie_cell_date((string)$cell) !== null || isset(bakery_zazie_weekday_tokens()[bakery_zazie_token((string)$cell)])) {
                $days++;
            }
        }
        if ($days >= 2) {
            return $i;
        }
    }
    return null;
}

/**
 * @param list<list<string>> $rows
 */
function bakery_zazie_find_anchor(array $rows, int $headerIndex): ?string
{
    $fallback = null;
    $last = min($headerIndex, count($rows) - 1);
    for ($i = 0; $i <= $last; $i++) {
        $joined = implode(' ', array_map('strval', $rows[$i]));
        $soft = bakery_zazie_soft_fold($joined);
        $date = bakery_zazie_cell_date($joined);
        $strong = str_contains($soft, 'week of') || str_contains($soft, 'semana del') || str_contains($soft, 'semana de');
        if ($strong && $date !== null) {
            return $date;
        }
        if ($date !== null && $fallback === null) {
            $fallback = $date;
        }
    }
    return $fallback;
}

/**
 * @param list<list<string>> $rows
 * @return array{
 *   dates: list<string>,
 *   rows: list<array{sheet_name:string,sheet_key:string,quantities:array<string,int>}>,
 *   errors: list<array{key:string,params:array<string,scalar>}>
 * }
 */
function bakery_zazie_weekly_parse_rows(array $rows, ?string $weekStart = null): array
{
    $headerIndex = bakery_zazie_find_header_index($rows);
    if ($headerIndex === null) {
        throw new BakeryZazieWeeklyException('zazie_weekly.parse_empty');
    }
    $header = $rows[$headerIndex];
    $productCol = null;
    foreach ($header as $index => $cell) {
        $isDate = bakery_zazie_cell_date((string)$cell) !== null;
        $isWeekday = isset(bakery_zazie_weekday_tokens()[bakery_zazie_token((string)$cell)]);
        if (!$isDate && !$isWeekday) {
            $productCol = (int)$index;
            break;
        }
    }
    if ($productCol === null) {
        throw new BakeryZazieWeeklyException('zazie_weekly.parse_empty');
    }

    $needsAnchor = false;
    $pending = [];
    foreach ($header as $index => $cell) {
        if ((int)$index === $productCol) {
            continue;
        }
        $explicit = bakery_zazie_cell_date((string)$cell);
        if ($explicit !== null) {
            $pending[] = ['index' => (int)$index, 'date' => $explicit];
            continue;
        }
        $dow = bakery_zazie_weekday_tokens()[bakery_zazie_token((string)$cell)] ?? null;
        if ($dow !== null) {
            $needsAnchor = true;
            $pending[] = ['index' => (int)$index, 'dow' => $dow];
        }
    }
    if ($pending === []) {
        throw new BakeryZazieWeeklyException('zazie_weekly.parse_empty');
    }

    $monday = null;
    if ($needsAnchor) {
        $anchor = bakery_zazie_find_anchor($rows, $headerIndex);
        if ($anchor === null && $weekStart !== null && trim($weekStart) !== '') {
            $anchor = trim($weekStart);
            if (bakery_zazie_cell_date($anchor) !== $anchor) {
                throw new BakeryZazieWeeklyException('zazie_weekly.bad_week');
            }
        }
        if ($anchor === null) {
            throw new BakeryZazieWeeklyException('zazie_weekly.missing_week');
        }
        $monday = bakery_zazie_monday_of($anchor);
    }

    $columns = [];
    foreach ($pending as $column) {
        $date = $column['date'] ?? bakery_zazie_add_days((string)$monday, ((int)$column['dow']) - 1);
        $columns[(int)$column['index']] = $date;
    }

    $dates = [];
    foreach ($columns as $date) {
        if (!in_array($date, $dates, true)) {
            $dates[] = $date;
        }
    }

    $parsedRows = [];
    $errors = [];
    $seen = [];
    for ($r = $headerIndex + 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $sheetName = trim((string)($row[$productCol] ?? ''));
        if ($sheetName === '') {
            continue;
        }
        $key = bakery_zazie_sheet_key($sheetName);
        if ($key === '' || isset(bakery_zazie_weekday_tokens()[$key])) {
            continue;
        }
        $quantities = [];
        foreach ($dates as $date) {
            $quantities[$date] = 0;
        }
        foreach ($columns as $index => $date) {
            $raw = trim((string)($row[$index] ?? ''));
            $qty = bakery_zazie_parse_qty($raw);
            if ($qty === null) {
                $errors[] = [
                    'key' => 'zazie_weekly.bad_quantity',
                    'params' => ['name' => $sheetName, 'date' => $date],
                ];
                continue;
            }
            $quantities[$date] = $qty;
        }
        if (isset($seen[$key])) {
            $parsedRows[$seen[$key]]['quantities'] = $quantities;
            $parsedRows[$seen[$key]]['sheet_name'] = $sheetName;
            continue;
        }
        $seen[$key] = count($parsedRows);
        $parsedRows[] = [
            'sheet_name' => $sheetName,
            'sheet_key' => $key,
            'quantities' => $quantities,
        ];
    }
    if ($parsedRows === [] && $errors === []) {
        throw new BakeryZazieWeeklyException('zazie_weekly.parse_empty');
    }

    return [
        'dates' => $dates,
        'rows' => $parsedRows,
        'errors' => $errors,
    ];
}

/**
 * @return list<list<string>>
 */
function bakery_zazie_weekly_read_file(string $path, string $originalName): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    if (filesize($path) > 2 * 1024 * 1024) {
        throw new BakeryZazieWeeklyException('zazie_weekly.file_too_large');
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return bakery_zazie_weekly_read_csv($path);
    }
    if ($ext === 'xlsx') {
        return bakery_zazie_weekly_read_xlsx($path);
    }
    throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
}

/**
 * @return list<list<string>>
 */
function bakery_zazie_weekly_read_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    $rows = [];
    try {
        while (($cells = fgetcsv($handle)) !== false) {
            if ($rows === [] && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]) ?? (string)$cells[0];
            }
            $rows[] = array_map(static fn($cell): string => trim((string)$cell), $cells);
            if (count($rows) > 500) {
                break;
            }
        }
    } finally {
        fclose($handle);
    }
    return $rows;
}

/**
 * @return list<list<string>>
 */
function bakery_zazie_weekly_read_xlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    try {
        $shared = bakery_zazie_xlsx_shared_strings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $sheetName = bakery_zazie_xlsx_first_sheet($zip);
        $sheetXml = $sheetName !== null ? ($zip->getFromName($sheetName) ?: '') : '';
    } finally {
        $zip->close();
    }
    if ($sheetXml === '') {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    return bakery_zazie_xlsx_rows($sheetXml, $shared);
}

function bakery_zazie_xlsx_first_sheet(ZipArchive $zip): ?string
{
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels') ?: '';
    if ($rels !== '' && preg_match('/Target="([^"]*worksheets\/[^"]+\.xml)"/', $rels, $match)) {
        $target = str_replace('\\', '/', $match[1]);
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        if (str_starts_with($target, 'xl/')) {
            return $target;
        }
        return 'xl/' . ltrim($target, '/');
    }
    if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    return null;
}

/**
 * @return list<string>
 */
function bakery_zazie_xlsx_shared_strings(string $xml): array
{
    if (trim($xml) === '') {
        return [];
    }
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return [];
    }
    $strings = [];
    foreach ($dom->getElementsByTagName('*') as $node) {
        if ($node->localName !== 'si') {
            continue;
        }
        $text = '';
        foreach ($node->getElementsByTagName('*') as $child) {
            if ($child->localName === 't') {
                $text .= $child->textContent;
            }
        }
        $strings[] = $text;
    }
    return $strings;
}

/**
 * @param list<string> $shared
 * @return list<list<string>>
 */
function bakery_zazie_xlsx_rows(string $xml, array $shared): array
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new BakeryZazieWeeklyException('zazie_weekly.bad_file');
    }
    $rows = [];
    foreach ($dom->getElementsByTagName('*') as $rowNode) {
        if ($rowNode->localName !== 'row') {
            continue;
        }
        $line = [];
        foreach ($rowNode->childNodes as $cell) {
            if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }
            $ref = (string)$cell->getAttribute('r');
            $index = bakery_zazie_column_index($ref);
            $line[$index] = bakery_zazie_xlsx_cell_value($cell, $shared);
        }
        if ($line === []) {
            $rows[] = [];
        } else {
            $width = max(array_keys($line)) + 1;
            $filled = array_fill(0, $width, '');
            foreach ($line as $index => $value) {
                $filled[$index] = $value;
            }
            $rows[] = $filled;
        }
        if (count($rows) > 500) {
            break;
        }
    }
    return $rows;
}

/**
 * @param list<string> $shared
 */
function bakery_zazie_xlsx_cell_value(DOMElement $cell, array $shared): string
{
    $type = (string)$cell->getAttribute('t');
    if ($type === 'inlineStr') {
        $text = '';
        foreach ($cell->getElementsByTagName('*') as $node) {
            if ($node->localName === 't') {
                $text .= $node->textContent;
            }
        }
        return trim($text);
    }
    $value = '';
    foreach ($cell->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'v') {
            $value = trim($child->textContent);
            break;
        }
    }
    if ($type === 's') {
        $index = (int)$value;
        return trim((string)($shared[$index] ?? ''));
    }
    return $value;
}

function bakery_zazie_column_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $match)) {
        return 0;
    }
    $letters = strtoupper($match[1]);
    $number = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $number = $number * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $number - 1);
}

/**
 * @return array<string, array{product_id:int,sheet_label:string}>
 */
function bakery_zazie_weekly_mapping_index(PDO $db): array
{
    if (!bakery_zazie_weekly_ready($db)) {
        throw new BakeryZazieWeeklyException('zazie_weekly.schema_missing');
    }
    $stmt = $db->query('SELECT sheet_key, sheet_label, product_id FROM zazie_sheet_product_map');
    $index = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $index[(string)$row['sheet_key']] = [
            'product_id' => (int)$row['product_id'],
            'sheet_label' => (string)$row['sheet_label'],
        ];
    }
    return $index;
}

/** @return list<array{sheet_key:string,sheet_label:string,product_id:int,product_name:string}> */
function bakery_zazie_weekly_mapping_rows(PDO $db): array
{
    if (!bakery_zazie_weekly_ready($db)) {
        return [];
    }
    $stmt = $db->query(
        'SELECT m.sheet_key, m.sheet_label, m.product_id, p.name AS product_name
         FROM zazie_sheet_product_map m
         INNER JOIN products p ON p.id = m.product_id
         ORDER BY m.sheet_label ASC, m.id ASC'
    );
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'sheet_key' => (string)$row['sheet_key'],
            'sheet_label' => (string)$row['sheet_label'],
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
        ];
    }
    return $rows;
}

function bakery_zazie_weekly_save_mapping(PDO $db, string $sheetName, int $productId): void
{
    if (!bakery_zazie_weekly_ready($db)) {
        throw new BakeryZazieWeeklyException('zazie_weekly.schema_missing');
    }
    $label = trim($sheetName);
    if (mb_strlen($label) > 191) {
        $label = mb_substr($label, 0, 191);
    }
    $key = bakery_zazie_sheet_key($label);
    if ($key === '' || $productId <= 0) {
        throw new BakeryZazieWeeklyException('zazie_weekly.map_invalid');
    }
    $product = $db->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $product->execute([$productId]);
    if (!$product->fetchColumn()) {
        throw new BakeryZazieWeeklyException('zazie_weekly.map_invalid');
    }
    $stmt = $db->prepare(
        'INSERT INTO zazie_sheet_product_map (sheet_key, sheet_label, product_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE sheet_label = ?, product_id = ?'
    );
    $stmt->execute([$key, $label, $productId, $label, $productId]);
}

/**
 * @param list<string> $dates
 * @return array<string, array<int, int>>
 */
function bakery_zazie_weekly_existing_map(PDO $db, int $customerId, array $dates): array
{
    $dates = array_values(array_filter($dates, static fn(string $date): bool => bakery_zazie_cell_date($date) === $date));
    if ($customerId <= 0 || $dates === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $db->prepare(
        "SELECT do.order_date, doi.product_id, doi.quantity
         FROM daily_orders do
         INNER JOIN daily_order_items doi ON doi.daily_order_id = do.id
         WHERE do.customer_id = ? AND do.order_date IN ($placeholders)"
    );
    $stmt->execute(array_merge([$customerId], $dates));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string)$row['order_date'];
        $map[$date][(int)$row['product_id']] = (int)$row['quantity'];
    }
    return $map;
}

function bakery_zazie_weekly_cell_status(int $sheetQty, int $existingQty): string
{
    if ($sheetQty === $existingQty) {
        return 'unchanged';
    }
    if ($existingQty === 0 && $sheetQty > 0) {
        return 'new';
    }
    return 'changed';
}

/**
 * @param array{dates:list<string>,rows:list<array{sheet_name:string,sheet_key:string,quantities:array<string,int>}>,errors:list<array{key:string,params:array<string,scalar>}>} $parsed
 * @return array<string, mixed>
 */
function bakery_zazie_weekly_build_preview(PDO $db, array $customer, array $parsed): array
{
    $mappings = bakery_zazie_weekly_mapping_index($db);
    $products = bakery_zazie_weekly_products($db);
    $byKey = [];
    foreach ($products as $product) {
        $key = bakery_zazie_sheet_key($product['name']);
        if ($key === '') {
            continue;
        }
        $byKey[$key][] = $product;
    }
    $productName = [];
    foreach ($products as $product) {
        $productName[$product['id']] = $product['name'];
    }
    $dates = $parsed['dates'];
    $existing = bakery_zazie_weekly_existing_map($db, (int)$customer['id'], $dates);
    $lines = [];
    $targets = [];
    $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'unmapped' => 0];
    foreach ($parsed['rows'] as $row) {
        $key = $row['sheet_key'];
        $productId = null;
        if (isset($mappings[$key]) && isset($productName[$mappings[$key]['product_id']])) {
            $productId = $mappings[$key]['product_id'];
        } elseif (isset($byKey[$key]) && count($byKey[$key]) === 1) {
            $productId = $byKey[$key][0]['id'];
        }
        if ($productId === null) {
            $counts['unmapped']++;
            $cells = [];
            foreach ($dates as $date) {
                $cells[$date] = [
                    'sheet_qty' => (int)($row['quantities'][$date] ?? 0),
                    'existing_qty' => 0,
                    'status' => 'unmapped',
                ];
            }
            $lines[] = [
                'sheet_name' => $row['sheet_name'],
                'sheet_key' => $key,
                'product_id' => null,
                'product_name' => null,
                'unmapped' => true,
                'cells' => $cells,
            ];
            continue;
        }
        $cells = [];
        foreach ($dates as $date) {
            $sheetQty = (int)($row['quantities'][$date] ?? 0);
            $existingQty = (int)($existing[$date][$productId] ?? 0);
            $status = bakery_zazie_weekly_cell_status($sheetQty, $existingQty);
            if (!($sheetQty === 0 && $existingQty === 0)) {
                $counts[$status]++;
            }
            $cells[$date] = [
                'sheet_qty' => $sheetQty,
                'existing_qty' => $existingQty,
                'status' => $status,
            ];
            if ($status === 'new' || $status === 'changed') {
                $targets[] = [
                    'date' => $date,
                    'product_id' => $productId,
                    'quantity' => $sheetQty,
                    'product_name' => $productName[$productId] ?? ('#' . $productId),
                    'sheet_name' => $row['sheet_name'],
                ];
            }
        }
        $lines[] = [
            'sheet_name' => $row['sheet_name'],
            'sheet_key' => $key,
            'product_id' => $productId,
            'product_name' => $productName[$productId] ?? null,
            'unmapped' => false,
            'cells' => $cells,
        ];
    }

    return [
        'dates' => $dates,
        'errors' => $parsed['errors'],
        'counts' => $counts,
        'lines' => $lines,
        'targets' => $targets,
        'customer_id' => (int)$customer['id'],
        'customer_name' => (string)$customer['name'],
    ];
}

/**
 * @param array<string, mixed> $session
 */
function bakery_zazie_weekly_begin_preview(PDO $db, string $path, string $originalName, ?string $weekStart, array &$session): void
{
    $customer = bakery_zazie_weekly_customer($db);
    if (!$customer) {
        throw new BakeryZazieWeeklyException('zazie_weekly.missing_customer');
    }
    $parsed = bakery_zazie_weekly_parse_rows(bakery_zazie_weekly_read_file($path, $originalName), $weekStart);
    bakery_zazie_weekly_build_preview($db, $customer, $parsed);
    $session['zazie_weekly_preview'] = [
        'token' => bin2hex(random_bytes(16)),
        'customer_id' => (int)$customer['id'],
        'parsed' => $parsed,
    ];
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>|null
 */
function bakery_zazie_weekly_session_preview(PDO $db, array $session): ?array
{
    $stored = $session['zazie_weekly_preview'] ?? null;
    if (!is_array($stored) || !isset($stored['parsed'], $stored['token'], $stored['customer_id'])) {
        return null;
    }
    $customer = bakery_zazie_weekly_customer($db);
    if (!$customer || (int)$customer['id'] !== (int)$stored['customer_id']) {
        return null;
    }
    $preview = bakery_zazie_weekly_build_preview($db, $customer, $stored['parsed']);
    $preview['token'] = (string)$stored['token'];
    return $preview;
}

/**
 * @param array<string, mixed> $preview
 * @return array{applied:int,unchanged:int,unmapped:int}
 */
function bakery_zazie_weekly_apply(PDO $db, array $customer, array $preview): array
{
    if (!empty($preview['errors'])) {
        throw new BakeryZazieWeeklyException('zazie_weekly.has_errors');
    }
    if ((int)($preview['customer_id'] ?? 0) !== (int)$customer['id']) {
        throw new BakeryZazieWeeklyException('zazie_weekly.preview_expired');
    }
    $customerId = (int)$customer['id'];
    $dates = [];
    foreach ($preview['targets'] ?? [] as $target) {
        if (is_array($target) && isset($target['date'])) {
            $dates[] = (string)$target['date'];
        }
    }
    $applied = 0;
    $db->beginTransaction();
    try {
        $existing = bakery_zazie_weekly_existing_map($db, $customerId, $dates);
        foreach ($preview['targets'] ?? [] as $target) {
            if (!is_array($target)) {
                continue;
            }
            $date = (string)($target['date'] ?? '');
            $productId = (int)($target['product_id'] ?? 0);
            $quantity = (int)($target['quantity'] ?? 0);
            if ($date === '' || $productId <= 0) {
                continue;
            }
            $current = (int)($existing[$date][$productId] ?? 0);
            if ($current === $quantity) {
                continue;
            }
            $productName = (string)($target['product_name'] ?? ('#' . $productId));
            $result = bakery_demand_set_dated_quantity($db, $customerId, $date, $productId, $quantity, [
                'source' => 'zazie_weekly_sheet',
                'summary' => 'Zazie weekly sheet set ' . $productName . ' to ' . $quantity . ' on ' . $date,
            ]);
            if (!empty($result['changed'])) {
                $applied++;
            }
            $existing[$date][$productId] = $quantity;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'applied' => $applied,
        'unchanged' => (int)($preview['counts']['unchanged'] ?? 0),
        'unmapped' => (int)($preview['counts']['unmapped'] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $session
 * @return array{applied:int,unchanged:int,unmapped:int}
 */
function bakery_zazie_weekly_confirm(PDO $db, string $token, array &$session): array
{
    $stored = $session['zazie_weekly_preview'] ?? null;
    $storedToken = is_array($stored) ? (string)($stored['token'] ?? '') : '';
    if ($storedToken === '' || !hash_equals($storedToken, $token)) {
        throw new BakeryZazieWeeklyException('zazie_weekly.preview_expired');
    }
    $preview = bakery_zazie_weekly_session_preview($db, $session);
    if ($preview === null) {
        throw new BakeryZazieWeeklyException('zazie_weekly.preview_expired');
    }
    $customer = bakery_zazie_weekly_customer($db);
    if (!$customer) {
        throw new BakeryZazieWeeklyException('zazie_weekly.missing_customer');
    }
    $result = bakery_zazie_weekly_apply($db, $customer, $preview);
    unset($session['zazie_weekly_preview']);
    return $result;
}

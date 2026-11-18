<?php
/**
 * One proof classifier for a delivery stop.
 *
 * Classes are after, receipt_only, arrival_only, or none. "Con foto" is a
 * real After photo. A receipt counts only when Danny sets
 * BAKERY_STOP_PROOF_RECEIPT_COUNTS_AS_DELIVERY. A Before photo never does.
 * This file does not read the database. The delivery gate is delivery_proof.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Danny's decision D4. False until he says a receipt is a delivery photo.
 */
const BAKERY_STOP_PROOF_RECEIPT_COUNTS_AS_DELIVERY = false;

/** @return list<string> */
function bakery_stop_proof_classes(): array
{
    return ['after', 'receipt_only', 'arrival_only', 'none'];
}

/** @return list<string> */
function bakery_stop_proof_integrity_flag_names(): array
{
    return ['reused', 'arrival_equals_after', 'far_from_store', 'out_of_order', 'cluster'];
}

/**
 * Filename token wins over the photo_type column, same rule as photo journey.
 *
 * @param array<string, mixed> $photo
 */
function bakery_stop_proof_photo_type(array $photo): string
{
    foreach (['filename', 'file_path', 'original_filename'] as $key) {
        $name = (string)($photo[$key] ?? '');
        if ($name !== '' && preg_match('/driver(\d+)_customer(\d+)_(Before|After|Receipt)(?:_|\.|$)/', $name, $matches)) {
            return $matches[3];
        }
    }
    $raw = (string)($photo['photo_type'] ?? ($photo['_photo_type'] ?? ''));
    if (strcasecmp($raw, 'After') === 0) {
        return 'After';
    }
    if (strcasecmp($raw, 'Before') === 0) {
        return 'Before';
    }
    if (strcasecmp($raw, 'Receipt') === 0) {
        return 'Receipt';
    }
    return $raw;
}

/**
 * @param array<string, mixed> $photo
 * @return list<string>
 */
function bakery_stop_proof_photo_flags(array $photo): array
{
    $raw = $photo['integrity_flags'] ?? null;
    if (is_array($raw)) {
        $flags = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        $flags = is_array($decoded) ? $decoded : [];
    } else {
        return [];
    }
    $known = array_fill_keys(bakery_stop_proof_integrity_flag_names(), true);
    $out = [];
    foreach ($flags as $flag) {
        $flag = (string)$flag;
        if (isset($known[$flag]) && !in_array($flag, $out, true)) {
            $out[] = $flag;
        }
    }
    return $out;
}

/**
 * Photo type decides the class. Delivery status does not promote a Before
 * photo into an After photo.
 *
 * @param array<int, mixed> $photos
 */
function bakery_stop_proof_class(array $photos, string $deliveryStatus): string
{
    // $deliveryStatus is part of the contract. It does not promote a Before
    // photo, a receipt, or an empty stop into after.
    $hasAfter = false;
    $hasReceipt = false;
    $hasBefore = false;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $type = bakery_stop_proof_photo_type($photo);
        if (strcasecmp($type, 'After') === 0) {
            $hasAfter = true;
        } elseif (strcasecmp($type, 'Receipt') === 0) {
            $hasReceipt = true;
        } elseif (strcasecmp($type, 'Before') === 0) {
            $hasBefore = true;
        }
    }
    if ($hasAfter) {
        return 'after';
    }
    if ($hasReceipt) {
        return 'receipt_only';
    }
    if ($hasBefore) {
        return 'arrival_only';
    }
    return 'none';
}

function bakery_stop_proof_is_delivery_photo(string $class): bool
{
    if ($class === 'after') {
        return true;
    }
    if ($class === 'receipt_only') {
        return BAKERY_STOP_PROOF_RECEIPT_COUNTS_AS_DELIVERY;
    }
    return false;
}

/**
 * True when every After photo on the stop carries an integrity flag.
 * A clean After photo still counts. No After photo is not doubtful.
 *
 * @param array<int, mixed> $photos
 */
function bakery_stop_proof_after_is_doubtful(array $photos): bool
{
    $sawAfter = false;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        if (strcasecmp(bakery_stop_proof_photo_type($photo), 'After') !== 0) {
            continue;
        }
        $sawAfter = true;
        if (bakery_stop_proof_photo_flags($photo) === []) {
            return false;
        }
    }
    return $sawAfter;
}

/**
 * Delivered with only an arrival photo, until an After photo exists.
 *
 * @param array<int, mixed> $photos
 */
function bakery_stop_needs_photo_2(array $photos, string $deliveryStatus): bool
{
    if (strtolower(trim($deliveryStatus)) !== 'delivered') {
        return false;
    }
    return bakery_stop_proof_class($photos, $deliveryStatus) === 'arrival_only';
}

/**
 * @param array<string, mixed> $stop
 */
function bakery_stop_proof_mark(array $stop): string
{
    $photos = $stop['photos'] ?? [];
    if (!is_array($photos)) {
        $photos = [];
    }
    $status = (string)($stop['delivery_status'] ?? '');
    $class = bakery_stop_proof_class($photos, $status);
    if (bakery_stop_proof_is_delivery_photo($class) && !bakery_stop_proof_after_is_doubtful($photos)) {
        return 'with_photo';
    }
    if ($class === 'after' && bakery_stop_proof_after_is_doubtful($photos)) {
        return 'doubtful_photo';
    }
    $departure = trim((string)($stop['delivered_at'] ?? ''));
    $hasDeparture = $departure !== '' && $departure !== '0000-00-00 00:00:00' && strtotime($departure) !== false;
    $badge = (string)($stop['badge'] ?? '');
    if ($status === 'delivered' || ($hasDeparture && $badge !== 'arrived_open')) {
        return 'delivered_no_photo';
    }
    return 'no_info';
}

/**
 * Shared counts: after (con foto), arrival_only (entregadas sin foto),
 * none (sin info). Receipt and doubtful are listed beside those three.
 *
 * @param array<int, mixed> $stops
 * @return array{after:int,arrival_only:int,none:int,receipt_only:int,doubtful:int}
 */
function bakery_stop_proof_report_counts(array $stops): array
{
    $counts = [
        'after' => 0,
        'arrival_only' => 0,
        'none' => 0,
        'receipt_only' => 0,
        'doubtful' => 0,
    ];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $photos = $stop['photos'] ?? [];
        if (!is_array($photos)) {
            $photos = [];
        }
        $status = (string)($stop['delivery_status'] ?? '');
        $class = bakery_stop_proof_class($photos, $status);
        $mark = bakery_stop_proof_mark($stop);
        if ($mark === 'with_photo') {
            $counts['after']++;
        } elseif ($mark === 'doubtful_photo') {
            $counts['doubtful']++;
        } elseif ($class === 'arrival_only' && $mark === 'delivered_no_photo') {
            $counts['arrival_only']++;
        } elseif ($class === 'receipt_only') {
            $counts['receipt_only']++;
        } elseif ($mark === 'no_info') {
            $counts['none']++;
        }
    }
    return $counts;
}

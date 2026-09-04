<?php
/**
 * Ingredient purchase notes — ordered / received stamps per bake date.
 * No POs, no stock mutation (Mission 63 step A).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_ingredient_purchase_notes_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'ingredient_purchase_notes');
}

/**
 * @return array<string,mixed>|null
 */
function bakery_ingredient_purchase_note_get(PDO $db, int $ingredientId, string $bakeDate): ?array
{
    if ($ingredientId <= 0 || !bakery_ingredient_purchase_notes_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT n.*, u.display_name AS updated_by_name
         FROM ingredient_purchase_notes n
         LEFT JOIN users u ON u.id = n.updated_by_user_id
         WHERE n.ingredient_id = ? AND n.bake_date = ?
         LIMIT 1'
    );
    $stmt->execute([$ingredientId, $bakeDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>> keyed by ingredient_id
 */
function bakery_ingredient_purchase_notes_for_date(PDO $db, string $bakeDate): array
{
    if (!bakery_ingredient_purchase_notes_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT n.*, u.display_name AS updated_by_name
         FROM ingredient_purchase_notes n
         LEFT JOIN users u ON u.id = n.updated_by_user_id
         WHERE n.bake_date = ?'
    );
    $stmt->execute([$bakeDate]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['ingredient_id']] = $row;
    }
    return $out;
}

/**
 * Upsert ordered/received/note for one ingredient on a bake date.
 *
 * @param array{ordered?:bool|int,received?:bool|int,note?:string} $input
 * @return array<string,mixed>
 */
function bakery_ingredient_purchase_note_upsert(PDO $db, int $ingredientId, string $bakeDate, array $input, int $userId): array
{
    if ($ingredientId <= 0) {
        throw new InvalidArgumentException('Ingredient is required');
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $bakeDate);
    if (!$dt || $dt->format('Y-m-d') !== $bakeDate) {
        throw new InvalidArgumentException('Bake date must be Y-m-d');
    }
    if (!bakery_ingredient_purchase_notes_ready($db)) {
        throw new RuntimeException('Purchase notes table is not installed. Run migration 081.');
    }
    $ordered = !empty($input['ordered']) ? 1 : 0;
    $received = !empty($input['received']) ? 1 : 0;
    $note = trim((string)($input['note'] ?? ''));
    if (strlen($note) > 500) {
        $note = substr($note, 0, 500);
    }
    $userId = $userId > 0 ? $userId : null;
    $stmt = $db->prepare(
        'INSERT INTO ingredient_purchase_notes
            (ingredient_id, bake_date, ordered, received, note, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           ordered = VALUES(ordered),
           received = VALUES(received),
           note = VALUES(note),
           updated_by_user_id = VALUES(updated_by_user_id),
           updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$ingredientId, $bakeDate, $ordered, $received, $note !== '' ? $note : null, $userId]);
    $row = bakery_ingredient_purchase_note_get($db, $ingredientId, $bakeDate);
    if (!$row) {
        throw new RuntimeException('Purchase note did not persist');
    }
    return $row;
}

/**
 * Needed ingredients (required > 0 or shortage) that are neither ordered nor received.
 *
 * @param list<array<string,mixed>> $ingredientRows from planner build
 * @return list<array<string,mixed>>
 */
function bakery_ingredient_purchase_notes_unmarked_needed(array $ingredientRows, array $notesByIngredientId): array
{
    $out = [];
    foreach ($ingredientRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)($row['ingredient_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $needed = (float)($row['required_grams'] ?? 0) > 0.05
            || (float)($row['shortage_grams'] ?? 0) > 0.05
            || !empty($row['suggested_purchase']);
        if (!$needed) {
            continue;
        }
        $note = $notesByIngredientId[$id] ?? null;
        $ordered = is_array($note) && !empty($note['ordered']);
        $received = is_array($note) && !empty($note['received']);
        if ($ordered || $received) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

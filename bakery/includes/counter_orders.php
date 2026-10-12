<?php
/**
 * Counter pickup slips.
 *
 * A cashier (or anyone on the shop floor) writes what someone wants,
 * where they will pick it up, and whether it is paid. Optional photo.
 * These rows are operational notes. They do not create wholesale orders,
 * Square sales, or finished-goods movements.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_counter_order_places(): array {
    return ['capp', 'panaderia', 'delivery'];
}

function bakery_counter_order_pay_statuses(): array {
    return ['paid', 'unpaid', 'other'];
}

function bakery_counter_order_can_complete(string $role): bool {
    return in_array($role, ['cashier', 'manager', 'administrator'], true);
}

function bakery_counter_order_validate(array $input, bool $hasPhoto): array {
    $date = trim((string)($input['pickup_date'] ?? ''));
    $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    if ($dateOk) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateOk = $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
    if (!$dateOk) {
        return ['ok' => false, 'error' => 'bad_date'];
    }

    $place = strtolower(trim((string)($input['pickup_place'] ?? '')));
    if (!in_array($place, bakery_counter_order_places(), true)) {
        return ['ok' => false, 'error' => 'bad_place'];
    }

    $pay = strtolower(trim((string)($input['pay_status'] ?? '')));
    if (!in_array($pay, bakery_counter_order_pay_statuses(), true)) {
        return ['ok' => false, 'error' => 'bad_pay'];
    }

    $text = trim((string)($input['order_text'] ?? ''));
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 2000);
    } else {
        $text = substr($text, 0, 2000);
    }
    if ($text === '' && !$hasPhoto) {
        return ['ok' => false, 'error' => 'need_what'];
    }

    $email = trim((string)($input['customer_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'bad_email'];
    }

    $note = trim((string)($input['pay_note'] ?? ''));
    if ($pay !== 'other') {
        $note = '';
    }

    return [
        'ok' => true,
        'fields' => [
            'pickup_date' => $date,
            'pickup_place' => $place,
            'order_text' => $text,
            'pay_status' => $pay,
            'pay_note' => bakery_counter_order_clip($note, 160),
            'customer_name' => bakery_counter_order_clip(trim((string)($input['customer_name'] ?? '')), 120),
            'customer_phone' => bakery_counter_order_clip(trim((string)($input['customer_phone'] ?? '')), 40),
            'customer_email' => bakery_counter_order_clip($email, 160),
        ],
    ];
}

function bakery_counter_order_clip(string $value, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

/**
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file
 * @return array{ok:bool,error?:string,id?:int}
 */
function bakery_counter_order_create(PDO $db, int $userId, array $input, ?array $file = null, bool $fromLocalPath = false): array {
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    $hasPhoto = bakery_counter_order_file_present($file);
    $validated = bakery_counter_order_validate($input, $hasPhoto);
    if (empty($validated['ok'])) {
        return $validated;
    }

    $photoPath = '';
    $photoMime = '';
    if ($hasPhoto) {
        $stored = bakery_counter_order_store_photo($file, $userId, $fromLocalPath);
        if (empty($stored['ok'])) {
            return $stored;
        }
        $photoPath = (string)$stored['path'];
        $photoMime = (string)$stored['mime'];
    }

    $fields = $validated['fields'];
    try {
        $stmt = $db->prepare(
            'INSERT INTO counter_orders
                (pickup_date, pickup_place, order_text, pay_status, pay_note,
                 customer_name, customer_phone, customer_email, photo_path, photo_mime, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fields['pickup_date'],
            $fields['pickup_place'],
            $fields['order_text'],
            $fields['pay_status'],
            $fields['pay_note'],
            $fields['customer_name'],
            $fields['customer_phone'],
            $fields['customer_email'],
            $photoPath,
            $photoMime,
            $userId,
        ]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        if ($photoPath !== '') {
            bakery_counter_order_unlink($photoPath);
        }
        error_log('counter order create failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'save_failed'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function bakery_counter_order_mark_done(PDO $db, int $orderId, int $userId, string $role): array {
    if (!bakery_counter_order_can_complete($role) || $userId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'forbidden_done'];
    }
    $stmt = $db->prepare(
        "UPDATE counter_orders
         SET status = 'done', done_by = ?, done_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$userId, $orderId]);
    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'error' => 'missing'];
    }
    return ['ok' => true];
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_counter_order_list(PDO $db, string $status = 'pending', ?string $date = null, ?string $place = null): array {
    $where = [];
    $params = [];
    if ($status === 'pending' || $status === 'done') {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    if ($date !== null && $date !== '') {
        $where[] = 'o.pickup_date = ?';
        $params[] = $date;
    } elseif ($status === 'done') {
        $where[] = 'o.pickup_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)';
    }
    if ($place !== null && $place !== '' && in_array($place, bakery_counter_order_places(), true)) {
        $where[] = 'o.pickup_place = ?';
        $params[] = $place;
    }
    $sql = 'SELECT o.*, cu.display_name AS created_name, du.display_name AS done_name
            FROM counter_orders o
            JOIN users cu ON cu.id = o.created_by
            LEFT JOIN users du ON du.id = o.done_by';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.pickup_date ASC, o.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function bakery_counter_order_file_present(?array $file): bool {
    if ($file === null) {
        return false;
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    return true;
}

/**
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:bool,error?:string,path?:string,mime?:string}
 */
function bakery_counter_order_store_photo(array $file, int $userId, bool $fromLocalPath = false): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    if ($fromLocalPath) {
        if (PHP_SAPI !== 'cli') {
            return ['ok' => false, 'error' => 'photo_failed'];
        }
    } elseif (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        $size = (int)@filesize($tmp);
    }
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }

    $mime = (string)($file['type'] ?? '');
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                $mime = $detected;
            }
        }
    }
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false && !in_array($mime, ['image/heic', 'image/heif'], true)) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)) {
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
    }
    $stamp = date('Ymd_His');
    $unique = bin2hex(random_bytes(4));
    $filename = 'counter' . $userId . '_' . $stamp . '_' . $unique . '.' . $ext;
    $yearMonth = date('Y/m');
    $dir = dirname(__DIR__) . '/uploads/counter_orders/' . $yearMonth;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    $target = $dir . '/' . $filename;
    $moved = $fromLocalPath ? @copy($tmp, $target) : @move_uploaded_file($tmp, $target);
    if (!$moved) {
        return ['ok' => false, 'error' => 'photo_failed'];
    }
    bakery_counter_order_shrink_photo($target);
    return [
        'ok' => true,
        'path' => $yearMonth . '/' . $filename,
        'mime' => $mime,
    ];
}

function bakery_counter_order_photo_href(string $path): string {
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    return BASE_URL . 'uploads/counter_orders/' . $path;
}

function bakery_counter_order_unlink(string $relativePath): void {
    $relativePath = str_replace('\\', '/', $relativePath);
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return;
    }
    $full = dirname(__DIR__) . '/uploads/counter_orders/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

function bakery_counter_order_shrink_photo(string $imagePath): void {
    $imageInfo = @getimagesize($imagePath);
    if (!$imageInfo) {
        return;
    }
    [$width, $height, $type] = $imageInfo;
    $maxDim = 1600;
    if ($width <= $maxDim && $height <= $maxDim) {
        return;
    }
    if ($width > $height) {
        $newW = $maxDim;
        $newH = (int)round($height * $newW / $width);
    } else {
        $newH = $maxDim;
        $newW = (int)round($width * $newH / $height);
    }
    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($imagePath);
            break;
        case IMAGETYPE_WEBP:
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : false;
            break;
        default:
            return;
    }
    if (!$src) {
        return;
    }
    $dst = imagecreatetruecolor($newW, $newH);
    if ($type === IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dst, $imagePath, 82);
            break;
        case IMAGETYPE_PNG:
            imagepng($dst, $imagePath, 6);
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagewebp')) {
                imagewebp($dst, $imagePath, 82);
            }
            break;
    }
    imagedestroy($src);
    imagedestroy($dst);
}

<?php
/**
 * SMS segment math for outbound texts.
 *
 * GSM-7 is 160 septets for one segment and 153 once a message is concatenated.
 * Any character outside GSM-7 (Spanish á í ó ú, emoji, and most symbols)
 * switches the whole message to UCS-2: 70 UTF-16 code units, then 67.
 * Extension characters (^ { } \ [ ~ ] | €) stay GSM-7 but count as two septets.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** HQ survey texts aim for this cap and must never exceed it. */
function bakery_sms_hq_max_segments(): int
{
    return 3;
}

/**
 * @return array{basic:array<string,true>,ext:array<string,true>}
 */
function bakery_sms_gsm7_maps(): array
{
    static $maps = null;
    if ($maps !== null) {
        return $maps;
    }
    $basic = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r"
        . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡'
        . 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    $ext = '^{}\\[~]|€';
    $basicMap = [];
    $extMap = [];
    foreach (preg_split('//u', $basic, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $basicMap[$char] = true;
    }
    foreach (preg_split('//u', $ext, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $extMap[$char] = true;
    }
    $maps = ['basic' => $basicMap, 'ext' => $extMap];
    return $maps;
}

/**
 * GSM-7 septet length, or null when the text must be UCS-2.
 */
function bakery_sms_gsm7_septets(string $text): ?int
{
    if ($text === '') {
        return 0;
    }
    $maps = bakery_sms_gsm7_maps();
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return null;
    }
    $septets = 0;
    foreach ($chars as $char) {
        if (isset($maps['basic'][$char])) {
            $septets++;
            continue;
        }
        if (isset($maps['ext'][$char])) {
            $septets += 2;
            continue;
        }
        return null;
    }
    return $septets;
}

/** UTF-16 code units. Emoji outside the BMP count as two. */
function bakery_sms_ucs2_units(string $text): int
{
    if ($text === '') {
        return 0;
    }
    if (function_exists('mb_convert_encoding')) {
        $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        if (is_string($utf16)) {
            return intdiv(strlen($utf16), 2);
        }
    }
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/**
 * @return array{encoding:string,units:int,segments:int}
 */
function bakery_sms_segment_info(string $text): array
{
    $septets = bakery_sms_gsm7_septets($text);
    if ($septets !== null) {
        return [
            'encoding' => 'GSM-7',
            'units' => $septets,
            'segments' => bakery_sms_units_to_segments($septets, 160, 153),
        ];
    }
    $units = bakery_sms_ucs2_units($text);
    return [
        'encoding' => 'UCS-2',
        'units' => $units,
        'segments' => bakery_sms_units_to_segments($units, 70, 67),
    ];
}

function bakery_sms_segment_count(string $text): int
{
    return bakery_sms_segment_info($text)['segments'];
}

function bakery_sms_units_to_segments(int $units, int $singleLimit, int $concatLimit): int
{
    if ($units <= 0) {
        return 0;
    }
    if ($units <= $singleLimit) {
        return 1;
    }
    if ($concatLimit < 1) {
        return $units;
    }
    return (int)ceil($units / $concatLimit);
}

function bakery_sms_join(string $summary, string $link): string
{
    $summary = trim($summary);
    $link = trim($link);
    if ($link === '') {
        return $summary;
    }
    if ($summary === '') {
        return $link;
    }
    return $summary . "\n" . $link;
}

/**
 * Keep $link whole when it fits. Trim $summary from the end until the
 * joined text is within $maxSegments. If the link alone is too long,
 * trim the whole text by UTF-8 characters until the cap holds.
 */
function bakery_sms_cap_body(string $summary, string $link, int $maxSegments = 3): string
{
    if ($maxSegments < 1) {
        $maxSegments = 1;
    }
    $link = trim($link);
    $summary = trim($summary);
    $full = bakery_sms_join($summary, $link);
    if (bakery_sms_segment_count($full) <= $maxSegments) {
        return $full;
    }
    if ($link !== '' && bakery_sms_segment_count($link) <= $maxSegments) {
        $lines = preg_split("/\n/", $summary) ?: [];
        while ($lines !== []) {
            array_pop($lines);
            $candidate = bakery_sms_join(trim(implode("\n", $lines)), $link);
            if (bakery_sms_segment_count($candidate) <= $maxSegments) {
                return $candidate;
            }
        }
        return $link;
    }
    return bakery_sms_trim_to_segments($full, $maxSegments);
}

function bakery_sms_trim_to_segments(string $text, int $maxSegments): string
{
    if (bakery_sms_segment_count($text) <= $maxSegments) {
        return $text;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $lo = 0;
    $hi = $length;
    $best = '';
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        $slice = function_exists('mb_substr') ? mb_substr($text, 0, $mid, 'UTF-8') : substr($text, 0, $mid);
        if (bakery_sms_segment_count($slice) <= $maxSegments) {
            $best = $slice;
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    return $best;
}

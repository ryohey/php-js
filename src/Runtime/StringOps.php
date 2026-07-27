<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * UTF-16 semantics over UTF-8/WTF-8 storage (DESIGN.md §6). ASCII strings take
 * fast paths; only non-ASCII strings pay for code-unit conversion. The byte
 * walker below tolerates WTF-8 (lone surrogates encoded as 3-byte sequences),
 * which regex-based decoding would reject.
 */
final class StringOps
{
    public static function isAscii(string $s): bool
    {
        return !preg_match('/[\x80-\xFF]/', $s);
    }

    /** Length in UTF-16 code units. */
    public static function length16(string $s): int
    {
        if (self::isAscii($s)) {
            return strlen($s);
        }
        $n = 0;
        foreach (self::codePoints($s) as $cp) {
            $n += $cp >= 0x10000 ? 2 : 1;
        }
        return $n;
    }

    /** @return list<int> code points (WTF-8 tolerant; invalid bytes become U+FFFD) */
    public static function codePoints(string $s): array
    {
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $out[] = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $out[] = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $out[] = (($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12)
                    | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $out[] = 0xFFFD;
                $i++;
            }
        }
        return $out;
    }

    /** @return list<int> UTF-16 code units */
    public static function toCodeUnits(string $s): array
    {
        if (self::isAscii($s)) {
            return array_map('ord', $s === '' ? [] : str_split($s));
        }
        $units = [];
        foreach (self::codePoints($s) as $cp) {
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $units[] = 0xD800 | ($cp >> 10);
                $units[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }
        return $units;
    }

    /** @param list<int> $units */
    public static function fromCodeUnits(array $units): string
    {
        $s = '';
        $n = count($units);
        for ($i = 0; $i < $n; $i++) {
            $u = $units[$i] & 0xFFFF;
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $n) {
                $lo = $units[$i + 1] & 0xFFFF;
                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                    $s .= self::encodeCp(0x10000 + (($u - 0xD800) << 10) + ($lo - 0xDC00));
                    $i++;
                    continue;
                }
            }
            // Lone surrogates are encoded as-is (WTF-8).
            $s .= self::encodeCp($u);
        }
        return $s;
    }

    public static function encodeCp(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /** Single-code-unit string at UTF-16 index $i, or null if out of range. */
    public static function charAt(string $s, int $i): ?string
    {
        if ($i < 0) {
            return null;
        }
        if (self::isAscii($s)) {
            return $i < strlen($s) ? $s[$i] : null;
        }
        $units = self::toCodeUnits($s);
        return $i < count($units) ? self::fromCodeUnits([$units[$i]]) : null;
    }

    /** UTF-16 code unit at index $i, or null. */
    public static function charCodeAt(string $s, int $i): ?int
    {
        if ($i < 0) {
            return null;
        }
        if (self::isAscii($s)) {
            return $i < strlen($s) ? ord($s[$i]) : null;
        }
        $units = self::toCodeUnits($s);
        return $units[$i] ?? null;
    }

    /** Substring by UTF-16 code-unit range [$start, $end). */
    public static function slice16(string $s, int $start, int $end): string
    {
        if (self::isAscii($s)) {
            return $start < $end ? substr($s, $start, $end - $start) : '';
        }
        $units = self::toCodeUnits($s);
        return $start < $end ? self::fromCodeUnits(array_slice($units, $start, $end - $start)) : '';
    }

    /** Convert a byte offset to a UTF-16 code-unit offset. */
    public static function byteToCu(string $s, int $byteOffset): int
    {
        if (self::isAscii($s)) {
            return $byteOffset;
        }
        return self::length16(substr($s, 0, $byteOffset));
    }

    /** Convert a UTF-16 code-unit offset to a byte offset. */
    public static function cuToByte(string $s, int $cuOffset): int
    {
        if (self::isAscii($s)) {
            return min($cuOffset, strlen($s));
        }
        $bytes = 0;
        $cu = 0;
        $len = strlen($s);
        while ($bytes < $len && $cu < $cuOffset) {
            $b = ord($s[$bytes]);
            if ($b < 0x80) {
                $bytes += 1;
                $cu += 1;
            } elseif (($b & 0xE0) === 0xC0) {
                $bytes += 2;
                $cu += 1;
            } elseif (($b & 0xF0) === 0xE0) {
                $bytes += 3;
                $cu += 1;
            } else {
                $bytes += 4;
                $cu += 2;
            }
        }
        return $bytes;
    }
}

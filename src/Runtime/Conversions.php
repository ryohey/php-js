<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * ES5.1 abstract operations, one method per operation (DESIGN.md §3.1), so
 * they can be checked side-by-side against engine262. Operations that may
 * call back into JS (ToPrimitive on objects) take the Vm.
 */
final class Conversions
{
    private const WS_PATTERN =
        '/^(?:[\x09-\x0D\x20]|\xC2\xA0|\xEF\xBB\xBF|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/';

    /** 9.2 ToBoolean */
    public static function toBoolean(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_float($v)) {
            return $v != 0.0 && !is_nan($v);
        }
        if (is_string($v)) {
            return $v !== '';
        }
        // null, undefined -> false; objects -> true
        return $v instanceof JSObject;
    }

    /** 9.3 ToNumber */
    public static function toNumber(Vm $vm, mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
            return self::stringToNumber($v);
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if ($v === null) {
            return 0;
        }
        if ($v instanceof JSObject) {
            return self::toNumber($vm, self::toPrimitive($vm, $v, 'number'));
        }
        return NAN; // undefined
    }

    /** 9.3.1 ToNumber applied to the String type */
    public static function stringToNumber(string $s): int|float
    {
        $s = self::trimJs($s);
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $s)) {
            return hexdec(substr($s, 2));
        }
        if ($s === 'Infinity' || $s === '+Infinity') {
            return INF;
        }
        if ($s === '-Infinity') {
            return -INF;
        }
        if (preg_match('/^[+-]?\d+$/', $s)) {
            $f = (float)$s;
            return ($f >= -PHP_INT_MAX && $f <= PHP_INT_MAX) ? (int)$s : $f;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s)) {
            return (float)$s;
        }
        return NAN;
    }

    public static function trimJs(string $s): string
    {
        $s = preg_replace(self::WS_PATTERN, '', $s) ?? $s;
        // Trailing: reverse the same character set.
        return preg_replace(
            '/(?:[\x09-\x0D\x20]|\xC2\xA0|\xEF\xBB\xBF|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+$/',
            '',
            $s
        ) ?? $s;
    }

    /** 9.5 ToInt32 */
    public static function toInt32(Vm $vm, mixed $v): int
    {
        $n = self::toNumber($vm, $v);
        if (is_int($n)) {
            $n &= 0xFFFFFFFF;
            return ($n ^ 0x80000000) - 0x80000000;
        }
        if (is_nan($n) || is_infinite($n)) {
            return 0;
        }
        $n = $n < 0 ? ceil($n) : floor($n);
        $n = fmod($n, 4294967296.0);
        $i = (int)$n & 0xFFFFFFFF;
        return ($i ^ 0x80000000) - 0x80000000;
    }

    /** 9.6 ToUint32 */
    public static function toUint32(Vm $vm, mixed $v): int
    {
        return self::toInt32($vm, $v) & 0xFFFFFFFF;
    }

    /** 9.8 ToString */
    public static function toString(Vm $vm, mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return self::numberToString($v);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if ($v instanceof JSObject) {
            return self::toString($vm, self::toPrimitive($vm, $v, 'string'));
        }
        return 'undefined';
    }

    /**
     * 9.8.1 ToString applied to the Number type. PHP's var_export emits the
     * shortest round-trip decimal (serialize_precision=-1); we reformat it
     * to the exact spec layout.
     */
    public static function numberToString(int|float $m): string
    {
        if (is_int($m)) {
            return (string)$m;
        }
        if (is_nan($m)) {
            return 'NaN';
        }
        if ($m === INF) {
            return 'Infinity';
        }
        if ($m === -INF) {
            return '-Infinity';
        }
        if ($m == 0.0) {
            return '0'; // covers -0
        }
        $sign = '';
        if ($m < 0) {
            $sign = '-';
            $m = -$m;
        }
        $repr = var_export($m, true);
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:E([+-]?\d+))?$/i', $repr, $mt)) {
            return $sign . $repr; // unreachable defensively
        }
        $digits = $mt[1] . ($mt[2] ?? '');
        $lenFrac = strlen($mt[2] ?? '');
        $e = (int)($mt[3] ?? 0);
        $trimmed = rtrim($digits, '0');
        $removedTrailing = strlen($digits) - strlen($trimmed);
        $s = ltrim($trimmed, '0');
        $k = strlen($s);
        $n = $e - $lenFrac + $removedTrailing + $k;
        if ($k <= $n && $n <= 21) {
            return $sign . $s . str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign . substr($s, 0, $n) . '.' . substr($s, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign . '0.' . str_repeat('0', -$n) . $s;
        }
        $expo = $n - 1;
        $expoStr = ($expo >= 0 ? '+' : '-') . abs($expo);
        if ($k === 1) {
            return $sign . $s . 'e' . $expoStr;
        }
        return $sign . $s[0] . '.' . substr($s, 1) . 'e' . $expoStr;
    }

    /** 9.1 ToPrimitive. $hint is 'number', 'string' or 'default'. */
    public static function toPrimitive(Vm $vm, mixed $v, string $hint = 'default'): mixed
    {
        if (!$v instanceof JSObject) {
            return $v;
        }
        if ($hint === 'default') {
            $hint = $v->className === 'Date' ? 'string' : 'number';
        }
        $order = $hint === 'string' ? ['toString', 'valueOf'] : ['valueOf', 'toString'];
        foreach ($order as $name) {
            $fn = $v->get($name, $vm);
            if ($fn instanceof JSFunctionBase) {
                $result = $vm->invoke($fn, $v, []);
                if (!$result instanceof JSObject) {
                    return $result;
                }
            }
        }
        $vm->throwError('TypeError', 'Cannot convert object to primitive value');
    }

    /** 9.9 ToObject */
    public static function toObject(Vm $vm, mixed $v): JSObject
    {
        if ($v instanceof JSObject) {
            return $v;
        }
        $realm = $vm->realm;
        if (is_string($v)) {
            return new JSPrimitiveWrapper($v, 'String', $realm->stringPrototype());
        }
        if (is_int($v) || is_float($v)) {
            return new JSPrimitiveWrapper($v, 'Number', $realm->numberPrototype());
        }
        if (is_bool($v)) {
            return new JSPrimitiveWrapper($v, 'Boolean', $realm->booleanPrototype());
        }
        $vm->throwError('TypeError', 'Cannot convert ' . ($v === null ? 'null' : 'undefined') . ' to object');
    }

    /** ToInteger (9.4), used by string/array builtins. */
    public static function toInteger(Vm $vm, mixed $v): int|float
    {
        $n = self::toNumber($vm, $v);
        if (is_int($n)) {
            return $n;
        }
        if (is_nan($n)) {
            return 0;
        }
        if (is_infinite($n)) {
            return $n;
        }
        $t = $n < 0 ? ceil($n) : floor($n);
        return ($t >= -PHP_INT_MAX && $t <= PHP_INT_MAX) ? (int)$t : $t;
    }
}

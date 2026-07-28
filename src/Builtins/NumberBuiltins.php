<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class NumberBuiltins
{
    public static function entries(): array
    {
        return [
            'Number' => [self::class, 'callAsFunction'],
            'Number.ctor' => [self::class, 'ctor'],
            'Number.prototype.toString' => [self::class, 'toStringMethod'],
            'Number.prototype.toLocaleString' => [self::class, 'toStringMethod'],
            'Number.prototype.valueOf' => [self::class, 'valueOf'],
            'Number.prototype.toFixed' => [self::class, 'toFixed'],
            'Number.prototype.toPrecision' => [self::class, 'toPrecision'],
            'Number.prototype.toExponential' => [self::class, 'toExponential'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach ([
            'toString' => 1, 'toLocaleString' => 0, 'valueOf' => 0,
            'toFixed' => 1, 'toPrecision' => 1, 'toExponential' => 1,
        ] as $name => $arity) {
            $r->defineMethod($proto, $name, "Number.prototype.$name", $arity);
        }
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Number', 'Number', 1, 'Number.ctor');
        $r->linkPair($ctor, $r->numberPrototype());
        $ctor->defineOwnData('MAX_VALUE', 1.7976931348623157e308, 0);
        $ctor->defineOwnData('MIN_VALUE', 5e-324, 0);
        $ctor->defineOwnData('NaN', NAN, 0);
        $ctor->defineOwnData('POSITIVE_INFINITY', INF, 0);
        $ctor->defineOwnData('NEGATIVE_INFINITY', -INF, 0);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return count($args) === 0 ? 0 : Conversions::toNumber($vm, $args[0]);
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $n = count($args) === 0 ? 0 : Conversions::toNumber($vm, $args[0]);
        return new JSPrimitiveWrapper($n, 'Number', $vm->realm->numberPrototype());
    }

    private static function thisNumber(Vm $vm, mixed $t): int|float
    {
        if (is_int($t) || is_float($t)) {
            return $t;
        }
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'Number') {
            return $t->primitiveValue;
        }
        $vm->throwError('TypeError', 'Number.prototype method called on incompatible receiver');
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        $radixArg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $radix = $radixArg instanceof JSUndefined ? 10 : (int)Conversions::toInteger($vm, $radixArg);
        if ($radix === 10) {
            return Conversions::numberToString($n);
        }
        if ($radix < 2 || $radix > 36) {
            $vm->throwError('RangeError', 'toString() radix must be between 2 and 36');
        }
        if (is_float($n) && (is_nan($n) || is_infinite($n))) {
            return Conversions::numberToString($n);
        }
        $neg = $n < 0;
        $n = abs($n);
        $int = is_float($n) ? floor($n) : $n;
        $frac = is_float($n) ? $n - $int : 0.0;
        $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
        if ($int == 0) {
            $intStr = '0';
        } else {
            $intStr = '';
            $i = $int;
            while ($i >= 1) {
                $d = (int)fmod((float)$i, (float)$radix);
                $intStr = $digits[$d] . $intStr;
                $i = floor((float)$i / $radix);
            }
        }
        $fracStr = '';
        if ($frac > 0) {
            $fracStr = '.';
            for ($k = 0; $k < 20 && $frac > 0; $k++) {
                $frac *= $radix;
                $d = (int)floor($frac);
                $fracStr .= $digits[$d];
                $frac -= $d;
            }
        }
        return ($neg ? '-' : '') . $intStr . $fracStr;
    }

    public static function valueOf(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisNumber($vm, $t);
    }

    public static function toFixed(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        // Range-check before the int cast: ToInteger(Infinity) is Infinity,
        // and casting that to int would silently land inside the valid range.
        $requested = Conversions::toInteger($vm, $args[0] ?? 0);
        if ($requested < 0 || $requested > 100) {
            $vm->throwError('RangeError', 'toFixed() digits argument must be between 0 and 100');
        }
        $digits = (int)$requested;
        if (is_float($n) && (is_nan($n))) {
            return 'NaN';
        }
        if (is_float($n) && is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if (abs($n) >= 1e21) {
            return Conversions::numberToString($n);
        }
        // %F rounds half away from zero on the exact binary value, which is
        // what 15.7.4.5 asks for; number_format() loses digits on large values.
        return sprintf('%.*F', $digits, (float)$n);
    }

    /**
     * 15.7.4.7. Note the check order the spec mandates: ToInteger, then NaN,
     * then Infinity, and only then the range check.
     */
    public static function toPrecision(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        $arg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($arg instanceof JSUndefined) {
            return Conversions::numberToString($n);
        }
        $requested = Conversions::toInteger($vm, $arg);
        if (is_float($n) && is_nan($n)) {
            return 'NaN';
        }
        $sign = '';
        if ($n < 0) {
            $sign = '-';
            $n = -$n;
        }
        if (is_float($n) && is_infinite($n)) {
            return $sign . 'Infinity';
        }
        if ($requested < 1 || $requested > 100) {
            $vm->throwError('RangeError', 'toPrecision() argument must be between 1 and 100');
        }
        $p = (int)$requested;
        if ($n == 0) {
            $digits = str_repeat('0', $p);
            return $sign . ($p === 1 ? '0' : '0.' . substr($digits, 1));
        }
        [$digits, $e] = Conversions::exponentialParts((float)$n, $p - 1);
        if ($e < -6 || $e >= $p) {
            return $sign . self::exponentialForm($digits, $e);
        }
        if ($e === $p - 1) {
            return $sign . $digits;
        }
        if ($e >= 0) {
            return $sign . substr($digits, 0, $e + 1) . '.' . substr($digits, $e + 1);
        }
        return $sign . '0.' . str_repeat('0', -($e + 1)) . $digits;
    }

    /** 15.7.4.6 */
    public static function toExponential(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        $arg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $requested = $arg instanceof JSUndefined ? null : Conversions::toInteger($vm, $arg);
        if (is_float($n) && is_nan($n)) {
            return 'NaN';
        }
        $sign = '';
        if ($n < 0) {
            $sign = '-';
            $n = -$n;
        }
        if (is_float($n) && is_infinite($n)) {
            return $sign . 'Infinity';
        }
        if ($requested !== null && ($requested < 0 || $requested > 100)) {
            $vm->throwError('RangeError', 'toExponential() argument must be between 0 and 100');
        }
        $f = $requested === null ? null : (int)$requested;
        [$digits, $e] = Conversions::exponentialParts((float)$n, $f);
        return $sign . self::exponentialForm($digits, $e);
    }

    private static function exponentialForm(string $digits, int $e): string
    {
        $mantissa = strlen($digits) > 1 ? $digits[0] . '.' . substr($digits, 1) : $digits;
        return $mantissa . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
    }
}

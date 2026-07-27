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
        if ($t instanceof JSPrimitiveWrapper && (is_int($t->primitiveValue) || is_float($t->primitiveValue))) {
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
        $digits = (int)Conversions::toInteger($vm, $args[0] ?? 0);
        if ($digits < 0 || $digits > 100) {
            $vm->throwError('RangeError', 'toFixed() digits argument must be between 0 and 100');
        }
        if (is_float($n) && (is_nan($n))) {
            return 'NaN';
        }
        if (is_float($n) && is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if (abs($n) >= 1e21) {
            return Conversions::numberToString($n);
        }
        return number_format((float)$n, $digits, '.', '');
    }

    public static function toPrecision(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        $arg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($arg instanceof JSUndefined) {
            return Conversions::numberToString($n);
        }
        $p = (int)Conversions::toInteger($vm, $arg);
        if ($p < 1 || $p > 100) {
            $vm->throwError('RangeError', 'toPrecision() argument must be between 1 and 100');
        }
        $s = sprintf('%.' . $p . 'G', (float)$n);
        // Normalize PHP's exponent format (1.0E+5) toward JS (1.0e+5).
        return str_replace(['E+', 'E-'], ['e+', 'e-'], $s);
    }

    public static function toExponential(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::thisNumber($vm, $t);
        $arg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $digits = $arg instanceof JSUndefined ? 6 : (int)Conversions::toInteger($vm, $arg);
        if ($digits < 0 || $digits > 100) {
            $vm->throwError('RangeError', 'toExponential() argument must be between 0 and 100');
        }
        $s = sprintf('%.' . $digits . 'e', (float)$n);
        // PHP prints e+05; JS wants e+5 (no leading zeros in the exponent).
        return preg_replace('/e([+-])0*(\d)/', 'e$1$2', $s);
    }
}

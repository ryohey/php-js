<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class MathBuiltins
{
    public static function entries(): array
    {
        return [
            'Math.abs' => [self::class, 'abs'],
            'Math.floor' => [self::class, 'floor'],
            'Math.ceil' => [self::class, 'ceil'],
            'Math.round' => [self::class, 'round'],
            'Math.sqrt' => [self::class, 'sqrt'],
            'Math.pow' => [self::class, 'pow'],
            'Math.exp' => [self::class, 'exp'],
            'Math.log' => [self::class, 'log'],
            'Math.sin' => [self::class, 'sin'],
            'Math.cos' => [self::class, 'cos'],
            'Math.tan' => [self::class, 'tan'],
            'Math.asin' => [self::class, 'asin'],
            'Math.acos' => [self::class, 'acos'],
            'Math.atan' => [self::class, 'atan'],
            'Math.atan2' => [self::class, 'atan2'],
            'Math.min' => [self::class, 'min'],
            'Math.max' => [self::class, 'max'],
            'Math.random' => [self::class, 'random'],
        ];
    }

    public static function makeObject(Realm $r): JSObject
    {
        $math = new JSObject($r->objectPrototype());
        $math->className = 'Math';
        $math->nativeId = 'Math';
        $math->defineOwnData('PI', M_PI, 0);
        $math->defineOwnData('E', M_E, 0);
        $math->defineOwnData('LN2', M_LN2, 0);
        $math->defineOwnData('LN10', M_LN10, 0);
        $math->defineOwnData('LOG2E', M_LOG2E, 0);
        $math->defineOwnData('LOG10E', M_LOG10E, 0);
        $math->defineOwnData('SQRT2', M_SQRT2, 0);
        $math->defineOwnData('SQRT1_2', M_SQRT1_2, 0);
        foreach ([
            'abs' => 1, 'floor' => 1, 'ceil' => 1, 'round' => 1, 'sqrt' => 1,
            'pow' => 2, 'exp' => 1, 'log' => 1,
            'sin' => 1, 'cos' => 1, 'tan' => 1, 'asin' => 1, 'acos' => 1,
            'atan' => 1, 'atan2' => 2, 'min' => 2, 'max' => 2, 'random' => 0,
        ] as $name => $arity) {
            $r->defineMethod($math, $name, "Math.$name", $arity);
        }
        return $math;
    }

    private static function arg(Vm $vm, array $args, int $i = 0): float
    {
        return (float)Conversions::toNumber($vm, $args[$i] ?? JSUndefined::$undefined);
    }

    /** Return an int when the double is integral and in range (unboxed number policy). */
    private static function intify(float $f): int|float
    {
        if (!is_nan($f) && !is_infinite($f) && $f == floor($f)
            && $f >= -9007199254740992.0 && $f <= 9007199254740992.0 && !($f == 0.0 && 1 / $f < 0)) {
            return (int)$f;
        }
        return $f;
    }

    public static function abs(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return is_int($n) ? abs($n) : abs($n);
    }

    public static function floor(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return is_int($n) ? $n : self::intify(floor($n));
    }

    public static function ceil(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return is_int($n) ? $n : self::intify(ceil($n));
    }

    public static function round(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        if (is_int($n)) {
            return $n;
        }
        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }
        // JS rounds half toward +Infinity (unlike PHP's round()).
        return self::intify(floor($n + 0.5));
    }

    public static function sqrt(Vm $vm, mixed $t, array $args): mixed
    {
        return sqrt(self::arg($vm, $args));
    }

    public static function pow(Vm $vm, mixed $t, array $args): mixed
    {
        $base = self::arg($vm, $args, 0);
        $exp = self::arg($vm, $args, 1);
        if (is_nan($exp)) {
            return NAN;
        }
        return self::intify(pow($base, $exp));
    }

    public static function exp(Vm $vm, mixed $t, array $args): mixed
    {
        return exp(self::arg($vm, $args));
    }

    public static function log(Vm $vm, mixed $t, array $args): mixed
    {
        $x = self::arg($vm, $args);
        if ($x < 0) {
            return NAN;
        }
        return $x == 0.0 ? -INF : log($x);
    }

    public static function sin(Vm $vm, mixed $t, array $args): mixed
    {
        return sin(self::arg($vm, $args));
    }

    public static function cos(Vm $vm, mixed $t, array $args): mixed
    {
        return cos(self::arg($vm, $args));
    }

    public static function tan(Vm $vm, mixed $t, array $args): mixed
    {
        return tan(self::arg($vm, $args));
    }

    public static function asin(Vm $vm, mixed $t, array $args): mixed
    {
        $x = self::arg($vm, $args);
        return ($x < -1 || $x > 1) ? NAN : asin($x);
    }

    public static function acos(Vm $vm, mixed $t, array $args): mixed
    {
        $x = self::arg($vm, $args);
        return ($x < -1 || $x > 1) ? NAN : acos($x);
    }

    public static function atan(Vm $vm, mixed $t, array $args): mixed
    {
        return atan(self::arg($vm, $args));
    }

    public static function atan2(Vm $vm, mixed $t, array $args): mixed
    {
        return atan2(self::arg($vm, $args, 0), self::arg($vm, $args, 1));
    }

    public static function min(Vm $vm, mixed $t, array $args): mixed
    {
        $best = INF;
        foreach ($args as $a) {
            $n = Conversions::toNumber($vm, $a);
            if (is_float($n) && is_nan($n)) {
                return NAN;
            }
            if ($n < $best || ($n == 0 && $best == 0 && is_float($n) && 1 / (float)$n < 0)) {
                $best = $n;
            }
        }
        return $best;
    }

    public static function max(Vm $vm, mixed $t, array $args): mixed
    {
        $best = -INF;
        foreach ($args as $a) {
            $n = Conversions::toNumber($vm, $a);
            if (is_float($n) && is_nan($n)) {
                return NAN;
            }
            if ($n > $best || ($n == 0 && $best == 0 && is_float($best) && 1 / (float)$best < 0)) {
                $best = $n;
            }
        }
        return $best;
    }

    public static function random(Vm $vm, mixed $t, array $args): mixed
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }
}

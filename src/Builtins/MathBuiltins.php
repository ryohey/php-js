<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\TypeOps;
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
            // ES2015. Native rather than left to the JS library file for a
            // measured reason: `Math.clz32` alone was 20% of a React 19 render
            // when interpreted -- React calls it a few hundred times per render
            // for tree context bits, and the JS version shifts one bit at a
            // time inside the dispatch loop (docs/aot-php.md §2).
            'Math.clz32' => [self::class, 'clz32'],
            'Math.imul' => [self::class, 'imul'],
            'Math.trunc' => [self::class, 'trunc'],
            'Math.sign' => [self::class, 'sign'],
            'Math.log2' => [self::class, 'log2'],
            'Math.log10' => [self::class, 'log10'],
            'Math.cbrt' => [self::class, 'cbrt'],
            'Math.hypot' => [self::class, 'hypot'],
            'Math.fround' => [self::class, 'fround'],
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
            'clz32' => 1, 'imul' => 2, 'trunc' => 1, 'sign' => 1,
            'log2' => 1, 'log10' => 1, 'cbrt' => 1, 'hypot' => 2, 'fround' => 1,
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
            && $f >= -9007199254740992.0 && $f <= 9007199254740992.0 && !TypeOps::isNegativeZero($f)) {
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
            if ($n < $best || ($n == 0 && $best == 0 && TypeOps::isNegativeZero($n))) {
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
            if ($n > $best || ($n == 0 && $best == 0 && TypeOps::isNegativeZero($best))) {
                $best = $n;
            }
        }
        return $best;
    }

    public static function random(Vm $vm, mixed $t, array $args): mixed
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }

    // ---- ES2015 ------------------------------------------------------------

    /**
     * `$args[$i] ?? undefined` is wrong here and it bit twice: JS null arrives
     * as PHP null, so `??` would silently turn it into undefined, which
     * converts to NaN instead of 0 -- making `Math.sign(null)` NaN. DESIGN.md
     * §5.1 flags the same trap for property reads.
     */
    private static function at(array $args, int $i): mixed
    {
        return \array_key_exists($i, $args) ? $args[$i] : JSUndefined::$undefined;
    }

    /** Leading zero count of the ToUint32 of the argument. */
    public static function clz32(Vm $vm, mixed $t, array $args): mixed
    {
        $v = Conversions::toUint32($vm, self::at($args, 0));
        if ($v === 0) {
            return 32;
        }
        // Binary search: nine PHP operations instead of up to 32 iterations.
        $n = 0;
        if (($v & 0xFFFF0000) === 0) {
            $n += 16;
            $v <<= 16;
        }
        if (($v & 0xFF000000) === 0) {
            $n += 8;
            $v <<= 8;
        }
        if (($v & 0xF0000000) === 0) {
            $n += 4;
            $v <<= 4;
        }
        if (($v & 0xC0000000) === 0) {
            $n += 2;
            $v <<= 2;
        }
        if (($v & 0x80000000) === 0) {
            $n += 1;
        }
        return $n;
    }

    /** C-style 32-bit integer multiplication. */
    public static function imul(Vm $vm, mixed $t, array $args): mixed
    {
        $a = Conversions::toInt32($vm, self::at($args, 0));
        $b = Conversions::toInt32($vm, self::at($args, 1));
        // PHP ints are 64-bit, so the product of two int32s cannot overflow
        // before it is truncated back to 32 bits.
        return ((($a * $b) & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
    }

    public static function trunc(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, self::at($args, 0));
        if (is_int($n)) {
            return $n;
        }
        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }
        // -0.5 truncates to -0, which must stay a float to keep its sign.
        $r = $n < 0 ? ceil($n) : floor($n);
        return $r === 0.0 && ($n < 0) ? -0.0 : self::intify($r);
    }

    public static function sign(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, self::at($args, 0));
        if (is_float($n) && is_nan($n)) {
            return $n;
        }
        if ($n == 0) {
            return $n; // preserves both +0 and -0
        }
        return $n > 0 ? 1 : -1;
    }

    public static function log2(Vm $vm, mixed $t, array $args): mixed
    {
        return self::intify(log(self::arg($vm, $args), 2));
    }

    public static function log10(Vm $vm, mixed $t, array $args): mixed
    {
        return self::intify(log10(self::arg($vm, $args)));
    }

    public static function cbrt(Vm $vm, mixed $t, array $args): mixed
    {
        $x = self::arg($vm, $args);
        if ($x === 0.0 || is_nan($x) || is_infinite($x)) {
            return $x === 0.0 ? self::intify($x) : $x;
        }
        $y = ($x < 0 ? -1 : 1) * pow(abs($x), 1 / 3);
        return self::intify($y);
    }

    /** sqrt of the sum of squares, over all arguments. */
    public static function hypot(Vm $vm, mixed $t, array $args): mixed
    {
        $sum = 0.0;
        $sawInfinity = false;
        $sawNan = false;
        foreach ($args as $arg) {
            $n = (float)Conversions::toNumber($vm, $arg);
            if (is_infinite($n)) {
                $sawInfinity = true;
            } elseif (is_nan($n)) {
                $sawNan = true;
            }
            $sum += $n * $n;
        }
        // Infinity wins over NaN, per the spec's ordering.
        if ($sawInfinity) {
            return INF;
        }
        if ($sawNan) {
            return NAN;
        }
        return self::intify(sqrt($sum));
    }

    /** Round to the nearest float32. */
    public static function fround(Vm $vm, mixed $t, array $args): mixed
    {
        $n = self::arg($vm, $args);
        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }
        return self::intify(unpack('g', pack('g', $n))[1]);
    }
}

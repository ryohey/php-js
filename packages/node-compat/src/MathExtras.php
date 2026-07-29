<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\TypeOps;
use PhpJs\Vm\Vm;

/**
 * The post-ES5 Math functions, in PHP.
 *
 * `js/polyfills.js` defines all of these in JS and would work on its own, but
 * `Math.clz32` alone was **20% of a React 19 render**: React uses it for tree
 * context bits, calls it a few hundred times per render, and the JS version
 * shifts one bit at a time inside the interpreter. Everything here is a handful
 * of PHP operations instead.
 *
 * These install before the polyfill file runs, and `def()` in that file only
 * defines what is missing, so the natives win and the JS stays as a fallback.
 *
 * Numbers follow the runtime's representation policy (DESIGN.md §3.1): an int
 * whenever the double is exact, never a float that happens to be integral.
 */
final class MathExtras
{
    /** @return array<string, callable> */
    public static function entries(): array
    {
        return [
            'node.Math.clz32' => [self::class, 'clz32'],
            'node.Math.imul' => [self::class, 'imul'],
            'node.Math.trunc' => [self::class, 'trunc'],
            'node.Math.sign' => [self::class, 'sign'],
            'node.Math.log2' => [self::class, 'log2'],
            'node.Math.log10' => [self::class, 'log10'],
            'node.Math.cbrt' => [self::class, 'cbrt'],
            'node.Math.hypot' => [self::class, 'hypot'],
            'node.Math.fround' => [self::class, 'fround'],
        ];
    }

    /** Arity per name; also the install list. */
    private const ARITY = [
        'clz32' => 1, 'imul' => 2, 'trunc' => 1, 'sign' => 1,
        'log2' => 1, 'log10' => 1, 'cbrt' => 1, 'hypot' => 2, 'fround' => 1,
    ];

    public static function install(Realm $realm, Vm $vm): void
    {
        $math = $realm->globalObject->get('Math', $vm);
        if (!$math instanceof JSObject) {
            return;
        }
        foreach (self::ARITY as $name => $arity) {
            $math->defineOwnData(
                $name,
                $realm->nativeFn("node.Math.$name", $name, $arity),
                JSObject::W | JSObject::C
            );
        }
    }

    /**
     * `$args[$i] ?? undefined` is wrong: JS null arrives as PHP null and `??`
     * would silently turn it into undefined, which converts to NaN instead of
     * 0 (DESIGN.md §5.1 flags the same trap for property reads).
     */
    private static function at(array $args, int $i): mixed
    {
        return array_key_exists($i, $args) ? $args[$i] : JSUndefined::$undefined;
    }

    private static function arg(Vm $vm, array $args, int $i = 0): float
    {
        return (float)Conversions::toNumber($vm, self::at($args, $i));
    }

    /** Return an int when the double is integral and exact (§3.1). */
    private static function intify(float $f): int|float
    {
        if (!is_nan($f) && !is_infinite($f) && $f == floor($f)
            && $f >= -9007199254740992.0 && $f <= 9007199254740992.0 && !TypeOps::isNegativeZero($f)) {
            return (int)$f;
        }
        return $f;
    }

    /** Leading zero count of the ToUint32 of the argument. */
    public static function clz32(Vm $vm, mixed $t, array $args): mixed
    {
        $v = Conversions::toUint32($vm, self::at($args, 0));
        if ($v === 0) {
            return 32;
        }
        // Binary search beats the polyfill's bit-at-a-time loop and, more to
        // the point, is nine PHP operations instead of up to 32 interpreted
        // iterations.
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

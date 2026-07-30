<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * Operator semantics that need more than a native PHP operator. The VM inlines
 * numeric/string fast paths; these are the slow paths.
 */
final class TypeOps
{
    public static function typeofOp(mixed $v): string
    {
        if ($v instanceof JSUndefined) {
            return 'undefined';
        }
        if ($v === null) {
            return 'object';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if ($v instanceof JSSymbol) {
            return 'symbol';
        }
        if ($v instanceof JSFunctionBase) {
            return 'function';
        }
        return 'object';
    }

    /** 11.6.1 The Addition operator (slow path). */
    public static function add(Vm $vm, mixed $a, mixed $b): mixed
    {
        $pa = Conversions::toPrimitive($vm, $a);
        $pb = Conversions::toPrimitive($vm, $b);
        if (is_string($pa) || is_string($pb)) {
            return Conversions::toString($vm, $pa) . Conversions::toString($vm, $pb);
        }
        return Conversions::toNumber($vm, $pa) + Conversions::toNumber($vm, $pb);
    }

    /** 11.9.6 Strict Equality Comparison. */
    public static function strictEquals(mixed $a, mixed $b): bool
    {
        $aNum = is_int($a) || is_float($a);
        if ($aNum) {
            // PHP == on numbers is IEEE-conformant: NAN != NAN, +0 == -0.
            return (is_int($b) || is_float($b)) && $a == $b;
        }
        if (is_string($a)) {
            return is_string($b) && $a === $b;
        }
        if (is_bool($a)) {
            return is_bool($b) && $a === $b;
        }
        // null, undefined singleton, objects: identity.
        return $a === $b;
    }

    /** 9.12 The SameValue Algorithm (distinguishes +0/-0, treats NaN as equal). */
    public static function sameValue(mixed $a, mixed $b): bool
    {
        $aNum = is_int($a) || is_float($a);
        if ($aNum) {
            if (!is_int($b) && !is_float($b)) {
                return false;
            }
            $aNan = is_float($a) && is_nan($a);
            $bNan = is_float($b) && is_nan($b);
            if ($aNan || $bNan) {
                return $aNan && $bNan;
            }
            if ($a == 0 && $b == 0) {
                return self::isNegativeZero($a) === self::isNegativeZero($b);
            }
            return $a == $b;
        }
        if (is_string($a)) {
            return is_string($b) && $a === $b;
        }
        if (is_bool($a)) {
            return is_bool($b) && $a === $b;
        }
        return $a === $b;
    }

    /** True for -0 only. fdiv() is required: PHP 8's `/` throws on zero divisors. */
    public static function isNegativeZero(int|float $v): bool
    {
        return is_float($v) && $v == 0.0 && fdiv(1.0, $v) < 0;
    }

    /** 11.9.3 Abstract Equality Comparison. */
    public static function looseEquals(Vm $vm, mixed $a, mixed $b): bool
    {
        for (;;) {
            $aNum = is_int($a) || is_float($a);
            $bNum = is_int($b) || is_float($b);
            if ($aNum && $bNum) {
                return $a == $b;
            }
            if (is_string($a) && is_string($b)) {
                return $a === $b;
            }
            if (is_bool($a)) {
                $a = $a ? 1 : 0;
                continue;
            }
            if (is_bool($b)) {
                $b = $b ? 1 : 0;
                continue;
            }
            $aNullish = $a === null || $a instanceof JSUndefined;
            $bNullish = $b === null || $b instanceof JSUndefined;
            if ($aNullish || $bNullish) {
                return $aNullish && $bNullish;
            }
            if ($aNum && is_string($b)) {
                $b = Conversions::stringToNumber($b);
                continue;
            }
            if (is_string($a) && $bNum) {
                $a = Conversions::stringToNumber($a);
                continue;
            }
            if ($a instanceof JSObject && !($b instanceof JSObject)) {
                $a = Conversions::toPrimitive($vm, $a);
                continue;
            }
            if ($b instanceof JSObject && !($a instanceof JSObject)) {
                $b = Conversions::toPrimitive($vm, $b);
                continue;
            }
            return $a === $b; // object identity
        }
    }

    /**
     * 11.8.5 Abstract Relational Comparison: x < y.
     * Returns null when either side is NaN (spec "undefined").
     * Note: string comparison uses byte order over UTF-8, which matches UTF-16
     * code-unit order except when supplementary characters meet U+E000..FFFF
     * (accepted deviation, DESIGN.md §3.1).
     */
    public static function lessThan(Vm $vm, mixed $x, mixed $y, bool $leftFirst): ?bool
    {
        if ($leftFirst) {
            $px = Conversions::toPrimitive($vm, $x, 'number');
            $py = Conversions::toPrimitive($vm, $y, 'number');
        } else {
            $py = Conversions::toPrimitive($vm, $y, 'number');
            $px = Conversions::toPrimitive($vm, $x, 'number');
        }
        if (is_string($px) && is_string($py)) {
            return strcmp($px, $py) < 0;
        }
        $nx = Conversions::toNumber($vm, $px);
        $ny = Conversions::toNumber($vm, $py);
        if ((is_float($nx) && is_nan($nx)) || (is_float($ny) && is_nan($ny))) {
            return null;
        }
        return $nx < $ny;
    }

    /** The `instanceof` operator (11.8.6 / 15.3.5.3). */
    public static function instanceofOp(Vm $vm, mixed $v, mixed $ctor): bool
    {
        if (!$ctor instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Right-hand side of instanceof is not callable');
        }
        if ($ctor instanceof JSBoundFunction) {
            return self::instanceofOp($vm, $v, $ctor->target);
        }
        if (!$v instanceof JSObject) {
            return false;
        }
        $proto = $ctor->get('prototype', $vm);
        if (!$proto instanceof JSObject) {
            $vm->throwError('TypeError', 'Function has non-object prototype in instanceof check');
        }
        for ($o = $v->proto; $o !== null; $o = $o->proto) {
            if ($o === $proto) {
                return true;
            }
        }
        return false;
    }

    /** The `in` operator (11.8.7). */
    public static function inOp(Vm $vm, mixed $key, mixed $obj): bool
    {
        if (!$obj instanceof JSObject) {
            $vm->throwError('TypeError', "Cannot use 'in' operator on non-object");
        }
        return $obj->hasProperty($vm->propertyKey($key));
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * JS operator semantics in callable form.
 *
 * The VM inlines all of this in its dispatch loop, where a method call per
 * operator would be pure overhead. Ahead-of-time compiled PHP (docs/aot-php.md)
 * has no dispatch loop to inline into and calls these instead, so the two must
 * agree exactly — the E2E suites exercise both paths against the same
 * expectations, and test262 covers the VM side.
 *
 * Everything here follows the runtime's number policy (DESIGN.md §3.1): PHP
 * `int` where the value is exactly representable, `float` otherwise. Nothing
 * here may normalise that away.
 */
final class Ops
{
    public static function sub(Vm $vm, mixed $a, mixed $b): int|float
    {
        if (!(is_int($a) || is_float($a))) {
            $a = Conversions::toNumber($vm, $a);
        }
        if (!(is_int($b) || is_float($b))) {
            $b = Conversions::toNumber($vm, $b);
        }
        return $a - $b;
    }

    public static function mul(Vm $vm, mixed $a, mixed $b): int|float
    {
        if (!(is_int($a) || is_float($a))) {
            $a = Conversions::toNumber($vm, $a);
        }
        if (!(is_int($b) || is_float($b))) {
            $b = Conversions::toNumber($vm, $b);
        }
        return $a * $b;
    }

    public static function div(Vm $vm, mixed $a, mixed $b): float
    {
        if (!(is_int($a) || is_float($a))) {
            $a = Conversions::toNumber($vm, $a);
        }
        if (!(is_int($b) || is_float($b))) {
            $b = Conversions::toNumber($vm, $b);
        }
        return fdiv((float)$a, (float)$b);
    }

    public static function mod(Vm $vm, mixed $a, mixed $b): int|float
    {
        if (is_int($a) && is_int($b) && $b !== 0) {
            // PHP % truncates toward zero, matching JS sign rules.
            return $a % $b;
        }
        if (!(is_int($a) || is_float($a))) {
            $a = Conversions::toNumber($vm, $a);
        }
        if (!(is_int($b) || is_float($b))) {
            $b = Conversions::toNumber($vm, $b);
        }
        return fmod((float)$a, (float)$b);
    }

    public static function neg(Vm $vm, mixed $a): int|float
    {
        if (!(is_int($a) || is_float($a))) {
            $a = Conversions::toNumber($vm, $a);
        }
        // Negating int 0 must produce -0, which only float has.
        return $a === 0 ? -0.0 : -$a;
    }

    public static function lt(Vm $vm, mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a < $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) < 0;
        }
        return TypeOps::lessThan($vm, $a, $b, true) ?? false;
    }

    public static function gt(Vm $vm, mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a > $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) > 0;
        }
        return TypeOps::lessThan($vm, $b, $a, false) ?? false;
    }

    public static function le(Vm $vm, mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a <= $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <= 0;
        }
        // `a <= b` is `!(b < a)`, with an unordered (NaN) result meaning false.
        $r = TypeOps::lessThan($vm, $b, $a, false);
        return $r === null ? false : !$r;
    }

    public static function ge(Vm $vm, mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a >= $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) >= 0;
        }
        $r = TypeOps::lessThan($vm, $a, $b, true);
        return $r === null ? false : !$r;
    }

    /** @return int the 32-bit result of a bitwise operator, sign-extended */
    public static function bitwise(Vm $vm, string $op, mixed $a, mixed $b): int
    {
        $x = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($vm, $a);
        $y = is_int($b) ? ((($b & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($vm, $b);
        return match ($op) {
            '&' => $x & $y,
            '|' => $x | $y,
            '^' => $x ^ $y,
            '<<' => (((($x << ($y & 31)) & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000),
            '>>' => $x >> ($y & 31),
            '>>>' => ($x & 0xFFFFFFFF) >> ($y & 31),
            default => throw new \LogicException("Unknown bitwise operator: $op"),
        };
    }

    public static function bnot(Vm $vm, mixed $a): int
    {
        $x = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($vm, $a);
        // ~ of a sign-extended int32 is already a valid int32.
        return ~$x;
    }

    /**
     * The `for-in` key list: own enumerable keys up the prototype chain, each
     * name reported once, snapshotted before iteration begins.
     *
     * @return list<string>
     */
    public static function forInKeys(Vm $vm, mixed $v): array
    {
        if ($v === null || $v instanceof JSUndefined) {
            return [];
        }
        $o = Conversions::toObject($vm, $v);
        $seen = [];
        $keys = [];
        for ($p = $o; $p !== null; $p = $p->proto) {
            foreach ($p->ownEnumerableKeys() as $k) {
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $keys[] = $k;
                }
            }
        }
        return $keys;
    }

    /**
     * Own enumerable keys only.
     *
     * For `for (k in o) if (hasOwnProperty.call(o, k))` this is the same set in
     * the same order as forInKeys() — for-in visits own keys first — but it
     * skips the prototype walk and the de-duplication table that the guard was
     * going to discard anyway. Measured at 185 ns against 648 ns for a plain
     * object, because forInKeys has to enumerate Object.prototype to find that
     * none of it is enumerable.
     *
     * @return list<string>
     */
    public static function ownKeys(Vm $vm, mixed $v): array
    {
        if ($v === null || $v instanceof JSUndefined) {
            return [];
        }
        return ($v instanceof JSObject ? $v : Conversions::toObject($vm, $v))->ownEnumerableKeys();
    }

    /**
     * True when a key from forInKeys() should still be visited: the spec lets
     * a property deleted during iteration be skipped, and the VM skips it.
     */
    public static function forInLive(mixed $v, string $key): bool
    {
        return !$v instanceof JSObject || $v->hasProperty($key);
    }

    /**
     * Property read with the dispatch loop's own fast path in front of it.
     *
     * Generated code goes through here rather than straight to Vm::getMember,
     * because a plain own data property -- overwhelmingly the common case --
     * is then one array probe instead of two method calls. Exotic objects
     * (array indices, string code units, mapped arguments) clear
     * `ownPropsArePlain` and take the general path, same rule as §5.1.
     */
    public static function getProp(Vm $vm, mixed $obj, mixed $key): mixed
    {
        if ($obj instanceof JSObject && $obj->ownPropsArePlain && is_string($key)
            && null !== ($v = $obj->props[$key] ?? null)) {
            return $v;
        }
        return $vm->getMember($obj, $key);
    }

    /**
     * `Object.prototype.hasOwnProperty.call(obj, key)` without the two native
     * invokes it normally costs (through Function.prototype.call, then through
     * hasOwnProperty). Ahead-of-time compiled code emits this only when the
     * build has proved the binding really is that builtin.
     */
    public static function hasOwn(Vm $vm, mixed $obj, mixed $key): bool
    {
        $k = is_string($key) ? $key : Conversions::toString($vm, $key);
        $o = $obj instanceof JSObject ? $obj : Conversions::toObject($vm, $obj);
        return $o->hasOwn($k);
    }

    /**
     * A property write to an object the compiler proved is a fresh plain
     * object: no prototype-chain setter can intercept it and no accessor can
     * shadow it, so it is a store rather than a [[Set]] walk.
     */
    public static function putOwn(Vm $vm, JSObject $obj, mixed $key, mixed $value): void
    {
        $obj->props[is_string($key) ? $key : Conversions::toString($vm, $key)] = $value;
    }

    /** Read the nth argument, where a missing one is `undefined` (never PHP null). */
    public static function arg(array $args, int $i): mixed
    {
        return $i >= 0 && $i < count($args) ? $args[$i] : JSUndefined::$undefined;
    }
}

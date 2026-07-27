<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\TypeOps;
use PhpJs\Vm\Vm;

/**
 * Array builtins. Hot higher-order functions run as PHP loops and re-enter
 * the VM only for the callback (DESIGN.md §5.3).
 */
final class ArrayBuiltins
{
    public static function entries(): array
    {
        return [
            'Array' => [self::class, 'callAsFunction'],
            'Array.ctor' => [self::class, 'ctor'],
            'Array.isArray' => [self::class, 'isArray'],
            'Array.prototype.toString' => [self::class, 'toString'],
            'Array.prototype.join' => [self::class, 'join'],
            'Array.prototype.push' => [self::class, 'push'],
            'Array.prototype.pop' => [self::class, 'pop'],
            'Array.prototype.shift' => [self::class, 'shift'],
            'Array.prototype.unshift' => [self::class, 'unshift'],
            'Array.prototype.slice' => [self::class, 'slice'],
            'Array.prototype.splice' => [self::class, 'splice'],
            'Array.prototype.concat' => [self::class, 'concat'],
            'Array.prototype.reverse' => [self::class, 'reverse'],
            'Array.prototype.indexOf' => [self::class, 'indexOf'],
            'Array.prototype.lastIndexOf' => [self::class, 'lastIndexOf'],
            'Array.prototype.forEach' => [self::class, 'forEach'],
            'Array.prototype.map' => [self::class, 'map'],
            'Array.prototype.filter' => [self::class, 'filter'],
            'Array.prototype.reduce' => [self::class, 'reduce'],
            'Array.prototype.reduceRight' => [self::class, 'reduceRight'],
            'Array.prototype.some' => [self::class, 'some'],
            'Array.prototype.every' => [self::class, 'every'],
            'Array.prototype.sort' => [self::class, 'sort'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach ([
            'toString' => 0, 'join' => 1, 'push' => 1, 'pop' => 0, 'shift' => 0,
            'unshift' => 1, 'slice' => 2, 'splice' => 2, 'concat' => 1,
            'reverse' => 0, 'indexOf' => 1, 'lastIndexOf' => 1, 'forEach' => 1,
            'map' => 1, 'filter' => 1, 'reduce' => 1, 'reduceRight' => 1,
            'some' => 1, 'every' => 1, 'sort' => 1,
        ] as $name => $arity) {
            $r->defineMethod($proto, $name, "Array.prototype.$name", $arity);
        }
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Array', 'Array', 1, 'Array.ctor');
        $r->linkPair($ctor, $r->arrayPrototype());
        $r->defineMethod($ctor, 'isArray', 'Array.isArray', 1);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return self::ctor($vm, $args);
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        if (count($args) === 1 && (is_int($args[0]) || is_float($args[0]))) {
            $len = Conversions::toUint32($vm, $args[0]);
            if ($args[0] != $len) {
                $vm->throwError('RangeError', 'Invalid array length');
            }
            $a = new JSArray($vm->realm->arrayPrototype());
            $a->length = $len;
            return $a;
        }
        return $vm->realm->newArray($args);
    }

    public static function isArray(Vm $vm, mixed $t, array $args): mixed
    {
        return ($args[0] ?? null) instanceof JSArray;
    }

    /** Coerce `this` for generic prototype methods. */
    private static function thisArray(Vm $vm, mixed $t, string $who): JSObject
    {
        if ($t instanceof JSObject) {
            return $t;
        }
        if ($t === null || $t instanceof JSUndefined) {
            $vm->throwError('TypeError', "Array.prototype.$who called on null or undefined");
        }
        return Conversions::toObject($vm, $t);
    }

    private static function lengthOf(Vm $vm, JSObject $o): int
    {
        if ($o instanceof JSArray) {
            return $o->length;
        }
        return Conversions::toUint32($vm, $o->get('length', $vm));
    }

    private static function getIdx(Vm $vm, JSObject $o, int $i, bool &$present): mixed
    {
        if ($o instanceof JSArray) {
            if (array_key_exists($i, $o->elements)) {
                $present = true;
                return $o->elements[$i];
            }
            // Fall through to the prototype chain for holes.
        }
        $key = (string)$i;
        $present = $o->hasProperty($key);
        return $present ? $o->get($key, $vm) : JSUndefined::$undefined;
    }

    public static function toString(Vm $vm, mixed $t, array $args): mixed
    {
        return self::join($vm, $t, []);
    }

    public static function join(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'join');
        $sep = ($args[0] ?? null) === null || ((\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined)) instanceof JSUndefined
            ? ',' : Conversions::toString($vm, $args[0]);
        $len = self::lengthOf($vm, $o);
        $parts = [];
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            $parts[] = ($v === null || $v instanceof JSUndefined) ? '' : Conversions::toString($vm, $v);
        }
        return implode($sep, $parts);
    }

    public static function push(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'push');
        if ($o instanceof JSArray) {
            foreach ($args as $v) {
                $o->elements[$o->length++] = $v;
            }
            return $o->length;
        }
        $len = self::lengthOf($vm, $o);
        foreach ($args as $v) {
            $o->set((string)$len, $v, $vm, false);
            $len++;
        }
        $o->set('length', $len, $vm, false);
        return $len;
    }

    public static function pop(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'pop');
        $len = self::lengthOf($vm, $o);
        if ($len === 0) {
            return JSUndefined::$undefined;
        }
        $present = false;
        $v = self::getIdx($vm, $o, $len - 1, $present);
        if ($o instanceof JSArray) {
            unset($o->elements[$len - 1]);
            $o->length = $len - 1;
        } else {
            $o->deleteKey((string)($len - 1), $vm, false);
            $o->set('length', $len - 1, $vm, false);
        }
        return $v;
    }

    public static function shift(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'shift');
        $len = self::lengthOf($vm, $o);
        if ($len === 0) {
            return JSUndefined::$undefined;
        }
        $present = false;
        $first = self::getIdx($vm, $o, 0, $present);
        if ($o instanceof JSArray) {
            $new = [];
            foreach ($o->elements as $i => $v) {
                if ($i > 0) {
                    $new[$i - 1] = $v;
                }
            }
            $o->elements = $new;
            $o->length = $len - 1;
        } else {
            for ($i = 1; $i < $len; $i++) {
                $present = false;
                $v = self::getIdx($vm, $o, $i, $present);
                if ($present) {
                    $o->set((string)($i - 1), $v, $vm, false);
                } else {
                    $o->deleteKey((string)($i - 1), $vm, false);
                }
            }
            $o->deleteKey((string)($len - 1), $vm, false);
            $o->set('length', $len - 1, $vm, false);
        }
        return $first;
    }

    public static function unshift(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'unshift');
        $len = self::lengthOf($vm, $o);
        $n = count($args);
        if ($o instanceof JSArray) {
            $new = [];
            foreach ($args as $i => $v) {
                $new[$i] = $v;
            }
            foreach ($o->elements as $i => $v) {
                $new[$i + $n] = $v;
            }
            $o->elements = $new;
            $o->length = $len + $n;
            return $o->length;
        }
        for ($i = $len - 1; $i >= 0; $i--) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $o->set((string)($i + $n), $v, $vm, false);
            } else {
                $o->deleteKey((string)($i + $n), $vm, false);
            }
        }
        foreach ($args as $i => $v) {
            $o->set((string)$i, $v, $vm, false);
        }
        $o->set('length', $len + $n, $vm, false);
        return $len + $n;
    }

    /** Normalize a relative index argument per 15.4.4.10. */
    private static function relIndex(Vm $vm, mixed $arg, int $len, int $default): int
    {
        if ($arg === null || $arg instanceof JSUndefined) {
            return $default;
        }
        $i = Conversions::toInteger($vm, $arg);
        if (is_float($i)) {
            $i = $i < 0 ? -PHP_INT_MAX : PHP_INT_MAX;
        }
        return $i < 0 ? max($len + $i, 0) : min($i, $len);
    }

    public static function slice(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'slice');
        $len = self::lengthOf($vm, $o);
        $start = self::relIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $end = self::relIndex($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined), $len, $len);
        $result = new JSArray($vm->realm->arrayPrototype());
        $n = 0;
        for ($i = $start; $i < $end; $i++, $n++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $result->elements[$n] = $v;
            }
        }
        $result->length = max(0, $end - $start);
        return $result;
    }

    public static function splice(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'splice');
        if (!$o instanceof JSArray) {
            $vm->throwError('TypeError', 'Array.prototype.splice on non-array is not supported');
        }
        $len = $o->length;
        $start = self::relIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $deleteCount = count($args) < 2
            ? $len - $start
            : max(0, min((int)Conversions::toInteger($vm, $args[1]), $len - $start));
        $items = array_slice($args, 2);
        $removed = new JSArray($vm->realm->arrayPrototype());
        for ($i = 0; $i < $deleteCount; $i++) {
            if (array_key_exists($start + $i, $o->elements)) {
                $removed->elements[$i] = $o->elements[$start + $i];
            }
        }
        $removed->length = $deleteCount;
        $tail = [];
        foreach ($o->elements as $i => $v) {
            if ($i >= $start + $deleteCount) {
                $tail[$i - $start - $deleteCount] = $v;
            }
        }
        foreach (array_keys($o->elements) as $i) {
            if ($i >= $start) {
                unset($o->elements[$i]);
            }
        }
        foreach ($items as $i => $v) {
            $o->elements[$start + $i] = $v;
        }
        $shift = $start + count($items);
        foreach ($tail as $i => $v) {
            $o->elements[$shift + $i] = $v;
        }
        $o->length = $len - $deleteCount + count($items);
        return $removed;
    }

    public static function concat(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'concat');
        $result = new JSArray($vm->realm->arrayPrototype());
        $n = 0;
        $sources = array_merge([$o], $args);
        foreach ($sources as $src) {
            if ($src instanceof JSArray) {
                for ($i = 0; $i < $src->length; $i++) {
                    if (array_key_exists($i, $src->elements)) {
                        $result->elements[$n] = $src->elements[$i];
                    }
                    $n++;
                }
            } else {
                $result->elements[$n] = $src;
                $n++;
            }
        }
        $result->length = $n;
        return $result;
    }

    public static function reverse(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'reverse');
        if (!$o instanceof JSArray) {
            $vm->throwError('TypeError', 'Array.prototype.reverse on non-array is not supported');
        }
        $len = $o->length;
        $new = [];
        foreach ($o->elements as $i => $v) {
            $new[$len - 1 - $i] = $v;
        }
        ksort($new);
        $o->elements = $new;
        return $o;
    }

    public static function indexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'indexOf');
        $len = self::lengthOf($vm, $o);
        $needle = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $from = count($args) > 1 ? (int)Conversions::toInteger($vm, $args[1]) : 0;
        if ($from < 0) {
            $from = max(0, $len + $from);
        }
        for ($i = $from; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && TypeOps::strictEquals($v, $needle)) {
                return $i;
            }
        }
        return -1;
    }

    public static function lastIndexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'lastIndexOf');
        $len = self::lengthOf($vm, $o);
        $needle = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $from = count($args) > 1 ? (int)Conversions::toInteger($vm, $args[1]) : $len - 1;
        if ($from < 0) {
            $from = $len + $from;
        } else {
            $from = min($from, $len - 1);
        }
        for ($i = $from; $i >= 0; $i--) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && TypeOps::strictEquals($v, $needle)) {
                return $i;
            }
        }
        return -1;
    }

    private static function callbackOf(Vm $vm, array $args, string $who): JSFunctionBase
    {
        $fn = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$fn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', "$who: callback is not a function");
        }
        return $fn;
    }

    public static function forEach(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'forEach');
        $fn = self::callbackOf($vm, $args, 'forEach');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $len = self::lengthOf($vm, $o);
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $vm->invoke($fn, $thisArg, [$v, $i, $o]);
            }
        }
        return JSUndefined::$undefined;
    }

    public static function map(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'map');
        $fn = self::callbackOf($vm, $args, 'map');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $len = self::lengthOf($vm, $o);
        $result = new JSArray($vm->realm->arrayPrototype());
        $result->length = $len;
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $result->elements[$i] = $vm->invoke($fn, $thisArg, [$v, $i, $o]);
            }
        }
        return $result;
    }

    public static function filter(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'filter');
        $fn = self::callbackOf($vm, $args, 'filter');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $len = self::lengthOf($vm, $o);
        $result = new JSArray($vm->realm->arrayPrototype());
        $n = 0;
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && Conversions::toBoolean($vm->invoke($fn, $thisArg, [$v, $i, $o]))) {
                $result->elements[$n++] = $v;
            }
        }
        $result->length = $n;
        return $result;
    }

    public static function reduce(Vm $vm, mixed $t, array $args): mixed
    {
        return self::reduceImpl($vm, $t, $args, false);
    }

    public static function reduceRight(Vm $vm, mixed $t, array $args): mixed
    {
        return self::reduceImpl($vm, $t, $args, true);
    }

    private static function reduceImpl(Vm $vm, mixed $t, array $args, bool $fromRight): mixed
    {
        $o = self::thisArray($vm, $t, $fromRight ? 'reduceRight' : 'reduce');
        $fn = self::callbackOf($vm, $args, 'reduce');
        $len = self::lengthOf($vm, $o);
        $indices = $fromRight ? range($len - 1, 0, -1) : ($len > 0 ? range(0, $len - 1) : []);
        $acc = null;
        $hasAcc = count($args) > 1;
        if ($hasAcc) {
            $acc = $args[1];
        }
        foreach ($indices as $i) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if (!$present) {
                continue;
            }
            if (!$hasAcc) {
                $acc = $v;
                $hasAcc = true;
                continue;
            }
            $acc = $vm->invoke($fn, JSUndefined::$undefined, [$acc, $v, $i, $o]);
        }
        if (!$hasAcc) {
            $vm->throwError('TypeError', 'Reduce of empty array with no initial value');
        }
        return $acc;
    }

    public static function some(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'some');
        $fn = self::callbackOf($vm, $args, 'some');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $len = self::lengthOf($vm, $o);
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && Conversions::toBoolean($vm->invoke($fn, $thisArg, [$v, $i, $o]))) {
                return true;
            }
        }
        return false;
    }

    public static function every(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'every');
        $fn = self::callbackOf($vm, $args, 'every');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $len = self::lengthOf($vm, $o);
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && !Conversions::toBoolean($vm->invoke($fn, $thisArg, [$v, $i, $o]))) {
                return false;
            }
        }
        return true;
    }

    public static function sort(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'sort');
        if (!$o instanceof JSArray) {
            $vm->throwError('TypeError', 'Array.prototype.sort on non-array is not supported');
        }
        $comparator = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $values = [];
        $undefs = 0;
        foreach ($o->elements as $v) {
            if ($v instanceof JSUndefined) {
                $undefs++;
            } else {
                $values[] = $v;
            }
        }
        $holes = $o->length - count($values) - $undefs;
        if ($comparator instanceof JSFunctionBase) {
            usort($values, function ($a, $b) use ($vm, $comparator) {
                $r = Conversions::toNumber($vm, $vm->invoke($comparator, JSUndefined::$undefined, [$a, $b]));
                if (is_float($r) && is_nan($r)) {
                    return 0;
                }
                return $r < 0 ? -1 : ($r > 0 ? 1 : 0);
            });
        } else {
            usort($values, function ($a, $b) use ($vm) {
                return strcmp(Conversions::toString($vm, $a), Conversions::toString($vm, $b));
            });
        }
        $elements = [];
        foreach ($values as $i => $v) {
            $elements[$i] = $v;
        }
        for ($i = 0; $i < $undefs; $i++) {
            $elements[count($values) + $i] = JSUndefined::$undefined;
        }
        $o->elements = $elements;
        return $o;
    }
}

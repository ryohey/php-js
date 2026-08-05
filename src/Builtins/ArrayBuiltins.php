<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunction;
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
            'Array.from' => [self::class, 'from'],
            'Array.prototype.toString' => [self::class, 'toString'],
            'Array.prototype.toLocaleString' => [self::class, 'toLocaleString'],
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
            'toString' => 0, 'toLocaleString' => 0, 'join' => 1, 'push' => 1, 'pop' => 0, 'shift' => 0,
            'unshift' => 1, 'slice' => 2, 'splice' => 2, 'concat' => 1,
            'reverse' => 0, 'indexOf' => 1, 'lastIndexOf' => 1, 'forEach' => 1,
            'map' => 1, 'filter' => 1, 'reduce' => 1, 'reduceRight' => 1,
            'some' => 1, 'every' => 1, 'sort' => 1,
        ] as $name => $arity) {
            $r->defineMethod($proto, $name, "Array.prototype.$name", $arity);
        }
        IteratorBuiltins::populateArrayProto($r, $proto);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Array', 'Array', 1, 'Array.ctor');
        $r->linkPair($ctor, $r->arrayPrototype());
        $r->defineMethod($ctor, 'isArray', 'Array.isArray', 1);
        $r->defineMethod($ctor, 'from', 'Array.from', 1);
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

    /** True when $v is usable as `new $v(...)` -- an arrow/generator/async function is not. */
    private static function isConstructorValue(mixed $v): bool
    {
        return ($v instanceof JSNativeFunction && $v->ctorId !== null)
            || ($v instanceof JSFunction && !$v->isArrow && !$v->isGenerator && !$v->isAsync);
    }

    /**
     * 23.1.2.1 Array.from: build a new array from an iterable or an
     * array-like, optionally passing each element through mapFn. `this` is
     * honored as a constructor (Array.from.call(SomeCtor, ...)) the same way
     * the spec's `C` does, falling back to a plain array otherwise.
     */
    public static function from(Vm $vm, mixed $t, array $args): mixed
    {
        $items = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $mapFn = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        $mapping = !($mapFn instanceof JSUndefined);
        if ($mapping && !$mapFn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Array.from: mapFn is not a function');
        }
        $thisArg = \array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined;
        $useCtor = self::isConstructorValue($t);

        $iterKey = $vm->realm->wellKnownSymbol('iterator')->propertyKey;
        $usingIterator = ($items === null || $items instanceof JSUndefined)
            ? JSUndefined::$undefined
            : $vm->getMember($items, $iterKey);

        if (!$usingIterator instanceof JSUndefined && $usingIterator !== null) {
            $a = $useCtor ? $vm->construct($t, []) : $vm->realm->newArray();
            if (!$a instanceof JSObject) {
                $vm->throwError('TypeError', 'Array.from: constructor did not return an object');
            }
            [$iter, $next] = $vm->getIterator($items);
            $k = 0;
            while (true) {
                $result = $vm->invoke($next, $iter, []);
                if (!$result instanceof JSObject) {
                    $vm->throwError('TypeError', 'Iterator result is not an object');
                }
                if (Conversions::toBoolean($result->get('done', $vm))) {
                    $a->set('length', $k, $vm, true);
                    return $a;
                }
                $value = $result->get('value', $vm);
                if ($mapping) {
                    $value = $vm->invoke($mapFn, $thisArg, [$value, $k]);
                }
                $a->set((string)$k, $value, $vm, true);
                $k++;
            }
        }

        $arrayLike = Conversions::toObject($vm, $items);
        $len = self::lengthOf($vm, $arrayLike);
        $a = $useCtor ? $vm->construct($t, [$len]) : self::speciesCreate($vm, $arrayLike, $len);
        if (!$a instanceof JSObject) {
            $vm->throwError('TypeError', 'Array.from: constructor did not return an object');
        }
        for ($k = 0; $k < $len; $k++) {
            $value = $arrayLike->get((string)$k, $vm);
            if ($mapping) {
                $value = $vm->invoke($mapFn, $thisArg, [$value, $k]);
            }
            $a->set((string)$k, $value, $vm, true);
        }
        $a->set('length', $len, $vm, true);
        return $a;
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
        // Generic array-likes use ToLength, not ToUint32: {length: 2**32} must
        // keep its length rather than wrapping to 0.
        return Conversions::toLength($vm, $o->get('length', $vm));
    }

    /**
     * Ascending indices a hole-skipping traversal must visit.
     *
     * Returns null to mean "scan 0..len-1 densely", which is both the spec's
     * algorithm and the fast path. A length up to 2^53-1 is legal, so a sparse
     * receiver is instead walked by the index keys it actually has — holes are
     * skipped either way, and with no Proxy in an ES5 realm the absent indices
     * are unobservable.
     *
     * @return list<int>|null
     */
    private static function visitList(JSObject $o, int $len): ?array
    {
        if ($len <= self::DENSE_SCAN_LIMIT) {
            return null;
        }
        if ($o instanceof JSArray) {
            if (count($o->elements) * 8 >= $len) {
                return null; // mostly dense: the plain scan is cheaper
            }
            $keys = [];
            foreach (array_keys($o->elements) as $i) {
                if ($i < $len) {
                    $keys[] = $i;
                }
            }
            sort($keys);
            return $keys;
        }
        $seen = [];
        for ($p = $o; $p !== null; $p = $p->proto) {
            foreach ($p->ownKeys() as $k) {
                $idx = JSArray::asIndex($k);
                if ($idx !== null && $idx < $len) {
                    $seen[$idx] = true;
                }
            }
        }
        $keys = array_keys($seen);
        sort($keys);
        return $keys;
    }

    private const DENSE_SCAN_LIMIT = 65536;

    /**
     * ArraySpeciesCreate, minus @@species (an ES5 realm has no Symbols): the
     * result is always a plain Array, but the constructor lookup and the
     * uint32 length limit are still observable.
     */
    private static function speciesCreate(Vm $vm, JSObject $o, int $length): JSArray
    {
        if ($o instanceof JSArray) {
            $ctor = $o->get('constructor', $vm);
            if (!$ctor instanceof JSUndefined && !$ctor instanceof JSObject) {
                $vm->throwError('TypeError', 'Array constructor is not an object');
            }
        }
        if ($length > 4294967295) {
            $vm->throwError('RangeError', 'Invalid array length');
        }
        $result = new JSArray($vm->realm->arrayPrototype());
        $result->length = $length;
        return $result;
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
        $o = Conversions::toObject($vm, $t);
        $join = $o->get('join', $vm);
        if ($join instanceof JSFunctionBase) {
            return $vm->invoke($join, $o, []);
        }
        return ObjectBuiltins::protoToString($vm, $o, []);
    }

    /** 15.4.4.3: join with "," after calling each element's toLocaleString. */
    public static function toLocaleString(Vm $vm, mixed $t, array $args): mixed
    {
        $o = Conversions::toObject($vm, $t);
        $len = self::lengthOf($vm, $o);
        $parts = [];
        for ($i = 0; $i < $len; $i++) {
            $v = $o->get((string)$i, $vm);
            if ($v === null || $v instanceof JSUndefined) {
                $parts[] = '';
                continue;
            }
            $fn = Conversions::toObject($vm, $v)->get('toLocaleString', $vm);
            if (!$fn instanceof JSFunctionBase) {
                $vm->throwError('TypeError', 'toLocaleString is not callable');
            }
            $parts[] = Conversions::toString($vm, $vm->invoke($fn, $v, []));
        }
        return implode(',', $parts);
    }

    public static function join(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'join');
        // Only a genuinely absent or undefined separator defaults to ',' --
        // an explicit `null` goes through ToString(null) = "null" like any
        // other value, per 23.1.3.16. The old check folded null into the
        // same case as undefined, which is wrong.
        $sep = (!\array_key_exists(0, $args) || $args[0] instanceof JSUndefined)
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
        $len = self::lengthOf($vm, $o);
        foreach ($args as $v) {
            if ($len >= Conversions::MAX_EXACT_INT - 1) {
                $vm->throwError('TypeError', 'Cannot push past the maximum array-like length');
            }
            // Set(..., true) throughout, so a frozen receiver or a
            // non-writable length surfaces as a TypeError even from sloppy code.
            $o->set((string)$len, $v, $vm, true);
            $len++;
        }
        $o->set('length', $len, $vm, true);
        return $len;
    }

    public static function pop(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'pop');
        $len = self::lengthOf($vm, $o);
        if ($len === 0) {
            // Even the empty case must normalize length (a garbage length
            // like NaN or -1 becomes 0).
            $o->set('length', 0, $vm, true);
            return JSUndefined::$undefined;
        }
        $idx = $len - 1;
        $present = false;
        $v = self::getIdx($vm, $o, $idx, $present);
        $o->deleteKey((string)$idx, $vm, true);
        $o->set('length', $idx, $vm, true);
        return $v;
    }

    /** True if any index below $len is inherited, which blocks the fast paths. */
    private static function protoHasIndex(JSObject $o, int $len): bool
    {
        for ($p = $o->proto; $p !== null; $p = $p->proto) {
            if ($p instanceof JSArray && $p->elements !== []) {
                return true;
            }
            foreach ($p->ownKeys() as $k) {
                $idx = JSArray::asIndex($k);
                if ($idx !== null && $idx < $len) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function shift(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'shift');
        $len = self::lengthOf($vm, $o);
        if ($len === 0) {
            $o->set('length', 0, $vm, true);
            return JSUndefined::$undefined;
        }
        if ($o instanceof JSArray && $o->lengthWritable && $o->descs === null
            && !self::protoHasIndex($o, $len)) {
            $first = $o->elements[0] ?? JSUndefined::$undefined;
            $shifted = [];
            foreach ($o->elements as $i => $v) {
                if ($i > 0) {
                    $shifted[$i - 1] = $v;
                }
            }
            $o->elements = $shifted;
            $o->length = $len - 1;
            return $first;
        }
        $present = false;
        $first = self::getIdx($vm, $o, 0, $present);
        for ($i = 1; $i < $len; $i++) {
            self::moveIdx($vm, $o, $i, $i - 1, true);
        }
        $o->deleteKey((string)($len - 1), $vm, true);
        $o->set('length', $len - 1, $vm, true);
        return $first;
    }

    public static function unshift(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'unshift');
        $len = self::lengthOf($vm, $o);
        $n = count($args);
        if ($o instanceof JSArray && $o->lengthWritable && $o->extensible && $o->descs === null) {
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
        if ($len + $n > Conversions::MAX_EXACT_INT - 1) {
            $vm->throwError('TypeError', 'Cannot unshift past the maximum array-like length');
        }
        if ($n === 0) {
            // Still performs Set(O, "length", len, true).
            $o->set('length', $len, $vm, true);
            return $len;
        }
        for ($i = $len - 1; $i >= 0; $i--) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $o->set((string)($i + $n), $v, $vm, true);
            } else {
                $o->deleteKey((string)($i + $n), $vm, true);
            }
        }
        foreach ($args as $i => $v) {
            $o->set((string)$i, $v, $vm, true);
        }
        $o->set('length', $len + $n, $vm, true);
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
        $result = self::speciesCreate($vm, $o, max(0, $end - $start));
        $visit = self::visitList($o, $len);
        $count = $visit === null ? max(0, $end - $start) : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $start + $j : $visit[$j];
            if ($i < $start || $i >= $end) {
                continue;
            }
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present) {
                $result->elements[$i - $start] = $v;
            }
        }
        $result->length = max(0, $end - $start);
        return $result;
    }

    public static function splice(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'splice');
        if (!$o instanceof JSArray) {
            return self::spliceGeneric($vm, $o, $args);
        }
        $len = $o->length;
        $start = self::relIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $deleteCount = count($args) < 2
            ? $len - $start
            : max(0, min((int)Conversions::toInteger($vm, $args[1]), $len - $start));
        $items = array_slice($args, 2);
        $removed = self::speciesCreate($vm, $o, 0);
        // Driven by the elements present, not by deleteCount: an array length
        // is a uint32, so scanning the requested range can mean billions of
        // iterations over a handful of real elements.
        $end = $start + $deleteCount;
        foreach ($o->elements as $i => $v) {
            if ($i >= $start && $i < $end) {
                $removed->elements[$i - $start] = $v;
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
        $result = self::speciesCreate($vm, $o, 0);
        $n = 0;
        $sources = array_merge([$o], $args);
        foreach ($sources as $src) {
            if ($src instanceof JSArray) {
                // Iterate the elements, not 0..length: a length is a uint32
                // and the array may hold only a handful of them.
                foreach ($src->elements as $i => $v) {
                    if ($i < $src->length) {
                        $result->elements[$n + $i] = $v;
                    }
                }
                $n += $src->length;
            } else {
                $result->elements[$n] = $src;
                $n++;
            }
        }
        $result->length = $n;
        return $result;
    }

    /**
     * 15.4.4.12 on a generic array-like. Works from the set of indices the
     * receiver actually has rather than scanning 0..len: a length may be up to
     * 2^53-1, so the spec's element-by-element shift is not runnable as written.
     */
    private static function spliceGeneric(Vm $vm, JSObject $o, array $args): JSArray
    {
        $len = self::lengthOf($vm, $o);
        $start = self::relIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $deleteCount = count($args) < 2
            ? $len - $start
            : max(0, min((int)Conversions::toInteger($vm, $args[1]), $len - $start));
        $items = array_slice($args, 2);
        $itemCount = count($items);

        $removed = self::speciesCreate($vm, $o, 0);
        $removed->length = $deleteCount;
        $shift = $itemCount - $deleteCount;
        $survivors = [];
        $touched = [];
        $visit = self::visitList($o, $len);
        $indices = $visit ?? ($len > 0 ? range(0, $len - 1) : []);
        foreach ($indices as $i) {
            if ($i < $start) {
                continue;
            }
            $touched[$i] = true;
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if (!$present) {
                continue;
            }
            if ($i < $start + $deleteCount) {
                $removed->elements[$i - $start] = $v;
            } else {
                $survivors[$i + $shift] = $v;
            }
        }
        foreach (array_keys($touched) as $i) {
            $o->deleteKey((string)$i, $vm, false);
        }
        foreach ($items as $i => $v) {
            $o->set((string)($start + $i), $v, $vm, true);
        }
        foreach ($survivors as $i => $v) {
            $o->set((string)$i, $v, $vm, true);
        }
        $o->set('length', $len + $shift, $vm, true);
        return $removed;
    }

    /** Copy index $from to $to, deleting $to when $from is a hole. */
    private static function moveIdx(Vm $vm, JSObject $o, int $from, int $to, bool $strict = false): void
    {
        $present = false;
        $v = self::getIdx($vm, $o, $from, $present);
        if ($present) {
            $o->set((string)$to, $v, $vm, $strict);
        } else {
            $o->deleteKey((string)$to, $vm, $strict);
        }
    }

    public static function reverse(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'reverse');
        if ($o instanceof JSArray) {
            $len = $o->length;
            $new = [];
            foreach ($o->elements as $i => $v) {
                $new[$len - 1 - $i] = $v;
            }
            ksort($new);
            $o->elements = $new;
            return $o;
        }
        $len = self::lengthOf($vm, $o);
        $middle = intdiv($len, 2);
        for ($lower = 0; $lower < $middle; $lower++) {
            $upper = $len - $lower - 1;
            $lowerExists = false;
            $lowerValue = self::getIdx($vm, $o, $lower, $lowerExists);
            $upperExists = false;
            $upperValue = self::getIdx($vm, $o, $upper, $upperExists);
            if ($lowerExists && $upperExists) {
                $o->set((string)$lower, $upperValue, $vm, false);
                $o->set((string)$upper, $lowerValue, $vm, false);
            } elseif ($upperExists) {
                $o->set((string)$lower, $upperValue, $vm, false);
                $o->deleteKey((string)$upper, $vm, false);
            } elseif ($lowerExists) {
                $o->deleteKey((string)$lower, $vm, false);
                $o->set((string)$upper, $lowerValue, $vm, false);
            }
        }
        return $o;
    }

    public static function indexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'indexOf');
        $len = self::lengthOf($vm, $o);
        $needle = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($len === 0) {
            return -1; // the length check precedes ToInteger(fromIndex)
        }
        $from = count($args) > 1 ? (int)Conversions::toInteger($vm, $args[1]) : 0;
        if ($from < 0) {
            $from = max(0, $len + $from);
        }
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
            if ($i < $from) {
                continue;
            }
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
        if ($len === 0) {
            return -1; // the length check precedes ToInteger(fromIndex)
        }
        $from = count($args) > 1 ? (int)Conversions::toInteger($vm, $args[1]) : $len - 1;
        if ($from < 0) {
            $from = $len + $from;
        } else {
            $from = min($from, $len - 1);
        }
        $visit = self::visitList($o, $len);
        if ($visit === null) {
            for ($i = $from; $i >= 0; $i--) {
                $present = false;
                $v = self::getIdx($vm, $o, $i, $present);
                if ($present && TypeOps::strictEquals($v, $needle)) {
                    return $i;
                }
            }
            return -1;
        }
        for ($j = count($visit) - 1; $j >= 0; $j--) {
            $i = $visit[$j];
            if ($i > $from) {
                continue;
            }
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
        // LengthOfArrayLike precedes IsCallable(callbackfn), so a length
        // getter runs even when the callback turns out to be invalid.
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'forEach');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
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
        // LengthOfArrayLike precedes IsCallable(callbackfn), so a length
        // getter runs even when the callback turns out to be invalid.
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'map');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $result = self::speciesCreate($vm, $o, $len);
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
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
        // LengthOfArrayLike precedes IsCallable(callbackfn), so a length
        // getter runs even when the callback turns out to be invalid.
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'filter');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $result = self::speciesCreate($vm, $o, 0);
        $n = 0;
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
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
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'reduce');
        $visit = self::visitList($o, $len);
        if ($visit === null) {
            $visit = $len > 0 ? range(0, $len - 1) : [];
        }
        $indices = $fromRight ? array_reverse($visit) : $visit;
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
        // LengthOfArrayLike precedes IsCallable(callbackfn), so a length
        // getter runs even when the callback turns out to be invalid.
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'some');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
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
        // LengthOfArrayLike precedes IsCallable(callbackfn), so a length
        // getter runs even when the callback turns out to be invalid.
        $len = self::lengthOf($vm, $o);
        $fn = self::callbackOf($vm, $args, 'every');
        $thisArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $visit = self::visitList($o, $len);
        $count = $visit === null ? $len : count($visit);
        for ($j = 0; $j < $count; $j++) {
            $i = $visit === null ? $j : $visit[$j];
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if ($present && !Conversions::toBoolean($vm->invoke($fn, $thisArg, [$v, $i, $o]))) {
                return false;
            }
        }
        return true;
    }

    /**
     * 15.4.4.11. Sort order puts defined values first, then undefined, then
     * holes — so values, undefined count and hole count are tracked separately.
     */
    public static function sort(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::thisArray($vm, $t, 'sort');
        $comparator = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$comparator instanceof JSUndefined && !$comparator instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'The comparison function must be either a function or undefined');
        }
        $len = self::lengthOf($vm, $o);
        $values = [];
        $undefs = 0;
        for ($i = 0; $i < $len; $i++) {
            $present = false;
            $v = self::getIdx($vm, $o, $i, $present);
            if (!$present) {
                continue;
            }
            if ($v instanceof JSUndefined) {
                $undefs++;
            } else {
                $values[] = $v;
            }
        }
        if ($comparator instanceof JSFunctionBase) {
            usort($values, function ($a, $b) use ($vm, $comparator) {
                $r = Conversions::toNumber($vm, $vm->invoke($comparator, JSUndefined::$undefined, [$a, $b]));
                if (is_float($r) && is_nan($r)) {
                    return 0;
                }
                return $r < 0 ? -1 : ($r > 0 ? 1 : 0);
            });
        } else {
            usort($values, fn ($a, $b) => strcmp(Conversions::toString($vm, $a), Conversions::toString($vm, $b)));
        }

        $defined = count($values);
        if ($o instanceof JSArray) {
            $elements = [];
            foreach ($values as $i => $v) {
                $elements[$i] = $v;
            }
            for ($i = 0; $i < $undefs; $i++) {
                $elements[$defined + $i] = JSUndefined::$undefined;
            }
            $o->elements = $elements;
            return $o;
        }
        foreach ($values as $i => $v) {
            $o->set((string)$i, $v, $vm, false);
        }
        for ($i = 0; $i < $undefs; $i++) {
            $o->set((string)($defined + $i), JSUndefined::$undefined, $vm, false);
        }
        for ($i = $defined + $undefs; $i < $len; $i++) {
            $o->deleteKey((string)$i, $vm, false);
        }
        return $o;
    }
}

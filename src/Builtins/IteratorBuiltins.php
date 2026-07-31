<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSArrayIterator;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSStringIterator;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Vm\Vm;

/**
 * The iteration protocol: %IteratorPrototype%, the array and string iterators,
 * and the `@@iterator` methods that hand them out.
 *
 * This is what `for…of`, spread and array destructuring are all defined over,
 * which is why they arrive together rather than as three index loops that
 * would each be wrong for a Set, a Map or a generator.
 */
final class IteratorBuiltins
{
    public static function entries(): array
    {
        return [
            '%IteratorPrototype%[@@iterator]' => [self::class, 'returnThis'],
            '%ArrayIteratorPrototype%.next' => [self::class, 'arrayIteratorNext'],
            '%StringIteratorPrototype%.next' => [self::class, 'stringIteratorNext'],
            'Array.prototype.values' => [self::class, 'arrayValues'],
            'Array.prototype.keys' => [self::class, 'arrayKeys'],
            'Array.prototype.entries' => [self::class, 'arrayEntries'],
            'String.prototype[@@iterator]' => [self::class, 'stringIterator'],
        ];
    }

    /**
     * %IteratorPrototype%[@@iterator] returns the iterator itself, which is
     * what makes an iterator iterable -- `for (const x of arr.values())`.
     */
    public static function returnThis(Vm $vm, mixed $thisVal): mixed
    {
        return $thisVal;
    }

    public static function populateIteratorProto(Realm $r, JSObject $proto): void
    {
        $key = $r->wellKnownSymbol('iterator')->propertyKey;
        $proto->defineOwnData(
            $key,
            $r->nativeFn('%IteratorPrototype%[@@iterator]', '[Symbol.iterator]', 0),
            JSObject::W | JSObject::C
        );
    }

    public static function populateArrayIteratorProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'next', '%ArrayIteratorPrototype%.next', 0);
        $proto->defineOwnData($r->wellKnownSymbol('toStringTag')->propertyKey, 'Array Iterator', JSObject::C);
    }

    public static function populateStringIteratorProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'next', '%StringIteratorPrototype%.next', 0);
        $proto->defineOwnData($r->wellKnownSymbol('toStringTag')->propertyKey, 'String Iterator', JSObject::C);
    }

    /**
     * `values`, `keys`, `entries` and `@@iterator` on Array.prototype.
     *
     * `@@iterator` is the *same function object* as `values` (23.1.3.36), which
     * is observable: `[][Symbol.iterator] === [].values`.
     */
    public static function populateArrayProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'keys', 'Array.prototype.keys', 0);
        $r->defineMethod($proto, 'entries', 'Array.prototype.entries', 0);
        $values = $r->nativeFn('Array.prototype.values', 'values', 0);
        $proto->defineOwnData('values', $values, JSObject::W | JSObject::C);
        $proto->defineOwnData($r->wellKnownSymbol('iterator')->propertyKey, $values, JSObject::W | JSObject::C);
    }

    public static function populateStringProto(Realm $r, JSObject $proto): void
    {
        $proto->defineOwnData(
            $r->wellKnownSymbol('iterator')->propertyKey,
            $r->nativeFn('String.prototype[@@iterator]', '[Symbol.iterator]', 0),
            JSObject::W | JSObject::C
        );
    }

    public static function arrayValues(Vm $vm, mixed $thisVal): mixed
    {
        return self::makeArrayIterator($vm, $thisVal, JSArrayIterator::VALUES);
    }

    public static function arrayKeys(Vm $vm, mixed $thisVal): mixed
    {
        return self::makeArrayIterator($vm, $thisVal, JSArrayIterator::KEYS);
    }

    public static function arrayEntries(Vm $vm, mixed $thisVal): mixed
    {
        return self::makeArrayIterator($vm, $thisVal, JSArrayIterator::ENTRIES);
    }

    private static function makeArrayIterator(Vm $vm, mixed $thisVal, string $kind): JSArrayIterator
    {
        // Generic over array-likes, like the rest of Array.prototype: the
        // receiver is coerced, not required to be an Array.
        $obj = Conversions::toObject($vm, $thisVal);
        return new JSArrayIterator($vm->realm->arrayIteratorPrototype(), $obj, $kind);
    }

    public static function arrayIteratorNext(Vm $vm, mixed $thisVal): mixed
    {
        if (!$thisVal instanceof JSArrayIterator) {
            $vm->throwError('TypeError', 'next called on an object that is not an Array Iterator');
        }
        $target = $thisVal->target;
        if ($target === null) {
            return self::result($vm, JSUndefined::$undefined, true);
        }
        $len = $target instanceof JSArray
            ? $target->length
            : (int)Conversions::toLength($vm, $target->get('length', $vm));
        $i = $thisVal->index;
        if ($i >= $len) {
            // Detached so a later `next` stays done without re-reading length,
            // which is what makes an exhausted iterator stay exhausted.
            $thisVal->target = null;
            return self::result($vm, JSUndefined::$undefined, true);
        }
        $thisVal->index = $i + 1;
        if ($thisVal->kind === JSArrayIterator::KEYS) {
            return self::result($vm, $i, false);
        }
        $value = $target->get((string)$i, $vm);
        if ($thisVal->kind === JSArrayIterator::VALUES) {
            return self::result($vm, $value, false);
        }
        $pair = new JSArray($vm->realm->arrayPrototype());
        $pair->elements = [$i, $value];
        $pair->length = 2;
        return self::result($vm, $pair, false);
    }

    public static function stringIteratorNext(Vm $vm, mixed $thisVal): mixed
    {
        if (!$thisVal instanceof JSStringIterator) {
            $vm->throwError('TypeError', 'next called on an object that is not a String Iterator');
        }
        $s = $thisVal->target;
        if ($s === null) {
            return self::result($vm, JSUndefined::$undefined, true);
        }
        $len = StringOps::length16($s);
        $i = $thisVal->index;
        if ($i >= $len) {
            $thisVal->target = null;
            return self::result($vm, JSUndefined::$undefined, true);
        }
        // One code point per step: a surrogate pair is a single iteration,
        // which is the whole difference from indexing by code unit.
        $unit = StringOps::charCodeAt($s, $i);
        $size = 1;
        if ($unit !== null && $unit >= 0xD800 && $unit <= 0xDBFF && $i + 1 < $len) {
            $trail = StringOps::charCodeAt($s, $i + 1);
            if ($trail !== null && $trail >= 0xDC00 && $trail <= 0xDFFF) {
                $size = 2;
            }
        }
        $thisVal->index = $i + $size;
        return self::result($vm, StringOps::slice16($s, $i, $i + $size), false);
    }

    public static function stringIterator(Vm $vm, mixed $thisVal): mixed
    {
        if ($thisVal === null || $thisVal instanceof JSUndefined) {
            $vm->throwError('TypeError', 'String.prototype[Symbol.iterator] called on null or undefined');
        }
        return new JSStringIterator(
            $vm->realm->stringIteratorPrototype(),
            Conversions::toString($vm, $thisVal)
        );
    }

    /** CreateIterResultObject: a fresh `{value, done}`, always a plain object. */
    public static function result(Vm $vm, mixed $value, bool $done): JSObject
    {
        $o = $vm->realm->newObject();
        $o->defineOwnData('value', $value);
        $o->defineOwnData('done', $done);
        return $o;
    }
}

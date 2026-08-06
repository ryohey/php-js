<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSCollection;
use PhpJs\Runtime\JSCollectionIterator;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Map / Set / WeakMap / WeakSet (ES2015 23.1–23.4).
 *
 * Native rather than written in the JS library file for a measured reason:
 * React 19 uses Map inside the renderer, and a JS implementation's key
 * derivation plus its accessors were about 6% of a render.
 *
 * Weak variants are aliases of the strong ones. Nothing here observes
 * collection, so the only difference would be whether keys are kept alive —
 * and in a request-scoped realm (§11) the realm is discarded wholesale anyway.
 */
final class CollectionBuiltins
{
    /** @return array<string, callable> */
    public static function entries(): array
    {
        return [
            'Map.ctor' => [self::class, 'mapCtor'],
            'Map.call' => [self::class, 'requiresNew'],
            'Map.prototype.get' => [self::class, 'get'],
            'Map.prototype.set' => [self::class, 'set'],
            'Map.prototype.has' => [self::class, 'has'],
            'Map.prototype.delete' => [self::class, 'delete'],
            'Map.prototype.clear' => [self::class, 'clear'],
            'Map.prototype.forEach' => [self::class, 'mapForEach'],
            'Map.prototype.size' => [self::class, 'size'],
            'Set.ctor' => [self::class, 'setCtor'],
            'Set.call' => [self::class, 'requiresNew'],
            'Set.prototype.add' => [self::class, 'add'],
            'Set.prototype.has' => [self::class, 'has'],
            'Set.prototype.delete' => [self::class, 'delete'],
            'Set.prototype.clear' => [self::class, 'clear'],
            'Set.prototype.forEach' => [self::class, 'setForEach'],
            'Set.prototype.size' => [self::class, 'size'],
            'Collection.iterator.next' => [self::class, 'iteratorNext'],
            'Collection.iterator.self' => [self::class, 'iteratorSelf'],
            'Map.prototype.keys' => [self::class, 'mapKeys'],
            'Map.prototype.values' => [self::class, 'mapValues'],
            'Map.prototype.entries' => [self::class, 'mapEntries'],
            'Set.prototype.keys' => [self::class, 'setValues'],
            'Set.prototype.values' => [self::class, 'setValues'],
            'Set.prototype.entries' => [self::class, 'setEntries'],
        ];
    }

    /**
     * Both constructors are built through `Realm`'s memoized accessors
     * (`mapConstructor()`/`setConstructor()`) and materialized on first
     * property miss like every other global, so a request that never touches
     * `Map` builds no Map objects (DESIGN.md §11.2).
     */
    public static function makeMapConstructor(Realm $realm): JSNativeFunction
    {
        $proto = new JSObject($realm->objectPrototype());
        $realm->defineMethod($proto, 'get', 'Map.prototype.get', 1);
        $realm->defineMethod($proto, 'set', 'Map.prototype.set', 2);
        $realm->defineMethod($proto, 'has', 'Map.prototype.has', 1);
        $realm->defineMethod($proto, 'delete', 'Map.prototype.delete', 1);
        $realm->defineMethod($proto, 'clear', 'Map.prototype.clear', 0);
        $realm->defineMethod($proto, 'forEach', 'Map.prototype.forEach', 1);
        // Iteration, which is not decoration: TypeScript's own bundle refuses to
        // load without `"entries" in Map.prototype`, and its downlevelled for-of
        // loops then drive these through `next()`.
        $realm->defineMethod($proto, 'keys', 'Map.prototype.keys', 0);
        $realm->defineMethod($proto, 'values', 'Map.prototype.values', 0);
        $realm->defineMethod($proto, 'entries', 'Map.prototype.entries', 0);
        $proto->defineOwnData(
            $realm->wellKnownSymbol('iterator')->propertyKey,
            $realm->nativeFn('Map.prototype.entries', '[Symbol.iterator]', 0),
            JSObject::W | JSObject::C
        );
        self::defineSize($realm, $proto, 'Map.prototype.size');
        $ctor = $realm->nativeFn('Map.call', 'Map', 0, 'Map.ctor');
        $realm->linkPair($ctor, $proto);
        return $ctor;
    }

    public static function makeSetConstructor(Realm $realm): JSNativeFunction
    {
        $proto = new JSObject($realm->objectPrototype());
        $realm->defineMethod($proto, 'add', 'Set.prototype.add', 1);
        $realm->defineMethod($proto, 'has', 'Set.prototype.has', 1);
        $realm->defineMethod($proto, 'delete', 'Set.prototype.delete', 1);
        $realm->defineMethod($proto, 'clear', 'Set.prototype.clear', 0);
        $realm->defineMethod($proto, 'forEach', 'Set.prototype.forEach', 1);
        $realm->defineMethod($proto, 'keys', 'Set.prototype.keys', 0);
        $realm->defineMethod($proto, 'values', 'Set.prototype.values', 0);
        $realm->defineMethod($proto, 'entries', 'Set.prototype.entries', 0);
        $proto->defineOwnData(
            $realm->wellKnownSymbol('iterator')->propertyKey,
            $realm->nativeFn('Set.prototype.values', '[Symbol.iterator]', 0),
            JSObject::W | JSObject::C
        );
        self::defineSize($realm, $proto, 'Set.prototype.size');
        $ctor = $realm->nativeFn('Set.call', 'Set', 0, 'Set.ctor');
        $realm->linkPair($ctor, $proto);
        return $ctor;
    }

    /** `size` is an accessor on the prototype, as in the spec — not a field. */
    private static function defineSize(Realm $realm, JSObject $proto, string $fnId): void
    {
        $proto->defineOwnAccessor('size', $realm->nativeFn($fnId, 'size', 0), null, JSObject::C);
    }

    public static function requiresNew(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $name = $fn?->name ?? 'Map';
        $vm->throwError('TypeError', "Constructor $name requires 'new'");
    }

    public static function mapCtor(Vm $vm, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $map = self::make($vm, $fn, 'Map');
        $init = self::at($args, 0);
        if ($init !== null && !$init instanceof JSUndefined) {
            foreach (self::iterableToList($vm, $init, 'Map') as $entry) {
                if (!$entry instanceof JSObject) {
                    $vm->throwError('TypeError', 'Iterator value is not an entry object');
                }
                $map->put($entry->get('0', $vm), $entry->get('1', $vm));
            }
        }
        return $map;
    }

    public static function setCtor(Vm $vm, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $set = self::make($vm, $fn, 'Set');
        $init = self::at($args, 0);
        if ($init !== null && !$init instanceof JSUndefined) {
            foreach (self::iterableToList($vm, $init, 'Set') as $value) {
                $set->put($value, $value);
            }
        }
        return $set;
    }

    private static function make(Vm $vm, ?JSNativeFunction $ctor, string $className): JSCollection
    {
        $proto = $ctor?->get('prototype', $vm);
        $obj = new JSCollection($proto instanceof JSObject ? $proto : $vm->realm->objectPrototype());
        $obj->className = $className;
        return $obj;
    }

    /**
     * The constructors accept an array (or array-like) of initial entries.
     * A general iterable would need the iterator protocol, which an ES5.1
     * target has no syntax to consume; an array is what downleveled code
     * actually passes.
     *
     * @return list<mixed>
     */
    private static function iterableToList(Vm $vm, mixed $init, string $who): array
    {
        if ($init instanceof JSArray) {
            $out = [];
            $len = $init->length;
            for ($i = 0; $i < $len; $i++) {
                $out[] = $init->get((string)$i, $vm);
            }
            return $out;
        }
        if ($init instanceof JSObject) {
            $len = Conversions::toUint32($vm, $init->get('length', $vm));
            $out = [];
            for ($i = 0; $i < $len; $i++) {
                $out[] = $init->get((string)$i, $vm);
            }
            return $out;
        }
        $vm->throwError('TypeError', "$who constructor argument is not iterable");
    }

    /**
     * Not `$args[$i] ?? undefined`: JS null is PHP null, and `??` would map it
     * onto undefined — two distinct Map keys collapsing into one.
     */
    private static function at(array $args, int $i): mixed
    {
        return array_key_exists($i, $args) ? $args[$i] : JSUndefined::$undefined;
    }

    private static function receiver(Vm $vm, mixed $t, string $who): JSCollection
    {
        if (!$t instanceof JSCollection) {
            $vm->throwError('TypeError', "$who called on an incompatible receiver");
        }
        return $t;
    }

    public static function size(Vm $vm, mixed $t, array $args): mixed
    {
        return self::receiver($vm, $t, 'size')->size;
    }

    public static function get(Vm $vm, mixed $t, array $args): mixed
    {
        $map = self::receiver($vm, $t, 'Map.prototype.get');
        $i = $map->find(self::at($args, 0));
        return $i === null ? JSUndefined::$undefined : $map->list[$i][1];
    }

    public static function set(Vm $vm, mixed $t, array $args): mixed
    {
        $map = self::receiver($vm, $t, 'Map.prototype.set');
        $map->put(self::at($args, 0), self::at($args, 1));
        return $map;
    }

    public static function add(Vm $vm, mixed $t, array $args): mixed
    {
        $set = self::receiver($vm, $t, 'Set.prototype.add');
        $v = self::at($args, 0);
        $set->put($v, $v);
        return $set;
    }

    public static function has(Vm $vm, mixed $t, array $args): mixed
    {
        return self::receiver($vm, $t, 'has')->find(self::at($args, 0)) !== null;
    }

    public static function delete(Vm $vm, mixed $t, array $args): mixed
    {
        return self::receiver($vm, $t, 'delete')->remove(self::at($args, 0));
    }

    public static function clear(Vm $vm, mixed $t, array $args): mixed
    {
        self::receiver($vm, $t, 'clear')->clearAll();
        return JSUndefined::$undefined;
    }

    public static function mapForEach(Vm $vm, mixed $t, array $args): mixed
    {
        return self::each($vm, $t, $args, 'Map.prototype.forEach', false);
    }

    public static function setForEach(Vm $vm, mixed $t, array $args): mixed
    {
        return self::each($vm, $t, $args, 'Set.prototype.forEach', true);
    }

    /**
     * The shared prototype for the iterators, carrying `next` and the
     * self-returning `[Symbol.iterator]` that makes an iterator iterable.
     */
    private static function iteratorPrototype(Realm $realm): JSObject
    {
        $proto = $realm->collectionIteratorPrototype();
        if ($proto->hasOwn('next')) {
            return $proto;
        }
        $realm->defineMethod($proto, 'next', 'Collection.iterator.next', 0);
        $proto->defineOwnData(
            $realm->wellKnownSymbol('iterator')->propertyKey,
            $realm->nativeFn('Collection.iterator.self', '[Symbol.iterator]', 0),
            JSObject::W | JSObject::C
        );
        return $proto;
    }

    private static function iterator(Vm $vm, mixed $t, string $kind, string $who): JSCollectionIterator
    {
        return new JSCollectionIterator(
            self::receiver($vm, $t, $who),
            $kind,
            self::iteratorPrototype($vm->realm)
        );
    }

    public static function mapKeys(Vm $vm, mixed $t, array $args): mixed
    {
        return self::iterator($vm, $t, JSCollectionIterator::KEYS, 'Map.prototype.keys');
    }

    public static function mapValues(Vm $vm, mixed $t, array $args): mixed
    {
        return self::iterator($vm, $t, JSCollectionIterator::VALUES, 'Map.prototype.values');
    }

    public static function mapEntries(Vm $vm, mixed $t, array $args): mixed
    {
        return self::iterator($vm, $t, JSCollectionIterator::ENTRIES, 'Map.prototype.entries');
    }

    /** A Set's keys and values are the same thing. */
    public static function setValues(Vm $vm, mixed $t, array $args): mixed
    {
        return self::iterator($vm, $t, JSCollectionIterator::KEYS, 'Set.prototype.values');
    }

    /** A Set's entries are [value, value]. */
    public static function setEntries(Vm $vm, mixed $t, array $args): mixed
    {
        return self::iterator($vm, $t, 'setEntries', 'Set.prototype.entries');
    }

    public static function iteratorSelf(Vm $vm, mixed $t, array $args): mixed
    {
        return $t;
    }

    public static function iteratorNext(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSCollectionIterator) {
            $vm->throwError('TypeError', 'next called on an incompatible receiver');
        }
        $result = $vm->realm->newObject();
        $list = $t->collection->list;
        // Past tombstones, and re-reading the list each call so entries added
        // during iteration are seen -- the same rule forEach follows.
        while ($t->cursor < count($list) && $list[$t->cursor] === null) {
            $t->cursor++;
        }
        if ($t->cursor >= count($list)) {
            $result->defineOwnData('value', JSUndefined::$undefined, JSObject::W | JSObject::E | JSObject::C);
            $result->defineOwnData('done', true, JSObject::W | JSObject::E | JSObject::C);
            return $result;
        }
        [$key, $value] = $list[$t->cursor++];
        $item = match ($t->kind) {
            JSCollectionIterator::KEYS => $key,
            JSCollectionIterator::VALUES => $value,
            JSCollectionIterator::ENTRIES => $vm->realm->newArray([$key, $value]),
            default => $vm->realm->newArray([$key, $key]),   // a Set's entries
        };
        $result->defineOwnData('value', $item, JSObject::W | JSObject::E | JSObject::C);
        $result->defineOwnData('done', false, JSObject::W | JSObject::E | JSObject::C);
        return $result;
    }

    private static function each(Vm $vm, mixed $t, array $args, string $who, bool $isSet): mixed
    {
        $coll = self::receiver($vm, $t, $who);
        $cb = self::at($args, 0);
        $thisArg = self::at($args, 1);
        // The callback may mutate the collection; iterating by index over a
        // snapshot of the length matches the polyfill and the spec's "visit
        // entries added during iteration" behaviour closely enough for the
        // append case, while tombstones cover deletion.
        for ($i = 0; $i < count($coll->list); $i++) {
            $entry = $coll->list[$i];
            if ($entry === null) {
                continue;
            }
            $vm->invoke($cb, $thisArg, $isSet
                ? [$entry[0], $entry[0], $coll]
                : [$entry[1], $entry[0], $coll]);
        }
        return JSUndefined::$undefined;
    }
}

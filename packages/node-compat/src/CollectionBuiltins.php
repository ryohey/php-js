<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Native Map / Set / WeakMap / WeakSet.
 *
 * React 19 uses Map inside the renderer, and the JS polyfill's key derivation
 * (`keyOf`) plus its accessors were about 6% of a render. The surface here is
 * deliberately the same as the polyfill's — constructor, get/set/has/delete/
 * add/clear/forEach/size — because that is what the polyfill has proven is
 * enough for real library code on an ES5 engine.
 *
 * What is *not* here, and why: no `@@iterator`, no `keys()`/`values()`/
 * `entries()`. Those return iterators, and an ES5.1 target has no `for...of`
 * to consume them (DESIGN.md scope). Adding them would mean an iterator
 * protocol the compiler cannot express.
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
            'node.Map.ctor' => [self::class, 'mapCtor'],
            'node.Map.call' => [self::class, 'requiresNew'],
            'node.Map.prototype.get' => [self::class, 'get'],
            'node.Map.prototype.set' => [self::class, 'set'],
            'node.Map.prototype.has' => [self::class, 'has'],
            'node.Map.prototype.delete' => [self::class, 'delete'],
            'node.Map.prototype.clear' => [self::class, 'clear'],
            'node.Map.prototype.forEach' => [self::class, 'mapForEach'],
            'node.Map.prototype.size' => [self::class, 'size'],
            'node.Set.ctor' => [self::class, 'setCtor'],
            'node.Set.call' => [self::class, 'requiresNew'],
            'node.Set.prototype.add' => [self::class, 'add'],
            'node.Set.prototype.has' => [self::class, 'has'],
            'node.Set.prototype.delete' => [self::class, 'delete'],
            'node.Set.prototype.clear' => [self::class, 'clear'],
            'node.Set.prototype.forEach' => [self::class, 'setForEach'],
            'node.Set.prototype.size' => [self::class, 'size'],
        ];
    }

    public static function install(Realm $realm): void
    {
        $flags = JSObject::W | JSObject::C;
        $global = $realm->globalObject;

        $mapProto = new JSObject($realm->objectPrototype());
        $realm->defineMethod($mapProto, 'get', 'node.Map.prototype.get', 1);
        $realm->defineMethod($mapProto, 'set', 'node.Map.prototype.set', 2);
        $realm->defineMethod($mapProto, 'has', 'node.Map.prototype.has', 1);
        $realm->defineMethod($mapProto, 'delete', 'node.Map.prototype.delete', 1);
        $realm->defineMethod($mapProto, 'clear', 'node.Map.prototype.clear', 0);
        $realm->defineMethod($mapProto, 'forEach', 'node.Map.prototype.forEach', 1);
        self::defineSize($realm, $mapProto, 'node.Map.prototype.size');
        $mapCtor = $realm->nativeFn('node.Map.call', 'Map', 0, 'node.Map.ctor');
        $realm->linkPair($mapCtor, $mapProto);

        $setProto = new JSObject($realm->objectPrototype());
        $realm->defineMethod($setProto, 'add', 'node.Set.prototype.add', 1);
        $realm->defineMethod($setProto, 'has', 'node.Set.prototype.has', 1);
        $realm->defineMethod($setProto, 'delete', 'node.Set.prototype.delete', 1);
        $realm->defineMethod($setProto, 'clear', 'node.Set.prototype.clear', 0);
        $realm->defineMethod($setProto, 'forEach', 'node.Set.prototype.forEach', 1);
        self::defineSize($realm, $setProto, 'node.Set.prototype.size');
        $setCtor = $realm->nativeFn('node.Set.call', 'Set', 0, 'node.Set.ctor');
        $realm->linkPair($setCtor, $setProto);

        $global->defineOwnData('Map', $mapCtor, $flags);
        $global->defineOwnData('WeakMap', $mapCtor, $flags);
        $global->defineOwnData('Set', $setCtor, $flags);
        $global->defineOwnData('WeakSet', $setCtor, $flags);
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

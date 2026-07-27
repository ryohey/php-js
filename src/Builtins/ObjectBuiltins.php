<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class ObjectBuiltins
{
    public static function entries(): array
    {
        return [
            'Object' => [self::class, 'call'],
            'Object.ctor' => [self::class, 'callCtor'],
            'Object.keys' => [self::class, 'keys'],
            'Object.getOwnPropertyNames' => [self::class, 'getOwnPropertyNames'],
            'Object.getPrototypeOf' => [self::class, 'getPrototypeOf'],
            'Object.create' => [self::class, 'create'],
            'Object.defineProperty' => [self::class, 'defineProperty'],
            'Object.defineProperties' => [self::class, 'defineProperties'],
            'Object.getOwnPropertyDescriptor' => [self::class, 'getOwnPropertyDescriptor'],
            'Object.freeze' => [self::class, 'freeze'],
            'Object.isFrozen' => [self::class, 'isFrozen'],
            'Object.seal' => [self::class, 'seal'],
            'Object.isSealed' => [self::class, 'isSealed'],
            'Object.preventExtensions' => [self::class, 'preventExtensions'],
            'Object.isExtensible' => [self::class, 'isExtensible'],
            'Object.prototype.hasOwnProperty' => [self::class, 'hasOwnProperty'],
            'Object.prototype.isPrototypeOf' => [self::class, 'isPrototypeOf'],
            'Object.prototype.propertyIsEnumerable' => [self::class, 'propertyIsEnumerable'],
            'Object.prototype.toString' => [self::class, 'protoToString'],
            'Object.prototype.toLocaleString' => [self::class, 'toLocaleString'],
            'Object.prototype.valueOf' => [self::class, 'protoValueOf'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'hasOwnProperty', 'Object.prototype.hasOwnProperty', 1);
        $r->defineMethod($proto, 'isPrototypeOf', 'Object.prototype.isPrototypeOf', 1);
        $r->defineMethod($proto, 'propertyIsEnumerable', 'Object.prototype.propertyIsEnumerable', 1);
        $r->defineMethod($proto, 'toString', 'Object.prototype.toString', 0);
        $r->defineMethod($proto, 'toLocaleString', 'Object.prototype.toLocaleString', 0);
        $r->defineMethod($proto, 'valueOf', 'Object.prototype.valueOf', 0);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Object', 'Object', 1, 'Object.ctor');
        $r->linkPair($ctor, $r->objectPrototype());
        foreach ([
            'keys' => 1, 'getOwnPropertyNames' => 1, 'getPrototypeOf' => 1,
            'create' => 2, 'defineProperty' => 3, 'defineProperties' => 2,
            'getOwnPropertyDescriptor' => 2,
            'freeze' => 1, 'isFrozen' => 1, 'seal' => 1, 'isSealed' => 1,
            'preventExtensions' => 1, 'isExtensible' => 1,
        ] as $name => $arity) {
            $r->defineMethod($ctor, $name, "Object.$name", $arity);
        }
        return $ctor;
    }

    public static function call(Vm $vm, mixed $t, array $args): mixed
    {
        $v = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($v === null || $v instanceof JSUndefined) {
            return $vm->realm->newObject();
        }
        return Conversions::toObject($vm, $v);
    }

    public static function callCtor(Vm $vm, array $args): mixed
    {
        return self::call($vm, JSUndefined::$undefined, $args);
    }

    private static function requireObject(Vm $vm, array $args, string $who): JSObject
    {
        $v = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$v instanceof JSObject) {
            $vm->throwError('TypeError', "$who called on non-object");
        }
        return $v;
    }

    public static function keys(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.keys');
        return $vm->realm->newArray($o->ownEnumerableKeys());
    }

    public static function getOwnPropertyNames(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.getOwnPropertyNames');
        return $vm->realm->newArray($o->ownKeys());
    }

    public static function getPrototypeOf(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.getPrototypeOf');
        return $o->proto ?? null;
    }

    public static function create(Vm $vm, mixed $t, array $args): mixed
    {
        $proto = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$proto instanceof JSObject && $proto !== null) {
            $vm->throwError('TypeError', 'Object prototype may only be an Object or null');
        }
        $obj = new JSObject($proto instanceof JSObject ? $proto : null);
        $props = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        if (!$props instanceof JSUndefined) {
            self::defineProperties($vm, $t, [$obj, $props]);
        }
        return $obj;
    }

    public static function defineProperty(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.defineProperty');
        $key = Conversions::toString($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined));
        $desc = (\array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined);
        self::applyDescriptor($vm, $o, $key, $desc);
        return $o;
    }

    public static function defineProperties(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.defineProperties');
        $props = Conversions::toObject($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined));
        foreach ($props->ownEnumerableKeys() as $key) {
            self::applyDescriptor($vm, $o, $key, $props->get($key, $vm));
        }
        return $o;
    }

    private static function applyDescriptor(Vm $vm, JSObject $o, string $key, mixed $desc): void
    {
        if (!$desc instanceof JSObject) {
            $vm->throwError('TypeError', 'Property description must be an object');
        }
        $und = JSUndefined::$undefined;
        $get = $desc->hasProperty('get') ? $desc->get('get', $vm) : $und;
        $set = $desc->hasProperty('set') ? $desc->get('set', $vm) : $und;
        $hasAccessor = !($get instanceof JSUndefined) || !($set instanceof JSUndefined);
        $flags = 0;
        if (Conversions::toBoolean($desc->hasProperty('enumerable') ? $desc->get('enumerable', $vm) : false)) {
            $flags |= JSObject::E;
        }
        if (Conversions::toBoolean($desc->hasProperty('configurable') ? $desc->get('configurable', $vm) : false)) {
            $flags |= JSObject::C;
        }
        if ($hasAccessor) {
            foreach ([$get, $set] as $fn) {
                if (!($fn instanceof JSUndefined) && !($fn instanceof JSFunctionBase)) {
                    $vm->throwError('TypeError', 'Getter/setter must be a function');
                }
            }
            $o->defineOwnAccessor(
                $key,
                $get instanceof JSUndefined ? null : $get,
                $set instanceof JSUndefined ? null : $set,
                $flags
            );
            return;
        }
        if (Conversions::toBoolean($desc->hasProperty('writable') ? $desc->get('writable', $vm) : false)) {
            $flags |= JSObject::W;
        }
        $value = $desc->hasProperty('value') ? $desc->get('value', $vm) : $und;
        $o->defineOwnData($key, $value, $flags);
    }

    public static function getOwnPropertyDescriptor(Vm $vm, mixed $t, array $args): mixed
    {
        $o = self::requireObject($vm, $args, 'Object.getOwnPropertyDescriptor');
        $key = Conversions::toString($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined));
        $d = $o->ownDescriptor($key);
        if ($d === null) {
            return JSUndefined::$undefined;
        }
        $result = $vm->realm->newObject();
        $und = JSUndefined::$undefined;
        if ($d[2] & JSObject::ACCESSOR) {
            $result->props['get'] = $d[0] ?? $und;
            $result->props['set'] = $d[1] ?? $und;
        } else {
            $result->props['value'] = $d[0];
            $result->props['writable'] = (bool)($d[2] & JSObject::W);
        }
        $result->props['enumerable'] = (bool)($d[2] & JSObject::E);
        $result->props['configurable'] = (bool)($d[2] & JSObject::C);
        return $result;
    }

    public static function freeze(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$o instanceof JSObject) {
            return $o;
        }
        $o->extensible = false;
        $o->ensureAllOwn();
        $o->descs ??= [];
        foreach ($o->ownKeys() as $key) {
            $d = $o->descs[$key] ?? [null, null, JSObject::DEFAULT_ATTRS];
            $d[2] &= ~(JSObject::C | (($d[2] & JSObject::ACCESSOR) ? 0 : JSObject::W));
            $o->descs[$key] = $d;
        }
        if ($o instanceof JSArray) {
            $o->descs['length'] = [null, null, 0];
        }
        return $o;
    }

    public static function isFrozen(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$o instanceof JSObject) {
            return true;
        }
        if ($o->extensible) {
            return false;
        }
        foreach ($o->ownKeys() as $key) {
            $d = $o->ownDescriptor($key);
            if ($d !== null && (($d[2] & JSObject::C) || (!($d[2] & JSObject::ACCESSOR) && ($d[2] & JSObject::W)))) {
                return false;
            }
        }
        return true;
    }

    public static function seal(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$o instanceof JSObject) {
            return $o;
        }
        $o->extensible = false;
        $o->ensureAllOwn();
        $o->descs ??= [];
        foreach ($o->ownKeys() as $key) {
            $d = $o->descs[$key] ?? [null, null, JSObject::DEFAULT_ATTRS];
            $d[2] &= ~JSObject::C;
            $o->descs[$key] = $d;
        }
        return $o;
    }

    public static function isSealed(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$o instanceof JSObject) {
            return true;
        }
        if ($o->extensible) {
            return false;
        }
        foreach ($o->ownKeys() as $key) {
            $d = $o->ownDescriptor($key);
            if ($d !== null && ($d[2] & JSObject::C)) {
                return false;
            }
        }
        return true;
    }

    public static function preventExtensions(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($o instanceof JSObject) {
            $o->extensible = false;
        }
        return $o;
    }

    public static function isExtensible(Vm $vm, mixed $t, array $args): mixed
    {
        $o = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        return $o instanceof JSObject && $o->extensible;
    }

    public static function hasOwnProperty(Vm $vm, mixed $t, array $args): mixed
    {
        $key = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $o = Conversions::toObject($vm, $t);
        return $o->hasOwn($key);
    }

    public static function isPrototypeOf(Vm $vm, mixed $t, array $args): mixed
    {
        $v = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$v instanceof JSObject) {
            return false;
        }
        $o = Conversions::toObject($vm, $t);
        for ($p = $v->proto; $p !== null; $p = $p->proto) {
            if ($p === $o) {
                return true;
            }
        }
        return false;
    }

    public static function propertyIsEnumerable(Vm $vm, mixed $t, array $args): mixed
    {
        $key = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $o = Conversions::toObject($vm, $t);
        $d = $o->ownDescriptor($key);
        return $d !== null && ($d[2] & JSObject::E);
    }

    public static function protoToString(Vm $vm, mixed $t, array $args): mixed
    {
        if ($t instanceof JSUndefined) {
            return '[object Undefined]';
        }
        if ($t === null) {
            return '[object Null]';
        }
        $o = Conversions::toObject($vm, $t);
        return '[object ' . $o->className . ']';
    }

    public static function toLocaleString(Vm $vm, mixed $t, array $args): mixed
    {
        $o = Conversions::toObject($vm, $t);
        return $vm->invoke($o->get('toString', $vm), $o, []);
    }

    public static function protoValueOf(Vm $vm, mixed $t, array $args): mixed
    {
        if ($t instanceof JSPrimitiveWrapper) {
            return $t;
        }
        return Conversions::toObject($vm, $t);
    }
}

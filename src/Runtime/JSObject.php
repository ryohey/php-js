<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * Base JS object. Property storage is delegated to PHP arrays (DESIGN.md §5):
 * plain data properties with default attributes live raw in $props; only
 * properties with non-default attributes or accessors get an entry in $descs.
 *
 * Serialization constraint (DESIGN.md §11): fields reachable from a JSObject
 * may only hold JS values, JSEnv instances, or function-template arrays.
 * PHP Closures, resources, and foreign PHP objects are forbidden on the heap.
 */
class JSObject
{
    public const W = 1;        // writable
    public const E = 2;        // enumerable
    public const C = 4;        // configurable
    public const ACCESSOR = 8;
    public const DEFAULT_ATTRS = self::W | self::E | self::C;

    /** @var array<string|int, mixed> raw values for data properties */
    public array $props = [];
    /** @var array<string|int, array{0: mixed, 1: mixed, 2: int}>|null [getter, setter, flags] */
    public ?array $descs = null;
    public ?JSObject $proto;
    public bool $extensible = true;
    public string $className = 'Object';
    /** Provenance ID for realm snapshots (unused until snapshots land). */
    public ?string $nativeId = null;
    /**
     * True when an own-property read is fully described by $props and $descs.
     * Exotic objects that compute own properties from somewhere else — array
     * indices, string code units, mapped arguments — clear this, because for
     * them a $props entry can be stale or absent while the property exists.
     *
     * It exists so the prototype walk in get() can take a plain array lookup
     * instead of a virtual call per level; nothing else may skip getOwn().
     * Lazily materialized properties (JSFunction's 'prototype', the globals)
     * do NOT clear it: an unmaterialized key is simply missing from $props,
     * so the fast path falls through to ensureOwn() on its own.
     */
    public bool $ownPropsArePlain = true;

    public function __construct(?JSObject $proto = null)
    {
        $this->proto = $proto;
    }

    /** Hook for lazily materialized own properties (JSFunction overrides). */
    protected function ensureOwn(string $key): void
    {
    }

    /** Force all lazy own properties to exist (used by enumeration/reflection). */
    public function ensureAllOwn(): void
    {
    }

    public function hasOwn(string $key): bool
    {
        $this->ensureOwn($key);
        return array_key_exists($key, $this->props)
            || ($this->descs !== null && isset($this->descs[$key]));
    }

    public function hasProperty(string $key): bool
    {
        for ($o = $this; $o !== null; $o = $o->proto) {
            if ($o->hasOwn($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * [[GetOwnProperty]] + value read. $found reports whether the property exists.
     * $receiver is the original `this` for getter invocation.
     */
    public function getOwn(string $key, Vm $vm, mixed $receiver, bool &$found): mixed
    {
        $this->ensureOwn($key);
        if ($this->descs !== null && isset($this->descs[$key]) && ($this->descs[$key][2] & self::ACCESSOR)) {
            $found = true;
            $getter = $this->descs[$key][0];
            return $getter === null ? JSUndefined::$undefined : $vm->invoke($getter, $receiver, []);
        }
        if (array_key_exists($key, $this->props)) {
            $found = true;
            return $this->props[$key];
        }
        $found = false;
        return JSUndefined::$undefined;
    }

    public function get(string $key, Vm $vm, mixed $receiver = null): mixed
    {
        for ($o = $this; $o !== null; $o = $o->proto) {
            // Fast path: an already-materialized data property. Accessors keep
            // a null placeholder in $props (see defineOwnAccessor) and lazily
            // built properties are absent until ensureOwn runs, so a non-null
            // hit here is always a plain value. This is the whole cost of a
            // method lookup, which walks two or three prototypes to find one.
            if ($o->ownPropsArePlain && null !== ($v = $o->props[$key] ?? null)) {
                return $v;
            }
            $found = false;
            $v = $o->getOwn($key, $vm, $receiver ??= $this, $found);
            if ($found) {
                return $v;
            }
        }
        return JSUndefined::$undefined;
    }

    /** [[Put]]: assignment semantics including prototype-chain accessor/read-only checks. */
    public function set(string $key, mixed $value, Vm $vm, bool $strict): void
    {
        for ($o = $this; $o !== null; $o = $o->proto) {
            $o->ensureOwn($key);
            if ($o->descs !== null && isset($o->descs[$key])) {
                $d = $o->descs[$key];
                if ($d[2] & self::ACCESSOR) {
                    if ($d[1] === null) {
                        if ($strict) {
                            $vm->throwError('TypeError', "Cannot set property '$key' which has only a getter");
                        }
                        return;
                    }
                    $vm->invoke($d[1], $this, [$value]);
                    return;
                }
                if (!($d[2] & self::W)) {
                    if ($strict) {
                        $vm->throwError('TypeError', "Cannot assign to read only property '$key'");
                    }
                    return;
                }
                if ($o === $this) {
                    $this->props[$key] = $value;
                    return;
                }
                break; // writable data property on the prototype: shadow it below
            }
            if (array_key_exists($key, $o->props)) {
                if ($o === $this) {
                    $this->props[$key] = $value;
                    return;
                }
                break;
            }
        }
        if (!$this->extensible) {
            if ($strict) {
                $vm->throwError('TypeError', "Cannot add property '$key', object is not extensible");
            }
            return;
        }
        $this->createOwn($key, $value);
    }

    /** Create a new own default-attribute data property (virtual for JSArray length upkeep). */
    protected function createOwn(string $key, mixed $value): void
    {
        $this->props[$key] = $value;
    }

    public function defineOwnData(string $key, mixed $value, int $flags = self::DEFAULT_ATTRS): void
    {
        $this->props[$key] = $value;
        if ($flags === self::DEFAULT_ATTRS) {
            if ($this->descs !== null) {
                unset($this->descs[$key]);
            }
        } else {
            $this->descs ??= [];
            $this->descs[$key] = [null, null, $flags];
        }
    }

    public function defineOwnAccessor(string $key, mixed $getter, mixed $setter, int $flags): void
    {
        // A null placeholder keeps the key in $props so enumeration reports it
        // in creation order alongside data properties. Every read consults
        // $descs for the ACCESSOR bit first, and the VM's `?? null` fast paths
        // fall through on null, so the placeholder is never observable.
        $this->props[$key] = null;
        $this->descs ??= [];
        $this->descs[$key] = [$getter, $setter, $flags | self::ACCESSOR];
    }

    /**
     * [[DefineOwnProperty]] (8.12.9). $desc carries only the fields the caller
     * specified, keyed 'value' / 'get' / 'set' / 'writable' / 'enumerable' /
     * 'configurable'; absent keys mean "not present" and are inherited from the
     * existing property (or default to false/undefined when creating).
     *
     * Returns false when the definition is rejected; throws a TypeError first
     * when $throw is set.
     */
    public function defineOwnProperty(string $key, array $desc, Vm $vm, bool $throw = true): bool
    {
        $hasAccessorField = array_key_exists('get', $desc) || array_key_exists('set', $desc);
        $hasDataField = array_key_exists('value', $desc) || array_key_exists('writable', $desc);
        $current = $this->ownDescriptor($key);

        if ($current === null) {
            if (!$this->extensible) {
                return $this->rejectDefine($vm, $throw, "Cannot define property $key, object is not extensible");
            }
            $flags = 0;
            if ($desc['enumerable'] ?? false) {
                $flags |= self::E;
            }
            if ($desc['configurable'] ?? false) {
                $flags |= self::C;
            }
            if ($hasAccessorField) {
                $this->defineOwnAccessor($key, $desc['get'] ?? null, $desc['set'] ?? null, $flags);
            } else {
                if ($desc['writable'] ?? false) {
                    $flags |= self::W;
                }
                $this->defineOwnData($key, $desc['value'] ?? JSUndefined::$undefined, $flags);
            }
            return true;
        }

        $curFlags = $current[2];
        $curIsAccessor = (bool)($curFlags & self::ACCESSOR);
        if (!($curFlags & self::C)) {
            if (($desc['configurable'] ?? false) === true) {
                return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
            }
            if (array_key_exists('enumerable', $desc) && $desc['enumerable'] !== (bool)($curFlags & self::E)) {
                return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
            }
            if ($hasAccessorField !== $curIsAccessor && ($hasAccessorField || $hasDataField)) {
                return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
            }
            if ($curIsAccessor) {
                if (array_key_exists('get', $desc) && ($desc['get'] ?? null) !== $current[0]) {
                    return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
                }
                if (array_key_exists('set', $desc) && ($desc['set'] ?? null) !== $current[1]) {
                    return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
                }
            } elseif (!($curFlags & self::W)) {
                if (($desc['writable'] ?? false) === true) {
                    return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
                }
                if (array_key_exists('value', $desc) && !TypeOps::sameValue($desc['value'], $current[0])) {
                    return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
                }
            }
        }

        $flags = $curFlags & (self::E | self::C);
        if (array_key_exists('enumerable', $desc)) {
            $flags = $desc['enumerable'] ? ($flags | self::E) : ($flags & ~self::E);
        }
        if (array_key_exists('configurable', $desc)) {
            $flags = $desc['configurable'] ? ($flags | self::C) : ($flags & ~self::C);
        }

        if ($hasAccessorField) {
            $this->defineOwnAccessor(
                $key,
                array_key_exists('get', $desc) ? $desc['get'] : ($curIsAccessor ? $current[0] : null),
                array_key_exists('set', $desc) ? $desc['set'] : ($curIsAccessor ? $current[1] : null),
                $flags
            );
            return true;
        }
        if ($hasDataField || !$curIsAccessor) {
            // Converting an accessor to data resets writable to its default.
            if (!$curIsAccessor && ($curFlags & self::W)) {
                $flags |= self::W;
            }
            if (array_key_exists('writable', $desc)) {
                $flags = $desc['writable'] ? ($flags | self::W) : ($flags & ~self::W);
            }
            $value = array_key_exists('value', $desc)
                ? $desc['value']
                : ($curIsAccessor ? JSUndefined::$undefined : $current[0]);
            $this->defineOwnData($key, $value, $flags);
            return true;
        }
        // Generic descriptor over an accessor: only the attributes change.
        $this->defineOwnAccessor($key, $current[0], $current[1], $flags);
        return true;
    }

    protected function rejectDefine(Vm $vm, bool $throw, string $message): bool
    {
        if ($throw) {
            $vm->throwError('TypeError', $message);
        }
        return false;
    }

    /** @return array{0: mixed, 1: mixed, 2: int}|null [getterOrValue, setter, flags] */
    public function ownDescriptor(string $key): ?array
    {
        $this->ensureOwn($key);
        if ($this->descs !== null && isset($this->descs[$key])) {
            $d = $this->descs[$key];
            if ($d[2] & self::ACCESSOR) {
                return $d;
            }
            return [$this->props[$key] ?? JSUndefined::$undefined, null, $d[2]];
        }
        if (array_key_exists($key, $this->props)) {
            return [$this->props[$key], null, self::DEFAULT_ATTRS];
        }
        return null;
    }

    public function deleteKey(string $key, Vm $vm, bool $strict): bool
    {
        $this->ensureOwn($key);
        $exists = array_key_exists($key, $this->props)
            || ($this->descs !== null && isset($this->descs[$key]));
        if (!$exists) {
            return true;
        }
        $flags = $this->descs[$key][2] ?? self::DEFAULT_ATTRS;
        if (!($flags & self::C)) {
            if ($strict) {
                $vm->throwError('TypeError', "Cannot delete property '$key'");
            }
            return false;
        }
        unset($this->props[$key]);
        if ($this->descs !== null) {
            unset($this->descs[$key]);
        }
        return true;
    }

    /**
     * [[OwnPropertyKeys]] ordering: array indices in ascending numeric order
     * first, then the remaining string keys in creation order. PHP arrays
     * preserve insertion order for both, so the indices need re-sorting.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    /**
     * [[OwnPropertyKeys]] order: integer indices ascending, then the rest in
     * insertion order.
     *
     * Symbol-keyed properties are dropped here, and doing it in this one place
     * is what keeps `Object.keys`, `for-in`, `JSON.stringify` and
     * `Object.getOwnPropertyNames` from ever seeing a symbol without any of them
     * knowing symbols exist (see JSSymbol). `ownSymbolKeys()` is the only way
     * back to them.
     */
    protected static function orderKeys(array $keys): array
    {
        $indices = [];
        $strings = [];
        foreach ($keys as $k) {
            if (JSSymbol::isSymbolKey($k)) {
                continue;
            }
            $idx = JSArray::asIndex($k);
            if ($idx !== null) {
                $indices[] = $idx;
            } else {
                $strings[] = $k;
            }
        }
        if ($indices === []) {
            return $strings;
        }
        sort($indices);
        return array_merge(array_map('strval', $indices), $strings);
    }

    /** @return list<string> own enumerable keys in [[OwnPropertyKeys]] order */
    public function ownEnumerableKeys(): array
    {
        $this->ensureAllOwn();
        $keys = [];
        if ($this->descs === null) {
            foreach ($this->props as $k => $_) {
                $keys[] = (string)$k;
            }
            return self::orderKeys($keys);
        }
        foreach ($this->props as $k => $_) {
            $d = $this->descs[$k] ?? null;
            if ($d === null || ($d[2] & self::E)) {
                $keys[] = (string)$k;
            }
        }
        return self::orderKeys($keys);
    }

    /** @return list<string> all own keys (including non-enumerable), names only */
    public function ownKeys(): array
    {
        $this->ensureAllOwn();
        $keys = [];
        foreach ($this->props as $k => $_) {
            $keys[] = (string)$k;
        }
        return self::orderKeys($keys);
    }

    /**
     * Own symbol-keyed property keys, in insertion order, as the private
     * strings they are stored under. `Object.getOwnPropertySymbols` maps them
     * back to symbols through the realm.
     *
     * @return list<string>
     */
    public function ownSymbolKeys(): array
    {
        $this->ensureAllOwn();
        $keys = [];
        foreach ($this->props as $k => $_) {
            $k = (string)$k;
            if (JSSymbol::isSymbolKey($k)) {
                $keys[] = $k;
            }
        }
        return $keys;
    }
}

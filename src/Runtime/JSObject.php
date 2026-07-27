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
        $receiver ??= $this;
        for ($o = $this; $o !== null; $o = $o->proto) {
            $found = false;
            $v = $o->getOwn($key, $vm, $receiver, $found);
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
        unset($this->props[$key]);
        $this->descs ??= [];
        $this->descs[$key] = [$getter, $setter, $flags | self::ACCESSOR];
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

    /** @return list<string> own enumerable keys, in insertion order */
    public function ownEnumerableKeys(): array
    {
        $this->ensureAllOwn();
        $keys = [];
        if ($this->descs === null) {
            foreach ($this->props as $k => $_) {
                $keys[] = (string)$k;
            }
            return $keys;
        }
        foreach ($this->props as $k => $_) {
            $d = $this->descs[$k] ?? null;
            if ($d === null || ($d[2] & self::E)) {
                $keys[] = (string)$k;
            }
        }
        foreach ($this->descs as $k => $d) {
            if (($d[2] & self::ACCESSOR) && ($d[2] & self::E)) {
                $keys[] = (string)$k;
            }
        }
        return $keys;
    }

    /** @return list<string> all own keys (including non-enumerable) */
    public function ownKeys(): array
    {
        $this->ensureAllOwn();
        $keys = [];
        foreach ($this->props as $k => $_) {
            $keys[] = (string)$k;
        }
        if ($this->descs !== null) {
            foreach ($this->descs as $k => $d) {
                if ($d[2] & self::ACCESSOR) {
                    $keys[] = (string)$k;
                }
            }
        }
        return $keys;
    }
}

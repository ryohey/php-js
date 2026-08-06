<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * Backing storage for a native Map or Set.
 *
 * DESIGN.md §11.3 forbids foreign PHP objects on the JS heap, so this cannot
 * be an `SplObjectStorage` wrapper: the fields below hold only JS values and
 * plain PHP arrays, exactly like `JSArray::$elements`.
 *
 * Layout mirrors what the JS polyfill did, because it is the right shape — a
 * string-keyed index into an insertion-ordered entry list, so iteration order
 * is preserved and lookup is a hash hit. Deletion leaves a tombstone (an entry
 * of `null`) so live indices stay valid, and the list is compacted once
 * tombstones outnumber live entries.
 */
final class JSCollection extends JSObject
{
    /** @var array<string, int> derived key => index into $list */
    public array $index = [];
    /** @var list<array{0: mixed, 1: mixed}|null> insertion-ordered [key, value], null = tombstone */
    public array $list = [];
    public int $size = 0;

    /** Identity stamps for object keys; see keyFor(). */
    private static int $objectIds = 0;
    /** @var \WeakMap<JSObject, int>|null */
    private static ?\WeakMap $objectIdMap = null;

    /**
     * SameValueZero as a PHP array key: NaN equals itself, +0 equals -0, and a
     * number is the same key whether the runtime is holding it as int or float.
     */
    public static function keyFor(mixed $v): string
    {
        if (is_string($v)) {
            return 's' . $v;
        }
        if (is_int($v)) {
            return 'n' . $v;
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return 'nNaN';
            }
            if ($v === 0.0) {
                return 'n0'; // -0.0 === 0.0, and SameValueZero wants them equal
            }
            if ($v >= -9007199254740992.0 && $v <= 9007199254740992.0 && $v == floor($v)) {
                return 'n' . (int)$v;
            }
            // Anything else is keyed by its exact bit pattern: two distinct
            // doubles can print identically at PHP's default precision, and a
            // collision here would silently merge two Map entries.
            return 'f' . pack('d', $v);
        }
        if ($v === null) {
            return 'null';
        }
        if ($v instanceof JSUndefined) {
            return 'undef';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v instanceof JSObject) {
            // The collection holds the key object alive in $list, so the id
            // cannot be recycled underneath a live entry.
            self::$objectIdMap ??= new \WeakMap();
            return 'o' . (self::$objectIdMap[$v] ??= ++self::$objectIds);
        }
        if ($v instanceof JSSymbol) {
            // Keyed by the symbol's own unique property key, which is exactly
            // the identity a Map needs: two symbols with one description are two
            // keys, and Symbol.for returns one symbol so it is one key.
            return 's' . $v->propertyKey;
        }
        // Unreachable for the value types in §3, but a wrong key would be a
        // silent lookup failure rather than an error, so be loud about it.
        throw new \LogicException('Unhashable collection key: ' . get_debug_type($v));
    }

    public function find(mixed $key): ?int
    {
        return $this->index[self::keyFor($key)] ?? null;
    }

    public function put(mixed $key, mixed $value): void
    {
        $k = self::keyFor($key);
        $i = $this->index[$k] ?? null;
        if ($i === null) {
            $this->index[$k] = count($this->list);
            $this->list[] = [$key, $value];
            $this->size++;
            return;
        }
        $this->list[$i][1] = $value;
    }

    public function remove(mixed $key): bool
    {
        $k = self::keyFor($key);
        $i = $this->index[$k] ?? null;
        if ($i === null) {
            return false;
        }
        unset($this->index[$k]);
        $this->list[$i] = null;
        $this->size--;
        if ($this->size < count($this->list) / 2) {
            $this->compact();
        }
        return true;
    }

    public function clearAll(): void
    {
        $this->index = [];
        $this->list = [];
        $this->size = 0;
    }

    private function compact(): void
    {
        $list = [];
        $index = [];
        foreach ($this->list as $entry) {
            if ($entry !== null) {
                $index[self::keyFor($entry[0])] = count($list);
                $list[] = $entry;
            }
        }
        $this->list = $list;
        $this->index = $index;
    }

    /** True when the two values are the same under SameValueZero. */
    public static function sameValueZero(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_nan($a)) {
            return is_float($b) && is_nan($b);
        }
        return TypeOps::strictEquals($a, $b);
    }
}

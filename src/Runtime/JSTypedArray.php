<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * 23.2 %TypedArray% instances: an Integer-Indexed exotic object (10.4.5)
 * over a view into a `JSArrayBuffer`. One class serves all nine kinds --
 * they differ only in element size and how a byte range decodes to a JS
 * number, both of which are one `match` on `$kind` away, not nine separate
 * classes duplicating the same exotic-property machinery.
 *
 * Unlike `JSArray`, indices are never stored in `$props`/`$elements` at
 * all -- every read and write goes straight through to the buffer's own
 * bytes, computed from `$byteOffset + $idx * elementSize`. `length` is
 * fixed at construction (no growth, no truncation) and every index in
 * range is always present, writable, enumerable and configurable, and
 * never an accessor; there is no way to make an index read-only or to
 * delete one (10.4.5.5, 10.4.5.9).
 */
final class JSTypedArray extends JSObject
{
    private const ELEMENT_SIZES = [
        'Int8' => 1, 'Uint8' => 1, 'Uint8Clamped' => 1,
        'Int16' => 2, 'Uint16' => 2,
        'Int32' => 4, 'Uint32' => 4,
        'Float32' => 4, 'Float64' => 8,
    ];

    /** @var list<string> every concrete kind, in the order the constructors are registered */
    public const KINDS = [
        'Int8', 'Uint8', 'Uint8Clamped', 'Int16', 'Uint16', 'Int32', 'Uint32', 'Float32', 'Float64',
    ];

    public function __construct(
        ?JSObject $proto,
        public JSArrayBuffer $buffer,
        public int $byteOffset,
        public int $length,
        /** @var 'Int8'|'Uint8'|'Uint8Clamped'|'Int16'|'Uint16'|'Int32'|'Uint32'|'Float32'|'Float64' */
        public string $kind,
    ) {
        parent::__construct($proto);
        $this->className = $kind . 'Array';
        // Indices are computed from the buffer, not stored in $props at
        // all -- get()'s fast path must not trust a $props miss to mean
        // "no such property" the way it safely can for an ordinary object.
        $this->ownPropsArePlain = false;
    }

    public static function bytesPerElement(string $kind): int
    {
        return self::ELEMENT_SIZES[$kind];
    }

    /** ToNumber (or the kind-appropriate narrower conversion) for one element's incoming value. */
    public function convert(Vm $vm, mixed $value): int|float
    {
        return match ($this->kind) {
            'Int8' => Conversions::toInt8($vm, $value),
            'Uint8' => Conversions::toUint8($vm, $value),
            'Uint8Clamped' => Conversions::toUint8Clamp($vm, $value),
            'Int16' => Conversions::toInt16($vm, $value),
            'Uint16' => Conversions::toUint16($vm, $value),
            'Int32' => Conversions::toInt32($vm, $value),
            'Uint32' => Conversions::toUint32($vm, $value),
            'Float32', 'Float64' => Conversions::toNumber($vm, $value),
        };
    }

    /**
     * A callback-driven method (forEach/map/filter/every/some/reduce/sort)
     * can detach this array's buffer partway through its own loop, from
     * inside the callback; the loop keeps running afterward the same way the
     * spec's own [[Get]]-based algorithms do, so this must not crash on a
     * now-empty backing string. Returning 0 rather than reproducing
     * `undefined` here is a deliberate simplification -- crash-freedom
     * matters more than matching the exact value these edge cases see -- and
     * `getOwn()`/`validIndexFrom()` is the path that gets the real undefined
     * right for ordinary property access.
     */
    public function readElement(int $idx): int|float
    {
        $offset = $this->byteOffset + $idx * self::ELEMENT_SIZES[$this->kind];
        $bytes = $this->buffer->bytes;
        if ($offset + self::ELEMENT_SIZES[$this->kind] > \strlen($bytes)) {
            return $this->kind === 'Float32' || $this->kind === 'Float64' ? 0.0 : 0;
        }
        return match ($this->kind) {
            'Int8' => \unpack('c', $bytes, $offset)[1],
            'Uint8', 'Uint8Clamped' => \ord($bytes[$offset]),
            'Int16' => self::signed16(\unpack('v', $bytes, $offset)[1]),
            'Uint16' => \unpack('v', $bytes, $offset)[1],
            'Int32' => self::signed32(\unpack('V', $bytes, $offset)[1]),
            'Uint32' => \unpack('V', $bytes, $offset)[1],
            'Float32' => \unpack('g', $bytes, $offset)[1],
            'Float64' => \unpack('e', $bytes, $offset)[1],
        };
    }

    /**
     * $value is already the result of convert() -- this only re-encodes it as
     * bytes. A silent no-op once detached (mid-loop, from a callback, same
     * as readElement above): every index is invalid against an empty buffer,
     * and PHP's auto-extending string-offset assignment would otherwise
     * resurrect bytes into what must stay permanently empty.
     */
    public function writeElementRaw(int $idx, int|float $value): void
    {
        if ($this->buffer->detached) {
            return;
        }
        $offset = $this->byteOffset + $idx * self::ELEMENT_SIZES[$this->kind];
        $packed = match ($this->kind) {
            'Int8', 'Uint8', 'Uint8Clamped' => \chr((int)$value & 0xFF),
            'Int16', 'Uint16' => \pack('v', (int)$value & 0xFFFF),
            'Int32', 'Uint32' => \pack('V', (int)$value & 0xFFFFFFFF),
            'Float32' => \pack('g', $value),
            'Float64' => \pack('e', $value),
        };
        // Byte-at-a-time offset assignment rather than substr_replace, which
        // would copy the whole buffer on every single element write.
        for ($i = 0, $n = \strlen($packed); $i < $n; $i++) {
            $this->buffer->bytes[$offset + $i] = $packed[$i];
        }
    }

    private static function signed16(int $u): int
    {
        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    private static function signed32(int $u): int
    {
        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }

    /**
     * 7.1.21 CanonicalNumericIndexString: the number a property key spells
     * exactly (round-tripping through ToString(ToNumber(key)) unchanged),
     * or null if it names an ordinary property instead. "-0" is the one
     * string that is canonical but does not round-trip through
     * numberToString (which always renders negative zero as "0"), so it
     * needs its own case, same as the spec's own algorithm carves out.
     */
    private static function canonicalNumericIndex(string $key): int|float|null
    {
        if ($key === '-0') {
            return -0.0;
        }
        $n = Conversions::stringToNumber($key);
        if (is_nan($n) && $key !== 'NaN') {
            return null;
        }
        return Conversions::numberToString($n) === $key ? $n : null;
    }

    /**
     * 10.4.5.11 IsValidIntegerIndex: a canonical numeric index further
     * narrowed to a non-negative whole number strictly less than length --
     * excludes -0, fractions, and anything past the (fixed) end -- or null
     * once the buffer is detached, which no index is ever valid against.
     */
    private function validIndexFrom(int|float $n): ?int
    {
        if ($this->buffer->detached) {
            return null;
        }
        if (is_float($n)) {
            if (is_nan($n) || is_infinite($n) || $n != floor($n)) {
                return null;
            }
            // `1 / $n` would work as a -0 test but the `/` operator throws
            // DivisionByZeroError on a zero divisor in PHP 8; fdiv() is the
            // IEEE-754 division that actually returns -INF here.
            if ($n === 0.0 && \fdiv(1, $n) < 0) {
                return null;
            }
        }
        $i = (int)$n;
        return ($i >= 0 && $i < $this->length) ? $i : null;
    }

    public function hasOwn(string $key): bool
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            return $this->validIndexFrom($n) !== null;
        }
        return parent::hasOwn($key);
    }

    /**
     * 10.4.5.4/10.4.5.8 [[Get]]: a canonical numeric index key resolves right
     * here whether or not it names a live element -- `$found = true` even
     * when the value is `undefined` -- because the exotic [[Get]] algorithm
     * returns undefined itself for an out-of-range or non-integer index
     * rather than falling through to an ordinary property lookup. Letting
     * `$found = false` bubble up here would send a key like `"1.1"` or
     * `"-0"` on to walk the prototype chain, which could observably run a
     * getter the spec's algorithm never reaches.
     */
    public function getOwn(string $key, Vm $vm, mixed $receiver, bool &$found): mixed
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            $found = true;
            $idx = $this->validIndexFrom($n);
            return $idx === null ? JSUndefined::$undefined : $this->readElement($idx);
        }
        return parent::getOwn($key, $vm, $receiver, $found);
    }

    /**
     * 10.4.5.13 IntegerIndexedElementSet: the value is converted -- side
     * effects and all -- whenever the key is *any* canonical numeric index,
     * even one this array rejects as out of range; only the write itself is
     * conditional on validity. A canonical-numeric-index key never falls
     * through to ordinary property assignment on this object or its
     * prototype chain, in range or not.
     */
    public function set(string $key, mixed $value, Vm $vm, bool $strict): void
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            $converted = $this->convert($vm, $value);
            $idx = $this->validIndexFrom($n);
            if ($idx !== null) {
                $this->writeElementRaw($idx, $converted);
            }
            return;
        }
        parent::set($key, $value, $vm, $strict);
    }

    public function defineOwnProperty(string $key, array $desc, Vm $vm, bool $throw = true): bool
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            $idx = $this->validIndexFrom($n);
            if ($idx === null) {
                return $this->rejectDefine($vm, $throw, "Invalid typed array index: $key");
            }
            if (($desc['configurable'] ?? true) === false
                || ($desc['enumerable'] ?? true) === false
                || ($desc['writable'] ?? true) === false
                || array_key_exists('get', $desc) || array_key_exists('set', $desc)) {
                return $this->rejectDefine($vm, $throw, "Cannot redefine property: $key");
            }
            if (array_key_exists('value', $desc)) {
                $this->writeElementRaw($idx, $this->convert($vm, $desc['value']));
            }
            return true;
        }
        return parent::defineOwnProperty($key, $desc, $vm, $throw);
    }

    public function ownDescriptor(string $key): ?array
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            $idx = $this->validIndexFrom($n);
            return $idx === null ? null : [$this->readElement($idx), null, self::DEFAULT_ATTRS];
        }
        return parent::ownDescriptor($key);
    }

    public function deleteKey(string $key, Vm $vm, bool $strict): bool
    {
        $n = self::canonicalNumericIndex($key);
        if ($n !== null) {
            if ($this->validIndexFrom($n) !== null) {
                if ($strict) {
                    $vm->throwError('TypeError', "Cannot delete property '$key'");
                }
                return false;
            }
            return true;
        }
        return parent::deleteKey($key, $vm, $strict);
    }

    public function ownEnumerableKeys(): array
    {
        $out = [];
        for ($i = 0; $i < $this->length; $i++) {
            $out[] = (string)$i;
        }
        foreach (parent::ownEnumerableKeys() as $k) {
            $out[] = $k;
        }
        return $out;
    }

    public function ownKeys(): array
    {
        $out = [];
        for ($i = 0; $i < $this->length; $i++) {
            $out[] = (string)$i;
        }
        foreach (parent::ownKeys() as $k) {
            $out[] = $k;
        }
        return $out;
    }
}

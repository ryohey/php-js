<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * Array exotic object. Elements live in $elements (a PHP array, packed while
 * dense) separate from $props; $length is tracked explicitly (DESIGN.md §5.2).
 */
final class JSArray extends JSObject
{
    /** @var array<int, mixed> */
    public array $elements = [];
    public int $length = 0;

    public function __construct(?JSObject $proto = null)
    {
        parent::__construct($proto);
        $this->className = 'Array';
    }

    /** Canonical array index for a property key, or null if not an index. */
    public static function asIndex(string|int $key): ?int
    {
        if (is_int($key)) {
            return ($key >= 0 && $key <= 4294967294) ? $key : null;
        }
        if ($key === '' || !ctype_digit($key)) {
            return null;
        }
        $i = (int)$key;
        return ((string)$i === $key && $i <= 4294967294) ? $i : null;
    }

    public function hasOwn(string $key): bool
    {
        if ($key === 'length') {
            return true;
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            return array_key_exists($idx, $this->elements);
        }
        return parent::hasOwn($key);
    }

    public function getOwn(string $key, Vm $vm, mixed $receiver, bool &$found): mixed
    {
        if ($key === 'length') {
            $found = true;
            return $this->length;
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            if (array_key_exists($idx, $this->elements)) {
                $found = true;
                return $this->elements[$idx];
            }
            $found = false;
            return JSUndefined::$undefined;
        }
        return parent::getOwn($key, $vm, $receiver, $found);
    }

    public function set(string $key, mixed $value, Vm $vm, bool $strict): void
    {
        if ($key === 'length') {
            $len = Conversions::toUint32($vm, $value);
            $num = Conversions::toNumber($vm, $value);
            $same = is_int($num) ? $num == $len : ($num == (float)$len);
            if (!$same) {
                $vm->throwError('RangeError', 'Invalid array length');
            }
            $this->setLength($len);
            return;
        }
        // Index writes bypass prototype-chain accessor lookup: index accessors
        // on Array.prototype are not supported (accepted deviation).
        $idx = self::asIndex($key);
        if ($idx !== null) {
            if (!$this->extensible && !array_key_exists($idx, $this->elements)) {
                if ($strict) {
                    $vm->throwError('TypeError', "Cannot add property $key, object is not extensible");
                }
                return;
            }
            $this->elements[$idx] = $value;
            if ($idx >= $this->length) {
                $this->length = $idx + 1;
            }
            return;
        }
        parent::set($key, $value, $vm, $strict);
    }

    public function setLength(int $len): void
    {
        if ($len < $this->length) {
            foreach (array_keys($this->elements) as $i) {
                if ($i >= $len) {
                    unset($this->elements[$i]);
                }
            }
        }
        $this->length = $len;
    }

    public function deleteKey(string $key, Vm $vm, bool $strict): bool
    {
        if ($key === 'length') {
            if ($strict) {
                $vm->throwError('TypeError', "Cannot delete property 'length'");
            }
            return false;
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            unset($this->elements[$idx]);
            return true;
        }
        return parent::deleteKey($key, $vm, $strict);
    }

    public function ownEnumerableKeys(): array
    {
        $keys = array_keys($this->elements);
        sort($keys);
        $out = [];
        foreach ($keys as $k) {
            $out[] = (string)$k;
        }
        return array_merge($out, parent::ownEnumerableKeys());
    }

    public function ownKeys(): array
    {
        return array_merge($this->ownEnumerableKeys(), ['length']);
    }

    /** @param list<mixed> $values */
    public static function fromList(array $values, ?JSObject $proto): self
    {
        $a = new self($proto);
        $a->elements = $values;
        $a->length = count($values);
        return $a;
    }

    /** Dense PHP list of elements with holes filled by undefined. */
    public function toList(): array
    {
        $out = [];
        $und = JSUndefined::$undefined;
        for ($i = 0; $i < $this->length; $i++) {
            $out[] = $this->elements[$i] ?? $und;
        }
        return $out;
    }
}

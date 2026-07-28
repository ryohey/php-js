<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * Array exotic object. Elements live in $elements (a PHP array, packed while
 * dense) separate from $props; $length is tracked explicitly (DESIGN.md §5.2).
 *
 * Index properties keep their values in $elements even when they carry
 * non-default attributes; the attributes themselves go in $descs under the
 * string key, so the fast paths only pay for arrays that actually use them.
 */
final class JSArray extends JSObject
{
    /** @var array<int, mixed> */
    public array $elements = [];
    public int $length = 0;
    public bool $lengthWritable = true;

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

    private function indexFlags(string $key): int
    {
        return $this->descs[$key][2] ?? self::DEFAULT_ATTRS;
    }

    private function indexAccessor(string $key): ?array
    {
        $d = $this->descs[$key] ?? null;
        return ($d !== null && ($d[2] & self::ACCESSOR)) ? $d : null;
    }

    public function hasOwn(string $key): bool
    {
        if ($key === 'length') {
            return true;
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            return array_key_exists($idx, $this->elements) || $this->indexAccessor($key) !== null;
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
            $accessor = $this->indexAccessor($key);
            if ($accessor !== null) {
                $found = true;
                return $accessor[0] === null
                    ? JSUndefined::$undefined
                    : $vm->invoke($accessor[0], $receiver, []);
            }
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
            if (!$this->lengthWritable) {
                if ($strict) {
                    $vm->throwError('TypeError', 'Cannot assign to read only property \'length\'');
                }
                return;
            }
            $this->setLength($this->toLength($vm, $value));
            return;
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            $accessor = $this->indexAccessor($key);
            if ($accessor !== null) {
                if ($accessor[1] === null) {
                    if ($strict) {
                        $vm->throwError('TypeError', "Cannot set property '$key' which has only a getter");
                    }
                    return;
                }
                $vm->invoke($accessor[1], $this, [$value]);
                return;
            }
            $exists = array_key_exists($idx, $this->elements);
            if ($exists) {
                if (!($this->indexFlags($key) & self::W)) {
                    if ($strict) {
                        $vm->throwError('TypeError', "Cannot assign to read only property '$key'");
                    }
                    return;
                }
            } else {
                if (!$this->extensible) {
                    if ($strict) {
                        $vm->throwError('TypeError', "Cannot add property $key, object is not extensible");
                    }
                    return;
                }
                if ($idx >= $this->length && !$this->lengthWritable) {
                    if ($strict) {
                        $vm->throwError('TypeError', 'Cannot add property past a non-writable length');
                    }
                    return;
                }
            }
            $this->elements[$idx] = $value;
            if ($idx >= $this->length) {
                $this->length = $idx + 1;
            }
            return;
        }
        parent::set($key, $value, $vm, $strict);
    }

    /** ToUint32 with the array-length RangeError check (15.4.5.1). */
    private function toLength(Vm $vm, mixed $value): int
    {
        $len = Conversions::toUint32($vm, $value);
        $num = Conversions::toNumber($vm, $value);
        if (!(is_float($num) ? $num == (float)$len : $num == $len)) {
            $vm->throwError('RangeError', 'Invalid array length');
        }
        return $len;
    }

    /**
     * Truncate or extend. Truncation stops at the highest non-configurable
     * element, per 15.4.5.1; the resulting length is returned.
     */
    public function setLength(int $len): int
    {
        if ($len < $this->length) {
            $keys = array_keys($this->elements);
            rsort($keys);
            foreach ($keys as $i) {
                if ($i < $len) {
                    break;
                }
                if (!($this->indexFlags((string)$i) & self::C)) {
                    $len = $i + 1;
                    break;
                }
                unset($this->elements[$i]);
                if ($this->descs !== null) {
                    unset($this->descs[(string)$i]);
                }
            }
        }
        $this->length = $len;
        return $len;
    }

    public function defineOwnProperty(string $key, array $desc, Vm $vm, bool $throw = true): bool
    {
        if ($key === 'length') {
            return $this->defineLength($desc, $vm, $throw);
        }
        $idx = self::asIndex($key);
        if ($idx === null) {
            return parent::defineOwnProperty($key, $desc, $vm, $throw);
        }
        if ($idx >= $this->length && !$this->lengthWritable) {
            return $this->rejectDefine($vm, $throw, 'Cannot add property past a non-writable length');
        }
        // Reuse the ordinary validation: ownDescriptor() below is this class's
        // override, so the parent sees the element; whatever it writes into
        // $props is moved back into $elements afterwards.
        try {
            $ok = parent::defineOwnProperty($key, $desc, $vm, $throw);
        } finally {
            if ($this->indexAccessor($key) !== null) {
                unset($this->elements[$idx], $this->props[$key]);
            } elseif (array_key_exists($key, $this->props)) {
                $this->elements[$idx] = $this->props[$key];
                unset($this->props[$key]);
            }
        }
        if ($ok && $idx >= $this->length) {
            $this->length = $idx + 1;
        }
        return $ok;
    }

    private function defineLength(array $desc, Vm $vm, bool $throw): bool
    {
        if (array_key_exists('get', $desc) || array_key_exists('set', $desc)) {
            return $this->rejectDefine($vm, $throw, 'Cannot redefine array length as an accessor');
        }
        if (($desc['enumerable'] ?? false) === true || ($desc['configurable'] ?? false) === true) {
            return $this->rejectDefine($vm, $throw, 'Cannot redefine property: length');
        }
        $newLen = $this->length;
        if (array_key_exists('value', $desc)) {
            $newLen = $this->toLength($vm, $desc['value']);
            if (!$this->lengthWritable && $newLen !== $this->length) {
                return $this->rejectDefine($vm, $throw, 'Cannot assign to read only property \'length\'');
            }
        }
        if (($desc['writable'] ?? true) === false) {
            $this->lengthWritable = false;
        } elseif (array_key_exists('writable', $desc) && !$this->lengthWritable) {
            return $this->rejectDefine($vm, $throw, 'Cannot redefine property: length');
        }
        if ($newLen !== $this->length) {
            $actual = $this->setLength($newLen);
            if ($actual !== $newLen) {
                return $this->rejectDefine($vm, $throw, 'Cannot truncate past a non-configurable element');
            }
        }
        return true;
    }

    public function ownDescriptor(string $key): ?array
    {
        if ($key === 'length') {
            return [$this->length, null, $this->lengthWritable ? self::W : 0];
        }
        $idx = self::asIndex($key);
        if ($idx !== null) {
            $accessor = $this->indexAccessor($key);
            if ($accessor !== null) {
                return $accessor;
            }
            if (!array_key_exists($idx, $this->elements)) {
                return null;
            }
            return [$this->elements[$idx], null, $this->indexFlags($key)];
        }
        return parent::ownDescriptor($key);
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
            if (!$this->hasOwn($key)) {
                return true;
            }
            if (!($this->indexFlags($key) & self::C)) {
                if ($strict) {
                    $vm->throwError('TypeError', "Cannot delete property '$key'");
                }
                return false;
            }
            unset($this->elements[$idx]);
            if ($this->descs !== null) {
                unset($this->descs[$key]);
            }
            return true;
        }
        return parent::deleteKey($key, $vm, $strict);
    }

    /** @return list<string> index keys present, in ascending numeric order */
    private function indexKeys(): array
    {
        $keys = array_keys($this->elements);
        if ($this->descs !== null) {
            foreach ($this->descs as $k => $d) {
                if (($d[2] & self::ACCESSOR) && self::asIndex((string)$k) !== null) {
                    $keys[] = (int)$k;
                }
            }
            $keys = array_unique($keys);
        }
        sort($keys);
        return array_map('strval', $keys);
    }

    public function ownEnumerableKeys(): array
    {
        $out = [];
        foreach ($this->indexKeys() as $k) {
            if ($this->indexFlags($k) & self::E) {
                $out[] = $k;
            }
        }
        foreach (parent::ownEnumerableKeys() as $k) {
            if (self::asIndex($k) === null) {
                $out[] = $k;
            }
        }
        return $out;
    }

    public function ownKeys(): array
    {
        $out = $this->indexKeys();
        $out[] = 'length';
        foreach (parent::ownKeys() as $k) {
            if ($k !== 'length' && self::asIndex($k) === null) {
                $out[] = $k;
            }
        }
        return $out;
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

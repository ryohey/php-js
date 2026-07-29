<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/** Wrapper objects created by ToObject / new String() etc. */
final class JSPrimitiveWrapper extends JSObject
{
    public function __construct(
        public mixed $primitiveValue,
        string $className,
        ?JSObject $proto = null,
    ) {
        parent::__construct($proto);
        $this->className = $className;
        // String wrappers expose indices and 'length' from the primitive.
        $this->ownPropsArePlain = $className !== 'String';
    }

    public function hasOwn(string $key): bool
    {
        if ($this->className === 'String') {
            if ($key === 'length') {
                return true;
            }
            $idx = JSArray::asIndex($key);
            if ($idx !== null) {
                return $idx < StringOps::length16($this->primitiveValue);
            }
        }
        return parent::hasOwn($key);
    }

    public function getOwn(string $key, Vm $vm, mixed $receiver, bool &$found): mixed
    {
        if ($this->className === 'String') {
            if ($key === 'length') {
                $found = true;
                return StringOps::length16($this->primitiveValue);
            }
            $idx = JSArray::asIndex($key);
            if ($idx !== null) {
                $ch = StringOps::charAt($this->primitiveValue, $idx);
                if ($ch !== null) {
                    $found = true;
                    return $ch;
                }
                $found = false;
                return JSUndefined::$undefined;
            }
        }
        return parent::getOwn($key, $vm, $receiver, $found);
    }

    public function ownDescriptor(string $key): ?array
    {
        if ($this->className === 'String') {
            if ($key === 'length') {
                return [StringOps::length16($this->primitiveValue), null, 0];
            }
            $idx = JSArray::asIndex($key);
            if ($idx !== null) {
                $ch = StringOps::charAt($this->primitiveValue, $idx);
                // String index properties are enumerable but fixed.
                return $ch === null ? null : [$ch, null, self::E];
            }
        }
        return parent::ownDescriptor($key);
    }

    /** @return list<string> the index keys a String object exposes */
    private function stringIndexKeys(): array
    {
        if ($this->className !== 'String') {
            return [];
        }
        $keys = [];
        $n = StringOps::length16($this->primitiveValue);
        for ($i = 0; $i < $n; $i++) {
            $keys[] = (string)$i;
        }
        return $keys;
    }

    public function ownEnumerableKeys(): array
    {
        return array_merge($this->stringIndexKeys(), parent::ownEnumerableKeys());
    }

    public function ownKeys(): array
    {
        $keys = $this->stringIndexKeys();
        if ($this->className === 'String') {
            $keys[] = 'length';
        }
        return array_merge($keys, parent::ownKeys());
    }
}

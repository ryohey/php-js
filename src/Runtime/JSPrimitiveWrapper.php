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
}

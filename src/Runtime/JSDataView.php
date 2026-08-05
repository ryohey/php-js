<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * 25.3 DataView instances. Not exotic at all -- unlike a typed array, a
 * DataView has no indexed element access; every read and write is an
 * explicit method call (`DataViewBuiltins`), so this is just a view (buffer
 * + byte offset + byte length) with ordinary property behavior inherited
 * unchanged from `JSObject`.
 */
final class JSDataView extends JSObject
{
    public function __construct(
        ?JSObject $proto,
        public JSArrayBuffer $buffer,
        public int $byteOffset,
        public int $byteLength,
    ) {
        parent::__construct($proto);
        $this->className = 'DataView';
    }
}

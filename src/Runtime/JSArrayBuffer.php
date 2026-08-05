<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Vm\Vm;

/**
 * 25.1 ArrayBuffer objects. Not exotic in the property sense -- it carries no
 * indexed access of its own, `JSTypedArray`/`JSDataView` read and write
 * through it -- so it needs no [[Get]]/[[Set]] overrides at all, only the
 * internal byte store those two actually use.
 *
 * The store is a plain PHP binary string, mutated a handful of bytes at a
 * time via direct offset assignment (`$this->bytes[$i] = $byte`) rather than
 * `substr_replace`, which would copy the whole buffer on every element
 * write. A string is also the natural fit for DESIGN.md §11's heap
 * constraint: no PHP resource, no foreign object, just a JS-heap-safe scalar
 * a snapshot could `var_export` like any other.
 */
final class JSArrayBuffer extends JSObject
{
    public string $bytes;
    /**
     * Transferable per spec (`ArrayBuffer.prototype.transfer`), which this
     * engine does not implement -- the flag exists so a future `DataView`/
     * `TypedArray` over a buffer that *did* get detached some other way
     * (there is currently no way to reach this state) fails closed rather
     * than reading stale bytes.
     */
    public bool $detached = false;

    /**
     * CreateByteDataBlock's size limit is implementation-defined; the spec
     * only requires that an unreasonable request throws RangeError rather
     * than actually attempting the allocation (test262 checks with requests
     * in the petabyte range). This host runs inside an ordinary PHP process
     * with a real memory_limit, so the cap is set well under what any
     * legitimate buffer in this engine needs rather than at what the spec
     * technically allows (up to 2**53 - 1).
     */
    public const MAX_BYTE_LENGTH = 0x40000000; // 1 GiB

    public function __construct(?JSObject $proto, int $byteLength)
    {
        parent::__construct($proto);
        $this->className = 'ArrayBuffer';
        $this->bytes = str_repeat("\0", $byteLength);
    }

    /** Construct via allocation, so a too-large request fails as RangeError instead of exhausting memory. */
    public static function allocate(Vm $vm, ?JSObject $proto, int $byteLength): self
    {
        if ($byteLength > self::MAX_BYTE_LENGTH) {
            $vm->throwError('RangeError', 'Array buffer allocation failed');
        }
        return new self($proto, $byteLength);
    }

    public function byteLength(): int
    {
        return $this->detached ? 0 : \strlen($this->bytes);
    }
}

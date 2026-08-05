<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArrayBuffer;
use PhpJs\Runtime\JSDataView;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSTypedArray;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/** 25.1 ArrayBuffer objects: a fixed-length raw byte buffer with no view of its own. */
final class ArrayBufferBuiltins
{
    public static function entries(): array
    {
        return [
            'ArrayBuffer' => [self::class, 'callAsFunction'],
            'ArrayBuffer.ctor' => [self::class, 'ctor'],
            'ArrayBuffer.prototype.byteLengthGetter' => [self::class, 'byteLengthGetter'],
            'ArrayBuffer.prototype.slice' => [self::class, 'slice'],
            'ArrayBuffer.isView' => [self::class, 'isView'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $getter = $r->nativeFn('ArrayBuffer.prototype.byteLengthGetter', 'get byteLength', 0);
        $proto->defineOwnAccessor('byteLength', $getter, null, JSObject::C);
        $r->defineMethod($proto, 'slice', 'ArrayBuffer.prototype.slice', 2);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('ArrayBuffer', 'ArrayBuffer', 1, 'ArrayBuffer.ctor');
        $r->linkPair($ctor, $r->arrayBufferPrototype());
        $r->defineMethod($ctor, 'isView', 'ArrayBuffer.isView', 1);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        $vm->throwError('TypeError', "Constructor ArrayBuffer requires 'new'");
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $len = Conversions::toIndex($vm, $args[0] ?? \PhpJs\Runtime\JSUndefined::$undefined);
        return JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $len);
    }

    private static function checkBuffer(Vm $vm, mixed $t, string $method): JSArrayBuffer
    {
        if (!$t instanceof JSArrayBuffer) {
            $vm->throwError('TypeError', "$method called on an object that is not an ArrayBuffer");
        }
        return $t;
    }

    public static function byteLengthGetter(Vm $vm, mixed $t, array $args): mixed
    {
        return self::checkBuffer($vm, $t, 'get ArrayBuffer.prototype.byteLength')->byteLength();
    }

    public static function slice(Vm $vm, mixed $t, array $args): mixed
    {
        $buf = self::checkBuffer($vm, $t, 'ArrayBuffer.prototype.slice');
        $len = $buf->byteLength();
        $start = self::relativeIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, $len, 0);
        $end = self::relativeIndex($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined, $len, $len);
        $newLen = max($end - $start, 0);
        $out = JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $newLen);
        if ($newLen > 0) {
            $out->bytes = substr($buf->bytes, $start, $newLen);
        }
        return $out;
    }

    /** 23.1.3.3-style relative index clamp shared by ArrayBuffer.slice and TypedArray.slice/subarray. */
    public static function relativeIndex(Vm $vm, mixed $arg, int $length, int $default): int
    {
        // Only a genuinely absent/undefined argument defaults -- an explicit
        // JS `null` (this engine's plain PHP null) is a real value and goes
        // through ToInteger(null) = 0 like any other, not the default.
        if ($arg instanceof \PhpJs\Runtime\JSUndefined) {
            return $default;
        }
        $n = Conversions::toInteger($vm, $arg);
        if (is_infinite($n)) {
            return $n < 0 ? 0 : $length;
        }
        $i = (int)$n;
        $i = $i < 0 ? max($length + $i, 0) : min($i, $length);
        return $i;
    }

    public static function isView(Vm $vm, mixed $t, array $args): mixed
    {
        $v = $args[0] ?? null;
        return $v instanceof JSTypedArray || $v instanceof JSDataView;
    }
}

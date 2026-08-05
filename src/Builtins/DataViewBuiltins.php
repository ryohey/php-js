<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArrayBuffer;
use PhpJs\Runtime\JSDataView;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * 25.3 DataView: unlike a typed array, no indexed element access at all --
 * every read and write is an explicit method call naming its own byte
 * offset, size and endianness, so this needs no Runtime-side exotic
 * behavior, only these methods.
 *
 * Integer accessors build/take the value apart a byte at a time by hand
 * (`readUint`/`writeUint`), the same shape regardless of size or requested
 * endianness; a float's bit layout is not just its integer bytes reordered,
 * so `getFloat32`/`getFloat64`/their setters go through PHP's own explicit-
 * endian pack codes (`g`/`G`/`e`/`E`) instead.
 */
final class DataViewBuiltins
{
    public static function entries(): array
    {
        return [
            'DataView' => [self::class, 'callAsFunction'],
            'DataView.ctor' => [self::class, 'ctor'],
            'DataView.prototype.bufferGetter' => [self::class, 'bufferGetter'],
            'DataView.prototype.byteLengthGetter' => [self::class, 'byteLengthGetter'],
            'DataView.prototype.byteOffsetGetter' => [self::class, 'byteOffsetGetter'],
            'DataView.prototype.getInt8' => [self::class, 'getInt8'],
            'DataView.prototype.getUint8' => [self::class, 'getUint8'],
            'DataView.prototype.getInt16' => [self::class, 'getInt16'],
            'DataView.prototype.getUint16' => [self::class, 'getUint16'],
            'DataView.prototype.getInt32' => [self::class, 'getInt32'],
            'DataView.prototype.getUint32' => [self::class, 'getUint32'],
            'DataView.prototype.getFloat32' => [self::class, 'getFloat32'],
            'DataView.prototype.getFloat64' => [self::class, 'getFloat64'],
            'DataView.prototype.setInt8' => [self::class, 'setInt8'],
            'DataView.prototype.setUint8' => [self::class, 'setUint8'],
            'DataView.prototype.setInt16' => [self::class, 'setInt16'],
            'DataView.prototype.setUint16' => [self::class, 'setUint16'],
            'DataView.prototype.setInt32' => [self::class, 'setInt32'],
            'DataView.prototype.setUint32' => [self::class, 'setUint32'],
            'DataView.prototype.setFloat32' => [self::class, 'setFloat32'],
            'DataView.prototype.setFloat64' => [self::class, 'setFloat64'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach ([
            'buffer' => 'DataView.prototype.bufferGetter',
            'byteLength' => 'DataView.prototype.byteLengthGetter',
            'byteOffset' => 'DataView.prototype.byteOffsetGetter',
        ] as $name => $fnId) {
            $getter = $r->nativeFn($fnId, "get $name", 0);
            $proto->defineOwnAccessor($name, $getter, null, JSObject::C);
        }
        // .length counts required parameters only: byteOffset for every
        // getter (littleEndian is always optional, even where a kind has one
        // at all) and byteOffset+value for every setter.
        foreach (['Int8', 'Uint8', 'Int16', 'Uint16', 'Int32', 'Uint32', 'Float32', 'Float64'] as $t) {
            $r->defineMethod($proto, "get$t", "DataView.prototype.get$t", 1);
            $r->defineMethod($proto, "set$t", "DataView.prototype.set$t", 2);
        }
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('DataView', 'DataView', 1, 'DataView.ctor');
        $r->linkPair($ctor, $r->dataViewPrototype());
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        $vm->throwError('TypeError', "Constructor DataView requires 'new'");
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $bufArg = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        if (!$bufArg instanceof JSArrayBuffer) {
            $vm->throwError('TypeError', 'First argument to DataView constructor must be an ArrayBuffer');
        }
        $byteOffset = \array_key_exists(1, $args) && !$args[1] instanceof JSUndefined
            ? Conversions::toIndex($vm, $args[1]) : 0;
        // ToIndex(byteOffset) runs -- and can detach the buffer through a
        // valueOf side effect -- before this check, not after.
        if ($bufArg->detached) {
            $vm->throwError('TypeError', 'Cannot construct a DataView over a detached ArrayBuffer');
        }
        $bufLen = $bufArg->byteLength();
        if ($byteOffset > $bufLen) {
            $vm->throwError('RangeError', 'Start offset is outside the bounds of the buffer');
        }
        if (\array_key_exists(2, $args) && !$args[2] instanceof JSUndefined) {
            $byteLength = Conversions::toIndex($vm, $args[2]);
            if ($byteOffset + $byteLength > $bufLen) {
                $vm->throwError('RangeError', 'Invalid DataView length');
            }
        } else {
            $byteLength = $bufLen - $byteOffset;
        }
        return new JSDataView($vm->realm->dataViewPrototype(), $bufArg, $byteOffset, $byteLength);
    }

    private static function checkView(Vm $vm, mixed $t, string $method): JSDataView
    {
        if (!$t instanceof JSDataView) {
            $vm->throwError('TypeError', "$method called on an object that is not a DataView");
        }
        return $t;
    }

    public static function bufferGetter(Vm $vm, mixed $t): mixed
    {
        return self::checkView($vm, $t, 'get DataView.prototype.buffer')->buffer;
    }

    public static function byteLengthGetter(Vm $vm, mixed $t): mixed
    {
        $dv = self::checkView($vm, $t, 'get DataView.prototype.byteLength');
        if ($dv->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot read byteLength of a detached ArrayBuffer');
        }
        return $dv->byteLength;
    }

    public static function byteOffsetGetter(Vm $vm, mixed $t): mixed
    {
        $dv = self::checkView($vm, $t, 'get DataView.prototype.byteOffset');
        if ($dv->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot read byteOffset of a detached ArrayBuffer');
        }
        return $dv->byteOffset;
    }

    private static function littleEndianArg(array $args, int $pos): bool
    {
        return \array_key_exists($pos, $args) && Conversions::toBoolean($args[$pos]);
    }

    /**
     * The detached-then-bounds part of GetViewValue/SetViewValue (25.3.1.1 /
     * 25.3.1.2), split out from the ToIndex/value-conversion steps that come
     * before it: SetViewValue runs ToNumber(value) *before* this, and a
     * valueOf that detaches or throws must win over both of these checks.
     */
    private static function checkBounds(Vm $vm, JSDataView $dv, int $offset, int $size): void
    {
        if ($dv->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot access a detached ArrayBuffer');
        }
        if ($offset + $size > $dv->byteLength) {
            $vm->throwError('RangeError', 'Offset is outside the bounds of the DataView');
        }
    }

    /** Absolute byte offset into the buffer, for the getters (no value conversion in between). */
    private static function resolveOffset(Vm $vm, JSDataView $dv, mixed $arg, int $size): int
    {
        $offset = Conversions::toIndex($vm, $arg);
        self::checkBounds($vm, $dv, $offset, $size);
        return $dv->byteOffset + $offset;
    }

    private static function readUint(string $bytes, int $offset, int $size, bool $littleEndian): int
    {
        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            $shift = $littleEndian ? ($i * 8) : (($size - 1 - $i) * 8);
            $value |= \ord($bytes[$offset + $i]) << $shift;
        }
        return $value;
    }

    private static function writeUint(string &$bytes, int $offset, int $size, int $value, bool $littleEndian): void
    {
        for ($i = 0; $i < $size; $i++) {
            $shift = $littleEndian ? ($i * 8) : (($size - 1 - $i) * 8);
            $bytes[$offset + $i] = \chr(($value >> $shift) & 0xFF);
        }
    }

    private static function toSigned(int $u, int $size): int
    {
        $bits = $size * 8;
        $half = 1 << ($bits - 1);
        return $u >= $half ? $u - (1 << $bits) : $u;
    }

    private static function genericGet(Vm $vm, mixed $t, array $args, string $method, int $size, bool $signed): int
    {
        $dv = self::checkView($vm, $t, $method);
        $off = self::resolveOffset($vm, $dv, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, $size);
        $le = self::littleEndianArg($args, 1);
        $u = self::readUint($dv->buffer->bytes, $off, $size, $le);
        return $signed ? self::toSigned($u, $size) : $u;
    }

    /** @param callable(Vm, mixed): int $convert */
    private static function genericSet(Vm $vm, mixed $t, array $args, string $method, int $size, callable $convert): mixed
    {
        $dv = self::checkView($vm, $t, $method);
        // ToIndex(offset), then ToNumber(value) -- which can itself detach
        // the buffer or throw -- both run before the detached/bounds check.
        $offset = Conversions::toIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $value = $convert($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $le = self::littleEndianArg($args, 2);
        self::checkBounds($vm, $dv, $offset, $size);
        self::writeUint($dv->buffer->bytes, $dv->byteOffset + $offset, $size, $value, $le);
        return JSUndefined::$undefined;
    }

    public static function getInt8(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getInt8', 1, true);
    }

    public static function getUint8(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getUint8', 1, false);
    }

    public static function getInt16(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getInt16', 2, true);
    }

    public static function getUint16(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getUint16', 2, false);
    }

    public static function getInt32(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getInt32', 4, true);
    }

    public static function getUint32(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericGet($vm, $t, $args, 'getUint32', 4, false);
    }

    public static function setInt8(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setInt8', 1, [Conversions::class, 'toInt8']);
    }

    public static function setUint8(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setUint8', 1, [Conversions::class, 'toUint8']);
    }

    public static function setInt16(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setInt16', 2, [Conversions::class, 'toInt16']);
    }

    public static function setUint16(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setUint16', 2, [Conversions::class, 'toUint16']);
    }

    public static function setInt32(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setInt32', 4, [Conversions::class, 'toInt32']);
    }

    public static function setUint32(Vm $vm, mixed $t, array $args): mixed
    {
        return self::genericSet($vm, $t, $args, 'setUint32', 4, [Conversions::class, 'toUint32']);
    }

    public static function getFloat32(Vm $vm, mixed $t, array $args): mixed
    {
        $dv = self::checkView($vm, $t, 'getFloat32');
        $off = self::resolveOffset($vm, $dv, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, 4);
        $le = self::littleEndianArg($args, 1);
        return \unpack($le ? 'g' : 'G', $dv->buffer->bytes, $off)[1];
    }

    public static function getFloat64(Vm $vm, mixed $t, array $args): mixed
    {
        $dv = self::checkView($vm, $t, 'getFloat64');
        $off = self::resolveOffset($vm, $dv, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, 8);
        $le = self::littleEndianArg($args, 1);
        return \unpack($le ? 'e' : 'E', $dv->buffer->bytes, $off)[1];
    }

    private static function setFloatBytes(Vm $vm, mixed $t, array $args, int $size, string $leCode, string $beCode): mixed
    {
        $dv = self::checkView($vm, $t, $size === 4 ? 'setFloat32' : 'setFloat64');
        $offset = Conversions::toIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $value = Conversions::toNumber($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $le = self::littleEndianArg($args, 2);
        self::checkBounds($vm, $dv, $offset, $size);
        $off = $dv->byteOffset + $offset;
        $packed = \pack($le ? $leCode : $beCode, $value);
        for ($i = 0; $i < $size; $i++) {
            $dv->buffer->bytes[$off + $i] = $packed[$i];
        }
        return JSUndefined::$undefined;
    }

    public static function setFloat32(Vm $vm, mixed $t, array $args): mixed
    {
        return self::setFloatBytes($vm, $t, $args, 4, 'g', 'G');
    }

    public static function setFloat64(Vm $vm, mixed $t, array $args): mixed
    {
        return self::setFloatBytes($vm, $t, $args, 8, 'e', 'E');
    }
}

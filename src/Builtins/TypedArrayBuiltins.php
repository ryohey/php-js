<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArrayBuffer;
use PhpJs\Runtime\JSFunction;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSTypedArray;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\TypeOps;
use PhpJs\Vm\Vm;

/**
 * 23.2 %TypedArray% objects: one implementation for all nine kinds, which
 * differ only in element size and byte<->number conversion, both entirely
 * `JSTypedArray`'s own concern. `%TypedArray%.prototype` carries every
 * method here; each concrete kind's own prototype (`Int8Array.prototype`
 * etc.) inherits from it and adds only `BYTES_PER_ELEMENT` and its own
 * constructor link, mirroring how the Error family shares one prototype
 * chain and one `ErrorBuiltins::makePair`.
 */
final class TypedArrayBuiltins
{
    public static function entries(): array
    {
        $e = [
            '%TypedArray%' => [self::class, 'callAsFunction'],
            '%TypedArray%.ctor' => [self::class, 'abstractCtor'],
            '%TypedArray%.from' => [self::class, 'from'],
            '%TypedArray%.of' => [self::class, 'of'],
            '%TypedArray%.prototype.bufferGetter' => [self::class, 'bufferGetter'],
            '%TypedArray%.prototype.byteLengthGetter' => [self::class, 'byteLengthGetter'],
            '%TypedArray%.prototype.byteOffsetGetter' => [self::class, 'byteOffsetGetter'],
            '%TypedArray%.prototype.lengthGetter' => [self::class, 'lengthGetter'],
            '%TypedArray%.prototype.set' => [self::class, 'set'],
            '%TypedArray%.prototype.copyWithin' => [self::class, 'copyWithin'],
            '%TypedArray%.prototype.subarray' => [self::class, 'subarray'],
            '%TypedArray%.prototype.slice' => [self::class, 'slice'],
            '%TypedArray%.prototype.fill' => [self::class, 'fill'],
            '%TypedArray%.prototype.indexOf' => [self::class, 'indexOf'],
            '%TypedArray%.prototype.lastIndexOf' => [self::class, 'lastIndexOf'],
            '%TypedArray%.prototype.includes' => [self::class, 'includes'],
            '%TypedArray%.prototype.join' => [self::class, 'join'],
            '%TypedArray%.prototype.toLocaleString' => [self::class, 'toLocaleString'],
            '%TypedArray%.prototype.forEach' => [self::class, 'forEach'],
            '%TypedArray%.prototype.map' => [self::class, 'map'],
            '%TypedArray%.prototype.filter' => [self::class, 'filter'],
            '%TypedArray%.prototype.reduce' => [self::class, 'reduce'],
            '%TypedArray%.prototype.reduceRight' => [self::class, 'reduceRight'],
            '%TypedArray%.prototype.every' => [self::class, 'every'],
            '%TypedArray%.prototype.some' => [self::class, 'some'],
            '%TypedArray%.prototype.reverse' => [self::class, 'reverse'],
            '%TypedArray%.prototype.sort' => [self::class, 'sort'],
            '%TypedArray%.prototype.values' => [self::class, 'values'],
            '%TypedArray%.prototype.keys' => [self::class, 'keys'],
            '%TypedArray%.prototype.entries' => [self::class, 'entriesIterator'],
        ];
        foreach (JSTypedArray::KINDS as $kind) {
            $e["{$kind}Array"] = [self::class, 'callAsFunction'];
            $e["{$kind}Array.ctor"] = [self::class, 'ctor'];
        }
        return $e;
    }

    /** %TypedArray%.prototype: every method and accessor every kind shares. */
    public static function populateSharedProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'set', '%TypedArray%.prototype.set', 1);
        $r->defineMethod($proto, 'copyWithin', '%TypedArray%.prototype.copyWithin', 2);
        $r->defineMethod($proto, 'subarray', '%TypedArray%.prototype.subarray', 2);
        $r->defineMethod($proto, 'slice', '%TypedArray%.prototype.slice', 2);
        $r->defineMethod($proto, 'fill', '%TypedArray%.prototype.fill', 1);
        $r->defineMethod($proto, 'indexOf', '%TypedArray%.prototype.indexOf', 1);
        $r->defineMethod($proto, 'lastIndexOf', '%TypedArray%.prototype.lastIndexOf', 1);
        $r->defineMethod($proto, 'includes', '%TypedArray%.prototype.includes', 1);
        $r->defineMethod($proto, 'join', '%TypedArray%.prototype.join', 1);
        // 23.2.3.36: the exact same function object as Array.prototype.toString,
        // not just an equivalent implementation -- it works unchanged because
        // it looks up and calls `this.join` generically, and this prototype
        // has its own `join` for that lookup to find.
        $proto->defineOwnData('toString', $r->arrayPrototype()->props['toString'], JSObject::W | JSObject::C);
        $r->defineMethod($proto, 'toLocaleString', '%TypedArray%.prototype.toLocaleString', 0);
        $r->defineMethod($proto, 'forEach', '%TypedArray%.prototype.forEach', 1);
        $r->defineMethod($proto, 'map', '%TypedArray%.prototype.map', 1);
        $r->defineMethod($proto, 'filter', '%TypedArray%.prototype.filter', 1);
        $r->defineMethod($proto, 'reduce', '%TypedArray%.prototype.reduce', 1);
        $r->defineMethod($proto, 'reduceRight', '%TypedArray%.prototype.reduceRight', 1);
        $r->defineMethod($proto, 'every', '%TypedArray%.prototype.every', 1);
        $r->defineMethod($proto, 'some', '%TypedArray%.prototype.some', 1);
        $r->defineMethod($proto, 'reverse', '%TypedArray%.prototype.reverse', 0);
        $r->defineMethod($proto, 'sort', '%TypedArray%.prototype.sort', 1);
        $r->defineMethod($proto, 'keys', '%TypedArray%.prototype.keys', 0);
        $r->defineMethod($proto, 'entries', '%TypedArray%.prototype.entries', 0);
        $values = $r->nativeFn('%TypedArray%.prototype.values', 'values', 0);
        $proto->defineOwnData('values', $values, JSObject::W | JSObject::C);
        $proto->defineOwnData($r->wellKnownSymbol('iterator')->propertyKey, $values, JSObject::W | JSObject::C);

        foreach ([
            'buffer' => '%TypedArray%.prototype.bufferGetter',
            'byteLength' => '%TypedArray%.prototype.byteLengthGetter',
            'byteOffset' => '%TypedArray%.prototype.byteOffsetGetter',
            'length' => '%TypedArray%.prototype.lengthGetter',
        ] as $name => $fnId) {
            $getter = $r->nativeFn($fnId, "get $name", 0);
            $proto->defineOwnAccessor($name, $getter, null, JSObject::C);
        }
    }

    /** `{Kind}Array.prototype`/`{Kind}Array`: BYTES_PER_ELEMENT plus the constructor link, everything else inherited. */
    public static function makePair(Realm $r, string $kind): void
    {
        $bytes = JSTypedArray::bytesPerElement($kind);
        $proto = new JSObject($r->typedArrayPrototype());
        $proto->nativeId = "{$kind}Array.prototype";
        $proto->defineOwnData('BYTES_PER_ELEMENT', $bytes, 0);
        $r->remember("{$kind}Array.prototype", $proto);
        $ctor = $r->nativeFn("{$kind}Array", "{$kind}Array", 3, "{$kind}Array.ctor");
        $ctor->defineOwnData('BYTES_PER_ELEMENT', $bytes, 0);
        $r->linkPair($ctor, $proto);
        // 23.2.6.1: a concrete kind's [[Prototype]] is %TypedArray%, not
        // Function.prototype -- that is how `from`/`of`, defined once below,
        // reach every concrete constructor without being copied onto each.
        $ctor->proto = $r->typedArrayConstructorAbstract();
        $r->remember("{$kind}Array", $ctor);
    }

    /** `%TypedArray%`: `from`/`of`, inherited by every concrete kind through its [[Prototype]]. */
    public static function populateAbstractConstructor(Realm $r, JSNativeFunction $ctor): void
    {
        $r->defineMethod($ctor, 'from', '%TypedArray%.from', 1);
        $r->defineMethod($ctor, 'of', '%TypedArray%.of', 0);
    }

    private static function kindFromCtorId(string $ctorId): string
    {
        $name = substr($ctorId, 0, -\strlen('.ctor'));
        return substr($name, 0, -\strlen('Array'));
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $vm->throwError('TypeError', "Constructor {$fn?->name} requires 'new'");
    }

    /** %TypedArray% has [[Construct]] only so IsConstructor sees it as one; actually calling it is always an error. */
    public static function abstractCtor(Vm $vm, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $vm->throwError('TypeError', 'Abstract class TypedArray not directly constructable');
    }

    /** True when $v is usable as `new $v(...)` -- an arrow/generator/async function is not. */
    private static function isConstructorValue(mixed $v): bool
    {
        return ($v instanceof JSNativeFunction && $v->ctorId !== null)
            || ($v instanceof JSFunction && !$v->isArrow && !$v->isGenerator && !$v->isAsync);
    }

    /**
     * 23.2.2.1 %TypedArray%.from: like Array.from, but `this` must actually be
     * a constructor (there is no "just make a plain array" fallback), and
     * writes go through [[Set]] rather than CreateDataPropertyOrThrow -- which
     * is what makes an out-of-range or non-integer key a silent no-op instead
     * of an error, matching 10.4.5.13.
     */
    public static function from(Vm $vm, mixed $t, array $args): mixed
    {
        if (!self::isConstructorValue($t)) {
            $vm->throwError('TypeError', '%TypedArray%.from called on a non-constructor');
        }
        $source = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $mapFn = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        $mapping = !($mapFn instanceof JSUndefined);
        if ($mapping && !$mapFn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', '%TypedArray%.from: mapFn is not a function');
        }
        $thisArg = \array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined;

        $iterKey = $vm->realm->wellKnownSymbol('iterator')->propertyKey;
        $usingIterator = ($source === null || $source instanceof JSUndefined)
            ? JSUndefined::$undefined
            : $vm->getMember($source, $iterKey);

        if (!$usingIterator instanceof JSUndefined && $usingIterator !== null) {
            $values = $vm->iterateToList($source);
            $target = self::typedArrayCreate($vm, $t, \count($values));
            foreach ($values as $k => $v) {
                $mapped = $mapping ? $vm->invoke($mapFn, $thisArg, [$v, $k]) : $v;
                $target->set((string)$k, $mapped, $vm, true);
            }
            return $target;
        }

        $arrayLike = Conversions::toObject($vm, $source);
        $len = Conversions::toLength($vm, $arrayLike->get('length', $vm));
        $target = self::typedArrayCreate($vm, $t, $len);
        for ($k = 0; $k < $len; $k++) {
            $kValue = $arrayLike->get((string)$k, $vm);
            $mapped = $mapping ? $vm->invoke($mapFn, $thisArg, [$kValue, $k]) : $kValue;
            $target->set((string)$k, $mapped, $vm, true);
        }
        return $target;
    }

    /** 23.2.2.2 %TypedArray%.of */
    public static function of(Vm $vm, mixed $t, array $args): mixed
    {
        if (!self::isConstructorValue($t)) {
            $vm->throwError('TypeError', '%TypedArray%.of called on a non-constructor');
        }
        $target = self::typedArrayCreate($vm, $t, \count($args));
        foreach ($args as $k => $v) {
            $target->set((string)$k, $v, $vm, true);
        }
        return $target;
    }

    /** TypedArrayCreate(C, «len»): Construct(C, [len]), requiring the result actually be a typed array of that length. */
    private static function typedArrayCreate(Vm $vm, mixed $ctor, int $len): JSTypedArray
    {
        $target = $vm->construct($ctor, [$len]);
        if (!$target instanceof JSTypedArray) {
            $vm->throwError('TypeError', 'Constructor did not return a typed array');
        }
        if ($target->length < $len) {
            $vm->throwError('TypeError', 'Derived constructor created a typed array that is too small');
        }
        return $target;
    }

    /**
     * 23.2.5.1: the four overloads -- a length, another typed array (element
     * conversion, fresh buffer), an array-like or iterable (same), or a
     * buffer to view (with alignment/bounds checks, no copy at all).
     */
    public static function ctor(Vm $vm, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $kind = self::kindFromCtorId($fn?->ctorId ?? '');
        $proto = $vm->realm->typedArrayKindPrototype($kind);
        $elemSize = JSTypedArray::bytesPerElement($kind);
        $arg0 = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;

        if ($arg0 instanceof JSArrayBuffer) {
            // ToIndex on both offset and an explicit length runs before the
            // detached check -- either can detach the buffer through a
            // valueOf side effect, and that must still surface as the same
            // TypeError the check below throws, not a stale bounds error.
            $byteOffset = \array_key_exists(1, $args) && !$args[1] instanceof JSUndefined
                ? Conversions::toIndex($vm, $args[1]) : 0;
            if ($byteOffset % $elemSize !== 0) {
                $vm->throwError('RangeError', "start offset of {$kind}Array should be a multiple of $elemSize");
            }
            $hasLength = \array_key_exists(2, $args) && !$args[2] instanceof JSUndefined;
            $length = $hasLength ? Conversions::toIndex($vm, $args[2]) : 0;
            if ($arg0->detached) {
                $vm->throwError('TypeError', "Cannot construct a {$kind}Array over a detached ArrayBuffer");
            }
            $bufLen = $arg0->byteLength();
            if ($byteOffset > $bufLen) {
                $vm->throwError('RangeError', 'start offset is outside the bounds of the buffer');
            }
            if ($hasLength) {
                if ($byteOffset + $length * $elemSize > $bufLen) {
                    $vm->throwError('RangeError', 'Invalid typed array length');
                }
            } else {
                $remaining = $bufLen - $byteOffset;
                if ($remaining % $elemSize !== 0) {
                    $vm->throwError('RangeError', "byte length of {$kind}Array should be a multiple of $elemSize");
                }
                $length = \intdiv($remaining, $elemSize);
            }
            return new JSTypedArray($proto, $arg0, $byteOffset, $length, $kind);
        }

        if ($arg0 instanceof JSTypedArray) {
            $length = $arg0->length;
            $out = new JSTypedArray(
                $proto,
                JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $length * $elemSize),
                0,
                $length,
                $kind
            );
            for ($i = 0; $i < $length; $i++) {
                $out->writeElementRaw($i, $out->convert($vm, $arg0->readElement($i)));
            }
            return $out;
        }

        if ($arg0 instanceof JSObject) {
            // GetMethod (7.3.11): undefined/null means "not iterable", but any
            // other non-callable value is a TypeError, not a silent fall
            // through to the array-like path.
            $iterFn = $arg0->get($vm->realm->wellKnownSymbol('iterator')->propertyKey, $vm);
            if (!$iterFn instanceof JSFunctionBase && !$iterFn instanceof JSUndefined && $iterFn !== null) {
                $vm->throwError('TypeError', 'Symbol.iterator is not a function');
            }
            if ($iterFn instanceof JSFunctionBase) {
                $items = $vm->iterateToList($arg0);
            } else {
                $len = Conversions::toLength($vm, $arg0->get('length', $vm));
                // Bail before the loop, not after: an array-like with an
                // absurd `.length` (test262 covers this up to 2**53 - 1)
                // must not try to build a PHP array that large just to find
                // out the resulting buffer would be rejected anyway.
                if ($len * $elemSize > JSArrayBuffer::MAX_BYTE_LENGTH) {
                    $vm->throwError('RangeError', 'Invalid typed array length');
                }
                $items = [];
                for ($i = 0; $i < $len; $i++) {
                    $items[] = $arg0->get((string)$i, $vm);
                }
            }
            $length = \count($items);
            $out = new JSTypedArray(
                $proto,
                JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $length * $elemSize),
                0,
                $length,
                $kind
            );
            foreach (array_values($items) as $i => $v) {
                $out->writeElementRaw($i, $out->convert($vm, $v));
            }
            return $out;
        }

        $length = $arg0 instanceof JSUndefined ? 0 : Conversions::toIndex($vm, $arg0);
        return new JSTypedArray(
            $proto,
            JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $length * $elemSize),
            0,
            $length,
            $kind
        );
    }

    private static function checkInstance(Vm $vm, mixed $t, string $method): JSTypedArray
    {
        if (!$t instanceof JSTypedArray) {
            $vm->throwError('TypeError', "$method called on an object that is not a typed array");
        }
        return $t;
    }

    /**
     * ValidateTypedArray (23.2.3.1): checkInstance() plus a detached-buffer
     * check. Not every method wants this -- the buffer/byteLength/byteOffset/
     * length getters are deliberately lenient (23.2.3.2/.3/.4/.21 return +0
     * rather than throw) -- so this is only for the methods spec'd to
     * validate fully before doing anything else.
     */
    private static function validateTypedArray(Vm $vm, mixed $t, string $method): JSTypedArray
    {
        $ta = self::checkInstance($vm, $t, $method);
        if ($ta->buffer->detached) {
            $vm->throwError('TypeError', "$method called on a typed array with a detached buffer");
        }
        return $ta;
    }

    public static function bufferGetter(Vm $vm, mixed $t): mixed
    {
        return self::checkInstance($vm, $t, 'get TypedArray.prototype.buffer')->buffer;
    }

    /**
     * 23.2.3.16/.35/.8 (values/keys/entries): unlike Array's own versions,
     * these require `this` to actually be a typed array (ValidateTypedArray)
     * rather than coercing any array-like -- hence a thin wrapper around the
     * generic array-iterator factory instead of sharing its function object
     * with Array.prototype the way `values` also being `[Symbol.iterator]`
     * shares one function object *within* this class.
     */
    public static function values(Vm $vm, mixed $t): mixed
    {
        self::validateTypedArray($vm, $t, 'values');
        return IteratorBuiltins::arrayValues($vm, $t);
    }

    public static function keys(Vm $vm, mixed $t): mixed
    {
        self::validateTypedArray($vm, $t, 'keys');
        return IteratorBuiltins::arrayKeys($vm, $t);
    }

    public static function entriesIterator(Vm $vm, mixed $t): mixed
    {
        self::validateTypedArray($vm, $t, 'entries');
        return IteratorBuiltins::arrayEntries($vm, $t);
    }

    public static function byteLengthGetter(Vm $vm, mixed $t): mixed
    {
        $ta = self::checkInstance($vm, $t, 'get TypedArray.prototype.byteLength');
        return $ta->buffer->detached ? 0 : $ta->length * JSTypedArray::bytesPerElement($ta->kind);
    }

    public static function byteOffsetGetter(Vm $vm, mixed $t): mixed
    {
        $ta = self::checkInstance($vm, $t, 'get TypedArray.prototype.byteOffset');
        return $ta->buffer->detached ? 0 : $ta->byteOffset;
    }

    public static function lengthGetter(Vm $vm, mixed $t): mixed
    {
        $ta = self::checkInstance($vm, $t, 'get TypedArray.prototype.length');
        return $ta->buffer->detached ? 0 : $ta->length;
    }

    /**
     * 23.2.3.23: offset is converted before any detached check runs -- its
     * own ToIntegerOrInfinity can detach either buffer through a valueOf
     * side effect, and that must surface as this method's own TypeError
     * rather than a stale check earlier in the call. checkInstance() only,
     * not validateTypedArray(), so entry itself never front-runs that.
     */
    public static function set(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::checkInstance($vm, $t, 'set');
        $source = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $offsetNum = \array_key_exists(1, $args) ? Conversions::toInteger($vm, $args[1]) : 0.0;
        if ($offsetNum < 0) {
            $vm->throwError('RangeError', 'Offset is out of bounds');
        }
        if ($ta->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot set into a typed array with a detached buffer');
        }
        if ($source instanceof JSTypedArray && $source->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot set from a typed array with a detached buffer');
        }
        // (int)(+INF) is 0 in PHP, not a huge number -- caught here, on the
        // float, while it would otherwise look like a valid in-bounds 0.
        if ($offsetNum > $ta->length) {
            $vm->throwError('RangeError', 'Offset is out of bounds');
        }
        $offset = (int)$offsetNum;
        if ($source instanceof JSTypedArray) {
            if ($offset + $source->length > $ta->length) {
                $vm->throwError('RangeError', 'Offset is out of bounds');
            }
            // Read every source element before writing any -- set() must
            // still work when source and target share (or overlap) a
            // buffer, and this engine has no fast path that would corrupt
            // the read even without the precaution, but computing the whole
            // source list first keeps that true regardless of how either
            // side's storage is ever implemented later.
            $values = [];
            for ($i = 0; $i < $source->length; $i++) {
                $values[] = $ta->convert($vm, $source->readElement($i));
            }
            foreach ($values as $i => $v) {
                $ta->writeElementRaw($offset + $i, $v);
            }
            return JSUndefined::$undefined;
        }
        $obj = Conversions::toObject($vm, $source);
        $len = Conversions::toLength($vm, $obj->get('length', $vm));
        if ($offset + $len > $ta->length) {
            $vm->throwError('RangeError', 'Offset is out of bounds');
        }
        for ($i = 0; $i < $len; $i++) {
            $v = $obj->get((string)$i, $vm);
            $ta->writeElementRaw($offset + $i, $ta->convert($vm, $v));
        }
        return JSUndefined::$undefined;
    }

    /**
     * 23.2.3.4: read the whole source range before writing any of it back,
     * the same precaution `set()` takes -- target and start/end windows can
     * overlap within the same buffer, and reading through what a forward or
     * backward in-place copy would already have overwritten is exactly the
     * bug that precaution avoids.
     */
    public static function copyWithin(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'copyWithin');
        $len = $ta->length;
        $target = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, $len, 0);
        $start = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined, $len, 0);
        $end = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined, $len, $len);
        $count = min($end - $start, $len - $target);
        if ($count > 0) {
            $values = [];
            for ($i = 0; $i < $count; $i++) {
                $values[] = $ta->readElement($start + $i);
            }
            foreach ($values as $i => $v) {
                $ta->writeElementRaw($target + $i, $v);
            }
        }
        return $ta;
    }

    /**
     * 23.2.3.30: unlike most of this file, subarray does *not* call
     * ValidateTypedArray -- begin/end are still converted (observably, via
     * ToInteger) even against an already-detached buffer, and a detached
     * buffer only becomes a TypeError as a side effect of the species
     * constructor it feeds the result through (TypedArrayCreate ->
     * Construct(C, [buffer, byteOffset, length]), and *that* constructor
     * checks). Species itself is out of scope here (see excluded-features),
     * but the detached check its construction step would have made is
     * cheap to reproduce directly, after begin/end rather than before.
     */
    public static function subarray(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::checkInstance($vm, $t, 'subarray');
        $len = $ta->length;
        $start = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, $len, 0);
        $end = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined, $len, $len);
        if ($ta->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot construct a typed array over a detached ArrayBuffer');
        }
        $newLen = max($end - $start, 0);
        $elemSize = JSTypedArray::bytesPerElement($ta->kind);
        return new JSTypedArray($ta->proto, $ta->buffer, $ta->byteOffset + $start * $elemSize, $newLen, $ta->kind);
    }

    public static function slice(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'slice');
        $len = $ta->length;
        $start = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined, $len, 0);
        $end = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined, $len, $len);
        $newLen = max($end - $start, 0);
        $elemSize = JSTypedArray::bytesPerElement($ta->kind);
        $out = new JSTypedArray(
            $ta->proto,
            JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $newLen * $elemSize),
            0,
            $newLen,
            $ta->kind
        );
        for ($i = 0; $i < $newLen; $i++) {
            $out->writeElementRaw($i, $ta->readElement($start + $i));
        }
        return $out;
    }

    /** 23.2.3.8: value/start/end are each converted after ValidateTypedArray, and any one of them can detach the buffer through a valueOf side effect -- re-checked before the write loop, not just at entry. */
    public static function fill(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'fill');
        $len = $ta->length;
        $value = $ta->convert($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $start = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined, $len, 0);
        $end = ArrayBufferBuiltins::relativeIndex($vm, \array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined, $len, $len);
        if ($ta->buffer->detached) {
            $vm->throwError('TypeError', 'Cannot fill a typed array with a detached buffer');
        }
        for ($i = $start; $i < $end; $i++) {
            $ta->writeElementRaw($i, $value);
        }
        return $ta;
    }

    public static function indexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'indexOf');
        $len = $ta->length;
        if ($len === 0) {
            return -1;
        }
        $needle = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $from = self::clampFromIndex($vm, $args, 1, 0, $len);
        if ($from >= $len) {
            return -1;
        }
        for ($i = $from; $i < $len; $i++) {
            if (TypeOps::strictEquals($ta->readElement($i), $needle)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * fromIndex, ascending (indexOf/includes): ToInteger, clamped into
     * [0, len] with a negative value counted back from the end -- computed
     * on the float, before any cast to int, since (int)INF is 0 in PHP, not
     * a value past the end the way +Infinity must clamp to `len`.
     */
    private static function clampFromIndex(Vm $vm, array $args, int $pos, int $default, int $len): int
    {
        if (!\array_key_exists($pos, $args)) {
            return $default;
        }
        $n = Conversions::toInteger($vm, $args[$pos]);
        if ($n >= $len) {
            return $len;
        }
        if ($n < 0) {
            $n += $len;
        }
        return (int)max($n, 0);
    }

    /** fromIndex, descending (lastIndexOf): same +Infinity/negative-cast hazard, clamped into [-1, len - 1] instead. */
    private static function clampFromIndexDescending(Vm $vm, array $args, int $pos, int $default, int $len): int
    {
        if (!\array_key_exists($pos, $args)) {
            return $default;
        }
        $n = Conversions::toInteger($vm, $args[$pos]);
        if (is_nan($n)) {
            return -1;
        }
        if ($n >= 0) {
            return (int)min($n, $len - 1);
        }
        $n += $len;
        return $n < 0 ? -1 : (int)$n;
    }

    public static function lastIndexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'lastIndexOf');
        $len = $ta->length;
        if ($len === 0) {
            return -1;
        }
        $needle = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $from = self::clampFromIndexDescending($vm, $args, 1, $len - 1, $len);
        if ($from < 0) {
            return -1;
        }
        for ($i = $from; $i >= 0; $i--) {
            if (TypeOps::strictEquals($ta->readElement($i), $needle)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * SameValueZero, unlike indexOf's strict equals: `includes(NaN)` can be
     * true. Both sides are coerced to float for the zero check -- an integer
     * kind's readElement() returns a plain int 0 with no sign of its own,
     * which must still count as the same zero as a float -0.0 needle.
     */
    private static function sameValueZero(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) && (float)$a == 0.0 && (float)$b == 0.0) {
            return true;
        }
        return TypeOps::sameValue($a, $b);
    }

    public static function includes(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'includes');
        $len = $ta->length;
        if ($len === 0) {
            return false;
        }
        $needle = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $from = self::clampFromIndex($vm, $args, 1, 0, $len);
        for ($i = $from; $i < $len; $i++) {
            if (self::sameValueZero($ta->readElement($i), $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function join(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'join');
        $sepArg = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $sep = $sepArg instanceof JSUndefined ? ',' : Conversions::toString($vm, $sepArg);
        $parts = [];
        for ($i = 0; $i < $ta->length; $i++) {
            $parts[] = Conversions::numberToString($ta->readElement($i));
        }
        return implode($sep, $parts);
    }

    /** Elements are always numbers, never holes -- no per-element undefined/null case to special-case, unlike Array's. */
    /** Calls Number.prototype.toLocaleString on each element, not a numberToString shortcut -- an override there must be observed. */
    public static function toLocaleString(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'toLocaleString');
        $parts = [];
        for ($i = 0; $i < $ta->length; $i++) {
            $v = $ta->readElement($i);
            $fn = Conversions::toObject($vm, $v)->get('toLocaleString', $vm);
            if (!$fn instanceof JSFunctionBase) {
                $vm->throwError('TypeError', 'toLocaleString is not callable');
            }
            $parts[] = Conversions::toString($vm, $vm->invoke($fn, $v, []));
        }
        return implode(',', $parts);
    }

    private static function callbackOf(Vm $vm, array $args, string $method): JSFunctionBase
    {
        $fn = $args[0] ?? null;
        if (!$fn instanceof JSFunctionBase) {
            $vm->throwError('TypeError', ($fn === null ? 'undefined' : TypeOps::typeofOp($fn)) . ' is not a function');
        }
        return $fn;
    }

    public static function forEach(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'forEach');
        $fn = self::callbackOf($vm, $args, 'forEach');
        $thisArg = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        for ($i = 0; $i < $ta->length; $i++) {
            $vm->invoke($fn, $thisArg, [$ta->readElement($i), $i, $ta]);
        }
        return JSUndefined::$undefined;
    }

    public static function map(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'map');
        $fn = self::callbackOf($vm, $args, 'map');
        $thisArg = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        $elemSize = JSTypedArray::bytesPerElement($ta->kind);
        $out = new JSTypedArray(
            $ta->proto,
            JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $ta->length * $elemSize),
            0,
            $ta->length,
            $ta->kind
        );
        for ($i = 0; $i < $ta->length; $i++) {
            $r = $vm->invoke($fn, $thisArg, [$ta->readElement($i), $i, $ta]);
            $out->writeElementRaw($i, $out->convert($vm, $r));
        }
        return $out;
    }

    public static function filter(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'filter');
        $fn = self::callbackOf($vm, $args, 'filter');
        $thisArg = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        $kept = [];
        for ($i = 0; $i < $ta->length; $i++) {
            $v = $ta->readElement($i);
            if (Conversions::toBoolean($vm->invoke($fn, $thisArg, [$v, $i, $ta]))) {
                $kept[] = $v;
            }
        }
        $n = \count($kept);
        $elemSize = JSTypedArray::bytesPerElement($ta->kind);
        $out = new JSTypedArray(
            $ta->proto,
            JSArrayBuffer::allocate($vm, $vm->realm->arrayBufferPrototype(), $n * $elemSize),
            0,
            $n,
            $ta->kind
        );
        foreach (array_values($kept) as $i => $v) {
            $out->writeElementRaw($i, $v);
        }
        return $out;
    }

    public static function reduce(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'reduce');
        $fn = self::callbackOf($vm, $args, 'reduce');
        $len = $ta->length;
        $i = 0;
        if (\array_key_exists(1, $args)) {
            $acc = $args[1];
        } else {
            if ($len === 0) {
                $vm->throwError('TypeError', 'Reduce of empty array with no initial value');
            }
            $acc = $ta->readElement(0);
            $i = 1;
        }
        for (; $i < $len; $i++) {
            $acc = $vm->invoke($fn, JSUndefined::$undefined, [$acc, $ta->readElement($i), $i, $ta]);
        }
        return $acc;
    }

    public static function reduceRight(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'reduceRight');
        $fn = self::callbackOf($vm, $args, 'reduceRight');
        $len = $ta->length;
        $i = $len - 1;
        if (\array_key_exists(1, $args)) {
            $acc = $args[1];
        } else {
            if ($len === 0) {
                $vm->throwError('TypeError', 'Reduce of empty array with no initial value');
            }
            $acc = $ta->readElement($i);
            $i--;
        }
        for (; $i >= 0; $i--) {
            $acc = $vm->invoke($fn, JSUndefined::$undefined, [$acc, $ta->readElement($i), $i, $ta]);
        }
        return $acc;
    }

    public static function every(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'every');
        $fn = self::callbackOf($vm, $args, 'every');
        $thisArg = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        for ($i = 0; $i < $ta->length; $i++) {
            if (!Conversions::toBoolean($vm->invoke($fn, $thisArg, [$ta->readElement($i), $i, $ta]))) {
                return false;
            }
        }
        return true;
    }

    public static function some(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'some');
        $fn = self::callbackOf($vm, $args, 'some');
        $thisArg = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        for ($i = 0; $i < $ta->length; $i++) {
            if (Conversions::toBoolean($vm->invoke($fn, $thisArg, [$ta->readElement($i), $i, $ta]))) {
                return true;
            }
        }
        return false;
    }

    public static function reverse(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'reverse');
        for ($i = 0, $j = $ta->length - 1; $i < $j; $i++, $j--) {
            $a = $ta->readElement($i);
            $b = $ta->readElement($j);
            $ta->writeElementRaw($i, $b);
            $ta->writeElementRaw($j, $a);
        }
        return $ta;
    }

    public static function sort(Vm $vm, mixed $t, array $args): mixed
    {
        $ta = self::validateTypedArray($vm, $t, 'sort');
        $comparator = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        if (!$comparator instanceof JSUndefined && !$comparator instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'The comparison function must be either a function or undefined');
        }
        $values = [];
        for ($i = 0; $i < $ta->length; $i++) {
            $values[] = $ta->readElement($i);
        }
        if ($comparator instanceof JSFunctionBase) {
            usort($values, static function ($a, $b) use ($vm, $comparator) {
                $r = Conversions::toNumber($vm, $vm->invoke($comparator, JSUndefined::$undefined, [$a, $b]));
                return $r < 0 ? -1 : ($r > 0 ? 1 : 0);
            });
        } else {
            // The default comparator is numeric ascending with NaN sorted to
            // the end -- unlike Array.prototype.sort's default, which
            // stringifies first (22.2.3.28.2). -0 sorts before +0, which
            // PHP's `<=>` does not do on its own (-0.0 <=> 0.0 is 0, since
            // PHP's own numeric comparison does not distinguish the sign of
            // zero the way this comparator is spec'd to).
            usort($values, static function ($a, $b) {
                $aNan = is_float($a) && is_nan($a);
                $bNan = is_float($b) && is_nan($b);
                if ($aNan) {
                    return $bNan ? 0 : 1;
                }
                if ($bNan) {
                    return -1;
                }
                if ($a == 0.0 && $b == 0.0) {
                    $aNeg = \fdiv(1, (float)$a) < 0;
                    $bNeg = \fdiv(1, (float)$b) < 0;
                    if ($aNeg !== $bNeg) {
                        return $aNeg ? -1 : 1;
                    }
                    return 0;
                }
                return $a <=> $b;
            });
        }
        foreach ($values as $i => $v) {
            $ta->writeElementRaw($i, $v);
        }
        return $ta;
    }
}

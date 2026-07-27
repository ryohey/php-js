<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class ErrorBuiltins
{
    public const KINDS = ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError'];

    public static function entries(): array
    {
        $e = [
            'Error.prototype.toString' => [self::class, 'toStringMethod'],
        ];
        foreach (self::KINDS as $kind) {
            $e[$kind] = [self::class, 'callAsFunction'];
            $e["$kind.ctor"] = [self::class, 'ctor'];
        }
        return $e;
    }

    /** Build the ctor/prototype pair for one error kind and register both. */
    public static function makePair(Realm $r, string $kind): void
    {
        $parentProto = $kind === 'Error' ? $r->objectPrototype() : $r->errorPrototype('Error');
        $proto = new JSObject($parentProto);
        $proto->nativeId = "$kind.prototype";
        $proto->className = 'Error';
        $proto->defineOwnData('name', $kind, JSObject::W | JSObject::C);
        $proto->defineOwnData('message', '', JSObject::W | JSObject::C);
        if ($kind === 'Error') {
            $r->defineMethod($proto, 'toString', 'Error.prototype.toString', 0);
        }
        $r->remember("$kind.prototype", $proto);
        $ctor = $r->nativeFn($kind, $kind, 1, "$kind.ctor");
        $r->linkPair($ctor, $proto);
        $r->remember($kind, $ctor);
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args, mixed $fn = null): mixed
    {
        $kind = $fn !== null ? $fn->name : 'Error';
        return self::make($vm, $kind, $args);
    }

    public static function ctor(Vm $vm, array $args, mixed $fn = null): mixed
    {
        $kind = $fn !== null ? preg_replace('/\.ctor$/', '', $fn->ctorId ?? 'Error') : 'Error';
        return self::make($vm, $kind, $args);
    }

    private static function make(Vm $vm, string $kind, array $args): JSObject
    {
        $err = new JSObject($vm->realm->errorPrototype($kind));
        $err->className = 'Error';
        $msg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$msg instanceof JSUndefined) {
            $err->defineOwnData('message', Conversions::toString($vm, $msg), JSObject::W | JSObject::C);
        }
        $err->defineOwnData(
            'stack',
            $kind . (isset($err->props['message']) ? ': ' . $err->props['message'] : '') . "\n" . $vm->captureStack(),
            JSObject::W | JSObject::C
        );
        return $err;
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSObject) {
            $vm->throwError('TypeError', 'Error.prototype.toString called on non-object');
        }
        $name = $t->get('name', $vm);
        $name = $name instanceof JSUndefined ? 'Error' : Conversions::toString($vm, $name);
        $msg = $t->get('message', $vm);
        $msg = $msg instanceof JSUndefined ? '' : Conversions::toString($vm, $msg);
        if ($msg === '') {
            return $name;
        }
        return $name === '' ? $msg : "$name: $msg";
    }
}

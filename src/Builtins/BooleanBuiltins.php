<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class BooleanBuiltins
{
    public static function entries(): array
    {
        return [
            'Boolean' => [self::class, 'callAsFunction'],
            'Boolean.ctor' => [self::class, 'ctor'],
            'Boolean.prototype.toString' => [self::class, 'toStringMethod'],
            'Boolean.prototype.valueOf' => [self::class, 'valueOf'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'toString', 'Boolean.prototype.toString', 0);
        $r->defineMethod($proto, 'valueOf', 'Boolean.prototype.valueOf', 0);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Boolean', 'Boolean', 1, 'Boolean.ctor');
        $r->linkPair($ctor, $r->booleanPrototype());
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return Conversions::toBoolean($args[0] ?? false);
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        return new JSPrimitiveWrapper(
            Conversions::toBoolean($args[0] ?? false),
            'Boolean',
            $vm->realm->booleanPrototype()
        );
    }

    private static function thisBoolean(Vm $vm, mixed $t): bool
    {
        if (is_bool($t)) {
            return $t;
        }
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'Boolean') {
            return $t->primitiveValue;
        }
        $vm->throwError('TypeError', 'Boolean.prototype method called on incompatible receiver');
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisBoolean($vm, $t) ? 'true' : 'false';
    }

    public static function valueOf(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisBoolean($vm, $t);
    }
}

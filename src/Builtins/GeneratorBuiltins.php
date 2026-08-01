<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\JSGeneratorObject;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Op;
use PhpJs\Vm\Vm;

/**
 * %GeneratorPrototype% (27.5.1): `next`, `throw`, `return`. The state
 * machine and frame suspend/resume live on `Vm::resumeGenerator()`; this is
 * just the three entry points and CreateIterResultObject packaging.
 */
final class GeneratorBuiltins
{
    public static function entries(): array
    {
        return [
            '%GeneratorPrototype%.next' => [self::class, 'next'],
            '%GeneratorPrototype%.throw' => [self::class, 'throwMethod'],
            '%GeneratorPrototype%.return' => [self::class, 'returnMethod'],
        ];
    }

    public static function populateGeneratorProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'next', '%GeneratorPrototype%.next', 1);
        $r->defineMethod($proto, 'throw', '%GeneratorPrototype%.throw', 1);
        $r->defineMethod($proto, 'return', '%GeneratorPrototype%.return', 1);
        $proto->defineOwnData($r->wellKnownSymbol('toStringTag')->propertyKey, 'Generator', JSObject::C);
    }

    private static function checkGenerator(Vm $vm, mixed $thisVal, string $method): JSGeneratorObject
    {
        if (!$thisVal instanceof JSGeneratorObject) {
            $vm->throwError('TypeError', "$method called on an object that is not a Generator");
        }
        return $thisVal;
    }

    public static function next(Vm $vm, mixed $thisVal, array $args): mixed
    {
        $gen = self::checkGenerator($vm, $thisVal, 'next');
        [$value, $done] = $vm->resumeGenerator($gen, Op::YIELD_NEXT, $args[0] ?? \PhpJs\Runtime\JSUndefined::$undefined);
        return IteratorBuiltins::result($vm, $value, $done);
    }

    public static function throwMethod(Vm $vm, mixed $thisVal, array $args): mixed
    {
        $gen = self::checkGenerator($vm, $thisVal, 'throw');
        [$value, $done] = $vm->resumeGenerator($gen, Op::YIELD_THROW, $args[0] ?? \PhpJs\Runtime\JSUndefined::$undefined);
        return IteratorBuiltins::result($vm, $value, $done);
    }

    public static function returnMethod(Vm $vm, mixed $thisVal, array $args): mixed
    {
        $gen = self::checkGenerator($vm, $thisVal, 'return');
        [$value, $done] = $vm->resumeGenerator($gen, Op::YIELD_RETURN, $args[0] ?? \PhpJs\Runtime\JSUndefined::$undefined);
        return IteratorBuiltins::result($vm, $value, $done);
    }
}

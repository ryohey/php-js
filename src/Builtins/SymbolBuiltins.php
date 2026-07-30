<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSSymbol;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * `Symbol`, the constructor that is not one.
 *
 * See JSSymbol for why the engine owns this type rather than node-compat
 * polyfilling it, and for what the well-known symbols do and do not mean here.
 */
final class SymbolBuiltins
{
    /**
     * Well-known symbols, created so code can use them as keys and compare
     * them. None of them changes engine behaviour — this is an ES5.1 realm with
     * no iteration protocol and no `@@species` (DESIGN.md §15) — but their
     * absence is what breaks feature detection, not their inertness.
     */
    public const WELL_KNOWN = [
        'iterator', 'asyncIterator', 'hasInstance', 'isConcatSpreadable',
        'match', 'replace', 'search', 'split', 'species', 'toPrimitive',
        'toStringTag', 'unscopables',
    ];

    public static function entries(): array
    {
        return [
            'Symbol' => [self::class, 'callAsFunction'],
            'Symbol.for' => [self::class, 'symbolFor'],
            'Symbol.keyFor' => [self::class, 'keyFor'],
            'Symbol.prototype.toString' => [self::class, 'toStringMethod'],
            'Symbol.prototype.valueOf' => [self::class, 'valueOf'],
            'Symbol.prototype.description' => [self::class, 'description'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'toString', 'Symbol.prototype.toString', 0);
        $r->defineMethod($proto, 'valueOf', 'Symbol.prototype.valueOf', 0);
        $proto->defineOwnAccessor(
            'description',
            $r->nativeFn('Symbol.prototype.description', 'get description', 0),
            null,
            JSObject::C
        );
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        // No ctorId: `new Symbol()` is a TypeError, which is what a native
        // function without a [[Construct]] already does.
        $ctor = $r->nativeFn('Symbol', 'Symbol', 0);
        $r->linkPair($ctor, $r->symbolPrototype());
        $r->defineMethod($ctor, 'for', 'Symbol.for', 1);
        $r->defineMethod($ctor, 'keyFor', 'Symbol.keyFor', 1);
        foreach (self::WELL_KNOWN as $name) {
            // Non-writable, non-configurable, like the spec's own.
            $ctor->defineOwnData($name, $r->wellKnownSymbol($name), 0);
        }
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        $arg = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $description = $arg instanceof JSUndefined ? null : Conversions::toString($vm, $arg);
        return $vm->realm->newSymbol($description);
    }

    /** The cross-realm-in-name-only registry: one table per realm. */
    public static function symbolFor(Vm $vm, mixed $t, array $args): mixed
    {
        $key = Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined);
        return $vm->realm->symbolForKey($key);
    }

    public static function keyFor(Vm $vm, mixed $t, array $args): mixed
    {
        $arg = $args[0] ?? JSUndefined::$undefined;
        if (!$arg instanceof JSSymbol) {
            $vm->throwError('TypeError', 'Symbol.keyFor requires a symbol');
        }
        return $arg->registryKey ?? JSUndefined::$undefined;
    }

    private static function thisSymbol(Vm $vm, mixed $t): JSSymbol
    {
        if ($t instanceof JSSymbol) {
            return $t;
        }
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'Symbol') {
            return $t->primitiveValue;
        }
        $vm->throwError('TypeError', 'Symbol.prototype method called on incompatible receiver');
    }

    /**
     * The one place a symbol becomes a string. Implicit conversion throws
     * (see Conversions::toString), so this and `String(sym)` are the only ways
     * to get one -- which is the point of the rule.
     */
    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisSymbol($vm, $t)->display();
    }

    public static function valueOf(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisSymbol($vm, $t);
    }

    public static function description(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisSymbol($vm, $t)->description ?? JSUndefined::$undefined;
    }
}

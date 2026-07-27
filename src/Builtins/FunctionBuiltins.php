<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Compiler\Compiler;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSBoundFunction;
use PhpJs\Runtime\JSFunction;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

final class FunctionBuiltins
{
    public static function entries(): array
    {
        return [
            'Function.prototype' => [self::class, 'protoCall'],
            'Function' => [self::class, 'callAsFunction'],
            'Function.ctor' => [self::class, 'ctor'],
            'Function.prototype.call' => [self::class, 'call'],
            'Function.prototype.apply' => [self::class, 'apply'],
            'Function.prototype.bind' => [self::class, 'bind'],
            'Function.prototype.toString' => [self::class, 'toString'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'call', 'Function.prototype.call', 1);
        $r->defineMethod($proto, 'apply', 'Function.prototype.apply', 2);
        $r->defineMethod($proto, 'bind', 'Function.prototype.bind', 1);
        $r->defineMethod($proto, 'toString', 'Function.prototype.toString', 0);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Function', 'Function', 1, 'Function.ctor');
        $r->linkPair($ctor, $r->functionPrototype());
        return $ctor;
    }

    /** Function.prototype itself is callable and returns undefined. */
    public static function protoCall(Vm $vm, mixed $t, array $args): mixed
    {
        return JSUndefined::$undefined;
    }

    /** Function(...) called without new behaves like the constructor. */
    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return self::ctor($vm, $args);
    }

    /**
     * The Function constructor: compiles at runtime through Peast + the
     * compiler. Never rides opcache — accepted for its low frequency
     * (DESIGN.md §15).
     */
    public static function ctor(Vm $vm, array $args = []): mixed
    {
        $params = [];
        $n = count($args);
        for ($i = 0; $i < $n - 1; $i++) {
            $params[] = Conversions::toString($vm, $args[$i]);
        }
        $bodySrc = $n > 0 ? Conversions::toString($vm, $args[$n - 1]) : '';
        $src = '(function anonymous(' . implode(',', $params) . "\n) {\n" . $bodySrc . "\n})";
        try {
            $tpl = Compiler::compile($src);
        } catch (\Throwable $e) {
            $vm->throwError('SyntaxError', $e->getMessage());
        }
        // The program evaluates to the function expression; run it.
        return $vm->runProgram($tpl);
    }

    public static function call(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Function.prototype.call called on non-function');
        }
        $thisArg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        return $vm->invoke($t, $thisArg, array_slice($args, 1));
    }

    public static function apply(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Function.prototype.apply called on non-function');
        }
        $thisArg = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $argArray = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        if ($argArray === null || $argArray instanceof JSUndefined) {
            $callArgs = [];
        } elseif ($argArray instanceof JSArray) {
            $callArgs = $argArray->toList();
        } elseif ($argArray instanceof JSObject) {
            $len = Conversions::toUint32($vm, $argArray->get('length', $vm));
            $callArgs = [];
            for ($i = 0; $i < $len; $i++) {
                $callArgs[] = $argArray->get((string)$i, $vm);
            }
        } else {
            $vm->throwError('TypeError', 'CreateListFromArrayLike called on non-object');
        }
        return $vm->invoke($t, $thisArg, $callArgs);
    }

    public static function bind(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Function.prototype.bind called on non-function');
        }
        return new JSBoundFunction(
            $t,
            (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined),
            array_slice($args, 1),
            $vm->realm->functionPrototype()
        );
    }

    public static function toString(Vm $vm, mixed $t, array $args): mixed
    {
        if ($t instanceof JSFunction) {
            return 'function ' . $t->name . '() { [bytecode] }';
        }
        if ($t instanceof JSFunctionBase) {
            return 'function ' . $t->name . '() { [native code] }';
        }
        $vm->throwError('TypeError', 'Function.prototype.toString called on non-function');
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPromise;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Native Promise implementation (DESIGN.md §9): then-chain resolution runs in
 * PHP; only user callbacks re-enter the VM. Per-promise capabilities are
 * JSNativeFunction instances whose $data points at the promise (heap-safe).
 */
final class PromiseBuiltins
{
    public static function entries(): array
    {
        return [
            'Promise' => [self::class, 'callAsFunction'],
            'Promise.ctor' => [self::class, 'ctor'],
            'Promise.resolve' => [self::class, 'staticResolve'],
            'Promise.reject' => [self::class, 'staticReject'],
            'Promise.all' => [self::class, 'all'],
            'Promise.race' => [self::class, 'race'],
            'Promise.prototype.then' => [self::class, 'then'],
            'Promise.prototype.catch' => [self::class, 'catchMethod'],
            'Promise.resolveFn' => [self::class, 'resolveFn'],
            'Promise.rejectFn' => [self::class, 'rejectFn'],
            'Promise.reactionJob' => [self::class, 'reactionJob'],
            'Promise.thenableJob' => [self::class, 'thenableJob'],
            'Promise.allElementFn' => [self::class, 'allElementFn'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'then', 'Promise.prototype.then', 2);
        $r->defineMethod($proto, 'catch', 'Promise.prototype.catch', 1);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Promise', 'Promise', 1, 'Promise.ctor');
        $r->linkPair($ctor, $r->promisePrototype());
        $r->defineMethod($ctor, 'resolve', 'Promise.resolve', 1);
        $r->defineMethod($ctor, 'reject', 'Promise.reject', 1);
        $r->defineMethod($ctor, 'all', 'Promise.all', 1);
        $r->defineMethod($ctor, 'race', 'Promise.race', 1);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        $vm->throwError('TypeError', "Promise constructor cannot be invoked without 'new'");
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $executor = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$executor instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Promise resolver is not a function');
        }
        $p = new JSPromise($vm->realm->promisePrototype());
        [$resolveFn, $rejectFn] = self::capabilities($vm, $p);
        try {
            $vm->invoke($executor, JSUndefined::$undefined, [$resolveFn, $rejectFn]);
        } catch (JSThrowSignal $e) {
            self::rejectPromise($vm, $p, $e->value);
        }
        return $p;
    }

    /** @return array{0: JSNativeFunction, 1: JSNativeFunction} */
    private static function capabilities(Vm $vm, JSPromise $p): array
    {
        return [
            $vm->realm->nativeFn('Promise.resolveFn', '', 1, null, $p),
            $vm->realm->nativeFn('Promise.rejectFn', '', 1, null, $p),
        ];
    }

    public static function resolveFn(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $p = $fn?->data;
        if ($p instanceof JSPromise && !$p->alreadyResolved) {
            $p->alreadyResolved = true;
            self::resolvePromise($vm, $p, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        }
        return JSUndefined::$undefined;
    }

    public static function rejectFn(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $p = $fn?->data;
        if ($p instanceof JSPromise && !$p->alreadyResolved) {
            $p->alreadyResolved = true;
            self::rejectPromise($vm, $p, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        }
        return JSUndefined::$undefined;
    }

    public static function resolvePromise(Vm $vm, JSPromise $p, mixed $value): void
    {
        if ($value === $p) {
            self::rejectPromise($vm, $p, $vm->realm->createError('TypeError', 'Chaining cycle detected'));
            return;
        }
        if ($value instanceof JSObject) {
            try {
                $then = $value->get('then', $vm);
            } catch (JSThrowSignal $e) {
                self::rejectPromise($vm, $p, $e->value);
                return;
            }
            if ($then instanceof JSFunctionBase) {
                $job = $vm->realm->nativeFn('Promise.thenableJob', '', 0, null, [$p, $value, $then]);
                $vm->realm->enqueueMicrotask($job, []);
                return;
            }
        }
        self::settle($vm, $p, JSPromise::FULFILLED, $value);
    }

    public static function thenableJob(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        [$p, $thenable, $then] = $fn->data;
        $p->alreadyResolved = false; // capabilities below take over resolution
        [$resolveFn, $rejectFn] = self::capabilities($vm, $p);
        try {
            $vm->invoke($then, $thenable, [$resolveFn, $rejectFn]);
        } catch (JSThrowSignal $e) {
            if (!$p->alreadyResolved) {
                $p->alreadyResolved = true;
                self::rejectPromise($vm, $p, $e->value);
            }
        }
        return JSUndefined::$undefined;
    }

    public static function rejectPromise(Vm $vm, JSPromise $p, mixed $reason): void
    {
        self::settle($vm, $p, JSPromise::REJECTED, $reason);
    }

    private static function settle(Vm $vm, JSPromise $p, int $state, mixed $result): void
    {
        if ($p->state !== JSPromise::PENDING) {
            return;
        }
        $p->state = $state;
        $p->result = $result;
        foreach ($p->reactions as $reaction) {
            self::enqueueReaction($vm, $p, $reaction);
        }
        $p->reactions = [];
        if ($state === JSPromise::REJECTED && !$p->handled) {
            $vm->realm->unhandledRejections[] = $p;
        }
    }

    /** @param array{0: mixed, 1: mixed, 2: JSPromise} $reaction */
    private static function enqueueReaction(Vm $vm, JSPromise $p, array $reaction): void
    {
        $handler = $p->state === JSPromise::FULFILLED ? $reaction[0] : $reaction[1];
        $job = $vm->realm->nativeFn('Promise.reactionJob', '', 0, null, [
            $handler, $reaction[2], $p->state,
        ]);
        $vm->realm->enqueueMicrotask($job, [$p->result]);
    }

    public static function reactionJob(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        [$handler, $chained, $state] = $fn->data;
        $value = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!$handler instanceof JSFunctionBase) {
            // Pass-through reaction.
            if ($state === JSPromise::FULFILLED) {
                self::resolvePromise($vm, $chained, $value);
            } else {
                self::rejectPromise($vm, $chained, $value);
            }
            return JSUndefined::$undefined;
        }
        try {
            $r = $vm->invoke($handler, JSUndefined::$undefined, [$value]);
            self::resolvePromise($vm, $chained, $r);
        } catch (JSThrowSignal $e) {
            self::rejectPromise($vm, $chained, $e->value);
        }
        return JSUndefined::$undefined;
    }

    public static function then(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSPromise) {
            $vm->throwError('TypeError', 'Promise.prototype.then called on incompatible receiver');
        }
        $onF = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $onR = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $chained = new JSPromise($vm->realm->promisePrototype());
        $reaction = [$onF, $onR, $chained];
        if ($t->state === JSPromise::PENDING) {
            $t->reactions[] = $reaction;
        } else {
            self::enqueueReaction($vm, $t, $reaction);
        }
        $t->handled = true;
        return $chained;
    }

    public static function catchMethod(Vm $vm, mixed $t, array $args): mixed
    {
        return self::then($vm, $t, [JSUndefined::$undefined, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined)]);
    }

    public static function staticResolve(Vm $vm, mixed $t, array $args): mixed
    {
        $v = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if ($v instanceof JSPromise) {
            return $v;
        }
        $p = new JSPromise($vm->realm->promisePrototype());
        self::resolvePromise($vm, $p, $v);
        return $p;
    }

    public static function staticReject(Vm $vm, mixed $t, array $args): mixed
    {
        $p = new JSPromise($vm->realm->promisePrototype());
        self::rejectPromise($vm, $p, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return $p;
    }

    public static function all(Vm $vm, mixed $t, array $args): mixed
    {
        $items = self::iterableToList($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $result = new JSPromise($vm->realm->promisePrototype());
        $n = count($items);
        if ($n === 0) {
            self::resolvePromise($vm, $result, $vm->realm->newArray([]));
            return $result;
        }
        // Shared counter state as a JS-heap-safe array object.
        $state = $vm->realm->newObject();
        $state->props['remaining'] = $n;
        $state->props['values'] = $vm->realm->newArray(array_fill(0, $n, JSUndefined::$undefined));
        [$_, $rejectFn] = self::capabilities($vm, $result);
        foreach ($items as $i => $item) {
            $p = self::staticResolve($vm, JSUndefined::$undefined, [$item]);
            $onF = $vm->realm->nativeFn('Promise.allElementFn', '', 1, null, [$state, $i, $result]);
            self::then($vm, $p, [$onF, $rejectFn]);
        }
        return $result;
    }

    public static function allElementFn(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        [$state, $i, $result] = $fn->data;
        $values = $state->props['values'];
        $values->elements[$i] = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (--$state->props['remaining'] === 0) {
            self::resolvePromise($vm, $result, $values);
        }
        return JSUndefined::$undefined;
    }

    public static function race(Vm $vm, mixed $t, array $args): mixed
    {
        $items = self::iterableToList($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $result = new JSPromise($vm->realm->promisePrototype());
        [$resolveFn, $rejectFn] = self::capabilities($vm, $result);
        foreach ($items as $item) {
            $p = self::staticResolve($vm, JSUndefined::$undefined, [$item]);
            self::then($vm, $p, [$resolveFn, $rejectFn]);
        }
        return $result;
    }

    /** ES5 target: only arrays (and array-likes) are accepted as iterables. */
    private static function iterableToList(Vm $vm, mixed $v): array
    {
        if ($v instanceof JSArray) {
            return $v->toList();
        }
        if ($v instanceof JSObject) {
            $len = Conversions::toUint32($vm, $v->get('length', $vm));
            $out = [];
            for ($i = 0; $i < $len; $i++) {
                $out[] = $v->get((string)$i, $vm);
            }
            return $out;
        }
        $vm->throwError('TypeError', 'Promise.all/race expects an array');
    }
}

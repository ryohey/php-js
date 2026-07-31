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
            'Promise.capabilityExecutor' => [self::class, 'capabilityExecutor'],
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

    /** catch is defined in terms of this.then, which may be overridden. */
    public static function catchMethod(Vm $vm, mixed $t, array $args): mixed
    {
        if (!$t instanceof JSObject) {
            $vm->throwError('TypeError', 'Promise.prototype.catch called on a non-object');
        }
        $then = $t->get('then', $vm);
        if (!$then instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'this.then is not callable');
        }
        return $vm->invoke($then, $t, [
            JSUndefined::$undefined,
            \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined,
        ]);
    }

    /**
     * The static combinators take `this` as the constructor to build with, so
     * a non-object receiver is a TypeError (25.4.4.5 step 2).
     */
    private static function requireConstructorReceiver(Vm $vm, mixed $t, string $who): void
    {
        if (!$t instanceof JSObject) {
            $vm->throwError('TypeError', "Promise.$who called on a non-object");
        }
    }

    public static function staticResolve(Vm $vm, mixed $t, array $args): mixed
    {
        self::requireConstructorReceiver($vm, $t, 'resolve');
        $v = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        // An existing promise passes through only when it was built by this
        // very constructor; otherwise it is re-wrapped through `this`.
        if ($v instanceof JSObject && $v->get('constructor', $vm) === $t && $v instanceof JSPromise) {
            return $v;
        }
        [$promise, $resolve, $_] = self::newCapability($vm, $t);
        $vm->invoke($resolve, JSUndefined::$undefined, [$v]);
        return $promise;
    }

    /** PromiseResolve without the receiver check, for internal use. */
    private static function promiseResolve(Vm $vm, mixed $v): JSPromise
    {
        if ($v instanceof JSPromise) {
            return $v;
        }
        $p = new JSPromise($vm->realm->promisePrototype());
        self::resolvePromise($vm, $p, $v);
        return $p;
    }

    public static function staticReject(Vm $vm, mixed $t, array $args): mixed
    {
        self::requireConstructorReceiver($vm, $t, 'reject');
        [$promise, $_, $reject] = self::newCapability($vm, $t);
        $vm->invoke($reject, JSUndefined::$undefined, [
            \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined,
        ]);
        return $promise;
    }

    /**
     * NewPromiseCapability(C): build the result promise through `this` so a
     * subclass constructor and its executor are honoured. The executor stores
     * the pair on a plain JS object, keeping the capability heap-safe.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed} [promise, resolve, reject]
     */
    private static function newCapability(Vm $vm, mixed $c): array
    {
        if (!$c instanceof JSObject) {
            $vm->throwError('TypeError', 'Promise constructor is not an object');
        }
        $holder = $vm->realm->newObject();
        $executor = $vm->realm->nativeFn('Promise.capabilityExecutor', '', 2, null, $holder);
        $promise = $vm->construct($c, [$executor]);
        $resolve = $holder->props['resolve'] ?? JSUndefined::$undefined;
        $reject = $holder->props['reject'] ?? JSUndefined::$undefined;
        if (!$resolve instanceof JSFunctionBase || !$reject instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'Promise resolve or reject function is not callable');
        }
        return [$promise, $resolve, $reject];
    }

    public static function capabilityExecutor(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $holder = $fn->data;
        $und = JSUndefined::$undefined;
        $resolve = $holder->props['resolve'] ?? $und;
        $reject = $holder->props['reject'] ?? $und;
        if (!$resolve instanceof JSUndefined || !$reject instanceof JSUndefined) {
            $vm->throwError('TypeError', 'Promise executor has already been invoked');
        }
        $holder->props['resolve'] = \array_key_exists(0, $args) ? $args[0] : $und;
        $holder->props['reject'] = \array_key_exists(1, $args) ? $args[1] : $und;
        return $und;
    }

    /**
     * Promise.all (25.4.4.1) and Promise.race (25.4.4.3) share everything but
     * the per-item reaction, so they run through one implementation.
     */
    private static function combinator(Vm $vm, mixed $t, array $args, bool $isAll): mixed
    {
        [$promise, $resolveCap, $rejectCap] = self::newCapability($vm, $t);
        try {
            // C.resolve is read once, before iterating, and must be callable.
            $promiseResolve = $t->get('resolve', $vm);
            if (!$promiseResolve instanceof JSFunctionBase) {
                $vm->throwError('TypeError', 'Promise.resolve is not callable');
            }
            $items = self::iterableToList($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);

            if (!$isAll) {
                foreach ($items as $item) {
                    $next = $vm->invoke($promiseResolve, $t, [$item]);
                    self::invokeThen($vm, $next, $resolveCap, $rejectCap);
                }
                return $promise;
            }

            $state = $vm->realm->newObject();
            // Starts at 1 so the counter cannot reach zero mid-iteration; the
            // extra count is released once every item has been queued.
            $state->props['remaining'] = 1;
            $state->props['values'] = $vm->realm->newArray([]);
            $state->props['resolve'] = $resolveCap;
            foreach ($items as $i => $item) {
                $state->props['values']->elements[$i] = JSUndefined::$undefined;
                $state->props['values']->length = $i + 1;
                $next = $vm->invoke($promiseResolve, $t, [$item]);
                $onFulfilled = $vm->realm->nativeFn('Promise.allElementFn', '', 1, null, [$state, $i]);
                $state->props['remaining']++;
                self::invokeThen($vm, $next, $onFulfilled, $rejectCap);
            }
            if (--$state->props['remaining'] === 0) {
                $vm->invoke($resolveCap, JSUndefined::$undefined, [$state->props['values']]);
            }
        } catch (JSThrowSignal $e) {
            $vm->invoke($rejectCap, JSUndefined::$undefined, [$e->value]);
        }
        return $promise;
    }

    private static function invokeThen(Vm $vm, mixed $next, mixed $onFulfilled, mixed $onRejected): void
    {
        if (!$next instanceof JSObject) {
            $vm->throwError('TypeError', 'Promise.resolve did not return an object');
        }
        $then = $next->get('then', $vm);
        if (!$then instanceof JSFunctionBase) {
            $vm->throwError('TypeError', 'then is not callable');
        }
        $vm->invoke($then, $next, [$onFulfilled, $onRejected]);
    }

    public static function all(Vm $vm, mixed $t, array $args): mixed
    {
        return self::combinator($vm, $t, $args, true);
    }

    public static function allElementFn(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        [$state, $i] = $fn->data;
        if ($fn->alreadyCalled) {
            return JSUndefined::$undefined;
        }
        $fn->alreadyCalled = true;
        $state->props['values']->elements[$i] = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        if (--$state->props['remaining'] === 0) {
            $vm->invoke($state->props['resolve'], JSUndefined::$undefined, [$state->props['values']]);
        }
        return JSUndefined::$undefined;
    }

    public static function race(Vm $vm, mixed $t, array $args): mixed
    {
        return self::combinator($vm, $t, $args, false);
    }

    /** ES5 target: only arrays (and array-likes) are accepted as iterables. */
    /**
     * The combinators take an *iterable*, not an array-like. Reading `length`
     * and indices instead would resolve `Promise.all(new Set([p]))` with
     * nothing at all rather than with the promise's value.
     */
    private static function iterableToList(Vm $vm, mixed $v): array
    {
        return $vm->iterateToList($v);
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Vm;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Builtins\PromiseBuiltins;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSBoundFunction;
use PhpJs\Runtime\JSFunction;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSGeneratorObject;
use PhpJs\Runtime\JSHole;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPromise;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Runtime\TypeOps;

/**
 * The bytecode VM: a single dispatch loop (DESIGN.md §4). JS frames live in
 * $frames (our own data, not the PHP call stack); the operand stack is one
 * shared array with function locals occupying a register window at the base
 * of each frame. JS exceptions are in-loop control flow; JSThrowSignal only
 * crosses the native boundary.
 */
final class Vm
{
    // Frame slot indices
    private const F_TPL = 0;
    private const F_BASE = 1;
    private const F_PC = 2;
    private const F_ENV = 3;
    private const F_THIS = 4;
    private const F_ARGS = 5;
    private const F_HANDLERS = 6;
    private const F_FUNC = 7;
    private const F_CTOR = 8;
    private const F_RETSP = 9;
    private const F_ARGSOBJ = 10;

    private const MAX_FRAMES = 10000;
    private const MAX_REENTRY = 400;
    /** How often the dispatch loop checks the wall clock against the deadline. */
    private const DEADLINE_CHECK_INTERVAL = 100000;

    /** @var array<int, mixed> shared operand stack (locals live at frame bases) */
    public array $stack = [];
    public int $sp = 0;
    /** @var list<array<int, mixed>> */
    public array $frames = [];
    public mixed $completion;
    private int $reentry = 0;
    /**
     * Wall-clock limit for JS execution, or null for no limit. A runaway loop
     * in guest code must not hang the host request, and PHP's own time limit
     * cannot unwind the VM cleanly. Checked every
     * DEADLINE_CHECK_INTERVAL instructions so the hot path stays untouched.
     */
    private ?float $deadline = null;
    private int $ticksToDeadlineCheck = self::DEADLINE_CHECK_INTERVAL;

    /** Abort execution if JS runs for more than $seconds. Null clears the limit. */
    public function setTimeLimit(?float $seconds): void
    {
        $this->deadline = $seconds === null ? null : microtime(true) + $seconds;
        $this->ticksToDeadlineCheck = self::DEADLINE_CHECK_INTERVAL;
    }

    public function __construct(public Realm $realm)
    {
        $this->completion = JSUndefined::$undefined;
        $realm->vm ??= $this;
    }

    /** Run a program template in the global scope; returns the completion value. */
    public function runProgram(array $template): mixed
    {
        $this->completion = JSUndefined::$undefined;
        $base = $this->sp;
        $nlocals = $template['nlocals'];
        for ($i = 0; $i < $nlocals; $i++) {
            $this->stack[$base + $i] = JSUndefined::$undefined;
        }
        $this->sp = $base + $nlocals;
        $env = $template['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv(null, $template['nenv']) : null;
        $this->frames[] = [
            $template, $base, 0, $env, $this->realm->globalObject,
            null, [], null, null, $base, null,
        ];
        return $this->execute(count($this->frames) - 1);
    }

    /** Call a JS value from PHP (native builtins, host code). */
    public function invoke(mixed $fn, mixed $thisVal, array $args, ?JSObject $ctorObj = null): mixed
    {
        while ($fn instanceof JSBoundFunction) {
            if ($ctorObj === null) {
                $thisVal = $fn->boundThis;
            }
            $args = array_merge($fn->boundArgs, $args);
            $fn = $fn->target;
        }
        if ($fn instanceof JSNativeFunction) {
            if ($ctorObj !== null && $fn->ctorId !== null) {
                return (BuiltinRegistry::get($fn->ctorId))($this, $args, $fn);
            }
            return (BuiltinRegistry::get($fn->fnId))($this, $ctorObj ?? $thisVal, $args, $fn);
        }
        if (!$fn instanceof JSFunction) {
            $this->throwError('TypeError', Conversions::toString($this, TypeOps::typeofOp($fn)) . ' is not a function');
        }
        if ($fn->isClassConstructor && $ctorObj === null) {
            // Reached from a native caller (a callback slot, a builtin that
            // invokes what it was handed) rather than through CALL, but the
            // same rule: [[Call]] without `new` is a TypeError regardless of
            // where the attempt comes from.
            $this->throwError('TypeError', "Class constructor {$fn->name} cannot be invoked without 'new'");
        }
        if ($fn->nativeId !== null) {
            return (BuiltinRegistry::get($fn->nativeId))(
                $this,
                $fn->isArrow
                    ? $fn->lexicalThis
                    : ($ctorObj ?? ($fn->template['strict'] ? $thisVal : $this->coerceThis($thisVal))),
                $args,
                $fn
            );
        }
        if ($fn->isGenerator) {
            // [[Call]] on a generator function never runs the body -- it
            // creates and returns a Generator object, suspended before its
            // first instruction (27.5.5.2). `construct()` already refuses
            // `new` on one, so $ctorObj is never set here.
            return $this->createGenerator($fn, $thisVal, $args);
        }
        if ($fn->isAsync) {
            // [[Call]] on an async function runs synchronously up to its
            // first `await` (or to completion) and always returns a Promise,
            // never a thrown value even for a throw before any await
            // (AsyncFunctionStart, 27.7.5.1). `construct()` already refuses
            // `new` on one, so $ctorObj is never set here.
            return $this->createAsyncCall($fn, $thisVal, $args);
        }
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        try {
            $this->pushFrame($fn, $thisVal, $args, $ctorObj);
            return $this->execute(count($this->frames) - 1);
        } finally {
            $this->reentry--;
        }
    }

    /** [[Construct]] entry point for natives (e.g. bind, Reflect-like helpers). */
    public function construct(mixed $ctor, array $args): mixed
    {
        while ($ctor instanceof JSBoundFunction) {
            $args = array_merge($ctor->boundArgs, $args);
            $ctor = $ctor->target;
        }
        if ($ctor instanceof JSNativeFunction) {
            if ($ctor->ctorId === null) {
                // No [[Construct]]: builtins like Function.prototype.call are
                // callable but not constructors, and their call signature does
                // not match the construct one.
                $this->throwError('TypeError', ($ctor->name !== '' ? $ctor->name : 'value') . ' is not a constructor');
            }
            return (BuiltinRegistry::get($ctor->ctorId))($this, $args, $ctor);
        }
        if (!$ctor instanceof JSFunction || $ctor->isArrow || $ctor->isGenerator || $ctor->isAsync) {
            $this->throwError('TypeError', ($ctor instanceof JSFunctionBase && $ctor->name !== ''
                ? $ctor->name : 'value') . ' is not a constructor');
        }
        $proto = $ctor->get('prototype', $this);
        $obj = new JSObject($proto instanceof JSObject ? $proto : $this->realm->objectPrototype());
        $result = $this->invoke($ctor, $obj, $args, $obj);
        return $result instanceof JSObject ? $result : $obj;
    }

    private function pushFrame(JSFunction $func, mixed $thisVal, array $args, ?JSObject $ctorObj): void
    {
        if (count($this->frames) >= self::MAX_FRAMES) {
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        $tpl = $func->template;
        $base = $this->sp;
        $argc = count($args);
        $n = min($argc, $tpl['nparams']);
        for ($i = 0; $i < $n; $i++) {
            $this->stack[$base + $i] = $args[$i];
        }
        $und = JSUndefined::$undefined;
        for ($i = $n; $i < $tpl['nlocals']; $i++) {
            $this->stack[$base + $i] = $und;
        }
        $this->sp = $base + $tpl['nlocals'];
        $env = $tpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($func->env, $tpl['nenv']) : $func->env;
        if ($func->isArrow) {
            // Whatever the caller passed is ignored, and no coercion applies:
            // an arrow has no `this` binding, it reads the one it closed over.
            $thisVal = $func->lexicalThis;
        } elseif ($ctorObj !== null) {
            $thisVal = $ctorObj;
        } elseif (!$tpl['strict']) {
            $thisVal = $this->coerceThis($thisVal);
        }
        $this->frames[] = [
            $tpl, $base, 0, $env, $thisVal,
            $tpl['usesArgs'] ? $args : null, [], $func, $ctorObj, $base, null,
        ];
    }

    private function coerceThis(mixed $t): mixed
    {
        if ($t === null || $t instanceof JSUndefined) {
            return $this->realm->globalObject;
        }
        if ($t instanceof JSObject) {
            return $t;
        }
        return Conversions::toObject($this, $t);
    }

    /**
     * [[Call]] on a generator function (27.5.5.2, via EvaluateGeneratorBody
     * 15.5.2): FunctionDeclarationInstantiation -- parameter binding
     * (which can throw, e.g. a bad destructuring parameter) and hoisting --
     * runs *now*, synchronously, the same as it would for an ordinary call.
     * Only once that succeeds does the compiled body reach the barrier
     * `Compiler::genFunction` inserts before its first real statement and
     * suspend there (GeneratorStart), which is why this always drives the
     * frame through one `execute()` pass up front rather than deferring
     * everything to the first `resumeGenerator()` call.
     */
    private function createGenerator(JSFunction $func, mixed $thisVal, array $args): JSGeneratorObject
    {
        if (!$func->template['strict']) {
            $thisVal = $this->coerceThis($thisVal);
        }
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        try {
            $this->pushFrame($func, $thisVal, $args, null);
            $result = $this->execute(count($this->frames) - 1);
        } finally {
            $this->reentry--;
        }
        // Only now -- after FunctionDeclarationInstantiation has run and
        // could have reassigned `func.prototype` as a side effect of a
        // parameter default -- is the generator's own [[Prototype]] read
        // (test262 generator-created-after-decl-inst.js); constructing the
        // JSGeneratorObject any earlier would read the wrong value.
        $proto = $func->get('prototype', $this);
        $gen = new JSGeneratorObject(
            $proto instanceof JSObject ? $proto : $this->realm->generatorPrototype(),
            $func,
            $thisVal,
            $args
        );
        // The compiled barrier always suspends before any RETURN can be
        // reached, so this is never anything but a FrameSuspend.
        $gen->suspended = $result->state;
        return $gen;
    }

    /**
     * GeneratorResume / GeneratorResumeAbrupt (27.5.3.2/.3), collapsed into
     * one entry point selected by `$mode` (`Op::YIELD_NEXT/THROW/RETURN`).
     * Returns `[value, done]`, the pieces of the `{value, done}` object
     * `next`/`throw`/`return` hand back; a JS exception either escaped the
     * generator body or was thrown directly here and crosses out as
     * `JSThrowSignal`, same as everywhere else at the native boundary.
     */
    public function resumeGenerator(JSGeneratorObject $gen, int $mode, mixed $value): array
    {
        if ($gen->state === JSGeneratorObject::EXECUTING) {
            $this->throwError('TypeError', 'Generator is already running');
        }
        if ($gen->state === JSGeneratorObject::COMPLETED) {
            if ($mode === Op::YIELD_THROW) {
                $this->throwValue($value);
            }
            return [$mode === Op::YIELD_RETURN ? $value : JSUndefined::$undefined, true];
        }

        // suspendedYield -- including the pre-first-statement barrier every
        // generator starts at, which is what makes a `.throw()`/`.return()`
        // before any `.next()` complete on the spot rather than running
        // anything: no exception handler is registered there yet. Restore
        // the captured frame at a fresh base -- possibly different from
        // where it suspended, since arbitrary JS has run in between -- and
        // continue right where YIELD left off.
        if (count($this->frames) >= self::MAX_FRAMES) {
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            $this->throwError('RangeError', 'Maximum call stack size exceeded');
        }
        $gen->state = JSGeneratorObject::EXECUTING;
        $saved = $gen->suspended;
        $gen->suspended = null;
        $newBase = $this->sp;
        $len = count($saved['saved']);
        for ($i = 0; $i < $len; $i++) {
            $this->stack[$newBase + $i] = $saved['saved'][$i];
        }
        $this->sp = $newBase + $len;
        $handlers = [];
        foreach ($saved['handlers'] as $h) {
            $handlers[] = [$h[0], $h[1] + $newBase, $h[2]];
        }
        $this->stack[$newBase + $saved['sentSlot']] = $value;
        $this->stack[$newBase + $saved['modeSlot']] = $mode;
        $tpl = $gen->func->template;
        $this->frames[] = [
            $tpl, $newBase, $saved['pc'], $saved['env'], $gen->thisVal,
            $tpl['usesArgs'] ? $gen->args : null, $handlers, $gen->func, null, $newBase, $saved['argsObj'],
        ];
        try {
            $result = $this->execute(count($this->frames) - 1);
        } catch (JSThrowSignal $e) {
            $gen->state = JSGeneratorObject::COMPLETED;
            throw $e;
        } finally {
            $this->reentry--;
        }
        return $this->settleGeneratorStep($gen, $result);
    }

    /** @return array{0: mixed, 1: bool} */
    private function settleGeneratorStep(JSGeneratorObject $gen, mixed $result): array
    {
        if ($result instanceof FrameSuspend) {
            $gen->state = JSGeneratorObject::SUSPENDED_YIELD;
            $gen->suspended = $result->state;
            return [$result->value, false];
        }
        $gen->state = JSGeneratorObject::COMPLETED;
        return [$result, true];
    }

    /**
     * [[Call]] on an async function (AsyncFunctionStart, 27.7.5.1): runs
     * FunctionDeclarationInstantiation and the body synchronously, same as
     * an ordinary call, up to its first `await` (if any) -- unlike a
     * generator, there is no barrier suspending before the first statement,
     * since an async function's body always starts running immediately. The
     * Promise this returns is created up front and is the only one this
     * call will ever settle, however many `await`s the body goes through
     * before it does.
     *
     * A throw escaping *before* any `await` -- including one from a bad
     * destructuring parameter, exactly the case that made `createGenerator`
     * defer reading `.prototype` -- rejects the promise rather than
     * propagating out of this call: per spec, calling an async function
     * never throws synchronously, it always returns a promise.
     */
    private function createAsyncCall(JSFunction $func, mixed $thisVal, array $args): JSPromise
    {
        $promise = new JSPromise($this->realm->promisePrototype());
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            PromiseBuiltins::rejectPromise($this, $promise, $this->realm->createError(
                'RangeError',
                'Maximum call stack size exceeded'
            ));
            return $promise;
        }
        try {
            $this->pushFrame($func, $thisVal, $args, null);
            $result = $this->execute(count($this->frames) - 1);
        } catch (JSThrowSignal $e) {
            PromiseBuiltins::rejectPromise($this, $promise, $e->value);
            return $promise;
        } finally {
            $this->reentry--;
        }
        $this->settleAsyncStep($promise, $result);
        return $promise;
    }

    /**
     * Either the body suspended at an `await` -- register a reaction that
     * resumes it once the awaited value settles -- or it ran to completion,
     * in which case the promise settles right now, the same as any other
     * `resolve(returnValue)`/an escaped throw already turned into a
     * rejection by the caller.
     */
    private function settleAsyncStep(JSPromise $promise, mixed $result): void
    {
        if ($result instanceof FrameSuspend) {
            $this->scheduleAsyncResume($promise, $result);
            return;
        }
        PromiseBuiltins::resolvePromise($this, $promise, $result);
    }

    /**
     * `Await` (27.7.5.3): wrap the awaited value through `PromiseResolve` --
     * adopting an existing promise or thenable rather than double-wrapping
     * it -- and drive the suspended frame's resume off its settlement,
     * entirely through the ordinary microtask queue (`PromiseBuiltins::
     * then`'s reaction jobs). No part of this ever runs inline: even an
     * already-fulfilled value's continuation waits for a microtask, which is
     * what makes `await x` observably suspend at least once even when `x` is
     * not a promise at all.
     */
    private function scheduleAsyncResume(JSPromise $promise, FrameSuspend $suspend): void
    {
        $awaited = PromiseBuiltins::promiseResolve($this, $suspend->value);
        $onFulfilled = $this->realm->nativeFn('Async.resumeJob', '', 1, null, [$promise, $suspend->state, Op::YIELD_NEXT]);
        $onRejected = $this->realm->nativeFn('Async.resumeJob', '', 1, null, [$promise, $suspend->state, Op::YIELD_THROW]);
        PromiseBuiltins::then($this, $awaited, [$onFulfilled, $onRejected]);
    }

    /**
     * The reaction job `scheduleAsyncResume` registers, run from the
     * microtask queue once the awaited value settles: restore the suspended
     * frame exactly like `resumeGenerator` does (there is no wrapper object
     * to read `func`/`thisVal`/`args` from here, which is why `FrameSuspend`
     * carries them itself), write the settled value and fulfilled/rejected
     * mode into `AWAIT`'s own two slots, and run it forward. A throw
     * escaping this resumed execution rejects the async function's own
     * promise rather than propagating into the microtask queue, which has
     * nothing meaningful to do with a bare PHP exception.
     */
    public function resumeAsync(JSPromise $promise, array $state, int $mode, mixed $value): void
    {
        if (count($this->frames) >= self::MAX_FRAMES) {
            PromiseBuiltins::rejectPromise($this, $promise, $this->realm->createError(
                'RangeError',
                'Maximum call stack size exceeded'
            ));
            return;
        }
        if (++$this->reentry > self::MAX_REENTRY) {
            $this->reentry--;
            PromiseBuiltins::rejectPromise($this, $promise, $this->realm->createError(
                'RangeError',
                'Maximum call stack size exceeded'
            ));
            return;
        }
        $newBase = $this->sp;
        $len = count($state['saved']);
        for ($i = 0; $i < $len; $i++) {
            $this->stack[$newBase + $i] = $state['saved'][$i];
        }
        $this->sp = $newBase + $len;
        $handlers = [];
        foreach ($state['handlers'] as $h) {
            $handlers[] = [$h[0], $h[1] + $newBase, $h[2]];
        }
        $this->stack[$newBase + $state['sentSlot']] = $value;
        $this->stack[$newBase + $state['modeSlot']] = $mode;
        $func = $state['func'];
        $this->frames[] = [
            $func->template, $newBase, $state['pc'], $state['env'], $state['thisVal'],
            $state['args'], $handlers, $func, null, $newBase, $state['argsObj'],
        ];
        try {
            $result = $this->execute(count($this->frames) - 1);
        } catch (JSThrowSignal $e) {
            PromiseBuiltins::rejectPromise($this, $promise, $e->value);
            return;
        } finally {
            $this->reentry--;
        }
        $this->settleAsyncStep($promise, $result);
    }

    /**
     * One `yield*` delegation-loop pass (13.3.8.1): call `next`/`throw`/
     * `return` on the inner iterable per `$mode` and unpack its result.
     * `$next` is the method GetIterator captured once; `throw`/`return` are
     * looked up on `$iter` fresh every pass, per spec -- unlike `next`,
     * mutating them mid-delegation is observable.
     *
     * @return array{0: mixed, 1: bool}
     */
    private function yieldDelegateStep(mixed $iter, mixed $next, int $mode, mixed $sentValue): array
    {
        if (!$iter instanceof JSObject) {
            $this->throwError('TypeError', 'Result of the Symbol.iterator method is not an object');
        }
        if ($mode === Op::YIELD_THROW) {
            $throwM = $iter->get('throw', $this);
            if ($throwM === null || $throwM instanceof JSUndefined) {
                // Give the inner iterator a chance to clean up before this
                // becomes the protocol-violation error the spec asks for.
                $this->closeIterator($iter, false);
                $this->throwError('TypeError', 'The iterator does not provide a "throw" method');
            }
            $result = $this->invoke($throwM, $iter, [$sentValue]);
        } elseif ($mode === Op::YIELD_RETURN) {
            $returnM = $iter->get('return', $this);
            if ($returnM === null || $returnM instanceof JSUndefined) {
                // No cleanup possible or needed: the received value becomes
                // the delegation's result directly.
                return [$sentValue, true];
            }
            $result = $this->invoke($returnM, $iter, [$sentValue]);
        } else {
            $result = $this->invoke($next, $iter, [$sentValue]);
        }
        if (!$result instanceof JSObject) {
            $this->throwError('TypeError', 'Iterator result is not an object');
        }
        return [$result->get('value', $this), Conversions::toBoolean($result->get('done', $this))];
    }

    /**
     * The dispatch loop. Runs until the frame at index $floor returns.
     * @param int $floor index of the entry frame
     */
    private function execute(int $floor): mixed
    {
        $stack = &$this->stack;
        $frames = &$this->frames;
        $realm = $this->realm;
        $und = JSUndefined::$undefined;

        $fi = count($frames) - 1;
        $frame = &$frames[$fi];
        $tpl = $frame[self::F_TPL];
        $code = $tpl['code'];
        $consts = $tpl['consts'];
        $strict = $tpl['strict'];
        $base = $frame[self::F_BASE];
        $env = $frame[self::F_ENV];
        $pc = $frame[self::F_PC];
        $sp = $this->sp;
        // Both of these were property accesses on every instruction, which is
        // measurable at this dispatch cost. The countdown is a plain local and
        // only exists at all when a limit is armed; $this->sp is instead
        // published by the individual opcodes that can re-enter the VM.
        $deadline = $this->deadline;
        $ticks = self::DEADLINE_CHECK_INTERVAL;

        for (;;) {
            try {
                if ($deadline !== null && --$ticks <= 0) {
                    $ticks = self::DEADLINE_CHECK_INTERVAL;
                    if (microtime(true) > $deadline) {
                        $this->deadline = $deadline = null; // let the unwind finish
                        $this->sp = $sp;
                        $this->throwError('RangeError', 'Script execution timed out');
                    }
                }
                $op = $code[$pc++];
                switch ($op) {
                    case Op::PUSH_CONST:
                        $stack[$sp++] = $consts[$code[$pc++]];
                        break;
                    case Op::PUSH_INT:
                        $stack[$sp++] = $code[$pc++];
                        break;
                    case Op::PUSH_TRUE:
                        $stack[$sp++] = true;
                        break;
                    case Op::PUSH_FALSE:
                        $stack[$sp++] = false;
                        break;
                    case Op::PUSH_NULL:
                        $stack[$sp++] = null;
                        break;
                    case Op::PUSH_UNDEF:
                        $stack[$sp++] = $und;
                        break;
                    case Op::PUSH_HOLE:
                        $stack[$sp++] = JSHole::$hole;
                        break;
                    case Op::DUP:
                        $stack[$sp] = $stack[$sp - 1];
                        $sp++;
                        break;
                    case Op::DUP2:
                        $stack[$sp] = $stack[$sp - 2];
                        $stack[$sp + 1] = $stack[$sp - 1];
                        $sp += 2;
                        break;
                    case Op::POP:
                        $sp--;
                        break;
                    case Op::SWAP:
                        $t = $stack[$sp - 1];
                        $stack[$sp - 1] = $stack[$sp - 2];
                        $stack[$sp - 2] = $t;
                        break;

                    case Op::GET_LOCAL:
                        $stack[$sp++] = $stack[$base + $code[$pc++]];
                        break;
                    case Op::SET_LOCAL:
                        $stack[$base + $code[$pc++]] = $stack[$sp - 1];
                        break;
                    case Op::STORE_LOCAL:
                        $stack[$base + $code[$pc++]] = $stack[--$sp];
                        break;
                    case Op::TYPEOF_LOCAL:
                        $stack[$sp++] = TypeOps::typeofOp($stack[$base + $code[$pc++]]);
                        break;
                    case Op::GET_LOCAL_PROP: {
                        $obj = $stack[$base + $code[$pc++]];
                        $key = $consts[$code[$pc++]];
                        if ($obj instanceof JSObject && null !== ($v = $obj->props[$key] ?? null)) {
                            $stack[$sp++] = $v;
                        } elseif ($obj instanceof JSArray && $key === 'length') {
                            $stack[$sp++] = $obj->length;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp++] = $this->getMember($obj, $key);
                        }
                        break;
                    }
                    case Op::GET_ENV: {
                        $d = $code[$pc++];
                        $e = $env;
                        while ($d--) {
                            $e = $e->parent;
                        }
                        $stack[$sp++] = $e->slots[$code[$pc++]];
                        break;
                    }
                    case Op::SET_ENV: {
                        $d = $code[$pc++];
                        $e = $env;
                        while ($d--) {
                            $e = $e->parent;
                        }
                        $e->slots[$code[$pc++]] = $stack[$sp - 1];
                        break;
                    }
                    case Op::CAPTURE_ENV:
                        $stack[$base + $code[$pc++]] = $env;
                        break;
                    case Op::NEW_ITER_ENV: {
                        $outerSlot = $code[$pc++];
                        $size = $code[$pc++];
                        $env = new \PhpJs\Runtime\JSEnv($stack[$base + $outerSlot], $size);
                        $frame[self::F_ENV] = $env;
                        break;
                    }
                    case Op::RESTORE_ENV:
                        $env = $stack[--$sp];
                        $frame[self::F_ENV] = $env;
                        break;
                    case Op::GET_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        if (null !== ($v = $g->props[$name] ?? null)) {
                            $stack[$sp++] = $v;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp++] = $this->globalGet($name);
                        }
                        break;
                    }
                    case Op::SET_GLOBAL:
                        $this->sp = $sp;
                        $this->globalSet($consts[$code[$pc++]], $stack[$sp - 1], $strict);
                        break;
                    case Op::DECL_GLOBAL: {
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        if (!$g->hasOwn($name)) {
                            $g->defineOwnData($name, $und, JSObject::W | JSObject::E);
                        }
                        break;
                    }
                    case Op::TYPEOF_GLOBAL: {
                        $this->sp = $sp;
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        $stack[$sp++] = $g->hasProperty($name)
                            ? TypeOps::typeofOp($g->get($name, $this))
                            : 'undefined';
                        break;
                    }
                    case Op::DEL_GLOBAL: {
                        $this->sp = $sp;
                        $name = $consts[$code[$pc++]];
                        $g = $realm->globalObject;
                        $stack[$sp++] = $g->hasOwn($name) ? $g->deleteKey($name, $this, false) : true;
                        break;
                    }

                    case Op::GET_PROP: {
                        $key = $consts[$code[$pc++]];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && null !== ($v = $obj->props[$key] ?? null)) {
                            $stack[$sp - 1] = $v;
                        } elseif ($obj instanceof JSArray && $key === 'length') {
                            $stack[$sp - 1] = $obj->length;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = $this->getMember($obj, $key);
                        }
                        break;
                    }
                    case Op::SET_PROP: {
                        $key = $consts[$code[$pc++]];
                        $val = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && $obj->descs === null && isset($obj->props[$key])
                            && !($obj instanceof JSArray && $key === 'length')) {
                            $obj->props[$key] = $val;
                        } else {
                            $this->sp = $sp;
                            $this->setMember($obj, $key, $val, $strict);
                        }
                        $stack[$sp - 1] = $val;
                        break;
                    }
                    case Op::GET_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSArray && is_int($key)) {
                            if (null !== ($v = $obj->elements[$key] ?? null)) {
                                $stack[$sp - 1] = $v;
                            } elseif (array_key_exists($key, $obj->elements)) {
                                $stack[$sp - 1] = null;
                            } else {
                                $this->sp = $sp;
                                $stack[$sp - 1] = $this->getMember($obj, $key);
                            }
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = $this->getMember($obj, $key);
                        }
                        break;
                    }
                    case Op::SET_ELEM: {
                        $val = $stack[--$sp];
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSArray && is_int($key) && $key >= 0 && $key <= 4294967294
                            && $obj->extensible) {
                            $obj->elements[$key] = $val;
                            if ($key >= $obj->length) {
                                $obj->length = $key + 1;
                            }
                        } else {
                            $this->sp = $sp;
                            $this->setMember($obj, $key, $val, $strict);
                        }
                        $stack[$sp - 1] = $val;
                        break;
                    }
                    case Op::DEL_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $this->sp = $sp;
                        $stack[$sp - 1] = $this->deleteMember($obj, $key, $strict);
                        break;
                    }
                    case Op::GET_METHOD: {
                        $key = $consts[$code[$pc++]];
                        $obj = $stack[$sp - 1];
                        if ($obj instanceof JSObject && null !== ($v = $obj->props[$key] ?? null)) {
                            $fn = $v;
                        } else {
                            $this->sp = $sp;
                            $fn = $this->getMember($obj, $key);
                        }
                        $stack[$sp - 1] = $fn;
                        $stack[$sp++] = $obj;
                        break;
                    }
                    case Op::GET_METHOD_ELEM: {
                        $key = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $this->sp = $sp;
                        $stack[$sp - 1] = $this->getMember($obj, $key);
                        $stack[$sp++] = $obj;
                        break;
                    }
                    case Op::DEFINE_DATA: {
                        $key = $consts[$code[$pc++]];
                        $val = $stack[--$sp];
                        $stack[$sp - 1]->defineOwnData($key, $val);
                        break;
                    }
                    case Op::DEFINE_GETTER: {
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $obj->defineOwnAccessor($key, $fn, $prev !== null && ($prev[2] & JSObject::ACCESSOR) ? $prev[1] : null, JSObject::E | JSObject::C);
                        break;
                    }
                    case Op::DEFINE_SETTER: {
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $obj->defineOwnAccessor($key, $prev !== null && ($prev[2] & JSObject::ACCESSOR) ? $prev[0] : null, $fn, JSObject::E | JSObject::C);
                        break;
                    }

                    case Op::DEFINE_DATA_ELEM: {
                        $val = $stack[--$sp];
                        $key = $this->propertyKey($stack[--$sp]);
                        $stack[$sp - 1]->defineOwnData($key, $val);
                        break;
                    }
                    case Op::DEFINE_GETTER_ELEM:
                    case Op::DEFINE_SETTER_ELEM: {
                        $isGetter = $op === Op::DEFINE_GETTER_ELEM;
                        $fn = $stack[--$sp];
                        $key = $this->propertyKey($stack[--$sp]);
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $keep = ($prev !== null && ($prev[2] & JSObject::ACCESSOR))
                            ? ($isGetter ? $prev[1] : $prev[0])
                            : null;
                        $obj->defineOwnAccessor(
                            $key,
                            $isGetter ? $fn : $keep,
                            $isGetter ? $keep : $fn,
                            JSObject::E | JSObject::C
                        );
                        break;
                    }
                    case Op::DEFINE_METHOD: {
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        // Not DEFAULT_ATTRS: a class method is non-enumerable
                        // (15.7.10), where an object literal's is not.
                        $stack[$sp - 1]->defineOwnData($key, $fn, JSObject::W | JSObject::C);
                        break;
                    }
                    case Op::DEFINE_METHOD_ELEM: {
                        $fn = $stack[--$sp];
                        $key = $this->propertyKey($stack[--$sp]);
                        $stack[$sp - 1]->defineOwnData($key, $fn, JSObject::W | JSObject::C);
                        break;
                    }
                    case Op::DEFINE_CLASS_GETTER:
                    case Op::DEFINE_CLASS_SETTER: {
                        $isGetter = $op === Op::DEFINE_CLASS_GETTER;
                        $key = $consts[$code[$pc++]];
                        $fn = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $keep = ($prev !== null && ($prev[2] & JSObject::ACCESSOR))
                            ? ($isGetter ? $prev[1] : $prev[0])
                            : null;
                        // Non-enumerable, unlike DEFINE_GETTER/SETTER's object
                        // literal form.
                        $obj->defineOwnAccessor($key, $isGetter ? $fn : $keep, $isGetter ? $keep : $fn, JSObject::C);
                        break;
                    }
                    case Op::DEFINE_CLASS_GETTER_ELEM:
                    case Op::DEFINE_CLASS_SETTER_ELEM: {
                        $isGetter = $op === Op::DEFINE_CLASS_GETTER_ELEM;
                        $fn = $stack[--$sp];
                        $key = $this->propertyKey($stack[--$sp]);
                        $obj = $stack[$sp - 1];
                        $prev = $obj->descs[$key] ?? null;
                        $keep = ($prev !== null && ($prev[2] & JSObject::ACCESSOR))
                            ? ($isGetter ? $prev[1] : $prev[0])
                            : null;
                        $obj->defineOwnAccessor($key, $isGetter ? $fn : $keep, $isGetter ? $keep : $fn, JSObject::C);
                        break;
                    }
                    case Op::SET_HOME_OBJECT: {
                        $home = $stack[--$sp];
                        $func = $stack[$sp - 1];
                        if ($func instanceof JSFunction && $home instanceof JSObject) {
                            $func->homeObject = $home;
                        }
                        break;
                    }
                    case Op::SET_FUNC_NAME: {
                        $prefix = $consts[$code[$pc++]];
                        $key = $stack[--$sp];
                        $fn = $stack[$sp - 1];
                        if ($fn instanceof JSFunctionBase) {
                            $sym = $realm->symbolByKey($key);
                            $fn->name = $prefix . ($sym !== null ? ($sym->description !== null ? '[' . $sym->description . ']' : '') : $key);
                        }
                        break;
                    }
                    case Op::NEW_CLASS: {
                        $childIdx = $code[$pc++];
                        $hasSuper = $code[$pc++];
                        $superVal = $hasSuper ? $stack[--$sp] : false;
                        $this->sp = $sp;

                        $ctorParent = null;      // static-side [[Prototype]]; null keeps the default
                        if (!$hasSuper) {
                            $protoParent = $realm->objectPrototype();
                        } elseif ($superVal === null) {
                            // `extends null`: a prototype with no [[Prototype]]
                            // at all, still a legal (if unusual) base.
                            $protoParent = null;
                        } else {
                            if ($superVal instanceof JSNativeFunction
                                || ($superVal instanceof JSFunction && $superVal->nativeId !== null)) {
                                // A native constructor builds its own object
                                // and returns it rather than initializing the
                                // one SUPER_CALL already has (see JSFunction's
                                // and BuiltinRegistry's calling convention) --
                                // running it against an existing `this` would
                                // silently drop everything it sets up.
                                $this->throwError(
                                    'TypeError',
                                    'Extending a native constructor is not supported yet'
                                );
                            }
                            if (!$superVal instanceof JSFunction || $superVal->isArrow) {
                                $this->throwError(
                                    'TypeError',
                                    'Class extends value ' . Conversions::toString($this, TypeOps::typeofOp($superVal))
                                        . ' is not a constructor or null'
                                );
                            }
                            $protoParent = $superVal->get('prototype', $this);
                            if ($protoParent !== null && !$protoParent instanceof JSObject) {
                                $this->throwError(
                                    'TypeError',
                                    'Class extends value does not have valid prototype property'
                                );
                            }
                            $ctorParent = $superVal;
                        }

                        $proto = new JSObject($protoParent);
                        $ctor = new JSFunction($tpl['children'][$childIdx], $env, $realm, $proto);
                        if ($ctorParent !== null) {
                            $ctor->proto = $ctorParent;
                        }
                        $proto->defineOwnData('constructor', $ctor, JSObject::W | JSObject::C);

                        $stack[$sp++] = $ctor;
                        $stack[$sp++] = $proto;
                        break;
                    }
                    case Op::GET_SUPER:
                    case Op::GET_SUPER_ELEM: {
                        $key = $op === Op::GET_SUPER ? $consts[$code[$pc++]] : $this->propertyKey($stack[--$sp]);
                        $home = $frame[self::F_FUNC] instanceof JSFunction ? $frame[self::F_FUNC]->homeObject : null;
                        $superProto = $home?->proto;
                        $this->sp = $sp;
                        $stack[$sp++] = $superProto === null ? JSUndefined::$undefined
                            : $superProto->get($key, $this, $frame[self::F_THIS]);
                        break;
                    }
                    case Op::GET_SUPER_METHOD:
                    case Op::GET_SUPER_METHOD_ELEM: {
                        $key = $op === Op::GET_SUPER_METHOD ? $consts[$code[$pc++]] : $this->propertyKey($stack[--$sp]);
                        $home = $frame[self::F_FUNC] instanceof JSFunction ? $frame[self::F_FUNC]->homeObject : null;
                        $superProto = $home?->proto;
                        $this->sp = $sp;
                        $stack[$sp++] = $superProto === null ? JSUndefined::$undefined
                            : $superProto->get($key, $this, $frame[self::F_THIS]);
                        $stack[$sp++] = $frame[self::F_THIS];
                        break;
                    }
                    case Op::SUPER_CALL: {
                        $argc = $code[$pc++];
                        $args = [];
                        for ($i = $argc; $i > 0; $i--) {
                            $args[$argc - $i] = $stack[$sp - $i];
                        }
                        $sp -= $argc;
                        $thisVal = $stack[--$sp];
                        $parentCtor = $stack[--$sp];
                        $this->sp = $sp;
                        $frame[self::F_PC] = $pc;
                        // Runs the parent constructor's body against the `this`
                        // the derived class's own [[Construct]] already built,
                        // rather than the spec's "create a fresh object from
                        // new.target.prototype and rebind `this` to it" -- the
                        // two coincide whenever new.target is the class that
                        // was actually `new`ed, which is every call this
                        // compiler can reach (DESIGN.md §2.5: Reflect is out of
                        // scope, so new.target never diverges from it).
                        $this->invoke($parentCtor, $thisVal, $args, $thisVal);
                        $stack[$sp++] = $thisVal;
                        break;
                    }
                    case Op::SUPER_CALL_SPREAD: {
                        $argsArr = $stack[--$sp];
                        $thisVal = $stack[--$sp];
                        $parentCtor = $stack[--$sp];
                        $this->sp = $sp;
                        $frame[self::F_PC] = $pc;
                        $this->invoke($parentCtor, $thisVal, $this->arrayToArgs($argsArr), $thisVal);
                        $stack[$sp++] = $thisVal;
                        break;
                    }
                    case Op::YIELD:
                    case Op::AWAIT: {
                        // Identical suspend mechanics either way -- only who
                        // drives the resume differs: `resumeGenerator`, called
                        // externally by `next`/`throw`/`return`, for YIELD;
                        // `resumeAsync`, called automatically off a promise
                        // reaction, for AWAIT (Vm::createAsyncCall).
                        $sentSlot = $code[$pc++];
                        $modeSlot = $code[$pc++];
                        $value = $stack[--$sp];
                        // Everything live in this frame right now -- locals
                        // plus whatever operand-stack values a partially
                        // evaluated enclosing expression left behind -- has
                        // to survive the suspension, relative to base so it
                        // replays at whatever base the resume lands on.
                        $saved = array_slice($stack, $base, $sp - $base);
                        $handlers = [];
                        foreach ($frame[self::F_HANDLERS] as $h) {
                            // $h[1] (sp) is rebased for the resume; $h[2] (env)
                            // is an object reference, not a stack offset, and
                            // carries through unchanged.
                            $handlers[] = [$h[0], $h[1] - $base, $h[2]];
                        }
                        $func = $frame[self::F_FUNC];
                        $thisVal = $frame[self::F_THIS];
                        $frameArgs = $frame[self::F_ARGS];
                        $argsObj = $frame[self::F_ARGSOBJ];
                        array_pop($frames);
                        $this->sp = $base;
                        return new FrameSuspend($value, [
                            'saved' => $saved,
                            'pc' => $pc,
                            'env' => $env,
                            'handlers' => $handlers,
                            'argsObj' => $argsObj,
                            'sentSlot' => $sentSlot,
                            'modeSlot' => $modeSlot,
                            'func' => $func,
                            'thisVal' => $thisVal,
                            'args' => $frameArgs,
                        ]);
                    }
                    case Op::YIELD_DELEGATE_STEP: {
                        $sentValue = $stack[--$sp];
                        $mode = $stack[--$sp];
                        $next = $stack[--$sp];
                        $iter = $stack[--$sp];
                        $this->sp = $sp;
                        [$value, $done] = $this->yieldDelegateStep($iter, $next, $mode, $sentValue);
                        $stack[$sp++] = $value;
                        $stack[$sp++] = $done;
                        break;
                    }
                    case Op::NEW_TAG_TEMPLATE: {
                        $key = $consts[$code[$pc++]];
                        $cooked = $consts[$code[$pc++]];
                        $raw = $consts[$code[$pc++]];
                        $this->sp = $sp;
                        $stack[$sp++] = $realm->templateObject($key, $cooked, $raw);
                        break;
                    }

                    case Op::PUSH_TDZ:
                        $stack[$sp++] = JSHole::$hole;
                        break;
                    case Op::TDZ_CHECK:
                        if ($stack[$sp - 1] === JSHole::$hole) {
                            $this->sp = $sp;
                            $this->throwError(
                                'ReferenceError',
                                "Cannot access '" . $consts[$code[$pc]] . "' before initialization"
                            );
                        }
                        $pc++;
                        break;
                    case Op::THROW_CONST:
                        $this->sp = $sp;
                        $this->throwError('TypeError', 'Assignment to constant variable.');
                        // no break (throwError does not return)
                    case Op::REQ_COERCIBLE:
                        if ($stack[$sp - 1] === null || $stack[$sp - 1] instanceof JSUndefined) {
                            $this->sp = $sp;
                            $this->throwError(
                                'TypeError',
                                'Cannot destructure ' . ($stack[$sp - 1] === null ? 'null' : 'undefined')
                            );
                        }
                        break;
                    case Op::COPY_REST: {
                        $nkeys = $code[$pc++];
                        $excluded = [];
                        for ($i = 0; $i < $nkeys; $i++) {
                            $excluded[$stack[--$sp]] = true;
                        }
                        $src = $stack[--$sp];
                        $this->sp = $sp;
                        // null and undefined contribute nothing rather than
                        // throwing: the pattern that reached here already ran
                        // RequireObjectCoercible if the spec asked for it.
                        $rest = new JSObject($realm->objectPrototype());
                        $this->copyDataProperties($rest, $src, $excluded);
                        $stack[$sp++] = $rest;
                        break;
                    }
                    case Op::ITER_GET: {
                        $obj = $stack[--$sp];
                        $this->sp = $sp;
                        [$iter, $next] = $this->getIterator($obj);
                        $stack[$sp++] = $iter;
                        $stack[$sp++] = $next;
                        break;
                    }
                    case Op::ITER_NEXT: {
                        $target = $code[$pc++];
                        $result = $stack[--$sp];
                        if (!$result instanceof JSObject) {
                            $this->sp = $sp;
                            $this->throwError('TypeError', 'Iterator result is not an object');
                        }
                        $this->sp = $sp;
                        if (Conversions::toBoolean($result->get('done', $this))) {
                            $pc = $target;
                            break;
                        }
                        $stack[$sp++] = $result->get('value', $this);
                        break;
                    }
                    case Op::ITER_CLOSE: {
                        $quiet = $code[$pc++];
                        $iter = $stack[--$sp];
                        $this->sp = $sp;
                        $this->closeIterator($iter, $quiet === 1);
                        break;
                    }
                    case Op::ITER_REC: {
                        $slot = $code[$pc++];
                        $obj = $stack[--$sp];
                        $this->sp = $sp;
                        [$iter, $next] = $this->getIterator($obj);
                        $stack[$base + $slot] = [$iter, $next, false];
                        break;
                    }
                    case Op::ITER_TAKE: {
                        $slot = $code[$pc++];
                        $rec = $stack[$base + $slot];
                        $this->sp = $sp;
                        if ($rec[2]) {
                            // Already finished: the rest of the pattern takes
                            // undefined without asking the iterator again.
                            $stack[$sp++] = JSUndefined::$undefined;
                            break;
                        }
                        // A step that throws leaves the iterator broken, and
                        // the spec does not close a broken iterator (8.5.2):
                        // the record is marked done before the throw escapes.
                        $result = $this->stepIterator($rec, $stack[$base + $slot][2]);
                        if ($result === null) {
                            $stack[$base + $slot][2] = true;
                            $stack[$sp++] = JSUndefined::$undefined;
                            break;
                        }
                        $stack[$sp++] = $result->get('value', $this);
                        break;
                    }
                    case Op::ITER_REST: {
                        $slot = $code[$pc++];
                        $rec = $stack[$base + $slot];
                        $this->sp = $sp;
                        $rest = new JSArray($realm->arrayPrototype());
                        $n = 0;
                        if (!$rec[2]) {
                            while (true) {
                                $this->checkDeadline();
                                $result = $this->stepIterator($rec, $stack[$base + $slot][2]);
                                if ($result === null) {
                                    break;
                                }
                                $rest->elements[$n++] = $result->get('value', $this);
                            }
                        }
                        $rest->length = $n;
                        // A rest element always drains the iterator, so the
                        // record is done and nothing is left to close.
                        $stack[$base + $slot][2] = true;
                        $stack[$sp++] = $rest;
                        break;
                    }
                    case Op::ITER_FIN: {
                        $slot = $code[$pc++];
                        $quiet = $code[$pc++];
                        $rec = $stack[$base + $slot];
                        if (is_array($rec) && !$rec[2]) {
                            $stack[$base + $slot][2] = true;
                            $this->sp = $sp;
                            $this->closeIterator($rec[0], $quiet === 1);
                        }
                        break;
                    }
                    case Op::ARR_APPEND: {
                        $v = $stack[--$sp];
                        $arr = $stack[$sp - 1];
                        if ($v !== JSHole::$hole) {
                            $arr->elements[$arr->length] = $v;
                        }
                        $arr->length++;
                        break;
                    }
                    case Op::ARR_SPREAD: {
                        $src = $stack[--$sp];
                        $arr = $stack[$sp - 1];
                        $this->sp = $sp;
                        foreach ($this->iterateToList($src) as $v) {
                            $arr->elements[$arr->length++] = $v;
                        }
                        break;
                    }
                    case Op::OBJ_SPREAD: {
                        $src = $stack[--$sp];
                        $obj = $stack[$sp - 1];
                        $this->sp = $sp;
                        $this->copyDataProperties($obj, $src, []);
                        break;
                    }
                    case Op::CALL_SPREAD: {
                        $argsArr = $stack[--$sp];
                        $thisVal = $stack[--$sp];
                        $fn = $stack[--$sp];
                        $this->sp = $sp;
                        $stack[$sp++] = $this->invoke($fn, $thisVal, $this->arrayToArgs($argsArr));
                        break;
                    }
                    case Op::NEW_SPREAD: {
                        $argsArr = $stack[--$sp];
                        $ctor = $stack[--$sp];
                        $this->sp = $sp;
                        $stack[$sp++] = $this->construct($ctor, $this->arrayToArgs($argsArr));
                        break;
                    }
                    case Op::TO_KEY:
                        // A string is already a key; anything else converts,
                        // and a symbol becomes the private string it is stored
                        // under, which GET_ELEM and COPY_REST both accept.
                        if (!is_string($stack[$sp - 1])) {
                            $this->sp = $sp;
                            $stack[$sp - 1] = $this->propertyKey($stack[$sp - 1]);
                        }
                        break;
                    case Op::REST_ARGS: {
                        $from = $code[$pc++];
                        $args = $frame[self::F_ARGS] ?? [];
                        $rest = new JSArray($realm->arrayPrototype());
                        for ($i = $from, $n = count($args); $i < $n; $i++) {
                            $rest->elements[$i - $from] = $args[$i];
                        }
                        $rest->length = max(0, count($args) - $from);
                        $stack[$sp++] = $rest;
                        break;
                    }
                    case Op::TOSTR:
                        if (!is_string($stack[$sp - 1])) {
                            $this->sp = $sp;
                            $stack[$sp - 1] = Conversions::toString($this, $stack[$sp - 1]);
                        }
                        break;
                    case Op::ADD: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a + $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a . $b;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = TypeOps::add($this, $a, $b);
                        }
                        break;
                    }
                    case Op::SUB: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $this->sp = $sp;
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $this->sp = $sp;
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = $a - $b;
                        break;
                    }
                    case Op::MUL: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $this->sp = $sp;
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $this->sp = $sp;
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = $a * $b;
                        break;
                    }
                    case Op::DIV: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $this->sp = $sp;
                            $a = Conversions::toNumber($this, $a);
                        }
                        if (!(is_int($b) || is_float($b))) {
                            $this->sp = $sp;
                            $b = Conversions::toNumber($this, $b);
                        }
                        $stack[$sp - 1] = fdiv((float)$a, (float)$b);
                        break;
                    }
                    case Op::MOD: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if (is_int($a) && is_int($b) && $b !== 0) {
                            // PHP % truncates toward zero, matching JS sign rules.
                            $stack[$sp - 1] = $a % $b;
                        } else {
                            if (!(is_int($a) || is_float($a))) {
                                $a = Conversions::toNumber($this, $a);
                            }
                            if (!(is_int($b) || is_float($b))) {
                                $b = Conversions::toNumber($this, $b);
                            }
                            $stack[$sp - 1] = fmod((float)$a, (float)$b);
                        }
                        break;
                    }
                    case Op::NEG: {
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $this->sp = $sp;
                            $a = Conversions::toNumber($this, $a);
                        }
                        // Negating int 0 must produce -0, which only float has.
                        $stack[$sp - 1] = $a === 0 ? -0.0 : -$a;
                        break;
                    }
                    case Op::TONUM: {
                        $a = $stack[$sp - 1];
                        if (!(is_int($a) || is_float($a))) {
                            $this->sp = $sp;
                            $stack[$sp - 1] = Conversions::toNumber($this, $a);
                        }
                        break;
                    }
                    case Op::NOT:
                        $stack[$sp - 1] = !Conversions::toBoolean($stack[$sp - 1]);
                        break;
                    case Op::BNOT: {
                        $a = $stack[$sp - 1];
                        $this->sp = $sp;
                        $i = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $a);
                        $stack[$sp - 1] = ~$i;
                        break;
                    }
                    case Op::TYPEOF:
                        $stack[$sp - 1] = TypeOps::typeofOp($stack[$sp - 1]);
                        break;

                    case Op::BAND:
                    case Op::BOR:
                    case Op::BXOR:
                    case Op::SHL:
                    case Op::SHR:
                    case Op::USHR: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $this->sp = $sp;
                        $a = is_int($a) ? ((($a & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $a);
                        $b = is_int($b) ? ((($b & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000) : Conversions::toInt32($this, $b);
                        $stack[$sp - 1] = match ($op) {
                            Op::BAND => $a & $b,
                            Op::BOR => $a | $b,
                            Op::BXOR => $a ^ $b,
                            Op::SHL => (((($a << ($b & 31)) & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000),
                            Op::SHR => $a >> ($b & 31),
                            Op::USHR => ($a & 0xFFFFFFFF) >> ($b & 31),
                        };
                        break;
                    }

                    case Op::EQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a == $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a === $b;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = TypeOps::looseEquals($this, $a, $b);
                        }
                        break;
                    }
                    case Op::NEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a != $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = $a !== $b;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = !TypeOps::looseEquals($this, $a, $b);
                        }
                        break;
                    }
                    case Op::SEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $stack[$sp - 1] = (is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b;
                        break;
                    }
                    case Op::JSEQ: {
                        $t = $code[$pc++];
                        $b = $stack[--$sp];
                        $a = $stack[--$sp];
                        if ((is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::JSNEQ: {
                        $t = $code[$pc++];
                        $b = $stack[--$sp];
                        $a = $stack[--$sp];
                        if (!((is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b)) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::SNEQ: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        $stack[$sp - 1] = !((is_int($a) || is_float($a))
                            ? ((is_int($b) || is_float($b)) && $a == $b)
                            : $a === $b);
                        break;
                    }
                    case Op::LT: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a < $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) < 0;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = TypeOps::lessThan($this, $a, $b, true) ?? false;
                        }
                        break;
                    }
                    case Op::GT: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a > $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) > 0;
                        } else {
                            $this->sp = $sp;
                            $stack[$sp - 1] = TypeOps::lessThan($this, $b, $a, false) ?? false;
                        }
                        break;
                    }
                    case Op::LE: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a <= $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) <= 0;
                        } else {
                            $this->sp = $sp;
                            $r = TypeOps::lessThan($this, $b, $a, false);
                            $stack[$sp - 1] = $r === null ? false : !$r;
                        }
                        break;
                    }
                    case Op::GE: {
                        $b = $stack[--$sp];
                        $a = $stack[$sp - 1];
                        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                            $stack[$sp - 1] = $a >= $b;
                        } elseif (is_string($a) && is_string($b)) {
                            $stack[$sp - 1] = strcmp($a, $b) >= 0;
                        } else {
                            $this->sp = $sp;
                            $r = TypeOps::lessThan($this, $a, $b, true);
                            $stack[$sp - 1] = $r === null ? false : !$r;
                        }
                        break;
                    }
                    case Op::IN_OP: {
                        $obj = $stack[--$sp];
                        $this->sp = $sp;
                        $stack[$sp - 1] = TypeOps::inOp($this, $stack[$sp - 1], $obj);
                        break;
                    }
                    case Op::INSTANCEOF: {
                        $ctor = $stack[--$sp];
                        $this->sp = $sp;
                        $stack[$sp - 1] = TypeOps::instanceofOp($this, $stack[$sp - 1], $ctor);
                        break;
                    }

                    case Op::JMP:
                        $pc = $code[$pc];
                        break;
                    case Op::JT: {
                        $t = $code[$pc++];
                        $v = $stack[--$sp];
                        if ($v === true ? true : ($v === false ? false : Conversions::toBoolean($v))) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::JF: {
                        $t = $code[$pc++];
                        $v = $stack[--$sp];
                        if (!($v === true ? true : ($v === false ? false : Conversions::toBoolean($v)))) {
                            $pc = $t;
                        }
                        break;
                    }
                    case Op::JT_KEEP: {
                        $t = $code[$pc++];
                        if (Conversions::toBoolean($stack[$sp - 1])) {
                            $pc = $t;
                        } else {
                            $sp--;
                        }
                        break;
                    }
                    case Op::JF_KEEP: {
                        $t = $code[$pc++];
                        if (!Conversions::toBoolean($stack[$sp - 1])) {
                            $pc = $t;
                        } else {
                            $sp--;
                        }
                        break;
                    }
                    case Op::JNN_KEEP: {
                        $t = $code[$pc++];
                        $v = $stack[$sp - 1];
                        if (!($v === null || $v instanceof JSUndefined)) {
                            $pc = $t;
                        } else {
                            $sp--;
                        }
                        break;
                    }

                    case Op::CALL: {
                        $argc = $code[$pc++];
                        $funcPos = $sp - $argc - 2;
                        $func = $stack[$funcPos];
                        if ($func instanceof JSFunction) {
                            if ($func->isClassConstructor) {
                                // 15.7.14's [[Call]] override: a class
                                // constructor may only be invoked through
                                // `new`. SUPER_CALL is the one caller allowed
                                // to reach one this way, and it does not use
                                // this opcode.
                                $this->sp = $sp;
                                $this->throwError(
                                    'TypeError',
                                    "Class constructor {$func->name} cannot be invoked without 'new'"
                                );
                            }
                            if ($func->nativeId !== null) {
                                // Ahead-of-time compiled body: an ordinary
                                // native call, no frame (docs/aot-php.md §3).
                                $args = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $args[] = $stack[$funcPos + 2 + $i];
                                }
                                if ($func->isArrow) {
                                    $thisVal = $func->lexicalThis;
                                } else {
                                    $thisVal = $stack[$funcPos + 1];
                                    if (!$func->template['strict']) {
                                        $thisVal = $this->coerceThis($thisVal);
                                    }
                                }
                                $sp = $funcPos;
                                $this->sp = $sp;
                                $frame[self::F_PC] = $pc;
                                $stack[$sp++] = (BuiltinRegistry::get($func->nativeId))($this, $thisVal, $args, $func);
                                break;
                            }
                            if ($func->isGenerator) {
                                // [[Call]] on a generator function creates a
                                // Generator object instead of running the
                                // body -- no frame to push here at all.
                                $args = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $args[] = $stack[$funcPos + 2 + $i];
                                }
                                $thisVal = $stack[$funcPos + 1];
                                $sp = $funcPos;
                                $this->sp = $sp;
                                $frame[self::F_PC] = $pc;
                                $stack[$sp++] = $this->createGenerator($func, $thisVal, $args);
                                break;
                            }
                            if ($func->isAsync) {
                                // [[Call]] on an async function runs the body
                                // synchronously up to its first `await` (or to
                                // completion) and always returns a Promise --
                                // no frame to push here at all, same shape as
                                // the generator case above.
                                $args = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $args[] = $stack[$funcPos + 2 + $i];
                                }
                                $thisVal = $func->isArrow ? $func->lexicalThis : $stack[$funcPos + 1];
                                $sp = $funcPos;
                                $this->sp = $sp;
                                $frame[self::F_PC] = $pc;
                                $stack[$sp++] = $this->createAsyncCall($func, $thisVal, $args);
                                break;
                            }
                            if ($fi + 1 >= self::MAX_FRAMES) {
                                $this->throwError('RangeError', 'Maximum call stack size exceeded');
                            }
                            $frame[self::F_PC] = $pc;
                            $ntpl = $func->template;
                            $nbase = $funcPos + 2;
                            $argsArr = null;
                            if ($ntpl['usesArgs']) {
                                $argsArr = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $argsArr[] = $stack[$nbase + $i];
                                }
                            }
                            $nlocals = $ntpl['nlocals'];
                            for ($i = ($argc < $ntpl['nparams'] ? $argc : $ntpl['nparams']); $i < $nlocals; $i++) {
                                $stack[$nbase + $i] = $und;
                            }
                            $sp = $nbase + $nlocals;
                            if ($func->isArrow) {
                                // No `this` binding of its own, and no coercion:
                                // the arrow reads the one it closed over.
                                $thisVal = $func->lexicalThis;
                            } else {
                                $thisVal = $stack[$funcPos + 1];
                                if (!$ntpl['strict']) {
                                    if ($thisVal === null || $thisVal instanceof JSUndefined) {
                                        $thisVal = $realm->globalObject;
                                    } elseif (!$thisVal instanceof JSObject) {
                                        $thisVal = Conversions::toObject($this, $thisVal);
                                    }
                                }
                            }
                            $nenv = $ntpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($func->env, $ntpl['nenv']) : $func->env;
                            $frames[] = [
                                $ntpl, $nbase, 0, $nenv, $thisVal,
                                $argsArr, [], $func, null, $funcPos, null,
                            ];
                            $fi++;
                            $frame = &$frames[$fi];
                            $tpl = $ntpl;
                            $code = $ntpl['code'];
                            $consts = $ntpl['consts'];
                            $strict = $ntpl['strict'];
                            $base = $nbase;
                            $env = $nenv;
                            $pc = 0;
                        } else {
                            $args = [];
                            for ($i = 0; $i < $argc; $i++) {
                                $args[] = $stack[$funcPos + 2 + $i];
                            }
                            $thisVal = $stack[$funcPos + 1];
                            $sp = $funcPos;
                            $this->sp = $sp;
                            $frame[self::F_PC] = $pc;
                            if ($func instanceof JSNativeFunction) {
                                $ret = (BuiltinRegistry::get($func->fnId))($this, $thisVal, $args, $func);
                            } elseif ($func instanceof JSBoundFunction) {
                                $ret = $this->invoke($func, $thisVal, $args);
                            } else {
                                $this->throwError('TypeError', TypeOps::typeofOp($func) . ' value is not a function');
                            }
                            $stack[$sp++] = $ret;
                        }
                        break;
                    }

                    case Op::NEW_OP: {
                        $argc = $code[$pc++];
                        $ctorPos = $sp - $argc - 1;
                        $ctor = $stack[$ctorPos];
                        if ($ctor instanceof JSFunction && $ctor->nativeId === null
                            && !$ctor->isArrow && !$ctor->isGenerator && !$ctor->isAsync) {
                            if ($fi + 1 >= self::MAX_FRAMES) {
                                $this->throwError('RangeError', 'Maximum call stack size exceeded');
                            }
                            $proto = $ctor->get('prototype', $this);
                            $newObj = new JSObject($proto instanceof JSObject ? $proto : $realm->objectPrototype());
                            // Shift args up one cell to make room for the `this` slot,
                            // matching the CALL layout [func this args...].
                            for ($i = $sp; $i > $ctorPos + 1; $i--) {
                                $stack[$i] = $stack[$i - 1];
                            }
                            $sp++;
                            $stack[$ctorPos + 1] = $newObj;
                            $frame[self::F_PC] = $pc;
                            $ntpl = $ctor->template;
                            $nbase = $ctorPos + 2;
                            $argsArr = null;
                            if ($ntpl['usesArgs']) {
                                $argsArr = [];
                                for ($i = 0; $i < $argc; $i++) {
                                    $argsArr[] = $stack[$nbase + $i];
                                }
                            }
                            $nlocals = $ntpl['nlocals'];
                            for ($i = ($argc < $ntpl['nparams'] ? $argc : $ntpl['nparams']); $i < $nlocals; $i++) {
                                $stack[$nbase + $i] = $und;
                            }
                            $sp = $nbase + $nlocals;
                            $nenv = $ntpl['nenv'] > 0 ? new \PhpJs\Runtime\JSEnv($ctor->env, $ntpl['nenv']) : $ctor->env;
                            $frames[] = [
                                $ntpl, $nbase, 0, $nenv, $newObj,
                                $argsArr, [], $ctor, $newObj, $ctorPos, null,
                            ];
                            $fi++;
                            $frame = &$frames[$fi];
                            $tpl = $ntpl;
                            $code = $ntpl['code'];
                            $consts = $ntpl['consts'];
                            $strict = $ntpl['strict'];
                            $base = $nbase;
                            $env = $nenv;
                            $pc = 0;
                        } else {
                            $args = [];
                            for ($i = 0; $i < $argc; $i++) {
                                $args[] = $stack[$ctorPos + 1 + $i];
                            }
                            $sp = $ctorPos;
                            $this->sp = $sp;
                            $frame[self::F_PC] = $pc;
                            $stack[$sp++] = $this->construct($ctor, $args);
                        }
                        break;
                    }

                    case Op::RETURN_UNDEF:
                        $stack[$sp++] = $und;
                        // fallthrough
                    case Op::RETURN: {
                        $ret = $stack[--$sp];
                        $old = array_pop($frames);
                        $fi--;
                        if ($old[self::F_CTOR] !== null && !($ret instanceof JSObject)) {
                            $ret = $old[self::F_CTOR];
                        }
                        if ($fi < $floor) {
                            $this->sp = $old[self::F_RETSP];
                            return $ret;
                        }
                        $frame = &$frames[$fi];
                        $tpl = $frame[self::F_TPL];
                        $code = $tpl['code'];
                        $consts = $tpl['consts'];
                        $strict = $tpl['strict'];
                        $base = $frame[self::F_BASE];
                        $env = $frame[self::F_ENV];
                        $pc = $frame[self::F_PC];
                        $sp = $old[self::F_RETSP];
                        $stack[$sp++] = $ret;
                        break;
                    }
                    case Op::SET_COMPLETION:
                        $this->completion = $stack[--$sp];
                        break;
                    case Op::RETURN_COMPLETION: {
                        $ret = $this->completion;
                        $old = array_pop($frames);
                        $fi--;
                        if ($fi < $floor) {
                            $this->sp = $old[self::F_RETSP];
                            return $ret;
                        }
                        $frame = &$frames[$fi];
                        $tpl = $frame[self::F_TPL];
                        $code = $tpl['code'];
                        $consts = $tpl['consts'];
                        $strict = $tpl['strict'];
                        $base = $frame[self::F_BASE];
                        $env = $frame[self::F_ENV];
                        $pc = $frame[self::F_PC];
                        $sp = $old[self::F_RETSP];
                        $stack[$sp++] = $ret;
                        break;
                    }

                    case Op::NEW_OBJECT:
                        $stack[$sp++] = new JSObject($realm->objectPrototype());
                        break;
                    case Op::NEW_ARRAY: {
                        $n = $code[$pc++];
                        $arr = new JSArray($realm->arrayPrototype());
                        $start = $sp - $n;
                        $hole = JSHole::$hole;
                        for ($i = 0; $i < $n; $i++) {
                            $v = $stack[$start + $i];
                            if ($v !== $hole) {
                                $arr->elements[$i] = $v;
                            }
                        }
                        $arr->length = $n;
                        $sp = $start;
                        $stack[$sp++] = $arr;
                        break;
                    }
                    case Op::NEW_FUNC: {
                        $fn = new JSFunction($tpl['children'][$code[$pc++]], $env, $realm);
                        if ($fn->isArrow) {
                            $fn->lexicalThis = $frame[self::F_THIS];
                        }
                        $stack[$sp++] = $fn;
                        break;
                    }
                    case Op::NEW_REGEXP: {
                        $pattern = $consts[$code[$pc++]];
                        $flags = $consts[$code[$pc++]];
                        $pcre = $consts[$code[$pc++]];
                        $this->sp = $sp;
                        $stack[$sp++] = $realm->createRegExp($pattern, $flags, $pcre);
                        break;
                    }
                    case Op::PUSH_THIS:
                        $stack[$sp++] = $frame[self::F_THIS];
                        break;
                    case Op::PUSH_CALLEE:
                        $stack[$sp++] = $frame[self::F_FUNC];
                        break;
                    case Op::ARGUMENTS: {
                        if ($frame[self::F_ARGSOBJ] === null) {
                            $args = $frame[self::F_ARGS] ?? [];
                            $obj = new \PhpJs\Runtime\JSArgumentsObject($realm->objectPrototype(), $env);
                            foreach ($args as $i => $v) {
                                $obj->props[(string)$i] = $v;
                            }
                            $obj->defineOwnData('length', count($args), JSObject::W | JSObject::C);
                            // `arguments` is iterable, and with the very same
                            // function object as `Array.prototype.values`
                            // (10.4.4.6), which is observable by identity.
                            $obj->defineOwnData(
                                $realm->wellKnownSymbol('iterator')->propertyKey,
                                $realm->arrayPrototype()->get('values', $this),
                                JSObject::W | JSObject::C
                            );
                            if ($strict) {
                                // Strict arguments objects are unmapped and
                                // poison callee/caller.
                                $thrower = $realm->nativeFn('Function.prototype.restricted', '', 0);
                                foreach (['callee', 'caller'] as $poisoned) {
                                    $obj->defineOwnAccessor($poisoned, $thrower, $thrower, 0);
                                }
                            } else {
                                if ($frame[self::F_FUNC] !== null) {
                                    $obj->defineOwnData('callee', $frame[self::F_FUNC], JSObject::W | JSObject::C);
                                }
                                foreach ($tpl['argMap'] as $i => $envIndex) {
                                    if ($envIndex >= 0 && $i < count($args)) {
                                        $obj->map[$i] = $envIndex;
                                    }
                                }
                            }
                            $frame[self::F_ARGSOBJ] = $obj;
                        }
                        $stack[$sp++] = $frame[self::F_ARGSOBJ];
                        break;
                    }

                    case Op::THROW:
                        $exc = $stack[--$sp];
                        goto unwind;
                    case Op::TRY_ENTER:
                        // $env travels with the handler so that unwinding to
                        // it restores whatever environment was live when this
                        // try was entered -- in particular, a loop's own
                        // per-iteration environment if the try sits inside
                        // one, or the loop's outer environment if it sits
                        // outside, either way with no separate mechanism
                        // needed just because a loop is involved.
                        $frame[self::F_HANDLERS][] = [$code[$pc++], $sp, $env];
                        break;
                    case Op::TRY_LEAVE:
                        array_pop($frame[self::F_HANDLERS]);
                        break;

                    case Op::FORIN_INIT: {
                        $slot = $code[$pc++];
                        $v = $stack[--$sp];
                        $this->sp = $sp;
                        if ($v === null || $v instanceof JSUndefined) {
                            $stack[$base + $slot] = [[], 0, null];
                        } else {
                            $o = Conversions::toObject($this, $v);
                            $seen = [];
                            $keys = [];
                            for ($p = $o; $p !== null; $p = $p->proto) {
                                foreach ($p->ownEnumerableKeys() as $k) {
                                    if (!isset($seen[$k])) {
                                        $seen[$k] = true;
                                        $keys[] = $k;
                                    }
                                }
                            }
                            $stack[$base + $slot] = [$keys, 0, $o];
                        }
                        break;
                    }
                    case Op::FORIN_NEXT: {
                        $slot = $code[$pc++];
                        $target = $code[$pc++];
                        $it = $stack[$base + $slot];
                        $n = count($it[0]);
                        $i = $it[1];
                        $pushed = false;
                        while ($i < $n) {
                            $k = $it[0][$i++];
                            // Properties deleted during iteration must be skipped.
                            if ($it[2] === null || $it[2]->hasProperty($k)) {
                                $stack[$sp++] = $k;
                                $pushed = true;
                                break;
                            }
                        }
                        $stack[$base + $slot][1] = $i;
                        if (!$pushed) {
                            $pc = $target;
                        }
                        break;
                    }

                    case Op::INC_LOCAL: {
                        $slot = $base + $code[$pc++];
                        $v = $stack[$slot];
                        if (is_int($v)) {
                            $stack[$slot] = $v + 1;
                        } else {
                            $this->sp = $sp;
                            $n = (is_float($v)) ? $v : Conversions::toNumber($this, $v);
                            $stack[$slot] = $n + 1;
                        }
                        break;
                    }
                    case Op::DEC_LOCAL: {
                        $slot = $base + $code[$pc++];
                        $v = $stack[$slot];
                        if (is_int($v)) {
                            $stack[$slot] = $v - 1;
                        } else {
                            $this->sp = $sp;
                            $n = (is_float($v)) ? $v : Conversions::toNumber($this, $v);
                            $stack[$slot] = $n - 1;
                        }
                        break;
                    }

                    case Op::NOP:
                        break;

                    default:
                        throw new \LogicException('VM bug: unknown opcode ' . Op::name($op) . " at pc=" . ($pc - 1));
                }
                continue;
            } catch (JSThrowSignal $sig) {
                $exc = $sig->value;
            }

            unwind:
            $fi = count($frames) - 1;
            for (;;) {
                if ($fi < $floor) {
                    $retsp = $frames[$floor][self::F_RETSP];
                    array_splice($frames, $floor);
                    $this->sp = $retsp;
                    throw new JSThrowSignal($exc);
                }
                if (!empty($frames[$fi][self::F_HANDLERS])) {
                    break;
                }
                $fi--;
            }
            if ($fi < count($frames) - 1) {
                array_splice($frames, $fi + 1);
            }
            $frame = &$frames[$fi];
            $h = array_pop($frame[self::F_HANDLERS]);
            $tpl = $frame[self::F_TPL];
            $code = $tpl['code'];
            $consts = $tpl['consts'];
            $strict = $tpl['strict'];
            $base = $frame[self::F_BASE];
            $env = $h[2];
            $pc = $h[0];
            $sp = $h[1];
            $stack[$sp++] = $exc;
        }
    }

    // ---- Slow-path member access -------------------------------------------

    public function getMember(mixed $obj, mixed $key): mixed
    {
        if ($obj instanceof JSObject) {
            return $obj->get($this->propertyKey($key), $this);
        }
        if (is_string($obj)) {
            if ($key === 'length') {
                return StringOps::length16($obj);
            }
            if (is_int($key)) {
                return StringOps::charAt($obj, $key) ?? JSUndefined::$undefined;
            }
            $k = $this->propertyKey($key);
            if ($k === 'length') {
                return StringOps::length16($obj);
            }
            $idx = JSArray::asIndex($k);
            if ($idx !== null) {
                return StringOps::charAt($obj, $idx) ?? JSUndefined::$undefined;
            }
            return $this->realm->stringPrototype()->get($k, $this, $obj);
        }
        if (is_int($obj) || is_float($obj)) {
            return $this->realm->numberPrototype()->get($this->propertyKey($key), $this, $obj);
        }
        if (is_bool($obj)) {
            return $this->realm->booleanPrototype()->get($this->propertyKey($key), $this, $obj);
        }
        if ($obj instanceof \PhpJs\Runtime\JSSymbol) {
            return $this->realm->symbolPrototype()->get($this->propertyKey($key), $this, $obj);
        }
        $desc = is_string($key) || is_int($key) ? " '" . $key . "'" : '';
        $this->throwError('TypeError', 'Cannot read properties of '
            . ($obj === null ? 'null' : 'undefined') . " (reading$desc)");
    }

    public function setMember(mixed $obj, mixed $key, mixed $value, bool $strict): void
    {
        if ($obj instanceof JSObject) {
            $obj->set($this->propertyKey($key), $value, $this, $strict);
            return;
        }
        if ($obj === null || $obj instanceof JSUndefined) {
            $this->throwError('TypeError', 'Cannot set properties of ' . ($obj === null ? 'null' : 'undefined'));
        }
        // Assignment to properties of other primitives is a silent no-op
        // (strict mode: TypeError). Prototype setters are not consulted.
        if ($strict) {
            $this->throwError('TypeError', 'Cannot create property on primitive value');
        }
    }

    public function deleteMember(mixed $obj, mixed $key, bool $strict): bool
    {
        if ($obj instanceof JSObject) {
            return $obj->deleteKey($this->propertyKey($key), $this, $strict);
        }
        if ($obj === null || $obj instanceof JSUndefined) {
            $this->throwError('TypeError', 'Cannot convert ' . ($obj === null ? 'null' : 'undefined') . ' to object');
        }
        return true;
    }

    /**
     * GetIterator (7.4.2). Returns [iterator, nextMethod].
     *
     * `next` is read once, here, because a step must not re-read it: the spec
     * captures it with the iterator, and a getter or a mutated iterator makes
     * the difference observable.
     *
     * @return array{0: JSObject, 1: mixed}
     */
    public function getIterator(mixed $obj): array
    {
        $key = $this->realm->wellKnownSymbol('iterator')->propertyKey;
        $method = ($obj === null || $obj instanceof JSUndefined)
            ? JSUndefined::$undefined
            : $this->getMember($obj, $key);
        if ($method === null || $method instanceof JSUndefined) {
            $this->throwError(
                'TypeError',
                Conversions::toString($this, TypeOps::typeofOp($obj)) === 'undefined'
                    ? 'undefined is not iterable'
                    : $this->describe($obj) . ' is not iterable'
            );
        }
        $iter = $this->invoke($method, $obj, []);
        if (!$iter instanceof JSObject) {
            $this->throwError('TypeError', 'Result of the Symbol.iterator method is not an object');
        }
        return [$iter, $iter->get('next', $this)];
    }

    /**
     * Enforce the wall-clock limit from a native loop.
     *
     * The dispatch loop checks the deadline every DEADLINE_CHECK_INTERVAL
     * instructions, but draining an iterator happens in PHP: an iterable that
     * never reports done would spin outside the VM entirely, which is exactly
     * the runaway the limit exists to stop.
     */
    private function checkDeadline(): void
    {
        if ($this->deadline !== null && microtime(true) > $this->deadline) {
            $this->deadline = null;                 // let the unwind finish
            $this->throwError('RangeError', 'Script execution timed out');
        }
    }

    /**
     * One step of a destructuring iterator: the result object, or null when the
     * iterator says done. `$done` is set through a reference so it is already
     * true if the step throws -- a broken iterator is not closed (8.5.2).
     *
     * @param array{0: JSObject, 1: mixed, 2: bool} $rec
     */
    private function stepIterator(array $rec, bool &$done): ?JSObject
    {
        $done = true;
        $result = $this->invoke($rec[1], $rec[0], []);
        if (!$result instanceof JSObject) {
            $this->throwError('TypeError', 'Iterator result is not an object');
        }
        if (Conversions::toBoolean($result->get('done', $this))) {
            return null;
        }
        $done = false;
        return $result;
    }

    /**
     * IterableToList (7.4.11): drain an iterable. Used by spread, where the
     * whole sequence is wanted at once rather than one element at a time.
     *
     * @return list<mixed>
     */
    public function iterateToList(mixed $obj): array
    {
        [$iter, $next] = $this->getIterator($obj);
        $out = [];
        while (true) {
            $this->checkDeadline();
            $result = $this->invoke($next, $iter, []);
            if (!$result instanceof JSObject) {
                $this->throwError('TypeError', 'Iterator result is not an object');
            }
            if (Conversions::toBoolean($result->get('done', $this))) {
                return $out;
            }
            $out[] = $result->get('value', $this);
        }
    }

    /**
     * CopyDataProperties (7.3.25): own enumerable properties of $src onto
     * $target, skipping $excluded. Shared by `{...src}` and by a rest element
     * in an object pattern, which are the same operation.
     *
     * @param array<string, true> $excluded
     */
    public function copyDataProperties(JSObject $target, mixed $src, array $excluded): void
    {
        if ($src === null || $src instanceof JSUndefined) {
            return;
        }
        $from = Conversions::toObject($this, $src);
        foreach ($from->ownEnumerableKeys() as $k) {
            if (!isset($excluded[$k])) {
                $target->defineOwnData($k, $from->get($k, $this));
            }
        }
        foreach ($from->ownSymbolKeys() as $k) {
            $d = $from->ownDescriptor($k);
            if ($d !== null && ($d[2] & JSObject::E) && !isset($excluded[$k])) {
                $target->defineOwnData($k, $from->get($k, $this));
            }
        }
    }

    /**
     * The argument list a spread call passes on. The array was built by this
     * compiler and is always dense, so its elements are the arguments.
     *
     * @return list<mixed>
     */
    private function arrayToArgs(mixed $arr): array
    {
        if (!$arr instanceof JSArray) {
            return [];
        }
        $args = [];
        $und = JSUndefined::$undefined;
        for ($i = 0; $i < $arr->length; $i++) {
            $args[] = $arr->elements[$i] ?? $und;
        }
        return $args;
    }

    /**
     * IteratorClose (7.4.9). A missing `return` is not an error, and while
     * unwinding a throw an error from `return` is discarded in favour of the
     * exception already in flight.
     */
    public function closeIterator(mixed $iter, bool $unwindingThrow): void
    {
        if (!$iter instanceof JSObject) {
            return;
        }
        try {
            $ret = $iter->get('return', $this);
            if ($ret === null || $ret instanceof JSUndefined) {
                return;
            }
            $result = $this->invoke($ret, $iter, []);
            if (!$unwindingThrow && !$result instanceof JSObject) {
                $this->throwError('TypeError', 'Iterator result is not an object');
            }
        } catch (\Throwable $e) {
            if (!$unwindingThrow) {
                throw $e;
            }
            // Swallowed: the completion that started the unwind wins.
        }
    }

    /** A short description of a value for a "not iterable" message. */
    private function describe(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return '"' . $v . '"';
        }
        if ($v instanceof JSObject) {
            return $v instanceof JSFunctionBase ? 'function' : ($v->className ?? 'object');
        }
        return Conversions::toString($this, $v);
    }

    /**
     * ToPropertyKey: the single place a JS value becomes a property-table key.
     *
     * Symbols are the reason this is public and the reason it is one function.
     * Property tables are string-keyed PHP arrays (DESIGN.md §5), so each symbol
     * carries one private string and this is where the substitution happens --
     * see JSSymbol. Ahead-of-time compiled code calls it through `Ops`, so the
     * two paths cannot disagree about what a key is.
     */
    public function propertyKey(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }
        if (is_int($key)) {
            return (string)$key;
        }
        if ($key instanceof \PhpJs\Runtime\JSSymbol) {
            return $key->propertyKey;
        }
        return Conversions::toString($this, $key);
    }

    // ---- Globals -----------------------------------------------------------

    public function globalGet(string $name): mixed
    {
        $g = $this->realm->globalObject;
        if ($g->hasProperty($name)) {
            return $g->get($name, $this);
        }
        $this->throwError('ReferenceError', "$name is not defined");
    }

    public function globalSet(string $name, mixed $value, bool $strict): void
    {
        $g = $this->realm->globalObject;
        if ($strict && !$g->hasProperty($name)) {
            $this->throwError('ReferenceError', "$name is not defined");
        }
        $g->set($name, $value, $this, $strict);
    }

    // ---- Errors ------------------------------------------------------------

    /** @return never */
    public function throwError(string $kind, string $message): mixed
    {
        throw new JSThrowSignal($this->realm->createError($kind, $message));
    }

    public function throwValue(mixed $value): never
    {
        throw new JSThrowSignal($value);
    }

    /**
     * Whether the code that is currently executing is strict. `eval` consults
     * this so evaluated source inherits the caller's mode, which is the part of
     * direct-eval semantics that is observable without scope injection
     * (DESIGN.md §15).
     */
    public function callerIsStrict(): bool
    {
        $top = end($this->frames);
        return $top !== false && $top[self::F_TPL]['strict'];
    }

    /** Current JS stack description, innermost first (for Error.stack). */
    public function captureStack(): string
    {
        $lines = [];
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            $f = $this->frames[$i];
            $tpl = $f[self::F_TPL];
            $name = $tpl['name'] !== '' ? $tpl['name'] : '<anonymous>';
            $line = $this->lineForPc($tpl, $f[self::F_PC]);
            $lines[] = "    at $name" . ($line > 0 ? " (line $line)" : '');
        }
        return implode("\n", $lines);
    }

    private function lineForPc(array $tpl, int $pc): int
    {
        $line = 0;
        foreach ($tpl['lines'] as $entry) {
            if ($entry[0] > $pc) {
                break;
            }
            $line = $entry[1];
        }
        return $line;
    }
}

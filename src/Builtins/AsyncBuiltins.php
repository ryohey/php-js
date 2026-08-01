<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSPromise;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Vm\Vm;

/**
 * The microtask-queue side of `await` (DESIGN.md §2.5): a promise reaction
 * registered by `Vm::scheduleAsyncResume`, never JS-visible (nothing holds a
 * reference to it -- it travels only as the callback `PromiseBuiltins::then`
 * enqueues, the same way `Promise.reactionJob`/`Promise.thenableJob` do).
 * The state machine itself lives on `Vm::resumeAsync`; this is just the
 * entry point `BuiltinRegistry` needs to invoke it from a job.
 */
final class AsyncBuiltins
{
    public static function entries(): array
    {
        return [
            'Async.resumeJob' => [self::class, 'resumeJob'],
        ];
    }

    public static function resumeJob(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        /** @var array{0: JSPromise, 1: array, 2: int} $data */
        $data = $fn->data;
        [$promise, $state, $mode] = $data;
        $value = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $vm->resumeAsync($promise, $state, $mode, $value);
        return JSUndefined::$undefined;
    }
}

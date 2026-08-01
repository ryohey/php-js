<?php

declare(strict_types=1);

namespace PhpJs\Vm;

/**
 * Internal signal: `execute()`'s return value when a `YIELD` or `AWAIT`
 * opcode suspended the frame it was run for, rather than the frame returning
 * normally. Never crosses into JS-visible values or the heap -- it is
 * consumed exactly once, by whichever of `Vm::resumeGenerator()`'s or
 * `Vm::resumeAsync()`'s calls to `execute()` produced it.
 *
 * `func`/`thisVal`/`args` make the state self-contained: a generator's own
 * resume reads them from its `JSGeneratorObject` instead (redundant but
 * harmless), but an async call has no such wrapper object to hold them, so
 * they travel with the suspend itself.
 */
final class FrameSuspend
{
    /**
     * @param array{
     *     saved: list<mixed>,
     *     pc: int,
     *     env: ?\PhpJs\Runtime\JSEnv,
     *     handlers: list<array{0: int, 1: int, 2: ?\PhpJs\Runtime\JSEnv}>,
     *     argsObj: ?\PhpJs\Runtime\JSObject,
     *     sentSlot: int,
     *     modeSlot: int,
     *     func: \PhpJs\Runtime\JSFunction,
     *     thisVal: mixed,
     *     args: ?array,
     * } $state
     */
    public function __construct(
        public mixed $value,
        public array $state,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Vm;

/**
 * Internal signal: `execute()`'s return value when a `YIELD` opcode
 * suspended the frame it was run for, rather than the frame returning
 * normally. Never crosses into JS-visible values or the heap -- it is
 * consumed exactly once, by whichever of `Vm::resumeGenerator()`'s calls to
 * `execute()` produced it.
 */
final class GeneratorSuspend
{
    /**
     * @param array{
     *     saved: list<mixed>,
     *     pc: int,
     *     env: ?\PhpJs\Runtime\JSEnv,
     *     handlers: list<array{0: int, 1: int}>,
     *     argsObj: ?\PhpJs\Runtime\JSObject,
     *     sentSlot: int,
     *     modeSlot: int,
     * } $state
     */
    public function __construct(
        public mixed $value,
        public array $state,
    ) {
    }
}

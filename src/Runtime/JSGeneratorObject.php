<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A Generator instance (27.5). Everything needed to resume it -- the
 * suspended frame's locals/operand-stack region, its `pc`, and its
 * exception-handler table -- is plain JS-heap-safe data (DESIGN.md §11):
 * arrays and values that were already reachable from the live frame, never a
 * PHP closure or resource. `$func`/`$thisVal`/`$args` are fixed for the
 * generator's whole lifetime, bound once when it was created ([[Call]] on a
 * generator function creates one of these instead of running the body).
 */
final class JSGeneratorObject extends JSObject
{
    public const SUSPENDED_YIELD = 'suspendedYield';
    public const EXECUTING = 'executing';
    public const COMPLETED = 'completed';

    /**
     * Always suspendedYield on construction: `Vm::createGenerator()` runs
     * FunctionDeclarationInstantiation (parameter binding, hoisting) then
     * suspends at a compiled barrier before the first body statement
     * (Compiler::genFunction), so there is no distinct "not started yet"
     * state to represent -- `$suspended` is always populated by the time
     * a caller ever sees this object.
     */
    public string $state = self::SUSPENDED_YIELD;

    /**
     * Captured by a `YIELD` opcode while suspended; null only while
     * `$state` is `executing` or `completed`. See `Vm\FrameSuspend` for
     * the shape.
     * @var array<string, mixed>|null
     */
    public ?array $suspended = null;

    public function __construct(
        ?JSObject $proto,
        public JSFunction $func,
        public mixed $thisVal,
        /** @var list<mixed> */
        public array $args,
    ) {
        parent::__construct($proto);
        $this->className = 'Generator';
    }
}

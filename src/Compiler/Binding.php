<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/** A statically resolved variable binding (DESIGN.md §2.2). */
final class Binding
{
    public bool $captured = false;
    /** Local frame slot when not captured. */
    public int $slot = -1;
    /** Environment-record slot when captured. */
    public int $envIndex = -1;
    /**
     * Declared inside a loop. Combined with `captured`, this is what a fresh
     * per-iteration binding is for (DESIGN.md §2.5) -- a name that is both
     * gets `envIndex` assigned in `loopScope` instead of its owning
     * function's flat environment.
     */
    public bool $inLoop = false;
    /**
     * The loop whose per-iteration environment this binding actually lives
     * in, once `assignSlots` has decided it needs one -- null for every
     * ordinary binding, including one that is merely `inLoop` but never
     * captured (that one keeps a plain frame slot, reused each iteration,
     * exactly like a `var` would).
     */
    public ?LoopEnvScope $loopScope = null;

    public function __construct(
        public Ctx $owner,
        public string $name,
        /** 'param' | 'var' | 'func' | 'catch' | 'self' */
        public string $kind,
        /** Parameter position (params keep positional slots even when captured). */
        public int $paramIndex = -1,
    ) {
    }
}

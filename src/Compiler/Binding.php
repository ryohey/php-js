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
     * gets `envIndex` assigned in `envScope` instead of its owning
     * function's flat environment.
     */
    public bool $inLoop = false;
    /**
     * The nested environment this binding actually lives in, once
     * `assignSlots` has decided it needs one, instead of its owning
     * function's flat environment -- null for every ordinary binding,
     * including one that is merely `inLoop` but never captured (that one
     * keeps a plain frame slot, reused each iteration, exactly like a `var`
     * would). Two unrelated things can set this: a loop's own per-iteration
     * environment (`inLoop` above), or -- for a `var`/function declaration
     * in a non-simple-parameter-list function's body -- that function's
     * separate variable environment (`Ctx::$paramEnvScope`, 9.2.12), kept
     * apart from its parameters so a closure made in a parameter default
     * cannot see a name the body declares.
     */
    public ?EnvScope $envScope = null;

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

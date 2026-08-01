<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/**
 * A nested environment layer that is not a function call: compile-time
 * bookkeeping only, parallel to `Ctx` but scoped to something smaller than a
 * whole function. Two unrelated things create one:
 *
 * - A loop's per-iteration environment (CreatePerIterationEnvironment,
 *   14.7.4.3), for a loop with a `let`/`const`/`class` binding a closure
 *   inside it captures (DESIGN.md §2.5). Every other loop has no entry for
 *   this at all, and compiles exactly as if the feature did not exist.
 * - A non-simple parameter list's separate variable environment (9.2.12),
 *   holding the function body's own `var`/function declarations apart from
 *   its parameters, so a closure made in a parameter default cannot see a
 *   name the body declares (and one made in the body sees the body's own
 *   copy of a same-named parameter, not the parameter itself). Every
 *   simple-parameter-list function has no entry for this either.
 *
 * Both chain through `$parent` into the same scope-resolution walk
 * `Ctx::$parent` already uses (`Compiler::envDepth`), so a nested loop's own
 * layer, an enclosing loop's, a function's separate variable environment,
 * and the enclosing function's own environment all compose uniformly --
 * nothing needs to know which kind of scope it is crossing, only whether
 * that scope turned out to own an environment at all (`$size > 0`, the
 * equivalent of `Ctx::$nenv > 0`).
 */
final class EnvScope
{
    public int $size = 0;

    public function __construct(
        public Ctx|EnvScope $parent,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/**
 * A loop's per-iteration environment layer (CreatePerIterationEnvironment,
 * 14.7.4.3): compile-time bookkeeping only, parallel to `Ctx` but scoped to
 * one loop rather than one function. Exists only for a loop that actually
 * has a `let`/`const`/`class` binding captured by a closure inside it --
 * every other loop has no entry here at all, and compiles exactly as if
 * this feature did not exist.
 *
 * Chained through `$parent` into the same scope-resolution walk `Ctx::$parent`
 * already uses (`Compiler::envDepth`), so a nested loop's own layer, an
 * enclosing loop's, and the enclosing function's all compose uniformly --
 * nothing needs to know which kind of scope it is crossing, only whether
 * that scope turned out to own an environment at all (`$size > 0`, the loop
 * equivalent of `Ctx::$nenv > 0`).
 */
final class LoopEnvScope
{
    public int $size = 0;

    public function __construct(
        public Ctx|LoopEnvScope $parent,
    ) {
    }
}

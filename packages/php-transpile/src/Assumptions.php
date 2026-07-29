<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

/**
 * What the build is allowed to take for granted about the code it compiles.
 *
 * Everything here is off by default, because the emitter's contract is that
 * ahead-of-time compilation changes nothing observable, and each of these
 * trades a slice of that for speed. They are sound for a *closed* build — a
 * fixed library version compiled at deploy time, with no untrusted code in the
 * realm — which is exactly the SSG case this exists for (docs/aot-php.md §1).
 *
 * They are not sound for a runtime that lets guest code monkey-patch the
 * standard library before the compiled module runs.
 */
final class Assumptions
{
    public function __construct(
        /**
         * `Object.prototype` and `Function.prototype` still hold their original
         * methods, and `Object.prototype` carries no user-defined setters.
         *
         * Enables two specializations that together are most of the gap between
         * generated and hand-written PHP (docs/aot-php.md §9):
         *
         * - `X.call(o, k)` becomes a direct own-property test when the build
         *   proves `X` was assigned `Object.prototype.hasOwnProperty` exactly
         *   once at module scope. That proof is per-module and mechanical; the
         *   assumption is only that nothing replaced the builtin *before* the
         *   module loaded.
         * - A write to a local the build proves holds a fresh object literal
         *   becomes a store instead of a `[[Set]]` walk, since no prototype
         *   setter can be in the way.
         */
        public readonly bool $standardBuiltins = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /** Everything that is sound for a fixed library compiled at build time. */
    public static function closedBuild(): self
    {
        return new self(standardBuiltins: true);
    }
}

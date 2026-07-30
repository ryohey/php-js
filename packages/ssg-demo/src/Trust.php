<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Which JavaScript this build is willing to compile into PHP.
 *
 * Ahead-of-time compilation and bytecode precompilation look like one
 * optimization but they sit on opposite sides of a trust boundary, and it is
 * worth being explicit about which is which:
 *
 * - **Precompiled bytecode is data.** `build/templates.*.php` is a `<?php return
 *   [...];` file of plain arrays. `require`ing it defines nothing and calls
 *   nothing; the VM interprets it afterwards, inside its sandbox. Precompiling
 *   any JavaScript is as safe as running it.
 * - **Generated PHP is code.** `build/natives.php` is PHP that a compiler wrote
 *   from JavaScript source, and `require`ing it is executing it. Everything that
 *   file does is bounded by what the emitter can emit — it is an AST-to-AST
 *   translation, never string concatenation, so there is no injection surface —
 *   but the guest has left the VM, and with it two guarantees the VM makes:
 *
 *   1. **The wall-clock limit is not enforced.** `Vm::setTimeLimit()` is checked
 *      by the dispatch loop, and generated PHP has no dispatch loop. A `while
 *      (true) {}` that the interpreter stops after 0.5 s runs forever once it is
 *      compiled. Verified, not assumed.
 *   2. **JS recursion becomes PHP recursion.** Interpreted JS frames live on the
 *      VM's own stack and unbounded recursion raises a catchable `RangeError`;
 *      compiled frames are PHP frames, and the same program exhausts memory with
 *      a fatal error instead.
 *
 * So: compile dependencies a lockfile pins, and leave everything else to the
 * interpreter. That is not a hedge about the emitter's correctness — it is that
 * the interpreter is the part with the safety rails, and untrusted code is
 * exactly the code that needs them.
 *
 * This demo has no untrusted JavaScript in it — every line is in this
 * repository. The boundary is drawn anyway, because a demo is where people read
 * the policy off the code, and because it costs about 3% (measured) to hold.
 */
final class Trust
{
    /**
     * The one directory whose contents are pinned by a lockfile and reviewed as
     * a version bump rather than edited.
     */
    private const PINNED = '/node_modules/';

    /**
     * Whether a module may be compiled to PHP.
     *
     * Anything this rejects still runs — as bytecode, in the VM, with the time
     * limit and the recursion guard intact. A rejection costs speed and nothing
     * else.
     */
    public static function mayCompileToPhp(string $modulePath): bool
    {
        return str_contains($modulePath, self::PINNED);
    }

    /** The same rule as a closure, for `NodeIntegration`. */
    public static function filter(): \Closure
    {
        return static fn (string $path): bool => self::mayCompileToPhp($path);
    }

    /**
     * Whether a compiled template respects the boundary: no function anywhere
     * inside a module we did not trust may carry a native ID.
     *
     * Checked by a test rather than believed, because the filter is one
     * `str_contains` away from silently accepting everything.
     *
     * @param array<string, mixed> $template
     */
    public static function templateIsInterpreted(array $template): bool
    {
        if (($template['nativeId'] ?? null) !== null) {
            return false;
        }
        foreach ($template['children'] ?? [] as $child) {
            if (!self::templateIsInterpreted($child)) {
                return false;
            }
        }
        return true;
    }
}

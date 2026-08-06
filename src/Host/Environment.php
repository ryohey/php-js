<?php

declare(strict_types=1);

namespace PhpJs\Host;

use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * What a runtime environment provides on top of ECMAScript.
 *
 * The engine implements the language and its standard library, and nothing
 * else: no `require`, no `process`, no timers, no filesystem, no network. An
 * `Engine` with no `Environment` is a complete, sealed ECMAScript runtime
 * that cannot reach the outside world — which is the property that makes this
 * boundary worth having, and it is asserted by a test rather than assumed.
 *
 * Everything beyond the language comes from an implementation of this
 * interface. `packages/node-compat` is one (CommonJS, `process`, `fs`,
 * timers, Node's core-module stubs); a `deno-compat` or a `node2024-compat`
 * would be another, and would need nothing from node-compat to exist. The
 * engine never names any environment, and no environment is privileged.
 *
 * Two things only, because these are the two things an environment actually
 * decides:
 *
 * 1. **What exists.** `install()` populates the global object with whatever
 *    this environment claims is there.
 * 2. **How a module specifier resolves.** `loadModule()` is the whole of it —
 *    resolution strategy, file layout, caching, and the module format itself
 *    are the environment's business, not the engine's.
 *
 * Deliberately *not* here: compilation caching (the engine's own, see
 * `PhpJs\Cache\ArtifactCache`), and anything about file layout, bundlers or
 * type stripping (an environment's private business). Keeping those out is
 * what lets an environment that shares none of node-compat's conventions
 * implement this interface at all.
 */
interface Environment
{
    /**
     * Populate a realm's global object with this environment's own globals.
     *
     * Called once, at `Engine` construction, after the ECMAScript standard
     * library is in place — so an environment can override a standard global
     * (or build on one) and knows it is there.
     */
    public function install(Realm $realm, Vm $vm): void;

    /**
     * Resolve and evaluate a module specifier, returning its exports.
     *
     * Reached from `Engine::importModule()`, and from whatever module
     * function the environment installs for guest code (node-compat's
     * `require`). When the compiler grows ESM (DESIGN.md §2.5), `import`
     * resolves through here too — the point of naming it now is that there
     * is one seam rather than one per module syntax.
     *
     * @param  string  $specifier what the guest asked for, unresolved
     * @param  ?string $referrer  the module doing the asking, or null at the
     *                            entry point; the environment defines what a
     *                            referrer means (node-compat: a directory)
     * @return mixed the module's exports as a JS value
     */
    public function loadModule(string $specifier, ?string $referrer, Vm $vm): mixed;
}

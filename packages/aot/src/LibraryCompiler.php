<?php

declare(strict_types=1);

namespace PhpJs\Aot;

use PhpJs\Node\NodeHost;
use PhpJs\Transpile\Assumptions;
use PhpJs\Transpile\NodeIntegration;

/**
 * Compiles a manifest's libraries ahead of time into an AOT cache directory —
 * `node_modules/.phpjs-aot/{contentHash}.php`, one file per module reached,
 * holding both its compiled template and whatever natives came out of it —
 * that any `NodeHost` picks up on a plain `require()` with no other wiring
 * (`ModuleLoader::aotArtifactTemplate()`, packages/node-compat).
 *
 * This is deliberately the only thing this class does. Everything about
 * *which* libraries to trust is `Trust`'s call to make in whatever project
 * invokes it (this compiles only what a manifest names, and only what that
 * traverses inside `node_modules`); everything about *how* the emitter
 * behaves is `Assumptions`' and `NodeIntegration`'s. Fixed at
 * `Assumptions::closedBuild()` here rather than left configurable — every
 * artifact under one cache directory has to share one profile (see the
 * comment on `NodeIntegration::attach()`'s `moduleKey`), and a build tool
 * that populates a shared, convention-addressed directory is exactly the
 * place that invariant has to be enforced by construction, not by
 * documentation.
 */
final class LibraryCompiler
{
    /**
     * @param list<string> $libraries module specifiers, resolved the same way `require()` would
     * @return array{converted: int, seen: int, files: int, refusals: list<array{reason: string, count: int}>}
     */
    public function compile(string $root, array $libraries, string $cacheDir): array
    {
        // The cache is never consulted while populating it: a partial or
        // stale artifact from a previous run must not shadow what this run
        // is about to emit fresh.
        $host = new NodeHost($root, aotCacheDir: false);
        $integration = NodeIntegration::forBuild(
            static fn (string $path): bool => str_contains($path, '/node_modules/'),
            Assumptions::closedBuild(),
        );
        $integration->attach($host);
        foreach ($libraries as $specifier) {
            $host->requireModule($specifier);
        }
        // Every module attach() saw, not just the ones with natives: a
        // cached template skips Compiler::compile() regardless of how much
        // of the module converted (ModuleLoader::aotArtifactTemplate()).
        $written = $integration->writeArtifacts($cacheDir, $host->modules->compiledTemplates());
        return [
            'converted' => $integration->totalConverted(),
            'seen' => $integration->totalSeen(),
            'files' => count($written),
            'refusals' => $integration->refusalSummary(),
        ];
    }
}

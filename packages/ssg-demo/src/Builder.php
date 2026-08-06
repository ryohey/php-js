<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Transpile\Assumptions;
use PhpJs\Transpile\NodeIntegration;

/**
 * The library build step: React, ahead of any request.
 *
 * PHP is shared-nothing, so a server that boots the runtime per request pays
 * for every compile per request. Parsing React's server build is a few hundred
 * milliseconds and the polyfill file is another thirty. Both produce plain
 * `var_export`-able arrays (DESIGN.md §11.1), so this writes them to
 * `<?php return [...];` files that opcache keeps in shared memory.
 *
 * Deliberately as far as this goes: `app/` — the site's own TSX — is not
 * touched here at all. It is compiled by `AppCompiler`, lazily, the first time
 * a `Renderer` needs it after a build, and cached to disk from then on the same
 * way this class's own output is. What that split buys: editing `app/` never
 * requires re-running this (comparatively expensive, React-sized) step, and a
 * server can ship with only the library prebuilt and let the very first
 * request compile and cache the site itself — the shape `bin/phpjs-ssg serve`
 * already gives the *rendered HTML* (`PageCache`), one layer lower.
 *
 * Two sets of module templates come out of it, not one. `aot` templates carry
 * the `nativeId`s the ahead-of-time compiler stamped on them; `bytecode`
 * templates carry none. That is what lets the demo serve the same page both ways
 * inside a single long-lived process: native functions are registered
 * process-wide and cannot be unregistered, but a template that was never
 * stamped will never reach them.
 */
final class Builder
{
    public const MANIFEST = 'manifest.php';

    /**
     * The library surface `AppCompiler` needs preloaded so requiring the app
     * never re-parses React: exactly what `app/entry.tsx` itself requires
     * (the deep react-dom path, because the streaming renderer it would
     * otherwise pull in needs Web APIs this host does not provide — see
     * entry.tsx's own comment).
     *
     * @var list<string>
     */
    public const LIBRARY_ENTRIES = [
        'react',
        'react-dom/cjs/react-dom-server-legacy.node.production.js',
    ];

    /**
     * @param string $appRoot   module root; holds app/ and node_modules/
     * @param string $buildDir  where the generated PHP goes
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $buildDir,
    ) {
    }

    /**
     * @param null|callable(string): void $log
     * @return array<string, mixed> the manifest that was written
     */
    public function run(?callable $log = null): array
    {
        $log ??= static function (string $_): void {
        };
        if (!is_dir($this->appRoot . '/node_modules/react')) {
            throw new \RuntimeException(
                "No React at {$this->appRoot}/node_modules/react — run `npm install` first."
            );
        }
        if (!is_dir($this->buildDir) && !mkdir($this->buildDir, 0o777, true) && !is_dir($this->buildDir)) {
            throw new \RuntimeException("Cannot create {$this->buildDir}");
        }
        // A stale AppCompiler cache from a previous build could preload
        // library bytecode templates this build is about to replace —
        // dropped here so the very next render recompiles the app against
        // what this run actually produces, rather than reusing a mix of old
        // and new.
        foreach ([AppCompiler::MANIFEST, AppCompiler::TEMPLATES] as $stale) {
            @unlink($this->buildDir . '/' . $stale);
        }

        // The polyfill file is the same for every host, so it is compiled once
        // here and never again.
        $started = microtime(true);
        $polyfill = \PhpJs\Compiler\Compiler::compile(NodeHost::polyfillSource());
        $polyfillPath = $this->writeArray('polyfill.php', $polyfill);
        // Both build hosts below get it for free, as a request (and
        // AppCompiler) will.
        NodeHost::preloadPolyfillTemplate($polyfill);
        $log(sprintf(
            'polyfills           %6.0f ms -> %s (%s)',
            (microtime(true) - $started) * 1000,
            basename($polyfillPath),
            self::size($polyfillPath)
        ));

        // Pass 1: ahead-of-time compiled. The hook stamps native IDs onto the
        // templates as they are compiled, so the templates and the generated
        // PHP come out of the same pass and cannot disagree.
        $started = microtime(true);
        $host = $this->freshHost();
        // Only what a lockfile pins gets compiled into PHP; the site's own
        // JavaScript stays interpreted. Trust explains why, and a test holds it.
        $aot = NodeIntegration::forBuild(Trust::filter(), Assumptions::closedBuild());
        $aot->attach($host);
        foreach (self::LIBRARY_ENTRIES as $specifier) {
            $host->requireModule($specifier);
        }
        $vm = $host->vm();
        $reactVersion = Conversions::toString($vm, $host->requireModule('react')->get('version', $vm));
        $aotTemplates = $this->writeArray('templates.aot.php', $host->modules->compiledTemplates());
        $natives = $aot->writePhp($this->buildDir . '/natives.php');
        $log(sprintf(
            'ahead-of-time PHP   %6.0f ms -> %s (%s), %d / %d functions',
            (microtime(true) - $started) * 1000,
            basename($natives),
            self::size($natives),
            $aot->totalConverted(),
            $aot->totalSeen()
        ));
        $log(sprintf(
            'templates (aot)              -> %s (%s), %d modules',
            basename($aotTemplates),
            self::size($aotTemplates),
            count($host->modules->compiledTemplates())
        ));

        // Pass 2: no hook, so nothing is stamped and these templates can only
        // ever run as bytecode. A separate host, because a template is compiled
        // once per loader and the first pass already cached the stamped ones.
        // AppCompiler preloads this set too, which is what lets requiring the
        // app skip re-parsing React entirely.
        $started = microtime(true);
        $plainHost = $this->freshHost();
        foreach (self::LIBRARY_ENTRIES as $specifier) {
            $plainHost->requireModule($specifier);
        }
        $plainTemplates = $this->writeArray('templates.bytecode.php', $plainHost->modules->compiledTemplates());
        $log(sprintf(
            'templates (bytecode)%6.0f ms -> %s (%s)',
            (microtime(true) - $started) * 1000,
            basename($plainTemplates),
            self::size($plainTemplates)
        ));

        $manifest = [
            'builtAt' => date('c'),
            'appRoot' => $this->appRoot,
            'reactVersion' => $reactVersion,
            'polyfill' => basename($polyfillPath),
            'converted' => $aot->totalConverted(),
            'seen' => $aot->totalSeen(),
            'refusals' => $aot->refusalSummary(),
            'engines' => [
                'aot' => ['templates' => basename($aotTemplates), 'natives' => basename($natives)],
                'bytecode' => ['templates' => basename($plainTemplates)],
            ],
        ];
        $this->writeArray(self::MANIFEST, $manifest);
        $log(sprintf('library                      -> React %s', $reactVersion));
        $log('app/                         -> not built; compiled on first render (AppCompiler)');
        return $manifest;
    }

    private function freshHost(): NodeHost
    {
        return new NodeHost($this->appRoot, captureOutput: true);
    }

    /** @param array<mixed> $value */
    private function writeArray(string $name, array $value): string
    {
        $path = $this->buildDir . '/' . $name;
        // Plain arrays only, which is the whole reason opcache can hold this.
        file_put_contents($path, "<?php\n\n// Generated by phpjs-ssg build. Do not edit.\n\nreturn "
            . var_export($value, true) . ";\n");
        return $path;
    }

    private static function size(string $path): string
    {
        $bytes = (int)filesize($path);
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int)round($bytes / 1024));
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Aot\LibraryCompiler;
use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

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
 * The ahead-of-time-PHP half of this is not this class's own concern anymore.
 * `LibraryCompiler` (`packages/aot`) is the generic version of what used to
 * live here directly: it does not know React exists, only that
 * `LIBRARY_ENTRIES` names some `node_modules` specifiers to compile, and it
 * writes its result to `node_modules/.phpjs-aot/`, the directory any
 * `NodeHost` (this one included, right below) checks on every ordinary
 * `require()` with no further wiring (`ModuleLoader::aotLookupHook()`,
 * packages/node-compat). This class's own job shrinks to: run that, then
 * compile two *template* sets from it — `aot` (native IDs stamped, because
 * the cache now exists when this compiles) and `bytecode` (a second host with
 * the lookup explicitly disabled) — so the demo can still show the same page
 * both ways.
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

        // Ahead-of-time PHP: generic, not React-specific (LibraryCompiler has
        // no idea what LIBRARY_ENTRIES names). Writes into
        // node_modules/.phpjs-aot/, one file per module reached.
        $started = microtime(true);
        $cacheDir = $this->appRoot . '/' . NodeHost::AOT_CACHE_SUBDIR;
        $aot = (new LibraryCompiler())->compile($this->appRoot, self::LIBRARY_ENTRIES, $cacheDir);
        $log(sprintf(
            'ahead-of-time PHP   %6.0f ms -> %s (%d files), %d / %d functions',
            (microtime(true) - $started) * 1000,
            $this->relative($cacheDir),
            $aot['files'],
            $aot['converted'],
            $aot['seen']
        ));

        // "aot" templates: an ordinary host, constructed *after* the cache
        // directory above exists, so it auto-discovers it -- every module
        // compiled here gets whatever native IDs the cache has for it
        // stamped on transparently, no hook of this class's own to attach.
        $started = microtime(true);
        $host = $this->freshHost();
        foreach (self::LIBRARY_ENTRIES as $specifier) {
            $host->requireModule($specifier);
        }
        $vm = $host->vm();
        $reactVersion = Conversions::toString($vm, $host->requireModule('react')->get('version', $vm));
        $aotTemplates = $this->writeArray('templates.aot.php', $host->modules->compiledTemplates());
        $log(sprintf(
            'templates (aot)     %6.0f ms -> %s (%s), %d modules',
            (microtime(true) - $started) * 1000,
            basename($aotTemplates),
            self::size($aotTemplates),
            count($host->modules->compiledTemplates())
        ));

        // "bytecode" templates: the lookup explicitly disabled, so nothing
        // is stamped and these can only ever run as bytecode, regardless of
        // what node_modules/.phpjs-aot/ has in it. AppCompiler preloads the
        // *aot* set instead of this one, which is what lets requiring the
        // app skip re-parsing React entirely while still running it fast.
        $started = microtime(true);
        $plainHost = $this->freshHost(aotCacheDir: false);
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
            'converted' => $aot['converted'],
            'seen' => $aot['seen'],
            'refusals' => $aot['refusals'],
            'engines' => [
                'aot' => ['templates' => basename($aotTemplates)],
                'bytecode' => ['templates' => basename($plainTemplates)],
            ],
        ];
        $this->writeArray(self::MANIFEST, $manifest);
        $log(sprintf('library                      -> React %s', $reactVersion));
        $log('app/                         -> not built; compiled on first render (AppCompiler)');
        return $manifest;
    }

    private function freshHost(string|false|null $aotCacheDir = null): NodeHost
    {
        return new NodeHost($this->appRoot, captureOutput: true, aotCacheDir: $aotCacheDir);
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

    private function relative(string $path): string
    {
        $root = rtrim($this->appRoot, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function size(string $path): string
    {
        $bytes = (int)filesize($path);
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int)round($bytes / 1024));
    }
}

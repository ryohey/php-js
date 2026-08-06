<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

/**
 * Compiles `app/` — the site's own TSX — the first time anything needs it
 * after a library build, and caches the result to disk so every request
 * after the first, in this process or any other, just loads it.
 *
 * This is `Builder`'s counterpart at request time rather than build time:
 * bytecode compilation happens here instead of in `bin/phpjs-ssg build`, so
 * editing `app/` never requires re-running that (comparatively expensive,
 * React-sized) step, and a server can ship with only the library prebuilt.
 * `require('./app/entry.tsx')` below reads TSX straight from `app/` — no
 * pre-transform step of this class's own anymore, since `packages/strip-types`
 * strips types and JSX per file, lazily, the moment `NodeHost`'s module
 * resolver reaches each one (auto-detected; see that package's own README).
 * The library's own (already AOT-stamped) templates are preloaded first, so
 * requiring the app — which transitively requires React — never re-parses it
 * and still gets React's own ahead-of-time PHP; only the app's own files end
 * up newly compiled, and only those are what gets cached here (`Builder::run()`
 * already cached React's, in `node_modules/.phpjs-aot/`).
 *
 * Concurrency is the same problem `PageCache` already solves for rendered
 * HTML, one layer down: a burst of first requests must compile once, not
 * once each. `ensure()` mirrors that lock-file shape — the winner compiles,
 * the losers wait for its output.
 *
 * `$appRoot` (where `app/` and `node_modules/` live — what `require()`
 * resolves against) and `$buildDir` (this class's own generated PHP: the
 * manifest, the app's templates, the lock file) are not necessarily the same
 * directory, and only one of the two may vary in practice: `$buildDir` is
 * read with `require` directly, an ordinary PHP include, so a caller (a test)
 * is free to point it anywhere, while `$appRoot` is a real filesystem/module
 * root and has no such freedom.
 */
final class AppCompiler
{
    public const MANIFEST = 'app-manifest.php';
    public const TEMPLATES = 'templates.app.php';

    /** How long a request waits for another process's compile before giving up and doing its own. */
    private const LOCK_TIMEOUT_SECONDS = 30.0;

    /**
     * @param string $appRoot  module root; holds app/ and node_modules/
     * @param string $buildDir where Builder::run() already wrote the library layer,
     *                         and where this class writes its own generated PHP
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $buildDir,
        private readonly string $entry = './app/entry.tsx',
    ) {
    }

    /**
     * @return array{entry: string, routes: list<string>, templates: string}
     */
    public function ensure(): array
    {
        $manifestFile = $this->buildDir . '/' . self::MANIFEST;
        $cached = $this->readManifest($manifestFile);
        if ($cached !== null) {
            return $cached;
        }

        $lock = $this->acquire();
        if ($lock === null) {
            // Someone else is compiling. Wait for them rather than doing the
            // same work twice.
            $cached = $this->waitFor($manifestFile);
            return $cached ?? $this->compile();
        }
        try {
            // Re-check: the lock may have been held by a compile that has
            // since finished and written the manifest.
            return $this->readManifest($manifestFile) ?? $this->compile();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{entry: string, routes: list<string>, templates: string} */
    private function compile(): array
    {
        if (!is_dir($this->buildDir) && !mkdir($this->buildDir, 0o777, true) && !is_dir($this->buildDir)) {
            throw new \RuntimeException("Cannot create {$this->buildDir}");
        }
        $libraryManifestFile = $this->buildDir . '/' . Builder::MANIFEST;
        if (!is_file($libraryManifestFile)) {
            throw new \RuntimeException("No library build at {$this->buildDir} — run `bin/phpjs-ssg build` first.");
        }
        $libraryManifest = require $libraryManifestFile;
        // The *aot* set, not bytecode -- native IDs stamped, because
        // Builder::run() compiled it after node_modules/.phpjs-aot/ already
        // existed. Preloading it skips both the re-parse *and* the
        // interpretation React's own functions would otherwise cost; the
        // registerAotCacheDir() call is what makes the stamps resolve to
        // something, since a preloaded template never reaches the
        // compile-time hook that would otherwise load them lazily.
        $libraryTemplates = require $this->buildDir . '/' . $libraryManifest['engines']['aot']['templates'];
        NodeHost::registerAotCacheDir($this->appRoot . '/' . NodeHost::AOT_CACHE_SUBDIR);
        \PhpJs\Engine::warmEcmaScriptLibrary($this->appRoot . '/' . NodeHost::AOT_CACHE_SUBDIR);

        // No pre-transform step: `require('./app/entry.tsx')` below strips
        // types and JSX on its own, per file, exactly when each one is first
        // reached (packages/strip-types, auto-detected by NodeHost). What
        // used to be Sucrase's job here now happens lazily inside the
        // compile this class was already doing.
        //
        // aotCacheDir: false -- app code stays interpreted bytecode always
        // (Trust §"why the site's own code stays interpreted"), enforced
        // here rather than left to the coincidence that none of its content
        // hashes could ever match an artifact in the library's own cache.
        // React itself still runs fast: its templates arrived preloaded
        // above, already stamped, natives already registered.
        $host = new NodeHost($this->appRoot, captureOutput: true, aotCacheDir: false);
        $host->modules->preloadTemplates($libraryTemplates);
        $vm = $host->vm();
        $entry = $host->requireModule($this->entry);
        $json = Conversions::toString($vm, $host->call($entry->get('routeManifest', $vm)));
        $routes = json_decode($json, true);
        if (!is_array($routes) || $routes === []) {
            throw new \RuntimeException('The app returned no routes');
        }

        // Everything newly compiled during that require, minus what was
        // preloaded, is exactly the app's own files -- React's are already
        // cached in the library's own templates file and would only bloat
        // this one redundantly.
        $appTemplates = array_diff_key($host->modules->compiledTemplates(), $libraryTemplates);
        $this->writeArray(self::TEMPLATES, $appTemplates);

        $result = [
            'entry' => $this->entry,
            'routes' => array_values(array_map('strval', $routes)),
            'templates' => self::TEMPLATES,
        ];
        $this->writeArray(self::MANIFEST, $result);
        return $result;
    }

    /** @return array{entry: string, routes: list<string>, templates: string}|null */
    private function readManifest(string $file): ?array
    {
        return is_file($file) ? require $file : null;
    }

    /** @return resource|null the held lock, or null if another process holds it */
    private function acquire(): mixed
    {
        if (!is_dir($this->buildDir) && !mkdir($this->buildDir, 0o777, true) && !is_dir($this->buildDir)) {
            return null;
        }
        $lockFile = $this->buildDir . '/.app-compile.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return null;
        }
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        fclose($handle);
        return null;
    }

    /** @return array{entry: string, routes: list<string>, templates: string}|null */
    private function waitFor(string $manifestFile): ?array
    {
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            usleep(50_000);
            $cached = $this->readManifest($manifestFile);
            if ($cached !== null) {
                return $cached;
            }
        }
        return null;
    }

    /** @param array<mixed> $value */
    private function writeArray(string $name, array $value): string
    {
        $path = $this->buildDir . '/' . $name;
        file_put_contents($path, "<?php\n\n// Generated by AppCompiler. Do not edit.\n\nreturn "
            . var_export($value, true) . ";\n");
        return $path;
    }
}

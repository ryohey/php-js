<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

/**
 * Renders a route, using only what the build produced.
 *
 * Nothing here compiles JavaScript. Everything it loads is a `<?php return
 * [...];` file that opcache keeps in shared memory, so a request pays for
 * instantiating a realm and running the module bodies — a couple of
 * milliseconds — rather than for parsing React.
 */
final class Renderer
{
    /** Where the layout reserves room for the host's own timings. */
    public const METRICS_ID = 'phpjs-metrics';

    public const ENGINES = ['aot', 'bytecode'];

    public float $bootMs = 0.0;
    /** Should stay 0: anything above it is JavaScript being compiled per request. */
    public int $modulesCompiled = 0;

    private NodeHost $host;
    private mixed $entry;
    private mixed $renderFn;

    /**
     * @param array<string, mixed> $manifest
     * @param ?string $aotCacheDir an AOT cache directory to bulk-register
     *                             natives from before preloading $templates,
     *                             or null for the `bytecode` engine (or a
     *                             build with nothing in its cache at all)
     * @param ?string $libraryCacheDir where to warm the engine's own JS
     *                                 standard library from -- unlike
     *                                 $aotCacheDir this is the same
     *                                 regardless of engine, since the
     *                                 library is always plain bytecode
     * @param array<string, mixed> $templates path => template, already absolute
     */
    private function __construct(
        public readonly string $engine,
        public readonly array $manifest,
        ?string $aotCacheDir,
        ?string $libraryCacheDir,
        array $templates,
    ) {
        $started = microtime(true);
        // Natives first: a template's nativeId has to already be registered
        // before the JSFunction carrying it is instantiated. Bulk, not the
        // usual lazy per-module lookup (PhpJs\Cache\ArtifactCache) --
        // $templates arrives preloaded below and so never goes through the
        // compile-time hook that lookup rides on.
        if ($aotCacheDir !== null) {
            NodeHost::registerAotCacheDir($aotCacheDir);
        }
        // The engine's own JS library needs the same treatment for the same
        // reason: this host's $aotCacheDir is disabled below (it only ever
        // runs preloaded templates), so nothing would otherwise look for it.
        if ($libraryCacheDir !== null) {
            \PhpJs\Engine::warmEcmaScriptLibrary($libraryCacheDir);
        }

        // aotCacheDir: false -- this host only ever runs preloaded templates
        // (never compiles a module fresh), so the lookup this would enable
        // could not fire anyway; disabling it outright skips the pointless
        // directory check.
        $this->host = new NodeHost($manifest['appRoot'], captureOutput: true, aotCacheDir: false);
        $this->host->modules->preloadTemplates($templates);
        $this->entry = $this->host->requireModule($manifest['entry']);
        $this->renderFn = $this->entry->get('renderPage', $this->host->vm());
        $this->bootMs = (microtime(true) - $started) * 1000;
        $this->modulesCompiled = $this->host->modules->compileCount;
    }

    /**
     * From a `phpjs-ssg build` directory, where both engines are available.
     *
     * `build/` only ever holds the library (React) precompiled ahead of
     * time; the app itself is `AppCompiler::ensure()`'s job, called right
     * here, on whichever request (or CLI command) is the first to construct
     * a Renderer after a build. Its own output is disk-cached, so only that
     * first caller pays for it.
     */
    public static function fromBuild(string $buildDir, string $engine = 'aot'): self
    {
        // Started here, not left to the constructor's own timer: on the one
        // request that pays for AppCompiler's compile, that cost has to
        // land in $bootMs the same as everything else that makes a boot
        // slow, or the toolbar and the `export`/`serve` logs would silently
        // stop accounting for the biggest number reasonable.
        $started = microtime(true);
        $manifestPath = $buildDir . '/' . Builder::MANIFEST;
        if (!is_file($manifestPath)) {
            throw new \RuntimeException("No build in $buildDir — run `bin/phpjs-ssg build` first.");
        }
        $manifest = require $manifestPath;
        $files = $manifest['engines'][$engine]
            ?? throw new \InvalidArgumentException("Unknown engine: $engine");
        $libraryTemplates = require $buildDir . '/' . $files['templates'];

        $app = (new AppCompiler($manifest['appRoot'], $buildDir))->ensure();
        $appTemplates = require $buildDir . '/' . $app['templates'];

        $renderer = new self(
            $engine,
            $manifest + ['entry' => $app['entry'], 'routes' => $app['routes']],
            // "bytecode" templates were never stamped in the first place
            // (Builder compiles that set with the lookup disabled), so
            // there is nothing for that engine to register.
            $engine === 'aot' ? $manifest['appRoot'] . '/' . NodeHost::AOT_CACHE_SUBDIR : null,
            // The library artifact lives in the same directory regardless
            // of engine -- it is never stamped with native IDs either way.
            $manifest['appRoot'] . '/' . NodeHost::AOT_CACHE_SUBDIR,
            $libraryTemplates + $appTemplates,
        );
        $renderer->bootMs = (microtime(true) - $started) * 1000;
        return $renderer;
    }

    /**
     * From a `phpjs-ssg package` directory — the deployable shape.
     *
     * A distribution is ahead-of-time only and carries relative paths, which
     * Distribution::load() rebases onto wherever it was installed. That is what
     * makes it shippable inside a plugin.
     */
    public static function fromDistribution(string $dir): self
    {
        $loaded = Distribution::load($dir);
        return new self(
            'aot',
            $loaded,
            $loaded['aotCacheDir'],
            $loaded['aotCacheDir'],
            $loaded['templatesInline'],
        );
    }

    /** @return list<string> */
    public function routes(): array
    {
        return $this->manifest['routes'];
    }

    public function reactVersion(): string
    {
        return (string)$this->manifest['reactVersion'];
    }

    /** @param array<string, int> $options render options, e.g. ['items' => 500] */
    public function render(string $path, array $options = []): Page
    {
        $vm = $this->host->vm();
        $started = microtime(true);
        $result = $this->host->call($this->renderFn, null, [
            $path,
            // A JSON string rather than a shared object: one scalar across the
            // boundary, and the shape stays defined in TypeScript.
            $options === [] ? '' : json_encode($options, JSON_THROW_ON_ERROR),
        ]);
        $renderMs = (microtime(true) - $started) * 1000;

        return new Page(
            $path,
            (int)Conversions::toNumber($vm, $result->get('status', $vm)),
            Conversions::toString($vm, $result->get('title', $vm)),
            "<!DOCTYPE html>\n" . Conversions::toString($vm, $result->get('html', $vm)),
            $renderMs,
        );
    }
}

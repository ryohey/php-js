<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Transpile\Artifact;

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
     * @param array<string, mixed> $templates path => template, already absolute
     */
    private function __construct(
        public readonly string $engine,
        public readonly array $manifest,
        string $polyfillFile,
        ?string $nativesFile,
        array $templates,
    ) {
        $started = microtime(true);
        // The generated PHP first: a template's natives have to be registered
        // before the JSFunction that would use them is instantiated.
        if ($nativesFile !== null) {
            Artifact::register($nativesFile);
        }
        NodeHost::preloadPolyfillTemplate(require $polyfillFile);

        $this->host = new NodeHost($manifest['appRoot'], captureOutput: true);
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
            $buildDir . '/' . $manifest['polyfill'],
            isset($files['natives']) ? $buildDir . '/' . $files['natives'] : null,
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
            $loaded['polyfillFile'],
            $loaded['nativesFile'],
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

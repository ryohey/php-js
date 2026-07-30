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

    /** @param array<string, mixed> $manifest */
    private function __construct(
        public readonly string $engine,
        public readonly array $manifest,
        string $buildDir,
    ) {
        $started = microtime(true);
        $engineFiles = $manifest['engines'][$engine]
            ?? throw new \InvalidArgumentException("Unknown engine: $engine");

        // The generated PHP first: a template's natives have to be registered
        // before the JSFunction that would use them is instantiated.
        if (isset($engineFiles['natives'])) {
            Artifact::register($buildDir . '/' . $engineFiles['natives']);
        }
        NodeHost::preloadPolyfillTemplate(require $buildDir . '/' . $manifest['polyfill']);

        $this->host = new NodeHost($manifest['appRoot'], captureOutput: true);
        $this->host->modules->preloadTemplates(require $buildDir . '/' . $engineFiles['templates']);
        $this->entry = $this->host->requireModule($manifest['entry']);
        $this->renderFn = $this->entry->get('renderPage', $this->host->vm());
        $this->bootMs = (microtime(true) - $started) * 1000;
        $this->modulesCompiled = $this->host->modules->compileCount;
    }

    public static function fromBuild(string $buildDir, string $engine = 'aot'): self
    {
        $manifestPath = $buildDir . '/' . Builder::MANIFEST;
        if (!is_file($manifestPath)) {
            throw new \RuntimeException("No build in $buildDir — run `bin/phpjs-ssg build` first.");
        }
        return new self($engine, require $manifestPath, $buildDir);
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

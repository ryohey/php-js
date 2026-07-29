<?php

declare(strict_types=1);

namespace PhpJs\Bench;

use PhpJs\JSException;
use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

/**
 * Drives React server-side rendering on the php-js runtime and reports
 * timings, split so the one-time costs (compiling React, evaluating its
 * modules) are visible separately from the per-render cost.
 */
final class Benchmark
{
    public const DEFAULT_ITEMS = 20;

    private NodeHost $host;
    private mixed $app = null;
    public float $bootSeconds = 0.0;
    public float $compileSeconds = 0.0;
    public int $modulesLoaded = 0;

    public function __construct(
        private readonly string $appRoot,
        private readonly string $entry = './js/app.js',
    ) {
        $this->host = new NodeHost($appRoot, captureOutput: true);
    }

    public function host(): NodeHost
    {
        return $this->host;
    }

    /** Compile and evaluate React plus the app module. */
    public function boot(): void
    {
        $started = microtime(true);
        $this->app = $this->host->requireModule($this->entry);
        $this->bootSeconds = microtime(true) - $started;
        $this->compileSeconds = $this->host->modules->compileSeconds;
        $this->modulesLoaded = $this->host->modules->compileCount;
    }

    public function reactVersion(): string
    {
        $this->requireBooted();
        return Conversions::toString($this->host->vm(), $this->app->get('reactVersion', $this->host->vm()));
    }

    /** @param 'renderToString'|'renderToStaticMarkup' $method */
    public function render(int $itemCount = self::DEFAULT_ITEMS, string $method = 'renderToStaticMarkup'): string
    {
        $this->requireBooted();
        $vm = $this->host->vm();
        $fn = $this->app->get($method, $vm);
        $result = $this->host->call($fn, null, [$itemCount, 'php-js']);
        return Conversions::toString($vm, $result);
    }

    /**
     * @return array{iterations: int, totalSeconds: float, perRenderMs: float,
     *               rendersPerSecond: float, htmlBytes: int}
     */
    public function measure(int $iterations, int $itemCount, string $method): array
    {
        $this->requireBooted();
        $html = $this->render($itemCount, $method); // warm up
        $started = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->render($itemCount, $method);
        }
        $elapsed = microtime(true) - $started;
        return [
            'iterations' => $iterations,
            'totalSeconds' => $elapsed,
            'perRenderMs' => $iterations > 0 ? $elapsed / $iterations * 1000 : 0.0,
            'rendersPerSecond' => $elapsed > 0 ? $iterations / $elapsed : INF,
            'htmlBytes' => strlen($html),
        ];
    }

    private function requireBooted(): void
    {
        if ($this->app === null) {
            throw new \LogicException('boot() must run before rendering');
        }
    }

    /**
     * Render the same app under Node, for output comparison. Returns null when
     * node is not on PATH — the benchmark is still useful without it.
     */
    public static function renderWithNode(string $appRoot, int $itemCount, string $method): ?string
    {
        // A fixture may ship a Node-specific entry when the module specifier
        // php-js uses is one Node refuses (React 19's package `exports` map
        // blocks the deep path into the synchronous server build).
        $entry = is_file($appRoot . '/js/app.node.js')
            ? $appRoot . '/js/app.node.js'
            : $appRoot . '/js/app.js';
        $script = sprintf(
            'process.stdout.write(require(%s).%s(%d, "php-js"))',
            json_encode($entry),
            $method === 'renderToString' ? 'renderToString' : 'renderToStaticMarkup',
            $itemCount
        );
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['node', '-e', $script], $descriptors, $pipes, $appRoot);
        if (!is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        return proc_close($process) === 0 ? $out : null;
    }

    /** Convert a JSException into a report that includes the JS stack. */
    public static function describe(JSException $e, NodeHost $host): string
    {
        $out = $e->getMessage();
        $value = $e->jsValue;
        if ($value instanceof \PhpJs\Runtime\JSObject) {
            $stack = $value->get('stack', $host->vm());
            if (is_string($stack)) {
                $out .= "\n" . $stack;
            }
        }
        return $out;
    }
}

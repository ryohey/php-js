<?php

declare(strict_types=1);

namespace PhpJs\Bench\Tests;

use PhpJs\Bench\Benchmark;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end check: real React, loaded from its published CommonJS build,
 * rendering on the php-js runtime. Skipped when the npm fixture is absent so a
 * checkout without `npm install` still runs the rest of the suite.
 */
final class ReactSsrTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/..';

    protected function setUp(): void
    {
        if (!is_dir(self::APP_ROOT . '/node_modules/react')) {
            $this->markTestSkipped('run `npm install` in packages/react-ssr-bench first');
        }
    }

    private function bench(): Benchmark
    {
        $bench = new Benchmark(self::APP_ROOT);
        $bench->boot();
        return $bench;
    }

    public function testReactLoads(): void
    {
        $this->assertMatchesRegularExpression('/^17\./', $this->bench()->reactVersion());
    }

    public function testStaticMarkupMatchesNode(): void
    {
        $bench = $this->bench();
        $ours = $bench->render(5, 'renderToStaticMarkup');
        $theirs = Benchmark::renderWithNode(self::APP_ROOT, 5, 'renderToStaticMarkup');
        if ($theirs === null) {
            $this->markTestSkipped('node is not available for comparison');
        }
        $this->assertSame($theirs, $ours);
    }

    public function testRenderToStringMatchesNode(): void
    {
        $bench = $this->bench();
        $ours = $bench->render(5, 'renderToString');
        $theirs = Benchmark::renderWithNode(self::APP_ROOT, 5, 'renderToString');
        if ($theirs === null) {
            $this->markTestSkipped('node is not available for comparison');
        }
        $this->assertSame($theirs, $ours);
    }

    public function testMarkupShape(): void
    {
        $html = $this->bench()->render(3, 'renderToStaticMarkup');
        $this->assertStringStartsWith('<div id="app" data-count="3">', $html);
        $this->assertStringContainsString('<h1>php-js SSR</h1>', $html);
        $this->assertStringContainsString('class="badge badge-ok"', $html);
        // Entities React escapes, proving the escape path runs.
        $this->assertStringContainsString('©', $html);
        $this->assertSame(3, substr_count($html, 'class="cell id"'));
    }

    public function testRenderIsRepeatable(): void
    {
        $bench = $this->bench();
        $this->assertSame(
            $bench->render(4, 'renderToStaticMarkup'),
            $bench->render(4, 'renderToStaticMarkup')
        );
    }

    public function testBootCompilesEveryModuleOnce(): void
    {
        $bench = $this->bench();
        // react, react-dom/server(+.node), the browser impl, object-assign,
        // the app itself and the stream stub — each compiled exactly once.
        $this->assertGreaterThanOrEqual(4, $bench->modulesLoaded);
        $bench->render(1, 'renderToStaticMarkup');
        $this->assertSame($bench->modulesLoaded, $bench->host()->modules->compileCount);
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Bench\Tests;

use PhpJs\Bench\Benchmark;
use PHPUnit\Framework\TestCase;

/**
 * React 19 on the runtime, from its published CommonJS build.
 *
 * Kept separate from the React 17 suite because the two fixtures have their
 * own node_modules and can be installed independently; either may be absent in
 * a given checkout.
 *
 * The fixture reaches the synchronous renderer by path
 * (`react-dom-server-legacy.node.production.js`) while Node reaches the same
 * function through `react-dom/server` — see apps/react19/js/app.js for why.
 */
final class React19SsrTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../apps/react19';

    protected function setUp(): void
    {
        if (!is_dir(self::APP_ROOT . '/node_modules/react')) {
            $this->markTestSkipped('run `npm install` in packages/react-ssr-bench/apps/react19 first');
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
        $this->assertMatchesRegularExpression('/^19\./', $this->bench()->reactVersion());
    }

    public function testStaticMarkupMatchesNode(): void
    {
        $bench = $this->bench();
        $node = Benchmark::renderWithNode(self::APP_ROOT, 12, 'renderToStaticMarkup');
        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }
        $this->assertSame($node, $bench->render(12, 'renderToStaticMarkup'));
    }

    public function testRenderToStringMatchesNode(): void
    {
        $bench = $this->bench();
        $node = Benchmark::renderWithNode(self::APP_ROOT, 12, 'renderToString');
        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }
        $this->assertSame($node, $bench->render(12, 'renderToString'));
    }

    public function testRenderIsRepeatable(): void
    {
        // React 19 threads more state through the renderer than 17 did; a
        // second render must not see leftovers from the first.
        $bench = $this->bench();
        $this->assertSame($bench->render(8), $bench->render(8));
    }

    /**
     * React 19 leans on library surface an ES5 engine does not have. These are
     * the ones it actually reaches for, and all of them are native rather than
     * interpreted — `Math.clz32` alone was 20% of a render as a JS polyfill.
     */
    public function testRendererUsesTheNativeLibrarySurface(): void
    {
        $host = $this->bench()->host();
        $vm = $host->vm();
        foreach (['Math.clz32', 'Math.imul'] as $path) {
            [$obj, $name] = explode('.', $path);
            $fn = $host->realm()->globalObject->get($obj, $vm)->get($name, $vm);
            $this->assertInstanceOf(
                \PhpJs\Runtime\JSNativeFunction::class,
                $fn,
                "$path fell back to the JS polyfill"
            );
        }
        $map = $host->engine->evaluate('new Map()');
        $this->assertInstanceOf(\PhpJs\Node\JSCollection::class, $map, 'Map fell back to the JS polyfill');
    }
}

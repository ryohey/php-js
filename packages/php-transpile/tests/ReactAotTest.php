<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Transpile\NodeIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end claim: ahead-of-time compiling React's own functions changes
 * nothing about what React renders.
 *
 * A unit test can only show that the emitter handles the constructs it was
 * given. This runs the real library — 200+ converted functions, including the
 * tree walker and the element factory — and requires the HTML to match the
 * interpreted run byte for byte.
 */
final class ReactAotTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../react-ssr-bench/apps/react19';

    protected function setUp(): void
    {
        if (!is_dir(self::APP_ROOT . '/node_modules/react')) {
            $this->markTestSkipped('run `npm install` in packages/react-ssr-bench/apps/react19 first');
        }
    }

    /** @return array{0: string, 1: ?NodeIntegration} */
    private function render(bool $aot, int $items = 12, string $method = 'renderToStaticMarkup'): array
    {
        $host = new NodeHost(self::APP_ROOT, captureOutput: true);
        $integration = null;
        if ($aot) {
            // React only. The scope decision in docs/aot-php.md §1 is that
            // library internals are worth converting and user components are
            // not, so the app module deliberately stays on bytecode.
            $integration = new NodeIntegration(fn (string $p) => str_contains($p, '/node_modules/react'));
            $integration->attach($host);
        }
        $app = $host->requireModule('./js/app.js');
        $vm = $host->vm();
        $html = Conversions::toString($vm, $host->call($app->get($method, $vm), null, [$items, 'php-js']));
        return [$html, $integration];
    }

    public function testStaticMarkupIsUnchanged(): void
    {
        [$plain] = $this->render(false);
        [$aot, $integration] = $this->render(true);

        $this->assertGreaterThan(100, $integration->totalConverted(), 'too few functions converted to prove anything');
        $this->assertSame($plain, $aot, 'ahead-of-time compilation changed the rendered HTML');
    }

    public function testRenderToStringIsUnchanged(): void
    {
        [$plain] = $this->render(false, 12, 'renderToString');
        [$aot] = $this->render(true, 12, 'renderToString');
        $this->assertSame($plain, $aot);
    }

    public function testMostOfReactIsConverted(): void
    {
        [, $integration] = $this->render(true);
        $ratio = $integration->totalConverted() / max(1, $integration->totalSeen());
        // Not a performance claim — a guard against a refactor that silently
        // starts refusing everything while the byte-identity tests still pass.
        $this->assertGreaterThan(0.6, $ratio, sprintf(
            'only %d of %d functions converted; top refusals: %s',
            $integration->totalConverted(),
            $integration->totalSeen(),
            json_encode(array_slice($integration->refusalSummary(), 0, 5))
        ));
    }

    public function testGeneratedPhpIsSyntacticallyValid(): void
    {
        [, $integration] = $this->render(true);
        $php = $integration->php();
        $tmp = tempnam(sys_get_temp_dir(), 'aot') . '.php';
        file_put_contents($tmp, $php);
        try {
            exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        } finally {
            @unlink($tmp);
        }
    }
}

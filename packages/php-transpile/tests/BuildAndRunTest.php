<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Transpile\Artifact;
use PhpJs\Transpile\NodeIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The deployable shape: build to a file, then run a process that only loads
 * PHP. This is what makes the generated code opcache-resident — `eval`'d code
 * never is, which is the whole reason the split exists (docs/aot-php.md §11).
 *
 * What has to hold for it to work is that a build and a later run agree on
 * native IDs without sharing any state, so that is what these check.
 */
final class BuildAndRunTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../react-ssr-bench/apps/react19';
    private static ?string $built = null;

    protected function setUp(): void
    {
        if (!is_dir(self::APP_ROOT . '/node_modules/react')) {
            $this->markTestSkipped('run `npm install` in packages/react-ssr-bench/apps/react19 first');
        }
    }

    private static function accept(): \Closure
    {
        return fn (string $p) => str_contains($p, '/node_modules/react');
    }

    private function build(): string
    {
        if (self::$built !== null && is_file(self::$built)) {
            return self::$built;
        }
        $host = new NodeHost(self::APP_ROOT, captureOutput: true);
        $aot = NodeIntegration::forBuild(self::accept());
        $aot->attach($host);
        $host->requireModule('./js/app.js');
        return self::$built = $aot->writePhp(sys_get_temp_dir() . '/phpjs-aot-test/react19.php');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$built !== null) {
            @unlink(self::$built);
        }
        self::$built = null;
    }

    private function render(?NodeIntegration $aot): string
    {
        $host = new NodeHost(self::APP_ROOT, captureOutput: true);
        $aot?->attach($host);
        $app = $host->requireModule('./js/app.js');
        $vm = $host->vm();
        return Conversions::toString($vm, $host->call($app->get('renderToStaticMarkup', $vm), null, [10, 'php-js']));
    }

    public function testABuiltFileRendersIdentically(): void
    {
        $path = $this->build();
        $this->assertGreaterThan(50_000, filesize($path), 'the build produced suspiciously little PHP');

        Artifact::register($path);
        $run = NodeIntegration::forRun(self::accept());

        $this->assertSame($this->render(null), $this->render($run));
        $this->assertGreaterThan(
            100,
            $run->totalConverted(),
            'run mode matched almost no IDs, so the build and the run disagree'
        );
    }

    public function testRunModeCompilesNothing(): void
    {
        Artifact::register($this->build());
        $run = NodeIntegration::forRun(self::accept());
        $this->render($run);
        // Run mode stamps IDs and looks them up; emitting there would mean the
        // build step was pointless.
        $this->assertSame(0.0, $run->emitSeconds);
        $this->assertSame([], $run->refused);
    }

    public function testWithoutTheBuiltFileEverythingFallsBackToBytecode(): void
    {
        // Run mode against natives that were never registered must not break —
        // JSFunction simply keeps interpreting. This is what lets the generated
        // PHP be optional at deploy time.
        $run = NodeIntegration::forRun(fn (string $p) => str_contains($p, 'no-such-module'));
        $this->assertSame($this->render(null), $this->render($run));
        $this->assertSame(0, $run->totalConverted());
    }

    public function testRegisteringTheSameBuildTwiceIsHarmless(): void
    {
        $path = $this->build();
        Artifact::register($path);
        $second = Artifact::register($path);
        $this->assertSame(0, $second, 'the second load should find every ID already present');
    }
}

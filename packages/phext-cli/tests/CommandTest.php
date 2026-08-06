<?php

declare(strict_types=1);

namespace PhpJs\PhextCli\Tests;

use PhpJs\Phext\Exporter;
use PhpJs\PhextCli\Build;
use PhpJs\PhextCli\Config;
use PhpJs\PhextCli\Server;
use PHPUnit\Framework\TestCase;

/**
 * What the `phext` binary does, tested through the classes it is a thin
 * wrapper over.
 *
 * The fixture site is `packages/phext`'s, deliberately: an app is supposed to
 * depend on this package and nothing under it, so a CLI that needed its own
 * private fixture would be evidence that the split had failed.
 */
final class CommandTest extends TestCase
{
    private static string $site = '';
    private string $out = '';

    public static function setUpBeforeClass(): void
    {
        self::$site = realpath(__DIR__ . '/../../phext/tests/fixtures/site') ?: '';
        if (self::$site === '' || !is_dir(self::$site . '/node_modules/react')) {
            self::markTestSkipped('No React. Run `npm install` in packages/phext/tests/fixtures/site.');
        }
    }

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/phext-cli-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->out)) {
            self::removeTree($this->out);
        }
    }

    private function config(): Config
    {
        return Config::load(self::$site);
    }

    // ---- configuration -----------------------------------------------------

    public function testAProjectWithNoSettingsGetsWorkingDefaults(): void
    {
        $config = $this->config();
        $this->assertSame(self::$site . '/public/cache', $config->cacheDir);
        $this->assertNull($config->ttl, 'a site with no TTL should not expire on its own');
        $this->assertSame('renderToString', $config->render);
    }

    public function testAnUnknownRenderMethodIsRefusedBeforeAnythingBoots(): void
    {
        $dir = $this->out;
        mkdir($dir . '/app', 0o777, true);
        file_put_contents($dir . '/package.json', json_encode(['phext' => ['render' => 'renderToPigeons']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('renderToPigeons');
        Config::load($dir);
    }

    public function testAMissingProjectDirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::load($this->out . '/nowhere');
    }

    // ---- build -------------------------------------------------------------

    public function testTheBuildListAlwaysIncludesTheFrameworksOwnDependencies(): void
    {
        $build = new Build($this->config()->app(''));
        $this->assertSame(Build::FRAMEWORK_LIBRARIES, $build->libraries());
    }

    public function testAProjectCanAddItsOwnDependenciesToTheBuild(): void
    {
        $dir = $this->out;
        mkdir($dir . '/app', 0o777, true);
        file_put_contents($dir . '/app/page.tsx', 'export default function P() { return null; }');
        file_put_contents($dir . '/package.json', json_encode(['phext' => ['aot' => ['lodash', 'react']]]));

        $libraries = (new Build(Config::load($dir)->app('')))->libraries();
        $this->assertContains('lodash', $libraries);
        // ...without duplicating one the framework already names.
        $this->assertSame(array_values(array_unique($libraries)), $libraries);
    }

    // ---- export ------------------------------------------------------------

    public function testExportWritesOneFilePerPathWithTheSameBytesAsARender(): void
    {
        $app = $this->config()->app('');
        $exporter = new Exporter($app, $this->out);
        $result = $exporter->run();

        $paths = $app->paths();
        $this->assertSame(count($paths), $result['pages']);
        foreach ($paths as $path) {
            $file = $exporter->fileFor($path);
            $this->assertFileExists($file, $path);
            // Static generation is a caching decision, not a second renderer.
            $this->assertSame($app->renderUncached($path)->html, file_get_contents($file), $path);
        }
        $this->assertFileExists($this->out . '/index.html');
        $this->assertFileExists($this->out . '/docs/intro/index.html');
    }

    public function testExportCopiesPublicFilesUnchanged(): void
    {
        $public = self::$site . '/public';
        $marker = $public . '/exported-marker.txt';
        if (!is_dir($public)) {
            mkdir($public, 0o777, true);
        }
        file_put_contents($marker, 'served as-is');
        try {
            (new Exporter($this->config()->app(''), $this->out))->run();
            $this->assertSame('served as-is', file_get_contents($this->out . '/exported-marker.txt'));
        } finally {
            unlink($marker);
        }
    }

    // ---- serving -----------------------------------------------------------

    public function testAGetRendersAndReportsWhetherItWasCached(): void
    {
        $app = $this->config()->app($this->out . '/cache');
        $server = new Server($app);

        ob_start();
        $status = $server->handle(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET']);
        $first = (string)ob_get_clean();

        $this->assertSame(200, $status);
        $this->assertStringContainsString('<h1>Hello from phext</h1>', $first);
        $this->assertSame('MISS', $app->cache()->lastStatus);

        ob_start();
        $server->handle(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET']);
        $second = (string)ob_get_clean();

        $this->assertSame('HIT', $app->cache()->lastStatus);
        $this->assertSame($first, $second, 'a cached page differed from the one that was cached');
    }

    public function testAnUnknownPathIsA404AndIsNotCached(): void
    {
        $app = $this->config()->app($this->out . '/cache');

        ob_start();
        $status = (new Server($app))->handle(['REQUEST_URI' => '/nope/', 'REQUEST_METHOD' => 'GET']);
        $body = (string)ob_get_clean();

        $this->assertSame(404, $status);
        $this->assertStringContainsString('No such page.', $body);
        // Caching a 404 would pin the wrong answer at a path a later build
        // might define, and would let any request write a file.
        $this->assertFileDoesNotExist($this->out . '/cache/nope/index.html');
    }

    public function testAWriteMethodIsRefused(): void
    {
        ob_start();
        $status = (new Server($this->config()->app('')))
            ->handle(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'POST']);
        ob_end_clean();

        $this->assertSame(405, $status);
    }

    public function testAHeadRequestSendsNoBody(): void
    {
        ob_start();
        $status = (new Server($this->config()->app('')))
            ->handle(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'HEAD']);
        $body = (string)ob_get_clean();

        $this->assertSame(200, $status);
        $this->assertSame('', $body);
    }

    public function testAQueryStringRendersAndIsNotCached(): void
    {
        $app = $this->config()->app($this->out . '/cache');

        ob_start();
        (new Server($app))->handle(['REQUEST_URI' => '/?x=1', 'REQUEST_METHOD' => 'GET'], ['x' => '1']);
        ob_end_clean();

        // The cache is keyed by path alone -- which is what lets a web server
        // serve a hit from disk without consulting PHP -- so anything that
        // varies the bytes must not be stored under that key.
        $this->assertFileDoesNotExist($this->out . '/cache/index.html');
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

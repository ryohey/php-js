<?php

declare(strict_types=1);

namespace PhpJs\Ssg\Tests;

use PhpJs\Ssg\Builder;
use PhpJs\Ssg\Distribution;
use PhpJs\Ssg\PageCache;
use PhpJs\Ssg\Paths;
use PhpJs\Ssg\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * The two things a PHP-only host actually needs: a cache that renders once, and
 * an artifact that survives being moved somewhere else.
 */
final class DeploymentTest extends TestCase
{
    private static string $buildDir = '';
    private string $tmp = '';

    public static function setUpBeforeClass(): void
    {
        if (!is_dir(Paths::appRoot() . '/node_modules/sucrase')) {
            self::markTestSkipped('No sucrase. Run `npm install` in packages/ssg-demo.');
        }
        self::$buildDir = sys_get_temp_dir() . '/phpjs-deploy-build-' . getmypid();
        (new Builder(Paths::appRoot(), self::$buildDir, Paths::entry()))->run();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$buildDir !== '' && is_dir(self::$buildDir)) {
            self::removeTree(self::$buildDir);
        }
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpjs-deploy-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            self::removeTree($this->tmp);
        }
    }

    // ---- the cache ---------------------------------------------------------

    public function testTheFirstRequestRendersAndTheSecondDoesNot(): void
    {
        $cache = new PageCache($this->tmp);
        $renders = 0;
        $render = function () use (&$renders): string {
            $renders++;
            return '<p>rendered</p>';
        };

        $this->assertSame('<p>rendered</p>', $cache->get('/docs/', $render));
        $this->assertSame(PageCache::MISS, $cache->lastStatus);
        $this->assertSame('<p>rendered</p>', $cache->get('/docs/', $render));
        $this->assertSame(PageCache::HIT, $cache->lastStatus);
        $this->assertSame(1, $renders, 'the second request re-rendered');
    }

    public function testTheCacheLayoutMatchesTheStaticExport(): void
    {
        $cache = new PageCache($this->tmp);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/getting-started/', static fn (): string => 'doc');

        // Same shape the exporter writes, which is what lets a web server serve
        // a hit without PHP.
        $this->assertFileExists($this->tmp . '/index.html');
        $this->assertFileExists($this->tmp . '/docs/getting-started/index.html');
        $this->assertSame('home', file_get_contents($this->tmp . '/index.html'));
    }

    public function testAnEntryExpiresWithItsTtl(): void
    {
        $cache = new PageCache($this->tmp, 1);
        $cache->get('/', static fn (): string => 'first');
        $this->assertSame('first', $cache->get('/', static fn (): string => 'second'));

        // Backdate past the TTL rather than sleeping through it.
        touch($cache->fileFor('/'), time() - 5);
        clearstatcache();
        $this->assertSame('second', $cache->get('/', static fn (): string => 'second'));
        $this->assertSame(PageCache::MISS, $cache->lastStatus);
    }

    /**
     * A 404 must not become a cached page: it would pin the wrong answer at
     * that path, and it lets any request for a nonexistent path write a file.
     */
    public function testARefusedPageIsServedButNotStored(): void
    {
        $cache = new PageCache($this->tmp);
        $html = $cache->get(
            '/nope/',
            static fn (): string => '<p>not found</p>',
            static fn (): bool => false
        );
        $this->assertSame('<p>not found</p>', $html);
        $this->assertFileDoesNotExist($cache->fileFor('/nope/'));
        // ...and it leaves nothing behind at all, lock file included.
        $this->assertSame([], glob($this->tmp . '/.locks/*') ?: []);
        $this->assertFalse(is_dir($this->tmp . '/nope'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent traversal' => ['/../secret/'];
        yield 'encoded traversal' => ['/a/../../etc/'];
        yield 'dotfile' => ['/.git/config'];
        yield 'hidden directory' => ['/.well-known/x/'];
        yield 'null byte' => ["/a\0b/"];
        yield 'backslash' => ['/a\\b/'];
        yield 'space' => ['/a b/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePaths')]
    public function testAnUnsafePathIsNeverWrittenToDisk(string $path): void
    {
        $cache = new PageCache($this->tmp);
        $this->assertSame('', $cache->fileFor($path), "fileFor accepted $path");

        $html = $cache->get($path, static fn (): string => 'body');
        $this->assertSame('body', $html, 'the page should still be served');
        $this->assertSame(PageCache::BYPASS, $cache->lastStatus);
        // Nothing at all: no page, no directory, no lock.
        $this->assertSame([], glob($this->tmp . '/*') ?: []);
    }

    public function testClearingRemovesPagesAndLocks(): void
    {
        $cache = new PageCache($this->tmp);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/', static fn (): string => 'docs');
        $this->assertGreaterThan(0, $cache->clear());
        $this->assertFileDoesNotExist($this->tmp . '/index.html');
        $this->assertFileDoesNotExist($this->tmp . '/docs/index.html');
    }

    public function testClearingOnePageLeavesTheRest(): void
    {
        $cache = new PageCache($this->tmp);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/', static fn (): string => 'docs');
        $cache->clear('/docs/');
        $this->assertFileExists($this->tmp . '/index.html');
        $this->assertFileDoesNotExist($this->tmp . '/docs/index.html');
    }

    public function testTheRewriteOnlyMatchesGetsWithAFilePresent(): void
    {
        $rules = (new PageCache($this->tmp))->htaccess('/cache');
        // The two conditions that keep it honest: never for a POST, and never
        // when the cached file is not actually there.
        $this->assertStringContainsString('%{REQUEST_METHOD} ^GET$', $rules);
        $this->assertStringContainsString('/cache/%{REQUEST_URI}/index.html -f', $rules);
    }

    // ---- the distribution --------------------------------------------------

    public function testADistributionRendersAfterBeingMoved(): void
    {
        $packaged = $this->tmp . '/build-here';
        (new Distribution(self::$buildDir, Paths::appRoot()))->writeTo($packaged);

        // Move it, as installing a plugin would: the build machine's paths are
        // gone and nothing may depend on them.
        $installed = $this->tmp . '/wp-content/plugins/pages/phpjs';
        mkdir(dirname($installed), 0o777, true);
        rename($packaged, $installed);

        $renderer = Renderer::fromDistribution($installed);
        $this->assertSame(0, $renderer->modulesCompiled, 'a distribution compiled JavaScript');
        $this->assertNotEmpty($renderer->routes());

        // Byte-identical to the build it came from, or it is not the same site.
        $fromBuild = Renderer::fromBuild(self::$buildDir, 'aot');
        foreach ($renderer->routes() as $route) {
            $this->assertSame(
                $fromBuild->render($route)->html,
                $renderer->render($route)->html,
                "packaging changed $route"
            );
        }
    }

    public function testADistributionCarriesNoAbsolutePaths(): void
    {
        $packaged = $this->tmp . '/dist';
        (new Distribution(self::$buildDir, Paths::appRoot()))->writeTo($packaged);

        // The build machine's layout must not survive into the artifact --
        // that is the whole reason paths are relativized.
        foreach (['templates.php', Distribution::MANIFEST] as $file) {
            $this->assertStringNotContainsString(
                Paths::appRoot(),
                (string)file_get_contents($packaged . '/' . $file),
                "$file remembers where it was built"
            );
        }
    }

    public function testADistributionOmitsWhatOnlyTheDemoNeeds(): void
    {
        $packaged = $this->tmp . '/dist';
        $result = (new Distribution(self::$buildDir, Paths::appRoot()))->writeTo($packaged);

        // Bytecode-only templates exist so the demo can switch engines; a
        // deployment renders one way and should not carry the other copy.
        $this->assertFileDoesNotExist($packaged . '/templates.bytecode.php');
        $this->assertFileExists($packaged . '/natives.php');
        $this->assertFileExists($packaged . '/htaccess.example');
        $this->assertGreaterThan(200, $result['functions']);
        // Comfortably smaller than node_modules, which is the point.
        $this->assertLessThan(8 * 1024 * 1024, $result['bytes']);
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

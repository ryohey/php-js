<?php

declare(strict_types=1);

namespace PhpJs\PhextCli\Tests;

use PhpJs\Node\NodeHost;
use PhpJs\Phext\App;
use PhpJs\PhextCli\Build;
use PhpJs\PhextCli\Config;
use PHPUnit\Framework\TestCase;

/**
 * The two claims `phext build` makes that would fail silently if they broke.
 *
 * A build that stopped compiling anything would still serve correct pages, at
 * a few hundred milliseconds each, and nothing would say so. A build that
 * started compiling the *app* to PHP would also serve correct pages — while
 * quietly moving the site's own code outside the VM's wall-clock limit and
 * recursion guard, which is the boundary docs/aot-php.md exists to draw.
 *
 * Both are asserted against a real build of the fixture site, because both
 * are properties of what lands on disk rather than of any one function.
 */
final class BuildBoundaryTest extends TestCase
{
    private static string $site = '';
    /** @var array<string, mixed> */
    private static array $result = [];

    public static function setUpBeforeClass(): void
    {
        self::$site = realpath(__DIR__ . '/../../phext/tests/fixtures/site') ?: '';
        if (self::$site === '' || !is_dir(self::$site . '/node_modules/react')) {
            self::markTestSkipped('No React. Run `npm install` in packages/phext/tests/fixtures/site.');
        }
        // From cold, deliberately: a build over a warm cache correctly
        // compiles and writes nothing, which would make every assertion below
        // pass by vacuum.
        foreach (glob(self::cacheDir() . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        self::$result = (new Build(Config::load(self::$site)->app('')))->run();
    }

    private static function cacheDir(): string
    {
        return self::$site . '/' . NodeHost::AOT_CACHE_SUBDIR;
    }

    public function testTheBuildProducedACache(): void
    {
        $files = glob(self::cacheDir() . '/*.php') ?: [];
        // React, its server build, the engine's own JS library, and the app's
        // own pages -- the exact count is not the claim, "more than nothing"
        // is, because zero would mean every request recompiles.
        $this->assertGreaterThan(3, count($files));
    }

    public function testAWarmRequestCompilesNoJavaScript(): void
    {
        $app = new App(self::$site);
        $app->renderUncached('/');

        // The whole reason `phext build` exists: with a warm cache, rendering
        // parses nothing. If this regresses it is invisible except in the
        // timings, which is exactly why it is a test.
        $this->assertSame(
            0,
            $app->host()->modules->compileCount,
            'a request compiled JavaScript despite a warm build cache'
        );
    }

    public function testTheBuildCachedTheAppsOwnModules(): void
    {
        // Every `.tsx` under app/ that a render reached, and nothing from
        // node_modules -- those are the dependency compiler's, and writing
        // them here would overwrite their natives.
        $modules = self::$result['modules'];
        $this->assertNotEmpty($modules);
        foreach ($modules as $path) {
            $this->assertStringStartsWith(self::$site . '/app/', $path);
        }
        $this->assertContains(self::$site . '/app/page.tsx', $modules);
        $this->assertContains(self::$site . '/app/layout.tsx', $modules);
    }

    public function testTheAppsOwnCodeIsNeverCompiledToPhp(): void
    {
        // The trust boundary, checked on what actually landed on disk rather
        // than believed of the filter that put it there. Generated PHP leaves
        // the VM's wall-clock limit and recursion guard behind; the site's own
        // code -- the code being edited -- is exactly what must keep them.
        // Keyed on the file as it is on disk plus the stripper's fingerprint
        // -- never on the stripped output, which would have to be produced
        // before the cache could be consulted (see ModuleLoader).
        $appHashes = [];
        foreach (self::$result['modules'] as $path) {
            $raw = (string)file_get_contents($path);
            $key = \PhpJs\Cache\ArtifactCache::contentHash(
                $raw . "\0" . \PhpJs\StripTypes\Stripper::fingerprint()
            );
            $appHashes[$key] = $path;
        }
        $this->assertNotEmpty($appHashes);

        $checked = 0;
        foreach (glob(self::cacheDir() . '/*.php') ?: [] as $file) {
            $hash = basename($file, '.php');
            if (!isset($appHashes[$hash])) {
                continue;
            }
            $checked++;
            $artifact = require $file;
            $this->assertSame([], $artifact['natives'], "{$appHashes[$hash]} was compiled to native PHP");
            $this->assertTrue(
                self::isInterpreted($artifact['template']),
                "{$appHashes[$hash]} carries a native ID"
            );
        }
        // A pass with nothing examined would mean the artifacts stopped being
        // findable, not that the boundary held.
        $this->assertSame(count($appHashes), $checked, 'not every app module was found in the cache');
    }

    /** @param array<string, mixed> $template */
    private static function isInterpreted(array $template): bool
    {
        if (($template['nativeId'] ?? null) !== null) {
            return false;
        }
        foreach ($template['children'] ?? [] as $child) {
            if (!self::isInterpreted($child)) {
                return false;
            }
        }
        return true;
    }
}

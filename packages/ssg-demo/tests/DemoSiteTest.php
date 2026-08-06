<?php

declare(strict_types=1);

namespace PhpJs\Ssg\Tests;

use PhpJs\Ssg\AppCompiler;
use PhpJs\Ssg\Builder;
use PhpJs\Ssg\Exporter;
use PhpJs\Ssg\Page;
use PhpJs\Ssg\Paths;
use PhpJs\Ssg\Renderer;
use PhpJs\Ssg\Toolbar;
use PhpJs\Ssg\Trust;
use PHPUnit\Framework\TestCase;

/**
 * The demo's own tests. Two claims are worth checking and the rest follows from
 * them: that ahead-of-time compiled PHP and interpreted bytecode produce the
 * same bytes, and that a request compiles no JavaScript.
 *
 * The build is shared across the whole class — it takes a couple of seconds and
 * nothing here mutates it.
 */
final class DemoSiteTest extends TestCase
{
    private static string $buildDir = '';

    public static function setUpBeforeClass(): void
    {
        if (!is_dir(Paths::appRoot() . '/node_modules/react')) {
            self::markTestSkipped('No React. Run `npm install` in packages/ssg-demo.');
        }
        self::$buildDir = sys_get_temp_dir() . '/phpjs-ssg-test-' . getmypid();
        (new Builder(Paths::appRoot(), self::$buildDir))->run();
        // Forces AppCompiler's one-time compile now, so every test in this
        // class -- not just whichever happens to construct a Renderer first
        // -- sees build/app-cjs/ and templates.app.php already in place.
        (new AppCompiler(Paths::appRoot(), self::$buildDir))->ensure();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$buildDir === '' || !is_dir(self::$buildDir)) {
            return;
        }
        foreach (glob(self::$buildDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(self::$buildDir);
    }

    public function testBuildCompilesReactToPhp(): void
    {
        $manifest = require self::$buildDir . '/' . Builder::MANIFEST;
        $this->assertGreaterThan(200, $manifest['converted'], 'ahead-of-time coverage collapsed');
        $this->assertStringStartsWith('19.', $manifest['reactVersion']);
    }

    public function testAppCompilerFindsTheRoutes(): void
    {
        $app = require self::$buildDir . '/' . AppCompiler::MANIFEST;
        $this->assertNotEmpty($app['routes']);
    }

    /**
     * The trust boundary, checked rather than believed.
     *
     * Generated PHP leaves the VM behind, and with it the wall-clock limit and
     * the recursion guard (see Trust). So only lockfile-pinned dependencies may
     * be compiled into PHP -- and the filter that decides this is one
     * `str_contains` away from silently accepting everything, which is why this
     * asserts on the built templates instead of on the filter.
     *
     * `templates.aot.php` (the library build) is React-only now and has
     * nothing untrusted in it by construction; the boundary that actually
     * needs checking is `templates.app.php` (AppCompiler's output), which is
     * the one place untrusted and trusted paths could in principle mix.
     */
    public function testOnlyPinnedDependenciesAreCompiledToPhp(): void
    {
        $templates = require self::$buildDir . '/' . AppCompiler::TEMPLATES;
        $checked = 0;
        foreach ($templates as $path => $template) {
            if (Trust::mayCompileToPhp($path)) {
                continue;
            }
            $checked++;
            $this->assertTrue(
                Trust::templateIsInterpreted($template),
                "$path was compiled to PHP but is not a pinned dependency"
            );
        }
        // The site's own bundle is in there, so a pass with nothing checked
        // would mean the paths stopped looking the way this assumes.
        $this->assertGreaterThan(0, $checked, 'no untrusted module was examined');
    }

    public function testTheBoundaryRejectsTheSitesOwnCode(): void
    {
        $this->assertFalse(Trust::mayCompileToPhp(Paths::buildDir() . '/app-cjs/entry.js'));
        $this->assertFalse(Trust::mayCompileToPhp('/srv/tenant-uploads/user.js'));
        $this->assertTrue(Trust::mayCompileToPhp(Paths::appRoot() . '/node_modules/react/index.js'));
    }

    public function testARequestCompilesNoJavaScript(): void
    {
        // The whole reason the build writes template files: a request that
        // compiles JavaScript pays hundreds of milliseconds for it.
        foreach (Renderer::ENGINES as $engine) {
            $renderer = Renderer::fromBuild(self::$buildDir, $engine);
            $this->assertSame(0, $renderer->modulesCompiled, "$engine compiled a module at boot");
        }
    }

    public function testBothEnginesProduceTheSameBytes(): void
    {
        $aot = Renderer::fromBuild(self::$buildDir, 'aot');
        $bytecode = Renderer::fromBuild(self::$buildDir, 'bytecode');
        foreach ($aot->routes() as $route) {
            $this->assertSame(
                $bytecode->render($route)->html,
                $aot->render($route)->html,
                "ahead-of-time PHP changed the output of $route"
            );
        }
    }

    public function testEveryRouteRendersADocument(): void
    {
        $renderer = Renderer::fromBuild(self::$buildDir, 'aot');
        foreach ($renderer->routes() as $route) {
            $page = $renderer->render($route);
            $this->assertSame(200, $page->status, $route);
            $this->assertStringStartsWith("<!DOCTYPE html>\n<html", $page->html, $route);
            $this->assertStringEndsWith('</html>', $page->html, $route);
            $this->assertStringContainsString('<title>', $page->html, $route);
        }
    }

    public function testAnUnknownPathIs404(): void
    {
        $page = Renderer::fromBuild(self::$buildDir, 'aot')->render('/no/such/page/');
        $this->assertSame(404, $page->status);
        $this->assertStringContainsString('Not found', $page->html);
    }

    public function testRenderOptionsReachTheComponents(): void
    {
        $renderer = Renderer::fromBuild(self::$buildDir, 'aot');
        $small = $renderer->render('/inventory/', ['items' => 5]);
        $large = $renderer->render('/inventory/', ['items' => 60]);
        $this->assertSame(5, substr_count($small->html, 'class="row'), 'items was ignored');
        $this->assertSame(60, substr_count($large->html, 'class="row'));
        $this->assertGreaterThan($small->bytes(), $large->bytes());
    }

    public function testTheToolbarReplacesThePlaceholderExactlyOnce(): void
    {
        $renderer = Renderer::fromBuild(self::$buildDir, 'aot');
        $page = $renderer->render('/');
        $placeholder = '<div id="' . Renderer::METRICS_ID . '"></div>';
        $this->assertStringContainsString($placeholder, $page->html, 'the layout stopped reserving the element');

        $withToolbar = $page->withToolbar(Toolbar::render(
            'aot',
            $page,
            $renderer->bootMs,
            $renderer->modulesCompiled,
            $renderer->reactVersion()
        ));
        $this->assertStringNotContainsString($placeholder, $withToolbar);
        $this->assertStringContainsString('ahead-of-time PHP', $withToolbar);

        // Everything on either side of the placeholder is untouched: the only
        // edit the host makes to React's markup is this one substitution.
        $at = strpos($page->html, $placeholder);
        $this->assertStringStartsWith(substr($page->html, 0, $at), $withToolbar);
        $this->assertStringEndsWith(substr($page->html, $at + strlen($placeholder)), $withToolbar);
    }

    public function testAPageWithoutThePlaceholderIsServedUnchanged(): void
    {
        $page = new Page('/x/', 200, 'x', '<html><body>no placeholder</body></html>', 1.0);
        $this->assertSame($page->html, $page->withToolbar('<div>ignored</div>'));
    }

    public function testExportWritesOneFilePerRouteWithTheSameBytes(): void
    {
        $renderer = Renderer::fromBuild(self::$buildDir, 'aot');
        $outDir = sys_get_temp_dir() . '/phpjs-ssg-dist-' . getmypid();
        $exporter = new Exporter($renderer, $outDir, Paths::assetsDir());
        $result = $exporter->run();

        $this->assertSame(count($renderer->routes()), $result['pages']);
        foreach ($renderer->routes() as $route) {
            $file = $exporter->fileFor($route);
            $this->assertFileExists($file, $route);
            // Static generation is a caching decision, not a different renderer.
            $this->assertSame($renderer->render($route)->html, file_get_contents($file), $route);
        }
        $this->assertFileExists($outDir . '/index.html');
        $this->assertFileExists($outDir . '/docs/getting-started/index.html');
        $this->assertFileExists($outDir . '/assets/site.css');

        self::removeTree($outDir);
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

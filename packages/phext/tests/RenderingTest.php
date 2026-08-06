<?php

declare(strict_types=1);

namespace PhpJs\Phext\Tests;

use PhpJs\Phext\App;
use PhpJs\Phext\Metadata;
use PhpJs\Phext\Page;
use PHPUnit\Framework\TestCase;

/**
 * Rendering a real React tree out of a real file-based route table.
 *
 * The fixture site under `tests/fixtures/site` is the specification for what
 * a phext app looks like: `app/layout.tsx` renders the document, `app/page.tsx`
 * is `/`, `app/docs/[slug]/page.tsx` is a dynamic route with
 * `generateStaticParams()`, and none of them imports React — which is itself
 * one of the things under test.
 *
 * The app is built once for the class: constructing the JS runtime and
 * compiling React costs a second or two and nothing here mutates it.
 */
final class RenderingTest extends TestCase
{
    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        if (!is_dir(self::root() . '/node_modules/react')) {
            self::markTestSkipped('No React. Run `npm install` in tests/fixtures/site.');
        }
        self::$app = new App(self::root());
    }

    public static function tearDownAfterClass(): void
    {
        self::$app = null;
    }

    private static function root(): string
    {
        return __DIR__ . '/fixtures/site';
    }

    private function render(string $path, array $searchParams = []): Page
    {
        return self::$app->renderUncached($path, $searchParams);
    }

    public function testAPageRendersInsideItsLayouts(): void
    {
        $html = $this->render('/docs/intro/')->html;

        // Root layout, then the docs layout, then the page -- the nesting the
        // directory tree describes, in that order.
        $this->assertStringContainsString('<nav>site nav</nav>', $html);
        $this->assertStringContainsString('<main class="docs">', $html);
        $this->assertStringContainsString('<article>Introduction</article>', $html);
        $this->assertLessThan(
            strpos($html, '<article>'),
            strpos($html, '<main class="docs">'),
            'the page rendered outside the layout that should enclose it'
        );
    }

    public function testARouteWithoutItsOwnLayoutSkipsThatLevel(): void
    {
        $html = $this->render('/')->html;
        $this->assertStringContainsString('<h1>Hello from phext</h1>', $html);
        $this->assertStringNotContainsString('class="docs"', $html);
    }

    public function testDynamicSegmentsReachThePageAsParams(): void
    {
        $this->assertStringContainsString('<article>Introduction</article>', $this->render('/docs/intro/')->html);
        $this->assertStringContainsString('<article>Going deeper</article>', $this->render('/docs/deep/')->html);
        // A value the page does not know is still a match -- what to do about
        // it is the page's decision, not the router's.
        $this->assertStringContainsString('<article>Unknown</article>', $this->render('/docs/nope/')->html);
    }

    public function testAComponentFileNeedsNoReactImport(): void
    {
        // None of the fixture files import React; they compile through the
        // automatic JSX runtime. If that regressed, every render above would
        // have died with "React is not defined" -- this asserts the reason
        // rather than the symptom.
        $this->assertStringNotContainsString(
            'import React',
            (string)file_get_contents(self::root() . '/app/page.tsx')
        );
        $this->assertSame(200, $this->render('/')->status);
    }

    public function testTheDocumentGetsADoctype(): void
    {
        $this->assertStringStartsWith("<!DOCTYPE html>\n<html", $this->render('/')->html);
    }

    public function testMetadataFromThePageWinsOverTheLayout(): void
    {
        // The root layout sets both; the page overrides only the title.
        $html = $this->render('/')->html;
        $this->assertStringContainsString('<title>Home</title>', $html);
        $this->assertStringContainsString('content="default description"', $html);
        $this->assertStringNotContainsString('<title>Fixture site</title>', $html);
    }

    public function testMetadataGoesInsideTheHead(): void
    {
        $html = $this->render('/')->html;
        $this->assertMatchesRegularExpression('/<head><title>Home<\/title>/', $html);
    }

    public function testAnUnmatchedPathRendersTheNotFoundPage(): void
    {
        $page = $this->render('/no/such/thing/');
        $this->assertSame(404, $page->status);
        $this->assertStringContainsString('<p>No such page.</p>', $page->html);
        // Still inside the root layout: a 404 is a page of the same site.
        $this->assertStringContainsString('<nav>site nav</nav>', $page->html);
    }

    public function testGenerateStaticParamsDrivesTheBuildsPathList(): void
    {
        $paths = self::$app->paths();
        sort($paths);
        $this->assertSame(['/', '/docs/deep/', '/docs/intro/'], $paths);
    }

    public function testRenderingIsDeterministic(): void
    {
        $this->assertSame($this->render('/docs/intro/')->html, $this->render('/docs/intro/')->html);
    }

    public function testMetadataIsEscaped(): void
    {
        $html = Metadata::apply('<head></head>', ['title' => 'a <script> & "quote"']);
        $this->assertStringContainsString('<title>a &lt;script&gt; &amp; &quot;quote&quot;</title>', $html);
    }

    public function testMetadataIsNotInjectedIntoADocumentWithNoHead(): void
    {
        $this->assertSame('<p>x</p>', Metadata::apply('<p>x</p>', ['title' => 'ignored']));
    }
}

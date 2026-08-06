<?php

declare(strict_types=1);

namespace PhpJs\Phext\Tests;

use PhpJs\Phext\Route;
use PhpJs\Phext\Router;
use PHPUnit\Framework\TestCase;

/**
 * The directory tree is the route table, so these are really tests of one
 * claim: that what a URL resolves to is predictable from the file layout
 * alone, without reading a single one of those files.
 */
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(__DIR__ . '/fixtures/app');
    }

    public function testEveryPageFileBecomesARoute(): void
    {
        $patterns = array_map(static fn (Route $r): string => $r->pattern, $this->router->routes());
        sort($patterns);
        $this->assertSame([
            '/',
            '/about/',
            '/blog/[year]/[month]/',
            '/docs/',
            '/docs/[slug]/',
        ], $patterns);
    }

    public function testAnUnderscoreDirectoryIsNotARoute(): void
    {
        // Somewhere to put components inside app/ without publishing a URL.
        $this->assertNull($this->router->match('/_components/Thing/'));
        $this->assertNull($this->router->match('/_components/'));
    }

    public function testLayoutsNestOutermostFirst(): void
    {
        $match = $this->router->match('/docs/getting-started/');
        $this->assertNotNull($match);
        $this->assertSame(
            ['app/layout.tsx', 'app/docs/layout.tsx'],
            array_map([$this, 'relative'], $match->route->layouts)
        );
    }

    public function testARouteWithNoLayoutOfItsOwnStillGetsTheRootOne(): void
    {
        $match = $this->router->match('/about/');
        $this->assertNotNull($match);
        $this->assertSame(['app/layout.tsx'], array_map([$this, 'relative'], $match->route->layouts));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function matchingPaths(): iterable
    {
        yield 'root' => ['/', '/', []];
        yield 'static' => ['/about/', '/about/', []];
        yield 'without a trailing slash' => ['/about', '/about/', []];
        yield 'with a query string' => ['/about/?x=1', '/about/', []];
        yield 'dynamic' => ['/docs/intro/', '/docs/[slug]/', ['slug' => 'intro']];
        yield 'two dynamic segments' => [
            '/blog/2026/08/', '/blog/[year]/[month]/', ['year' => '2026', 'month' => '08'],
        ];
        yield 'percent-encoded' => ['/docs/a%20b/', '/docs/[slug]/', ['slug' => 'a b']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matchingPaths')]
    public function testMatching(string $path, string $pattern, array $params): void
    {
        $match = $this->router->match($path);
        $this->assertNotNull($match, "$path matched nothing");
        $this->assertSame($pattern, $match->route->pattern);
        $this->assertSame($params, $match->params);
    }

    public function testAStaticSegmentBeatsADynamicOne(): void
    {
        // Both `/docs/` and `/docs/[slug]/` exist; the literal one wins, and
        // it must not depend on which order scandir returned them in.
        $this->assertSame('/docs/', $this->router->match('/docs/')->route->pattern);
    }

    public function testARouteFileNameIsNotReservedInTheUrlSpace(): void
    {
        // `/docs/layout/` is an ordinary value for `[slug]`. That `layout.tsx`
        // is a special *file* does not make `layout` a special *segment* --
        // the two namespaces never meet, and a site with a doc called
        // "layout" would otherwise have an unreachable page.
        $match = $this->router->match('/docs/layout/');
        $this->assertNotNull($match);
        $this->assertSame(['slug' => 'layout'], $match->params);
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonMatches(): iterable
    {
        yield 'unknown top level' => ['/nope/'];
        yield 'too deep' => ['/about/more/'];
        yield 'too shallow' => ['/blog/2026/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonMatches')]
    public function testWhatDoesNotMatch(string $path): void
    {
        $this->assertNull($this->router->match($path));
    }

    public function testTheNotFoundPageIsFoundButIsNotAddressable(): void
    {
        $notFound = $this->router->notFound();
        $this->assertNotNull($notFound);
        $this->assertSame('app/not-found.tsx', $this->relative($notFound->pageFile));
        // It renders inside the root layout, like any other page...
        $this->assertSame(['app/layout.tsx'], array_map([$this, 'relative'], $notFound->layouts));
        // ...but no URL reaches it by name.
        $this->assertNull($this->router->match('/not-found/'));
    }

    public function testStaticPathsSkipsDynamicRoutesUntilToldTheirValues(): void
    {
        $this->assertSame(['/', '/about/', '/docs/'], $this->sorted($this->router->staticPaths()));

        $paths = $this->router->staticPaths(static fn (Route $r): array => match ($r->pattern) {
            '/docs/[slug]/' => [['slug' => 'intro'], ['slug' => 'advanced']],
            default => [],
        });
        $this->assertSame(
            ['/', '/about/', '/docs/', '/docs/advanced/', '/docs/intro/'],
            $this->sorted($paths)
        );
    }

    public function testFillingInARouteRefusesToGuess(): void
    {
        $route = $this->router->match('/docs/x/')->route;
        $this->assertSame('/docs/y%20z/', $route->pathFor(['slug' => 'y z']));

        $this->expectException(\InvalidArgumentException::class);
        $route->pathFor([]);
    }

    public function testAMissingAppDirectoryIsRefusedImmediately(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Router(__DIR__ . '/fixtures/no-such-app');
    }

    /** @param list<string> $paths @return list<string> */
    private function sorted(array $paths): array
    {
        sort($paths);
        return $paths;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen(__DIR__ . '/fixtures/'));
    }
}

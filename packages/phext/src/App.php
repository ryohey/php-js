<?php

declare(strict_types=1);

namespace PhpJs\Phext;

use PhpJs\Engine;
use PhpJs\Node\NodeHost;

/**
 * A phext site: the routes, the runtime that renders them, and the cache in
 * front of both.
 *
 * This is the whole public surface. A host — `phext-cli`'s server, a
 * front controller, a WordPress plugin — needs `render()` and `paths()` and
 * nothing else; everything under it (which JavaScript engine, which module
 * loader, where compiled bytecode is cached) is this package's business.
 *
 * Construction is lazy in the one way that matters on shared-nothing hosting:
 * the JS runtime is not built until something actually needs rendering, so a
 * request that the page cache answers from disk never starts one.
 */
final class App
{
    private ?NodeHost $host = null;
    private ?Renderer $renderer = null;
    private ?Router $router = null;
    private ?PageCache $pageCache = null;

    /**
     * @param string  $root      project root: holds `app/` and `node_modules/`
     * @param ?string $cacheDir  where rendered pages are cached, or null for
     *                           no caching (every request renders)
     * @param ?int    $ttl       seconds before a cached page is re-rendered,
     *                           or null to keep it until something clears it
     * @param string  $method    `renderToString` or `renderToStaticMarkup`
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $cacheDir = null,
        private readonly ?int $ttl = null,
        private readonly string $method = 'renderToString',
    ) {
        if (!is_dir($this->root . '/app')) {
            throw new \InvalidArgumentException("No app/ directory in {$this->root}");
        }
    }

    public function router(): Router
    {
        return $this->router ??= new Router($this->root . '/app');
    }

    /**
     * Every URL a build should render: the static routes, plus whatever each
     * dynamic route's `generateStaticParams()` asks for.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->router()->staticPaths(fn (Route $route): array => $this->renderer()->staticParams($route));
    }

    /**
     * Render one path, through the cache if there is one.
     *
     * A page that does not match any route is a 404 and is never cached —
     * caching it would pin the wrong answer at a path a later build might
     * define, and would let any request for a nonexistent path write a file.
     *
     * @param array<string, string|list<string>> $searchParams
     */
    public function render(string $path, array $searchParams = []): Page
    {
        // Query parameters change the bytes, so a request carrying any is
        // rendered and not cached -- which keeps the cache keyed by path
        // alone, and that is what makes the file layout (and therefore
        // serving a hit without PHP) possible.
        $cache = $this->cache();
        if ($cache === null || $searchParams !== []) {
            return $this->renderUncached($path, $searchParams);
        }

        $rendered = null;
        $html = $cache->get(
            $path,
            function () use ($path, &$rendered): string {
                $rendered = $this->renderUncached($path);
                return $rendered->html;
            },
            // A closure rather than an arrow function, and that matters: an
            // arrow function captures by value, so this would read the
            // `null` from before the render and store every 404.
            static function () use (&$rendered): bool {
                return $rendered === null || $rendered->status === 200;
            },
        );

        return $rendered ?? new Page($path, 200, $html);
    }

    /** Render without consulting or writing the cache. */
    public function renderUncached(string $path, array $searchParams = []): Page
    {
        $match = $this->router()->match($path);
        if ($match !== null) {
            return $this->renderer()->render($match, $searchParams);
        }
        $notFound = $this->router()->notFound();
        if ($notFound === null) {
            return new Page($path, 404, '<!DOCTYPE html>' . "\n" . '<h1>404</h1>');
        }
        return $this->renderer()->render(new RouteMatch($notFound), $searchParams, 404);
    }

    /**
     * The page cache, or null if this app has none.
     *
     * Memoized, and that is not an optimization: `lastStatus` is state, so a
     * caller that wants to know whether the request it just served was a hit
     * has to be holding the same instance that served it.
     */
    public function cache(): ?PageCache
    {
        if ($this->cacheDir === null) {
            return null;
        }
        return $this->pageCache ??= new PageCache($this->cacheDir, $this->ttl);
    }

    /**
     * The JS runtime, built on first use.
     *
     * `node_modules/.phpjs-aot/` is picked up automatically if a build put
     * one there, which is what takes boot from hundreds of milliseconds to
     * single digits; nothing here has to ask for it.
     */
    public function host(): NodeHost
    {
        return $this->host ??= new NodeHost($this->root, captureOutput: true);
    }

    public function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer($this->host(), $this->method);
    }

    /** Where the runtime caches compiled JavaScript, by convention. */
    public function buildCacheDir(): string
    {
        return $this->root . '/' . NodeHost::AOT_CACHE_SUBDIR;
    }

    /** Files served as-is, the same convention Next.js uses. */
    public function publicDir(): string
    {
        return $this->root . '/public';
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Compile everything a request would otherwise compile, into the build
     * cache — the engine's own standard library included.
     *
     * What this does *not* do is compile the app's own pages to native PHP.
     * Generated PHP leaves the VM's wall-clock limit and stack guard behind
     * (docs/aot-php.md), which is a fine trade for a lockfile-pinned
     * dependency and a bad one for code that is being edited. Pages stay
     * interpreted bytecode; only their compilation is cached.
     */
    public function warmBuildCache(): void
    {
        Engine::cacheEcmaScriptLibrary($this->buildCacheDir());
    }
}

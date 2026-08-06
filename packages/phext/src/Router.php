<?php

declare(strict_types=1);

namespace PhpJs\Phext;

/**
 * File-based routing: the directory tree under `app/` *is* the route table.
 *
 * ```
 * app/
 *   layout.tsx           encloses every route below it
 *   page.tsx             /
 *   about/page.tsx       /about/
 *   docs/
 *     layout.tsx         encloses /docs/ and everything under it
 *     page.tsx           /docs/
 *     [slug]/page.tsx    /docs/:slug/
 *   not-found.tsx        rendered for anything that matches nothing
 * ```
 *
 * The shape is Next.js's App Router, minus the parts that need a client:
 * route groups, parallel and intercepted routes, `loading`/`error` boundaries
 * and middleware are all out of scope, and a directory whose name would mean
 * one of those is treated as an ordinary path segment. What is here is the
 * part that a server-only renderer can honour completely, which is the whole
 * point — a feature arrives with its semantics intact or is refused
 * (CLAUDE.md).
 *
 * Scanning is `scandir`-ordered but the result is not: routes are sorted so
 * that matching is deterministic and static segments beat dynamic ones,
 * regardless of what order the filesystem handed them over in.
 */
final class Router
{
    /** Basenames that make a directory a route, in resolution order. */
    private const PAGE = 'page';
    private const LAYOUT = 'layout';
    private const NOT_FOUND = 'not-found';

    /** Extensions a route file may have, in resolution order. */
    public const EXTENSIONS = ['tsx', 'ts', 'jsx', 'js'];

    /** @var list<Route> */
    private array $routes = [];
    private ?string $notFoundFile = null;
    /** @var list<string> layouts enclosing the not-found page, outermost first */
    private array $notFoundLayouts = [];

    public function __construct(private readonly string $appDir)
    {
        if (!is_dir($this->appDir)) {
            throw new \InvalidArgumentException("No app directory at {$this->appDir}");
        }
        $this->scan($this->appDir, '', [], []);
        // Static before dynamic at equal depth, then longest first: `/docs/x/`
        // must win over `/docs/[slug]/` no matter how they were discovered.
        usort($this->routes, static function (Route $a, Route $b): int {
            return [count($a->params), substr_count($b->pattern, '/')]
                <=> [count($b->params), substr_count($a->pattern, '/')];
        });
    }

    /** @return list<Route> every route the tree defines, matching order */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Every URL this site has, for a build that renders all of them.
     *
     * Dynamic routes contribute nothing on their own — `[slug]` names a shape,
     * not a page. `$paramsFor` is asked what values exist for one, which is
     * `generateStaticParams()`'s job in Next.js and `Renderer`'s here; a route
     * it has no answer for is simply not exported, and stays available to a
     * live server that can match it on demand.
     *
     * @param  null|callable(Route): list<array<string, string>> $paramsFor
     * @return list<string>
     */
    public function staticPaths(?callable $paramsFor = null): array
    {
        $paths = [];
        foreach ($this->routes as $route) {
            if (!$route->isDynamic()) {
                $paths[] = $route->pattern;
                continue;
            }
            foreach ($paramsFor === null ? [] : $paramsFor($route) as $params) {
                $paths[] = $route->pathFor($params);
            }
        }
        return $paths;
    }

    /** The `not-found` page, if the tree has one. */
    public function notFound(): ?Route
    {
        return $this->notFoundFile === null
            ? null
            : new Route('/404/', $this->notFoundFile, $this->notFoundLayouts);
    }

    /** Resolve a URL path, or null when nothing matches. */
    public function match(string $path): ?RouteMatch
    {
        $wanted = self::segments($path);
        foreach ($this->routes as $route) {
            $params = self::bind(self::segments($route->pattern), $wanted);
            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }
        return null;
    }

    /**
     * Match one route's segments against a request's.
     *
     * @param  list<string> $pattern
     * @param  list<string> $wanted
     * @return array<string, string>|null the bound parameters, or null on no match
     */
    private static function bind(array $pattern, array $wanted): ?array
    {
        if (count($pattern) !== count($wanted)) {
            return null;
        }
        $params = [];
        foreach ($pattern as $i => $segment) {
            if ($segment !== '' && $segment[0] === '[') {
                $params[trim($segment, '[]')] = $wanted[$i];
                continue;
            }
            if ($segment !== $wanted[$i]) {
                return null;
            }
        }
        return $params;
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        $trimmed = trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
        return $trimmed === '' ? [] : array_map('rawurldecode', explode('/', $trimmed));
    }

    /**
     * @param list<string> $layouts enclosing layouts, outermost first
     * @param list<string> $params  dynamic segment names seen so far
     */
    private function scan(string $dir, string $pattern, array $layouts, array $params): void
    {
        $layout = self::fileIn($dir, self::LAYOUT);
        if ($layout !== null) {
            $layouts[] = $layout;
        }

        $notFound = self::fileIn($dir, self::NOT_FOUND);
        // The outermost one wins, the same way a root layout does: a nested
        // not-found would need a matched route to nest *inside*, and by
        // definition there is not one.
        if ($notFound !== null && $this->notFoundFile === null) {
            $this->notFoundFile = $notFound;
            $this->notFoundLayouts = $layouts;
        }

        $page = self::fileIn($dir, self::PAGE);
        if ($page !== null) {
            $this->routes[] = new Route($pattern === '' ? '/' : $pattern . '/', $page, $layouts, $params);
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || !is_dir($dir . '/' . $name)) {
                continue;
            }
            // A directory whose name is `[x]` binds a parameter; `_x` is
            // private, the one Next.js convention worth keeping here because
            // it is how a component directory lives inside app/ without
            // becoming a URL.
            if ($name[0] === '_') {
                continue;
            }
            $this->scan(
                $dir . '/' . $name,
                $pattern . '/' . $name,
                $layouts,
                $name[0] === '[' ? [...$params, trim($name, '[]')] : $params,
            );
        }
    }

    /** The first `$base.<ext>` present in `$dir`, or null. */
    private static function fileIn(string $dir, string $base): ?string
    {
        foreach (self::EXTENSIONS as $ext) {
            $path = $dir . '/' . $base . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}

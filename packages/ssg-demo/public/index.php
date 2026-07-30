<?php

/**
 * Front controller for the demo server.
 *
 * Renders a page the first time it is asked for, writes it to the cache, and
 * serves the file thereafter — the shape Next.js calls on-demand ISR, arranged
 * for hosting that has PHP and nothing else.
 *
 * Two details are the whole design:
 *
 * - **What is cached has no toolbar in it.** The page is stored exactly as the
 *   static export writes it, with the metrics element left empty, and the
 *   toolbar is substituted in on the way out. So a cached file is deployable as
 *   is, and `PageCache::htaccess()` can hand it to Apache directly — which is
 *   the point, because then a hit never starts PHP at all.
 * - **Only the plain page is cached.** `?engine=` and `?items=` change the
 *   output, so a request carrying either renders and is not stored. That keeps
 *   the cache keyed by path alone, which is what makes the file layout — and
 *   therefore the Apache bypass — possible.
 *
 * Not a production front controller otherwise: no compression, no error page.
 */

declare(strict_types=1);

use PhpJs\JSException;
use PhpJs\Ssg\Page;
use PhpJs\Ssg\PageCache;
use PhpJs\Ssg\Paths;
use PhpJs\Ssg\Renderer;
use PhpJs\Ssg\Toolbar;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Let the built-in server hand back files that exist on disk (the stylesheet).
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(Paths::publicDir() . $path)) {
    return false;
}

$engine = $_GET['engine'] ?? 'aot';
$items = isset($_GET['items']) ? max(1, min(20000, (int)$_GET['items'])) : null;
$options = $items === null ? [] : ['items' => $items];
$buildDir = getenv('PHPJS_SSG_BUILD') ?: Paths::buildDir();
$distDir = getenv('PHPJS_SSG_DIST') ?: Paths::distDir();

$cacheDir = getenv('PHPJS_SSG_CACHE');
$ttl = getenv('PHPJS_SSG_TTL');
// Only the plain page is cacheable: the query parameters below change the bytes.
$cacheable = $cacheDir !== false && $cacheDir !== ''
    && !isset($_GET['engine']) && !isset($_GET['items']);
$cache = $cacheable ? new PageCache($cacheDir, $ttl === false ? null : (int)$ttl) : null;

/** Send a page, with the timings in headers as well as in the toolbar. */
$send = static function (
    int $status,
    string $html,
    float $renderMs,
    float $bootMs,
    string $cacheStatus = PageCache::BYPASS
): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-PhpJs-Cache: ' . $cacheStatus);
    header(sprintf('X-PhpJs-Boot: %.2fms', $bootMs));
    header(sprintf('X-PhpJs-Render: %.2fms', $renderMs));
    // Shows up in the browser's own network panel, next to the transfer time.
    header(sprintf('Server-Timing: boot;dur=%.2f, render;dur=%.2f', $bootMs, $renderMs));
    echo $html;
};

try {
    if ($engine === 'static') {
        $renderer = Renderer::fromBuild($buildDir, 'aot');
        $exporter = new PhpJs\Ssg\Exporter($renderer, $distDir, Paths::assetsDir());
        $file = $exporter->fileFor(rtrim($path, '/') . '/');
        $started = microtime(true);
        $html = is_file($file) ? (string)file_get_contents($file) : null;
        $readMs = (microtime(true) - $started) * 1000;
        if ($html === null) {
            // Fall back to rendering, but say why the static copy is missing.
            $page = $renderer->render($path, $options);
            $send(
                $page->status,
                $page->withToolbar(Toolbar::missingStatic($path)),
                $page->renderMs,
                $renderer->bootMs
            );
            return;
        }
        $page = new PhpJs\Ssg\Page($path, 200, '', $html, $readMs);
        $send(
            200,
            $page->withToolbar(Toolbar::render(
                'static',
                $page,
                $renderer->bootMs,
                $renderer->modulesCompiled,
                $renderer->reactVersion(),
                $options
            )),
            $readMs,
            $renderer->bootMs
        );
        return;
    }

    if (!in_array($engine, Renderer::ENGINES, true)) {
        $engine = 'aot';
    }

    if ($cache !== null) {
        // A hit must not boot the runtime, so the renderer is built lazily --
        // that is where the ~70 ms of boot lives, and skipping it is most of
        // what the cache buys when PHP does handle the request.
        $renderer = null;
        $page = null;
        $html = $cache->get(
            $path,
            static function () use ($buildDir, $engine, $path, $options, &$renderer, &$page): string {
                $renderer = Renderer::fromBuild($buildDir, $engine);
                $page = $renderer->render($path, $options);
                // Stored without the toolbar, so the file stays deployable.
                return $page->html;
            },
            // A 404 is served but never becomes a cached page at that path.
            // By reference on purpose: an arrow function would capture $page by
            // value, while it is still null, and cache every 404.
            static function () use (&$page): bool {
                return ($page?->status ?? 200) === 200;
            }
        );

        $status = $page?->status ?? 200;
        $renderMs = $page?->renderMs ?? $cache->lastReadMs;
        $cached = new Page($path, $status, '', $html, $renderMs);
        // On a hit there is no renderer to ask, and the manifest is a plain
        // array file that opcache already holds.
        $reactVersion = $renderer?->reactVersion()
            ?? (string)((@include $buildDir . '/manifest.php')['reactVersion'] ?? '');
        $send(
            $status,
            $cached->withToolbar(Toolbar::render(
                $engine,
                $cached,
                $renderer?->bootMs ?? 0.0,
                $renderer?->modulesCompiled ?? 0,
                $reactVersion,
                $options,
                $cache->lastStatus
            )),
            $renderMs,
            $renderer?->bootMs ?? 0.0,
            $cache->lastStatus
        );
        return;
    }

    $renderer = Renderer::fromBuild($buildDir, $engine);
    $page = $renderer->render($path, $options);
    $send(
        $page->status,
        $page->withToolbar(Toolbar::render(
            $engine,
            $page,
            $renderer->bootMs,
            $renderer->modulesCompiled,
            $renderer->reactVersion(),
            $options
        )),
        $page->renderMs,
        $renderer->bootMs
    );
} catch (JSException | RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

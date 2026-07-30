<?php

/**
 * Front controller for the demo server.
 *
 * Boots the runtime and renders on every request, deliberately: PHP is
 * shared-nothing, so this is what a real deployment without a cache in front of
 * it would actually pay. The toolbar reports both halves of that — boot and
 * render — and lets you switch the same URL between ahead-of-time compiled PHP,
 * interpreted bytecode, and the file a static export wrote.
 *
 * Served by `bin/phpjs-ssg serve`, which starts PHP's built-in server with
 * opcache and the tracing JIT turned on. Not a production front controller: it
 * has no cache, no compression and no error page.
 */

declare(strict_types=1);

use PhpJs\JSException;
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

/** Send a page, with the timings in headers as well as in the toolbar. */
$send = static function (int $status, string $html, float $renderMs, float $bootMs): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
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

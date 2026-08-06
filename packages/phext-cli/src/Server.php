<?php

declare(strict_types=1);

namespace PhpJs\PhextCli;

use PhpJs\Phext\App;
use PhpJs\Phext\PageCache;

/**
 * The request handler `phext start` serves with — and the same one a real
 * deployment's front controller calls.
 *
 * Kept apart from the CLI that launches it precisely so those two are the
 * same code: a demo server whose request path differs from production's
 * proves nothing about production.
 */
final class Server
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Handle one request, writing headers and body.
     *
     * @param array<string, mixed> $server a $_SERVER-shaped array
     * @param array<string, mixed> $query  a $_GET-shaped array
     * @return int the status code sent
     */
    public function handle(array $server, array $query = []): int
    {
        $path = parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'GET' && $method !== 'HEAD') {
            // Nothing here mutates anything, so anything else is a mistake
            // rather than a route that happens to be missing.
            $this->send(405, ['Allow' => 'GET, HEAD'], '<h1>405 Method Not Allowed</h1>');
            return 405;
        }

        // Static files under public/ are served as-is, and are checked first
        // so a real file always wins over a route that would shadow it.
        $file = $this->publicFile($path);
        if ($file !== null) {
            $this->sendFile($file);
            return 200;
        }

        $started = microtime(true);
        $cache = $this->app->cache();
        $page = $this->app->render($path, $query);
        $elapsed = (microtime(true) - $started) * 1000;

        $this->send($page->status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Phext-Cache' => $cache?->lastStatus ?? PageCache::BYPASS,
            'Server-Timing' => sprintf('page;dur=%.2f', $elapsed),
        ], $method === 'HEAD' ? '' : $page->html);
        return $page->status;
    }

    /** @param array<string, string> $headers */
    private function send(int $status, array $headers, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
        }
        echo $body;
    }

    private function sendFile(string $file): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . self::mimeType($file));
            header('Content-Length: ' . (string)filesize($file));
        }
        readfile($file);
    }

    /**
     * A real file under `public/` for this path, or null.
     *
     * The path is resolved and then checked to still be inside `public/`,
     * which is the only thing standing between a request line and the rest of
     * the filesystem.
     */
    private function publicFile(string $path): ?string
    {
        $publicDir = realpath($this->app->publicDir());
        if ($publicDir === false || $path === '/' || str_ends_with($path, '/')) {
            return null;
        }
        $candidate = realpath($publicDir . '/' . ltrim(rawurldecode($path), '/'));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        return str_starts_with($candidate, $publicDir . DIRECTORY_SEPARATOR) ? $candidate : null;
    }

    private static function mimeType(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'txt' => 'text/plain; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };
    }
}

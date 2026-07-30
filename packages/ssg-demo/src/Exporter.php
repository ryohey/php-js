<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Static site generation: render every route once, write it to a file.
 *
 * The markup is byte-identical to what the per-request path serves, which is the
 * point — static generation is a caching decision, not a different renderer.
 * Files are written with the toolbar element left empty, so an export is
 * deployable as-is and the demo server is the only thing that fills it in.
 */
final class Exporter
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly string $outDir,
        private readonly string $assetsDir,
    ) {
    }

    /**
     * @param null|callable(string): void $log
     * @return array{pages: int, bytes: int, renderMs: float}
     */
    public function run(?callable $log = null): array
    {
        $log ??= static function (string $_): void {
        };
        $this->mkdir($this->outDir);

        $pages = 0;
        $bytes = 0;
        $renderMs = 0.0;
        foreach ($this->renderer->routes() as $route) {
            $page = $this->renderer->render($route);
            $target = $this->fileFor($route);
            $this->mkdir(dirname($target));
            file_put_contents($target, $page->html);
            $pages++;
            $bytes += $page->bytes();
            $renderMs += $page->renderMs;
            $log(sprintf(
                '%-28s %7.1f ms  %6.0f KB  -> %s',
                $route,
                $page->renderMs,
                $page->bytes() / 1024,
                self::relative($this->outDir, $target)
            ));
        }

        $copied = $this->copyAssets();
        if ($copied > 0) {
            $log(sprintf('%-28s %s', 'assets', $copied . ' files copied'));
        }
        return ['pages' => $pages, 'bytes' => $bytes, 'renderMs' => $renderMs];
    }

    /** Where a route's HTML lives: "/docs/x/" becomes "docs/x/index.html". */
    public function fileFor(string $route): string
    {
        $trimmed = trim($route, '/');
        return $trimmed === ''
            ? $this->outDir . '/index.html'
            : $this->outDir . '/' . $trimmed . '/index.html';
    }

    private function copyAssets(): int
    {
        if (!is_dir($this->assetsDir)) {
            return 0;
        }
        $target = $this->outDir . '/assets';
        $this->mkdir($target);
        $copied = 0;
        foreach (scandir($this->assetsDir) ?: [] as $name) {
            $source = $this->assetsDir . '/' . $name;
            if (!is_file($source)) {
                continue;
            }
            copy($source, $target . '/' . $name);
            $copied++;
        }
        return $copied;
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
    }

    private static function relative(string $base, string $path): string
    {
        return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
    }
}

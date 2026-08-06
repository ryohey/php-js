<?php

declare(strict_types=1);

namespace PhpJs\Phext;

/**
 * Static generation: render every route once, write it to a file.
 *
 * The markup is byte-identical to what a request would be served, because it
 * is the same renderer — static generation is a caching decision here, not a
 * second code path. The layout matches `PageCache`'s exactly, so an export
 * and a warm cache are interchangeable on disk.
 */
final class Exporter
{
    public function __construct(
        private readonly App $app,
        private readonly string $outDir,
    ) {
    }

    /**
     * @param  null|callable(string): void $log
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
        foreach ($this->app->paths() as $path) {
            $page = $this->app->renderUncached($path);
            $target = $this->fileFor($path);
            $this->mkdir(\dirname($target));
            file_put_contents($target, $page->html);
            $pages++;
            $bytes += $page->bytes();
            $renderMs += $page->renderMs;
            $log(sprintf(
                '%-28s %7.1f ms  %6.0f KB  -> %s',
                $path,
                $page->renderMs,
                $page->bytes() / 1024,
                substr($target, \strlen($this->outDir) + 1)
            ));
        }

        $copied = $this->copyPublic();
        if ($copied > 0) {
            $log(sprintf('%-28s %s', 'public/', $copied . ' files copied'));
        }
        return ['pages' => $pages, 'bytes' => $bytes, 'renderMs' => $renderMs];
    }

    /** Where a path's HTML lives: "/docs/x/" becomes "docs/x/index.html". */
    public function fileFor(string $path): string
    {
        $trimmed = trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
        return $trimmed === ''
            ? $this->outDir . '/index.html'
            : $this->outDir . '/' . rawurldecode($trimmed) . '/index.html';
    }

    /** Everything in `public/` ships as-is, the same convention Next.js uses. */
    private function copyPublic(): int
    {
        $source = $this->app->publicDir();
        if (!is_dir($source)) {
            return 0;
        }
        $copied = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $target = $this->outDir . '/' . substr($file->getPathname(), \strlen($source) + 1);
            $this->mkdir(\dirname($target));
            copy($file->getPathname(), $target);
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
}

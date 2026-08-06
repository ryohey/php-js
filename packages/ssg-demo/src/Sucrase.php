<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\StripTypes\Stripper;

/**
 * Mirrors `app/`'s TSX/TS sources as plain CJS under `build/app-cjs/`, one
 * file at a time, with no bundling step — `packages/strip-types`'s
 * `Stripper` does the actual type erasure; this class only exists to walk a
 * directory tree and write the result out.
 *
 * php-js's own rendering no longer needs this at all: `require('./app/entry.tsx')`
 * strips and compiles transparently now (`AppCompiler`, `NodeHost`'s
 * auto-detection of `packages/strip-types`). What still needs a written-out
 * `.js` mirror is `bin/phpjs-ssg compare` — real Node has no TSX support of
 * its own, so `entry.node.tsx`'s whole module graph has to exist as files
 * Node can `require` directly, and this is where that mirror comes from.
 */
final class Sucrase
{
    private const SOURCE_EXTENSIONS = ['tsx', 'ts', 'jsx', 'js'];

    public function __construct(
        private readonly string $srcDir,
        private readonly string $outDir,
    ) {
    }

    /** @return int files transformed */
    public function run(): int
    {
        $count = 0;
        foreach ($this->sourceFiles() as $full) {
            $rel = substr($full, \strlen($this->srcDir) + 1);
            $outPath = $this->outDir . '/' . preg_replace('/\.(tsx|ts|jsx|js)$/', '.js', $rel);
            if (!is_dir(\dirname($outPath)) && !mkdir(\dirname($outPath), 0o777, true) && !is_dir(\dirname($outPath))) {
                throw new \RuntimeException('Cannot create ' . \dirname($outPath));
            }

            $src = file_get_contents($full);
            if ($src === false) {
                throw new \RuntimeException("Cannot read $full");
            }
            file_put_contents($outPath, Stripper::strip($src, $full));
            $count++;
        }
        return $count;
    }

    /** @return list<string> absolute paths, sorted for a deterministic build */
    private function sourceFiles(): array
    {
        $pattern = implode('|', self::SOURCE_EXTENSIONS);
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && preg_match("/\\.($pattern)$/", $file->getFilename())) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}

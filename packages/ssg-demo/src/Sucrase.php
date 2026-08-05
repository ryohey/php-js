<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

/**
 * Node-style "type stripping": TSX/TS sources under `app/` become plain CJS
 * under `build/app-cjs/`, one file at a time, with no bundling step.
 *
 * This runs sucrase (github.com/alangpierce/sucrase) — a fast, no-type-check
 * TS/JSX-to-CJS transformer — *inside* php-js itself, so the whole pipeline
 * from TSX source to rendered HTML stays pure PHP: no `node`, `vite`, `babel`
 * or `tsc` process is ever spawned. `disableESTransforms` is what makes this
 * safe to skip Babel's ES5 downleveling — the sources use `??`, `?.`,
 * optional catch binding and the rest of what DESIGN.md §2.5 has landed, and
 * turning that flag on tells sucrase to leave all of it alone rather than
 * rewriting it through helper functions the engine does not need.
 *
 * The `imports` transform turns `import`/`export` into `require`/`exports`
 * per file rather than bundling: relative specifiers are untouched, so
 * `ModuleLoader::resolve()` walks the resulting tree exactly as it would a
 * hand-written CommonJS tree.
 */
final class Sucrase
{
    private const SOURCE_EXTENSIONS = ['tsx', 'ts', 'jsx', 'js'];

    public function __construct(
        private readonly string $appRoot,
        private readonly string $srcDir,
        private readonly string $outDir,
    ) {
    }

    /** @return int files transformed */
    public function run(): int
    {
        $host = new NodeHost($this->appRoot);
        $vm = $host->vm();
        $sucrase = $host->requireModule('sucrase');
        $transform = $sucrase->get('transform', $vm);

        $count = 0;
        foreach ($this->sourceFiles() as $full) {
            $rel = substr($full, \strlen($this->srcDir) + 1);
            $outPath = $this->outDir . '/' . preg_replace('/\.(tsx|ts|jsx|js)$/', '.js', $rel);
            if (!is_dir(\dirname($outPath)) && !mkdir(\dirname($outPath), 0o777, true) && !is_dir(\dirname($outPath))) {
                throw new \RuntimeException('Cannot create ' . \dirname($outPath));
            }

            $opts = $host->realm()->newObject();
            $opts->defineOwnData('transforms', $host->realm()->newArray(['typescript', 'jsx', 'imports']));
            $opts->defineOwnData('jsxRuntime', 'classic');
            $opts->defineOwnData('production', true);
            $opts->defineOwnData('disableESTransforms', true);
            $opts->defineOwnData('filePath', $full);

            $src = file_get_contents($full);
            if ($src === false) {
                throw new \RuntimeException("Cannot read $full");
            }
            $result = $host->call($transform, null, [$src, $opts]);
            $code = Conversions::toString($vm, $result->get('code', $vm));
            file_put_contents($outPath, $code);
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

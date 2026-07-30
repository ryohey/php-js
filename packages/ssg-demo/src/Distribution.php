<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Assembles everything a render-only host needs, as a directory you can drop
 * into a WordPress plugin, a theme, or a zip.
 *
 * Two facts make the result much smaller than the build directory it comes from,
 * and both were measured rather than assumed:
 *
 * - **Rendering needs no parser.** With every template precompiled, neither
 *   Peast (1.1 MB, compiles JavaScript) nor nikic/php-parser (emits PHP) is ever
 *   loaded. Verified by deleting both and rendering.
 * - **Rendering needs almost no JavaScript.** The template bundle's keys are the
 *   exact list of files the program loads — 6 files, 280 KB, against the 7.5 MB
 *   that `npm install react react-dom` puts on disk. Module *resolution* still
 *   consults the filesystem, so the files ship; nothing else does.
 *
 * What it deliberately leaves out: `templates.bytecode.php`, which exists only
 * so the demo can serve the same page interpreted, and the second copy of React
 * that nobody loads.
 *
 * **Paths are made relative on the way in and absolute on the way out.** A build
 * happens at one path and a plugin installs at another, so a distribution whose
 * manifest remembered the build machine's directory layout would be useless. The
 * native IDs need no such treatment: they are hashes of module *contents*
 * (docs/aot-php.md §4), so they survive relocation untouched.
 */
final class Distribution
{
    public const MANIFEST = 'phpjs-manifest.php';

    /**
     * @param string $buildDir where `phpjs-ssg build` wrote its output
     * @param string $appRoot  the module root that build used
     */
    public function __construct(
        private readonly string $buildDir,
        private readonly string $appRoot,
    ) {
    }

    /**
     * @param  null|callable(string): void $log
     * @return array{files: int, bytes: int, modules: int, functions: int}
     */
    public function writeTo(string $outDir, ?callable $log = null): array
    {
        $log ??= static function (string $_): void {
        };
        $manifest = require $this->buildDir . '/' . Builder::MANIFEST;
        $engine = $manifest['engines']['aot'];

        $this->mkdir($outDir);
        $bytes = 0;
        $files = 0;

        // 1. The templates, rekeyed to paths relative to the module root.
        $templates = require $this->buildDir . '/' . $engine['templates'];
        $relative = [];
        foreach ($templates as $path => $template) {
            $relative[$this->relativize($path)] = $template;
        }
        $bytes += $this->writeArray($outDir . '/templates.php', $relative);
        $files++;
        $log(sprintf('templates      %6s  %d modules, paths relativized',
            self::size($bytes), count($relative)));

        // 2. The generated PHP, copied as-is: native IDs are content hashes, so
        //    it does not care where it ends up.
        $nativesBytes = $this->copy(
            $this->buildDir . '/' . $engine['natives'],
            $outDir . '/natives.php'
        );
        $bytes += $nativesBytes;
        $files++;
        $log(sprintf('natives        %6s  %d of %d functions compiled to PHP',
            self::size($nativesBytes), $manifest['converted'], $manifest['seen']));

        // 3. The polyfill template, so constructing a host compiles nothing.
        $polyfillBytes = $this->copy(
            $this->buildDir . '/' . $manifest['polyfill'],
            $outDir . '/polyfill.php'
        );
        $bytes += $polyfillBytes;
        $files++;
        $log(sprintf('polyfill       %6s', self::size($polyfillBytes)));

        // 4. The JavaScript the templates name, at its relative path. Only
        //    module resolution reads these; nothing is compiled from them.
        $jsBytes = 0;
        $jsFiles = 0;
        foreach (array_keys($relative) as $rel) {
            if (!is_file($this->appRoot . '/' . $rel)) {
                continue;   // a shipped stub, resolved by name rather than path
            }
            $jsBytes += $this->copy($this->appRoot . '/' . $rel, $outDir . '/js/' . $rel);
            $jsFiles++;
        }
        $bytes += $jsBytes;
        $files += $jsFiles;
        $log(sprintf('javascript     %6s  %d files (of a %s node_modules)',
            self::size($jsBytes), $jsFiles, self::size($this->directorySize($this->appRoot . '/node_modules'))));

        // 5. The Apache rewrite that lets a cached page skip PHP entirely. Not
        //    applied, only offered: whether AllowOverride permits it is the
        //    host's business, and silently depending on it would be worse.
        $htaccess = (new PageCache('/cache'))->htaccess('/cache');
        file_put_contents($outDir . '/htaccess.example', $htaccess);
        $bytes += strlen($htaccess);
        $files++;

        // 6. The manifest, with everything the runtime needs and nothing about
        //    where it was built.
        $bytes += $this->writeArray($outDir . '/' . self::MANIFEST, [
            'builtAt' => $manifest['builtAt'],
            'reactVersion' => $manifest['reactVersion'],
            'routes' => $manifest['routes'],
            'entry' => $manifest['entry'],
            'converted' => $manifest['converted'],
            'seen' => $manifest['seen'],
            // Relative to this directory, resolved by Distribution::load().
            'templates' => 'templates.php',
            'natives' => 'natives.php',
            'polyfill' => 'polyfill.php',
            'jsRoot' => 'js',
        ]);
        $files++;

        $log(sprintf("\n%d files, %s total", $files, self::size($bytes)));
        return [
            'files' => $files,
            'bytes' => $bytes,
            'modules' => count($relative),
            'functions' => $manifest['converted'],
        ];
    }

    /**
     * Turn a distribution directory back into something Renderer can use, by
     * rebasing the relative paths onto wherever it actually landed.
     *
     * @return array<string, mixed> a manifest in the shape Renderer expects
     */
    public static function load(string $dir): array
    {
        $manifest = require rtrim($dir, '/') . '/' . self::MANIFEST;
        $dir = rtrim($dir, '/');
        // realpath, because the module loader compares resolved paths and a
        // symlinked plugin directory would otherwise miss every template.
        $jsRoot = realpath($dir . '/' . $manifest['jsRoot']);
        if ($jsRoot === false) {
            throw new \RuntimeException("Distribution at $dir has no {$manifest['jsRoot']} directory");
        }

        $absolute = [];
        foreach (require $dir . '/' . $manifest['templates'] as $rel => $template) {
            $absolute[$jsRoot . '/' . $rel] = $template;
        }

        return [
            'appRoot' => $jsRoot,
            'entry' => $manifest['entry'],
            'reactVersion' => $manifest['reactVersion'],
            'routes' => $manifest['routes'],
            'converted' => $manifest['converted'],
            'seen' => $manifest['seen'],
            'polyfillFile' => $dir . '/' . $manifest['polyfill'],
            'nativesFile' => $dir . '/' . $manifest['natives'],
            'templatesInline' => $absolute,
        ];
    }

    private function relativize(string $path): string
    {
        $root = rtrim($this->appRoot, '/') . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }
        // A stub shipped inside node-compat, outside the module root. Its
        // resolved path is not portable, so key it by basename under a reserved
        // prefix; the loader rebases it the same way and the loader is the only
        // thing that reads it.
        return '_stub/' . basename($path);
    }

    private function copy(string $from, string $to): int
    {
        $this->mkdir(dirname($to));
        if (!copy($from, $to)) {
            throw new \RuntimeException("Cannot copy $from to $to");
        }
        return (int)filesize($to);
    }

    /** @param array<mixed> $value */
    private function writeArray(string $path, array $value): int
    {
        $this->mkdir(dirname($path));
        file_put_contents(
            $path,
            "<?php\n\n// Generated by phpjs-ssg package. Do not edit.\n\nreturn "
                . var_export($value, true) . ";\n"
        );
        return (int)filesize($path);
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
    }

    private function directorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }
        return $total;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : sprintf('%d KB', (int)round($bytes / 1024));
    }
}

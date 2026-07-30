<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

use PhpJs\Node\NodeHost;
use PhpJs\Transpile\Assumptions;
use PhpJs\Transpile\NodeIntegration;

/**
 * The build step: everything that must not happen on a request.
 *
 * PHP is shared-nothing, so a server that boots this runtime per request pays
 * for every compile per request. What that costs is not marginal — parsing
 * React's server build is a few hundred milliseconds and the polyfill file is
 * another thirty. Both produce plain `var_export`-able arrays (DESIGN.md §11.1),
 * so this writes them to `<?php return [...];` files that opcache keeps in
 * shared memory, and a request instantiates objects instead of parsing
 * JavaScript.
 *
 * Two sets of module templates come out of it, not one. `aot` templates carry
 * the `nativeId`s the ahead-of-time compiler stamped on them; `bytecode`
 * templates carry none. That is what lets the demo serve the same page both ways
 * inside a single long-lived process: native functions are registered
 * process-wide and cannot be unregistered, but a template that was never
 * stamped will never reach them.
 */
final class Builder
{
    public const MANIFEST = 'manifest.php';

    /** Compile React ahead of time only where it is worth it: React itself. */
    private const AOT_PATHS = '/node_modules/react';

    /**
     * @param string $appRoot   module root; holds bundle/ and node_modules/
     * @param string $buildDir  where the generated PHP goes
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $buildDir,
        private readonly string $entry = './bundle/entry.cjs',
    ) {
    }

    /**
     * @param null|callable(string): void $log
     * @return array<string, mixed> the manifest that was written
     */
    public function run(?callable $log = null): array
    {
        $log ??= static function (string $_): void {
        };
        if (!is_file($this->appRoot . '/bundle/entry.cjs')) {
            throw new \RuntimeException(
                "No bundle at {$this->appRoot}/bundle/entry.cjs — run `npm install && npm run build` first."
            );
        }
        if (!is_dir($this->buildDir) && !mkdir($this->buildDir, 0o777, true) && !is_dir($this->buildDir)) {
            throw new \RuntimeException("Cannot create {$this->buildDir}");
        }

        // The polyfill file is the same for every host, so it is compiled once
        // here and never again.
        $started = microtime(true);
        $polyfill = \PhpJs\Compiler\Compiler::compile(NodeHost::polyfillSource());
        $polyfillPath = $this->writeArray('polyfill.php', $polyfill);
        // Both build hosts below get it for free, as a request will.
        NodeHost::preloadPolyfillTemplate($polyfill);
        $log(sprintf(
            'polyfills           %6.0f ms -> %s (%s)',
            (microtime(true) - $started) * 1000,
            basename($polyfillPath),
            self::size($polyfillPath)
        ));

        // Pass 1: ahead-of-time compiled. The hook stamps native IDs onto the
        // templates as they are compiled, so the templates and the generated
        // PHP come out of the same pass and cannot disagree.
        $started = microtime(true);
        $host = $this->freshHost();
        $aot = NodeIntegration::forBuild(
            static fn (string $path): bool => str_contains($path, self::AOT_PATHS),
            Assumptions::closedBuild()
        );
        $aot->attach($host);
        $entry = $host->requireModule($this->entry);
        $vm = $host->vm();
        $reactVersion = \PhpJs\Runtime\Conversions::toString($vm, $entry->get('reactVersion', $vm));
        $routes = $this->routesOf($host, $entry);
        $aotTemplates = $this->writeArray('templates.aot.php', $host->modules->compiledTemplates());
        $natives = $aot->writePhp($this->buildDir . '/natives.php');
        $log(sprintf(
            'ahead-of-time PHP   %6.0f ms -> %s (%s), %d / %d functions',
            (microtime(true) - $started) * 1000,
            basename($natives),
            self::size($natives),
            $aot->totalConverted(),
            $aot->totalSeen()
        ));
        $log(sprintf(
            'templates (aot)              -> %s (%s), %d modules',
            basename($aotTemplates),
            self::size($aotTemplates),
            count($host->modules->compiledTemplates())
        ));

        // Pass 2: no hook, so nothing is stamped and these templates can only
        // ever run as bytecode. A separate host, because a template is compiled
        // once per loader and the first pass already cached the stamped ones.
        $started = microtime(true);
        $plainHost = $this->freshHost();
        $plainHost->requireModule($this->entry);
        $plainTemplates = $this->writeArray('templates.bytecode.php', $plainHost->modules->compiledTemplates());
        $log(sprintf(
            'templates (bytecode)%6.0f ms -> %s (%s)',
            (microtime(true) - $started) * 1000,
            basename($plainTemplates),
            self::size($plainTemplates)
        ));

        $manifest = [
            'builtAt' => date('c'),
            'appRoot' => $this->appRoot,
            'entry' => $this->entry,
            'reactVersion' => $reactVersion,
            'routes' => $routes,
            'polyfill' => basename($polyfillPath),
            'converted' => $aot->totalConverted(),
            'seen' => $aot->totalSeen(),
            'refusals' => $aot->refusalSummary(),
            'engines' => [
                'aot' => ['templates' => basename($aotTemplates), 'natives' => basename($natives)],
                'bytecode' => ['templates' => basename($plainTemplates)],
            ],
        ];
        $this->writeArray(self::MANIFEST, $manifest);
        $log(sprintf('routes                       -> %d pages, React %s', count($routes), $reactVersion));
        return $manifest;
    }

    private function freshHost(): NodeHost
    {
        return new NodeHost($this->appRoot, captureOutput: true);
    }

    /** @return list<string> */
    private function routesOf(NodeHost $host, mixed $entry): array
    {
        $vm = $host->vm();
        $json = \PhpJs\Runtime\Conversions::toString(
            $vm,
            $host->call($entry->get('routeManifest', $vm))
        );
        $routes = json_decode($json, true);
        if (!is_array($routes) || $routes === []) {
            throw new \RuntimeException('The bundle returned no routes');
        }
        return array_values(array_map('strval', $routes));
    }

    /** @param array<mixed> $value */
    private function writeArray(string $name, array $value): string
    {
        $path = $this->buildDir . '/' . $name;
        // Plain arrays only, which is the whole reason opcache can hold this.
        file_put_contents($path, "<?php\n\n// Generated by phpjs-ssg build. Do not edit.\n\nreturn "
            . var_export($value, true) . ";\n");
        return $path;
    }

    private static function size(string $path): string
    {
        $bytes = (int)filesize($path);
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int)round($bytes / 1024));
    }
}

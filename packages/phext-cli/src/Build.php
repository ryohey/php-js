<?php

declare(strict_types=1);

namespace PhpJs\PhextCli;

use PhpJs\Aot\LibraryCompiler;
use PhpJs\Engine;
use PhpJs\Phext\App;

/**
 * `phext build`: compile everything ahead of a request, so that serving one
 * parses no JavaScript.
 *
 * PHP is shared-nothing. Whatever is not compiled before a request is
 * compiled *during* it, and then thrown away — parsing React's server build
 * alone is a few hundred milliseconds, per request, forever. This writes all
 * of it to disk in the format the engine reads back through opcache
 * (`PhpJs\Cache\ArtifactCache`), where the second and later requests get it
 * for free.
 *
 * Two different things get compiled, and the difference is a trust boundary
 * rather than an optimization:
 *
 * - **Dependencies** are compiled to native PHP as well as bytecode
 *   (`packages/aot`). Generated PHP leaves the VM behind, and with it the
 *   wall-clock limit and the recursion guard (docs/aot-php.md) — fine for a
 *   version a lockfile pins and that you upgrade deliberately.
 * - **The app's own pages** are compiled to bytecode only. Bytecode is data:
 *   loading it defines nothing and calls nothing, and the VM interprets it
 *   with every guard in place. Code you are editing is exactly the code that
 *   needs those guards, so pages never become PHP.
 *
 * That this package depends on `packages/aot` and `phext` does not is the
 * same boundary again: a deployment that only renders needs the runtime, not
 * the compiler that produced its cache.
 */
final class Build
{
    /**
     * What phext itself needs compiled, being a React framework.
     *
     * The deep `react-dom` path rather than `react-dom/server`, for the
     * reason `Phext\Renderer` documents: the package entry also pulls in the
     * streaming renderer, which needs Web APIs this runtime does not have.
     *
     * `react/jsx-runtime` is here because every page compiles into calls to
     * it (the automatic JSX transform), and a module the *app* pulls in but
     * no library entry names would otherwise be compiled on the first request
     * and never cached — the app step below deliberately refuses to write
     * anything under `node_modules`.
     */
    public const FRAMEWORK_LIBRARIES = [
        'react',
        'react/jsx-runtime',
        'react-dom/cjs/react-dom-server-legacy.node.production.js',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param  null|callable(string): void $log
     * @return array{libraries: int, converted: int, seen: int, pages: int, modules: list<string>, refusals: list<array{reason: string, count: int}>}
     */
    public function run(?callable $log = null): array
    {
        $log ??= static function (string $_): void {
        };
        $root = $this->app->root();
        $cacheDir = $this->app->buildCacheDir();

        $started = microtime(true);
        Engine::cacheEcmaScriptLibrary($cacheDir);
        $log(sprintf('%-22s %6.0f ms', 'standard library', (microtime(true) - $started) * 1000));

        $libraries = $this->libraries();
        $started = microtime(true);
        $aot = (new LibraryCompiler())->compile($root, $libraries, $cacheDir);
        $log(sprintf(
            '%-22s %6.0f ms  %d / %d functions to PHP, %d modules',
            'dependencies',
            (microtime(true) - $started) * 1000,
            $aot['converted'],
            $aot['seen'],
            $aot['files'],
        ));

        // Rendering every page is how its modules get compiled, and also the
        // only way to find out that they *can* be rendered -- a build that
        // succeeds is a build whose every page is known to work.
        $started = microtime(true);
        $pages = 0;
        foreach ($this->app->paths() as $path) {
            $this->app->renderUncached($path);
            $pages++;
        }
        // ...and then the compiled bytecode is written where the next process
        // will find it. `node_modules` is excluded because `LibraryCompiler`
        // has already written *native* artifacts for those, and these would
        // overwrite them with natives-free ones -- silently undoing the
        // ahead-of-time compilation a moment after doing it.
        $modules = $this->app->host()->modules->cacheCompiledTemplates(
            $cacheDir,
            static fn (string $path): bool => !str_contains($path, '/node_modules/'),
        );
        $log(sprintf(
            '%-22s %6.0f ms  %d pages, %d modules',
            'app',
            (microtime(true) - $started) * 1000,
            $pages,
            count($modules),
        ));

        return [
            'libraries' => count($libraries),
            'converted' => $aot['converted'],
            'seen' => $aot['seen'],
            'pages' => $pages,
            'modules' => array_keys($modules),
            'refusals' => $aot['refusals'],
        ];
    }

    /**
     * The dependencies to compile: phext's own, plus whatever the project
     * adds under `"phext": { "aot": [...] }` in its package.json.
     *
     * A specifier is resolved exactly as `require()` would resolve it, so
     * what goes in the list is whatever the code that loads it would write.
     *
     * @return list<string>
     */
    public function libraries(): array
    {
        $extra = [];
        $file = $this->app->root() . '/package.json';
        if (is_file($file)) {
            $json = json_decode((string)file_get_contents($file), true);
            $configured = $json['phext']['aot'] ?? [];
            if (is_array($configured)) {
                $extra = array_values(array_filter($configured, 'is_string'));
            }
        }
        return array_values(array_unique([...self::FRAMEWORK_LIBRARIES, ...$extra]));
    }
}

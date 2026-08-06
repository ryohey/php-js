<?php

declare(strict_types=1);

namespace PhpJs\StripTypes;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;

/**
 * Type erasure for TypeScript and JSX, the same shape as Node's own
 * `--experimental-strip-types`: no type *checking*, no bundling, one file in
 * and one file out, safe to run on any source regardless of what is actually
 * in it — plain JavaScript included, since there is nothing to strip and it
 * passes through close to unchanged.
 *
 * Runs [sucrase](https://github.com/alangpierce/sucrase) — itself just
 * JavaScript — inside php-js, vendored under `js/vendor/node_modules/` so
 * this package needs nothing from npm at install time; a project that adds
 * it gets TSX support with no separate build tool to install or configure.
 * `disableESTransforms` stays on: php-js parses `??`, `?.`, destructuring,
 * classes, `async`/`await` and the rest of what DESIGN.md §2.5 has landed,
 * so nothing sucrase would otherwise downlevel to ES5 needs downleveling
 * here.
 */
final class Stripper
{
    /** Extensions this class can meaningfully strip. */
    public const EXTENSIONS = ['ts', 'tsx', 'jsx'];

    /**
     * Which JSX transform to emit.
     *
     * `automatic` (React 17+, and what Next.js and every current toolchain
     * default to) compiles JSX to `react/jsx-runtime` calls the file did not
     * have to import, so a component file needs no `import React` at all.
     * `classic` compiles to `React.createElement` and does require that
     * import to be present.
     *
     * Automatic by default because the failure mode of the other one is a
     * confusing runtime `ReferenceError: React is not defined` in a file that
     * looks complete. A project whose React predates 17 can set this to
     * `'classic'` before the first strip.
     */
    public static string $jsxRuntime = 'automatic';

    /**
     * Everything about *this* stripper that changes what it produces from the
     * same input, for whoever is caching that output.
     *
     * The sucrase version belongs here because it is vendored and pinned:
     * bumping it is a deliberate edit to this repository, and one that can
     * change the emitted JavaScript. Anything else that becomes configurable
     * has to be added here at the same time, or a cache keyed on this will
     * keep serving output the new setting would not have produced.
     */
    public static function fingerprint(): string
    {
        return 'sucrase-3.35;jsx=' . self::$jsxRuntime;
    }

    private static ?NodeHost $host = null;
    private static mixed $transformFn = null;

    /**
     * @param string $filename only used to decide TS-vs-TSX-vs-plain-JS
     *                         parsing (by its extension) and to attribute
     *                         error messages; never read from disk
     */
    public static function strip(string $source, string $filename = 'module.tsx'): string
    {
        [$host, $transform] = self::engine();
        $realm = $host->realm();
        $vm = $host->vm();
        $opts = $realm->newObject();
        $opts->defineOwnData('transforms', $realm->newArray(['typescript', 'jsx', 'imports']));
        $opts->defineOwnData('jsxRuntime', self::$jsxRuntime);
        $opts->defineOwnData('production', true);
        $opts->defineOwnData('disableESTransforms', true);
        $opts->defineOwnData('filePath', $filename);
        $result = $host->call($transform, null, [$source, $opts]);
        return Conversions::toString($vm, $result->get('code', $vm));
    }

    /**
     * The engine that runs sucrase is built once per process and reused —
     * constructing a `NodeHost` and requiring sucrase costs tens of
     * milliseconds, and every call to `strip()` in one request or one build
     * step shares it rather than paying that again.
     *
     * @return array{0: NodeHost, 1: mixed}
     */
    private static function engine(): array
    {
        if (self::$host === null) {
            $host = new NodeHost(__DIR__ . '/../js/vendor');
            self::$transformFn = $host->requireModule('sucrase')->get('transform', $host->vm());
            self::$host = $host;
        }
        return [self::$host, self::$transformFn];
    }
}

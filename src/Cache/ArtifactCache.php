<?php

declare(strict_types=1);

namespace PhpJs\Cache;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Compiler\Compiler;

/**
 * Compiled JavaScript, on disk, addressed by the content it was compiled from
 * (DESIGN.md §11.1, §13).
 *
 * PHP is shared-nothing: a server that boots the runtime per request pays
 * every compile per request, and parsing something React-sized is hundreds of
 * milliseconds. A function template is a plain `var_export`-able array, so it
 * can be written to a `<?php return [...];` file that opcache keeps in shared
 * memory and handed straight back on the next request without the compiler
 * running at all. That is the whole idea, and it is the *compiler's* concern —
 * not a module system's, which is why this lives in core and not in whatever
 * happens to be calling `require()`.
 *
 * One file per compiled source, named `{contentHash}.php`, holding both halves
 * of a build:
 *
 * - `'template'` — the compiled bytecode. Plain data; loading it defines
 *   nothing and calls nothing, and the VM interprets it afterwards inside its
 *   sandbox. Caching this is exactly as safe as running the source was.
 * - `'natives'` — PHP implementations of some of that template's functions,
 *   keyed by the `functionId()` the template already stamped on them
 *   (`packages/php-transpile` generates these; docs/aot-php.md). Optional and
 *   independent: a template whose natives are missing runs entirely on
 *   bytecode, which is what makes it safe to ship them separately or not at
 *   all.
 *
 * Addressing by content hash is what makes invalidation correct without a
 * manifest to keep in sync: an edited or upgraded source simply hashes to a
 * name that is not there, and misses. A stale artifact is never *found*,
 * rather than found and detected.
 *
 * One constraint this does not enforce, and cannot: every artifact under one
 * directory must have been produced by the same compiler and, where natives
 * are involved, the same emitter assumptions. The hash covers the source, not
 * the toolchain. `packages/aot`'s CLI is the one thing that populates a shared
 * cache directory, and it holds that invariant by construction.
 */
final class ArtifactCache
{
    public static function contentHash(string $source): string
    {
        return hash('xxh128', $source);
    }

    /**
     * The native-ID format an artifact's `'natives'` are keyed by.
     *
     * Lives here because it names a *cache entry*, so a generator and a
     * reader that never meet still agree on it — the alternative is two
     * copies of a string format drifting apart silently.
     */
    public static function functionId(string $contentHash, int $counter, string $name): string
    {
        return sprintf(
            'aot:%s#%d%s',
            $contentHash,
            $counter,
            $name !== '' ? ':' . preg_replace('/[^A-Za-z0-9_$]/', '', $name) : ''
        );
    }

    /**
     * Compile `$source`, or hand back a cached artifact for it if `$cacheDir`
     * has one (registering its natives first, since a template that stamped a
     * `nativeId` cannot run without it).
     *
     * A miss — no directory, no matching file, an unreadable one — falls
     * through to an ordinary `Compiler::compile()`, exactly as if no cache
     * existed. That is the entire contract: a cache is a directory that
     * happens to have the right file in it.
     *
     * @param  ?callable $hook per-function compile hook, passed straight
     *                         through; only consulted on a miss
     * @return array<string, mixed> a function template
     */
    public static function compile(string $source, ?string $cacheDir, ?callable $hook = null): array
    {
        if ($cacheDir !== null) {
            $artifact = self::read($cacheDir, self::contentHash($source));
            if ($artifact !== null) {
                self::registerNatives($artifact['natives']);
                return $artifact['template'];
            }
        }
        return Compiler::compile($source, $hook);
    }

    /**
     * @param  array<string, mixed>    $template
     * @param  array<string, callable> $natives
     * @return string the file written
     */
    public static function write(string $cacheDir, string $contentHash, array $template, array $natives = []): string
    {
        return self::put($cacheDir, $contentHash, var_export($template, true), var_export($natives, true));
    }

    /**
     * The same, for a caller whose natives are already rendered as PHP source.
     *
     * `var_export()` cannot serialize a closure, and generated natives *are*
     * closures — `packages/php-transpile` prints them with an AST printer it
     * owns, which is a dependency core has no reason to carry just to run.
     *
     * @return string the file written
     */
    public static function writeRaw(string $cacheDir, string $contentHash, string $templatePhp, string $nativesPhp): string
    {
        return self::put($cacheDir, $contentHash, $templatePhp, $nativesPhp);
    }

    /** @return array{template: array<string, mixed>, natives: array<string, callable>}|null */
    public static function read(string $cacheDir, string $contentHash): ?array
    {
        return self::readFile(self::fileFor($cacheDir, $contentHash));
    }

    /**
     * The same, given a path — for a caller walking a directory rather than
     * asking about one known source.
     *
     * @return array{template: array<string, mixed>, natives: array<string, callable>}|null
     */
    public static function readFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $artifact = require $file;
        if (!is_array($artifact)
            || !isset($artifact['template']) || !is_array($artifact['template'])
            || !isset($artifact['natives']) || !is_array($artifact['natives'])) {
            return null;
        }
        return ['template' => $artifact['template'], 'natives' => $artifact['natives']];
    }

    public static function fileFor(string $cacheDir, string $contentHash): string
    {
        return rtrim($cacheDir, '/') . '/' . $contentHash . '.php';
    }

    /**
     * Make natives resolvable by ID.
     *
     * Registering the same artifact twice in one process is not an error —
     * IDs are content-derived, so a second copy is the same generated code
     * under the same name. Only the not-yet-seen ones are offered to
     * `BuiltinRegistry`, which would reject a genuine collision.
     *
     * @param  array<string, callable> $natives
     * @return int how many were newly registered
     */
    public static function registerNatives(array $natives): int
    {
        $fresh = [];
        foreach ($natives as $id => $fn) {
            if (!BuiltinRegistry::hasHost($id)) {
                $fresh[$id] = $fn;
            }
        }
        if ($fresh !== []) {
            BuiltinRegistry::registerHost($fresh);
        }
        return count($fresh);
    }

    /**
     * Register every native in a directory at once.
     *
     * `compile()` already does this lazily, one artifact at a time, as each
     * source is actually reached — but a template that arrives from somewhere
     * else entirely (a precompiled bundle, seeded straight into a module
     * loader) never passes through here, so a `nativeId` it was stamped with
     * would never resolve and would silently fall back to bytecode. Call this
     * first in that case.
     *
     * @return int how many were newly registered
     */
    public static function registerAllNatives(string $cacheDir): int
    {
        $registered = 0;
        foreach (glob(rtrim($cacheDir, '/') . '/*.php') ?: [] as $file) {
            $artifact = self::readFile($file);
            if ($artifact !== null) {
                $registered += self::registerNatives($artifact['natives']);
            }
        }
        return $registered;
    }

    private static function put(string $cacheDir, string $contentHash, string $templatePhp, string $nativesPhp): string
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException("Cannot create $cacheDir");
        }
        $file = self::fileFor($cacheDir, $contentHash);
        file_put_contents(
            $file,
            "<?php\n\n// Generated by php-js. Do not edit.\n\nreturn [\n"
                . "    'template' => " . $templatePhp . ",\n"
                . "    'natives' => " . $nativesPhp . ",\n"
                . "];\n"
        );
        return $file;
    }
}

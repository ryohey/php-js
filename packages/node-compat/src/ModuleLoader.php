<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Cache\ArtifactCache;
use PhpJs\Compiler\Compiler;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Vm\Vm;

/**
 * CommonJS module loading.
 *
 * Each module is compiled as `(function (exports, require, module, __filename,
 * __dirname) { ... })` — the same wrapper Node uses — so module scope falls out
 * of ordinary function scope with no engine support required. The `require`
 * handed to a module is a native function whose $data is just its directory
 * string, keeping the JS heap free of PHP objects (DESIGN.md §11.3).
 *
 * Ahead-of-time PHP (packages/php-transpile, docs/aot-php.md) is transparent
 * from here: nothing that merely `require`s a module needs to know it exists.
 * `$onCompileModule` remains the explicit extension point a *build* installs to
 * emit new natives, but the default path — no hook installed — still checks
 * `$aotCacheDir` for an already-compiled artifact matching this exact module's
 * content, `{contentHash}.php`, and uses it outright if present: the whole
 * compiled template, so `require()` skips `Compiler::compile()` entirely
 * rather than merely resolving natives inside it. Falls back to ordinary
 * bytecode when there is no artifact. That is the whole contract: an AOT
 * cache is a directory that happens to have the right files in it, and
 * `require` either finds a match or it does not. The format and the lookup
 * itself are the engine's (`PhpJs\Cache\ArtifactCache`) -- caching a
 * compile is the compiler's business, not a module system's; what belongs
 * here is only the decision of *when* to consult one.
 *
 * `$sourceTransforms` is the same idea one step earlier: given a filename
 * extension this loader would otherwise refuse to resolve at all, run the
 * module's raw text through a registered transform before it is ever wrapped
 * or handed to `Compiler::compile()`. `packages/strip-types` uses this to
 * make `require('./component')` find `component.tsx` and see plain
 * JavaScript by the time this class's own compiler ever looks at it — again
 * with no dependency in this direction; see `NodeHost`'s constructor for
 * where that registration actually happens.
 */
final class ModuleLoader
{
    /** @var array<string, mixed> resolved path => exports */
    private array $cache = [];
    /** @var array<string, JSObject> resolved path => module object (for cycles) */
    private array $loading = [];
    /** @var array<string, array<string, mixed>> resolved path => compiled template */
    private array $templates = [];
    /** @var array<string, string> resolved path => content hash, for modules this loader compiled */
    private array $contentHashes = [];
    /** @var array<string, mixed> built-in module name => exports */
    private array $builtins = [];

    public int $compileCount = 0;
    public float $compileSeconds = 0.0;
    /**
     * Optional per-module compile hook, passed straight to `Compiler::compile`
     * as its per-function callback. The ahead-of-time PHP compiler
     * (packages/php-transpile, docs/aot-php.md) installs one here to claim the
     * functions it can compile *and generate PHP for them*. Set only during a
     * build; leave it null everywhere else and `$aotCacheDir` (if any) drives
     * the same stamping instead, read-only.
     *
     * `fn(string $path): ?callable` — given a module path, return the hook for
     * that module, or null to compile it as ordinary bytecode.
     * @var null|callable(string): ?callable
     */
    public $onCompileModule = null;

    /**
     * Where to look for an already-compiled `{contentHash}.php` for a module
     * this loader is about to compile. Null disables the lookup entirely, at
     * zero cost — the exact behavior before this existed.
     */
    private ?string $aotCacheDir = null;

    /**
     * Extension (without the dot) => a source-to-source transform run on a
     * module's text before it is wrapped and compiled. `packages/strip-types`
     * registers `ts`/`tsx`/`jsx` here (via `NodeHost`'s own auto-detection,
     * not by this class knowing that package exists — see its docblock);
     * nothing else currently uses this, but it is deliberately not specific
     * to TypeScript. An extension registered here is also added to
     * `resolveAsFile()`'s probe list, so `require('./x')` finds `x.tsx` the
     * same way it already finds `x.js`.
     *
     * @var array<string, array{0: callable(string $source, string $path): string, 1: string}>
     */
    private array $sourceTransforms = [];

    public function __construct(
        private readonly NodeHost $host,
        private readonly string $root,
    ) {
    }

    /** @param ?string $dir a directory of `{contentHash}.php` files, or null to disable the lookup */
    public function setAotCacheDir(?string $dir): void
    {
        $this->aotCacheDir = $dir;
    }

    /**
     * @param string $extension without the leading dot, e.g. `'tsx'`
     * @param callable(string $source, string $path): string $transform
     * @param string $fingerprint anything that changes what `$transform`
     *        produces from the same input — its version, its settings. It
     *        goes into the cache key, so changing it invalidates exactly the
     *        artifacts it should. Leaving it empty is a claim that the
     *        transform's output depends on nothing but its input, and a
     *        *wrong* such claim is silent: a stale artifact keeps being
     *        served after the transform's behaviour changes.
     */
    public function registerSourceTransform(string $extension, callable $transform, string $fingerprint = ''): void
    {
        $this->sourceTransforms[$extension] = [$transform, $fingerprint];
    }

    public static function entries(): array
    {
        return [
            'node.require' => [self::class, 'requireNative'],
            'node.require.resolve' => [self::class, 'resolveNative'],
        ];
    }

    /**
     * Seed the compiled-template cache from a precompiled bundle.
     *
     * Compiling a dependency tree the size of React costs a few hundred
     * milliseconds, and PHP is shared-nothing: a server that boots the runtime
     * per request would pay it per request. Templates are plain
     * `var_export`-able arrays (DESIGN.md §11.1), so a build step can write
     * them to a `<?php return [...];` file that opcache keeps in shared memory,
     * and this is where that file goes back in.
     *
     * Keys are resolved absolute paths. A template already carries whatever
     * `onCompileModule` stamped on it when it was built — including
     * ahead-of-time `nativeId`s — so a preloaded module never reaches the
     * compiler and never re-runs the hook.
     *
     * @param array<string, array<string, mixed>> $templates path => template
     */
    public function preloadTemplates(array $templates): void
    {
        foreach ($templates as $path => $template) {
            $this->templates[$path] = $template;
        }
    }

    /**
     * The templates compiled or preloaded so far, for writing a bundle.
     *
     * @return array<string, array<string, mixed>> path => template
     */
    public function compiledTemplates(): array
    {
        return $this->templates;
    }

    /** Register a synthetic module, e.g. `stream` or a stub for `util`. */
    public function define(string $name, mixed $exports): void
    {
        $this->builtins[$name] = $exports;
    }

    /**
     * Core-module stubs shipped with this package, loaded on first require.
     * They exist because bundles pull them in at load time even when the code
     * path that uses them is never taken.
     */
    public const STUBS = ['stream', 'util', 'events', 'crypto', 'async_hooks'];

    /** Where a shipped stub's source lives, whether or not it exists. */
    public static function stubPath(string $name): string
    {
        return __DIR__ . '/../js/stubs/' . $name . '.js';
    }

    private function loadStub(string $name, Vm $vm): mixed
    {
        $file = self::stubPath($name);
        if (!is_file($file)) {
            return null;
        }
        $realm = $this->host->realm();
        $exports = $realm->newObject();
        $module = $realm->newObject();
        $module->defineOwnData('exports', $exports);
        // Through the same template cache as a real module, so a stub is
        // compiled once per process and can be preloaded from a bundle. It
        // cannot go through the sandboxed filesystem, though -- these files
        // ship with the package and live outside the module root.
        $fn = $vm->runProgram($this->templateFor($file, (string)file_get_contents($file)));
        $vm->invoke($fn, JSUndefined::$undefined, [
            $exports,
            $this->makeRequire($this->root),
            $module,
            $file,
            \dirname($file),
        ]);
        return $this->builtins[$name] = $module->get('exports', $vm);
    }

    public function makeRequire(string $dir): JSNativeFunction
    {
        $require = $this->host->realm()->nativeFn('node.require', 'require', 1, null, $dir);
        $require->defineOwnData(
            'resolve',
            $this->host->realm()->nativeFn('node.require.resolve', 'resolve', 1, null, $dir),
            JSObject::W | JSObject::C
        );
        $require->defineOwnData('cache', $this->host->realm()->newObject(), JSObject::W | JSObject::C);
        return $require;
    }

    public static function requireNative(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $host = NodeHost::of($vm);
        $specifier = Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined);
        return $host->modules->load($specifier, (string)$fn->data, $vm);
    }

    public static function resolveNative(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $host = NodeHost::of($vm);
        $specifier = Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined);
        $path = $host->modules->resolve($specifier, (string)$fn->data);
        if ($path === null) {
            $vm->throwError('Error', "Cannot find module '$specifier'");
        }
        return $path;
    }

    public function load(string $specifier, string $fromDir, ?Vm $vm = null): mixed
    {
        $vm ??= $this->host->vm();
        if (isset($this->builtins[$specifier])) {
            return $this->builtins[$specifier];
        }
        if (in_array($specifier, self::STUBS, true)) {
            $stub = $this->loadStub($specifier, $vm);
            if ($stub !== null) {
                return $stub;
            }
        }
        $path = $this->resolve($specifier, $fromDir);
        if ($path === null) {
            $vm->throwError('Error', "Cannot find module '$specifier' from '$fromDir'");
        }
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }
        if (isset($this->loading[$path])) {
            // Cyclic require: hand back the partially populated exports, which
            // is what Node does.
            return $this->loading[$path]->get('exports', $vm);
        }
        return $this->evaluateModule($path, $vm);
    }

    private function evaluateModule(string $path, Vm $vm): mixed
    {
        $realm = $this->host->realm();
        $exports = $realm->newObject();
        $module = $realm->newObject();
        $module->defineOwnData('exports', $exports);
        $module->defineOwnData('id', $path);
        $module->defineOwnData('filename', $path);
        $module->defineOwnData('loaded', false);
        $this->loading[$path] = $module;

        try {
            if (str_ends_with($path, '.json')) {
                $json = $realm->globalObject->get('JSON', $vm);
                $parse = $json->get('parse', $vm);
                $result = $vm->invoke($parse, $json, [$this->host->fs->read($path)]);
                $module->set('exports', $result, $vm, false);
            } else {
                $fn = $vm->runProgram($this->templateFor($path));
                $dir = \dirname($path);
                $vm->invoke($fn, JSUndefined::$undefined, [
                    $exports,
                    $this->makeRequire($dir),
                    $module,
                    $path,
                    $dir,
                ]);
            }
        } finally {
            unset($this->loading[$path]);
        }

        $module->set('loaded', true, $vm, false);
        $result = $module->get('exports', $vm);
        $this->cache[$path] = $result;
        return $result;
    }

    /**
     * @param ?string $source the module text, when it does not come from the
     *                        sandboxed filesystem (a shipped stub)
     * @return array<string, mixed>
     */
    private function templateFor(string $path, ?string $source = null): array
    {
        if (isset($this->templates[$path])) {
            return $this->templates[$path];
        }
        $source ??= $this->host->fs->read($path);
        [$transform, $fingerprint] = $this->sourceTransforms[pathinfo($path, PATHINFO_EXTENSION)] ?? [null, ''];

        // Keyed on the file as it is on disk, plus whatever fingerprint the
        // transform declared -- never on the transform's *output*, which
        // would have to be produced before the cache could be consulted. For
        // TypeScript that means booting a whole second engine to strip a file
        // whose compiled form is already sitting on disk, which costs about
        // two seconds and defeats the entire point of having cached it.
        //
        // Hashing the file rather than the CommonJS wrapper around it is what
        // keeps this in agreement with `php-transpile`, which hashes the file
        // it read; a mismatch there is silent, and every lookup would miss.
        $cacheKey = ArtifactCache::contentHash(
            $fingerprint === '' ? $source : $source . "\0" . $fingerprint
        );

        // A build hook takes precedence: it is generating natives, so it has
        // to see every function even if an artifact for this module exists.
        if ($this->onCompileModule === null && $this->aotCacheDir !== null) {
            $cached = ArtifactCache::read($this->aotCacheDir, $cacheKey);
            if ($cached !== null) {
                ArtifactCache::registerNatives($cached['natives']);
                // Deliberately not counted as a compile: `compileCount` is how
                // a host asserts that a warm build parses no JavaScript.
                return $this->templates[$path] = $cached['template'];
            }
        }

        if ($transform !== null) {
            $source = $transform($source, $path);
        }

        $started = microtime(true);
        $template = Compiler::compile(self::wrapAsModule($source), $this->onCompileModule !== null ? ($this->onCompileModule)($path) : null);
        $this->compileSeconds += microtime(true) - $started;
        $this->compileCount++;
        // Remembered so a build can write this module's artifact under the
        // same key a later `require()` will look it up by.
        $this->contentHashes[$path] = $cacheKey;
        return $this->templates[$path] = $template;
    }

    /**
     * Write the modules compiled so far into a cache directory, so a later
     * process finds them instead of compiling them again.
     *
     * The counterpart of the lookup in `templateFor()`, and the reason a
     * build step can make a request compile nothing at all. Only modules this
     * loader actually compiled are written — one it read back from the cache
     * is already there, and one that arrived through `preloadTemplates()` was
     * never hashed.
     *
     * Artifacts are written with no natives. That is not a limitation to be
     * fixed later: generating natives means generating PHP, which is
     * `packages/php-transpile`'s job and carries a trust boundary
     * (docs/aot-php.md) this class has no business crossing on its own. What
     * this caches is bytecode, which is data.
     *
     * @param  null|callable(string): bool $accept decides per module path;
     *         the default writes everything. A build that has already
     *         produced *native* artifacts for its dependencies must exclude
     *         them here, or this would overwrite those with natives-free
     *         ones and silently undo the compilation.
     * @return array<string, string> module path => the file written
     */
    public function cacheCompiledTemplates(string $cacheDir, ?callable $accept = null): array
    {
        $written = [];
        foreach ($this->contentHashes as $path => $hash) {
            if ($accept !== null && !$accept($path)) {
                continue;
            }
            $written[$path] = ArtifactCache::write($cacheDir, $hash, $this->templates[$path]);
        }
        return $written;
    }

    /**
     * The CommonJS wrapper every module is compiled inside:
     * `(function (exports, require, module, __filename, __dirname) { ... })`
     * — the same shape Node uses, so module scope falls out of ordinary
     * function scope with no engine support required. Exposed (not just
     * inlined above) because `php-transpile` compiles modules for this loader
     * and has to wrap them identically -- a second copy of this string would
     * silently drift the two apart instead of failing loudly.
     */
    public static function wrapAsModule(string $source): string
    {
        // A trailing newline before the closing brace guards against a source
        // that ends inside a line comment.
        return "(function (exports, require, module, __filename, __dirname) {" . $source . "\n})";
    }

    /** Node's resolution algorithm, minus conditional exports and ESM. */
    public function resolve(string $specifier, string $fromDir): ?string
    {
        if (str_starts_with($specifier, '/')) {
            return $this->resolveAsFileOrDirectory($specifier);
        }
        if (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            return $this->resolveAsFileOrDirectory($fromDir . '/' . $specifier);
        }
        // Bare specifier: walk node_modules up to the root.
        $dir = $fromDir;
        for (;;) {
            $found = $this->resolveAsFileOrDirectory($dir . '/node_modules/' . $specifier);
            if ($found !== null) {
                return $found;
            }
            $parent = \dirname($dir);
            if ($parent === $dir || !str_starts_with($dir, $this->root)) {
                return null;
            }
            $dir = $parent;
        }
    }

    private function resolveAsFileOrDirectory(string $path): ?string
    {
        $file = $this->resolveAsFile($path);
        if ($file !== null) {
            return $file;
        }
        $pkg = $path . '/package.json';
        if ($this->host->fs->isFile($pkg)) {
            $main = $this->mainFrom($pkg);
            if ($main !== null) {
                $resolved = $this->resolveAsFile($path . '/' . $main)
                    ?? $this->resolveAsFile($path . '/' . $main . '/index');
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
        return $this->resolveAsFile($path . '/index');
    }

    private function resolveAsFile(string $path): ?string
    {
        foreach ($this->probeExtensions() as $ext) {
            $candidate = $path . $ext;
            if ($this->host->fs->isFile($candidate)) {
                return $this->host->fs->realpath($candidate);
            }
        }
        return null;
    }

    /** @return list<string> extensions (with a leading dot, `''` for an already-complete specifier) to try resolving, in order */
    private function probeExtensions(): array
    {
        $extensions = ['', '.js', '.json'];
        foreach (array_keys($this->sourceTransforms) as $extension) {
            $extensions[] = ".$extension";
        }
        return $extensions;
    }

    private function mainFrom(string $packageJson): ?string
    {
        $data = json_decode($this->host->fs->read($packageJson), true);
        if (!is_array($data)) {
            return null;
        }
        $main = $data['main'] ?? null;
        return is_string($main) && $main !== '' ? $main : null;
    }
}

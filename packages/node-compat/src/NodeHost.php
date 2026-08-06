<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Engine;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * A Node-shaped host for the php-js engine: CommonJS `require`, `process`,
 * timers and a read-only `fs`.
 *
 * None of this is part of the engine. The core implements ECMAScript; module
 * resolution and host I/O are policy, so they live here and are wired in
 * through the public extension points (BuiltinRegistry::registerHost and
 * Realm::$hostContext). Nothing reachable from the JS heap holds a PHP object
 * from this package — native functions carry only strings in their $data, and
 * the host handle hangs off the realm, which the heap never points at.
 */
final class NodeHost
{
    public readonly Engine $engine;
    public readonly ModuleLoader $modules;
    public readonly TimerQueue $timers;
    public readonly FileSystem $fs;
    /** Canonical form of $root: resolution compares paths by prefix. */
    public readonly string $root;

    /** @var array<string, string> */
    private array $env = ['NODE_ENV' => 'production'];
    /** @var list<string> */
    private array $argv;
    private string $output = '';

    /**
     * Where an AOT cache lives by convention, relative to a module root — a
     * dot-prefixed directory under `node_modules`, the same idea as
     * `node_modules/.bin` or `node_modules/.cache`: tooling's own, not a
     * package, invisible to ordinary `require` resolution. `phpjs-aot`
     * writes here; the constructor below reads it if it happens to exist.
     */
    public const AOT_CACHE_SUBDIR = 'node_modules/.phpjs-aot';

    /**
     * @param string  $root          module resolution root; also the fs sandbox root
     * @param bool    $captureOutput keep console/stdout text instead of printing
     * @param ?string $aotCacheDir   an AOT cache directory to consult on every
     *                               module compile, or null to auto-detect
     *                               `$root/`.AOT_CACHE_SUBDIR (used only if it
     *                               exists), or false to disable the lookup
     *                               entirely even if that directory is there
     * @param bool    $stripTypes    whether to auto-detect `packages/strip-types`
     *                               (`PhpJs\StripTypes\Stripper`) and, if it is
     *                               installed, register it for `ts`/`tsx`/`jsx`
     *                               so `require()` resolves and strips those
     *                               extensions transparently. False disables
     *                               this even when the package is present.
     */
    public function __construct(
        string $root,
        private readonly bool $captureOutput = false,
        string|false|null $aotCacheDir = null,
        bool $stripTypes = true,
    ) {
        $canonical = realpath($root);
        if ($canonical === false) {
            throw new \InvalidArgumentException("Module root does not exist: $root");
        }
        $this->root = $canonical;
        self::registerNatives();

        $this->engine = new Engine(function (string $s): void {
            if ($this->captureOutput) {
                $this->output .= $s;
            } else {
                fwrite(STDOUT, $s);
            }
        });
        $this->argv = ['phpjs', $this->root];
        $this->fs = new FileSystem($this->root);
        $this->modules = new ModuleLoader($this, $this->root);
        $this->timers = new TimerQueue();
        $resolvedAotCacheDir = match (true) {
            $aotCacheDir === false => null,   // explicitly disabled
            $aotCacheDir !== null => $aotCacheDir,
            is_dir($this->root . '/' . self::AOT_CACHE_SUBDIR) => $this->root . '/' . self::AOT_CACHE_SUBDIR,
            default => null,
        };
        if ($resolvedAotCacheDir !== null) {
            $this->modules->setAotCacheDir($resolvedAotCacheDir);
            // Same directory, same content-hash convention, one more file
            // it might hold: the polyfill's own compiled template. See
            // installGlobals()/warmPolyfillTemplate() below -- this is what
            // lets the *first* host in a fresh process skip compiling
            // polyfills.js too, not just the modules a build named.
            self::warmPolyfillTemplate($resolvedAotCacheDir);
        }
        // Soft dependency, the same shape as the AOT cache above: this
        // package never requires packages/strip-types, so a project that
        // never installed it pays nothing and behaves exactly as before.
        // One that did gets `.ts`/`.tsx`/`.jsx` support with no call of its
        // own to make -- `node --experimental-strip-types`'s own shape.
        if ($stripTypes && class_exists(\PhpJs\StripTypes\Stripper::class)) {
            foreach (\PhpJs\StripTypes\Stripper::EXTENSIONS as $extension) {
                $this->modules->registerSourceTransform($extension, [\PhpJs\StripTypes\Stripper::class, 'strip']);
            }
        }

        $this->engine->realm->hostContext = $this;
        $this->installGlobals();
    }

    public static function registerNatives(): void
    {
        if (BuiltinRegistry::hasHost('node.require')) {
            return;
        }
        BuiltinRegistry::registerHost(array_merge(
            ModuleLoader::entries(),
            ProcessBuiltins::entries(),
            FileSystem::entries(),
            TimerQueue::entries(),
            MathExtras::entries(),
            CollectionBuiltins::entries(),
        ));
    }

    /** The host owning a realm, for use from a native function. */
    public static function of(Vm $vm): self
    {
        $host = $vm->realm->hostContext;
        if (!$host instanceof self) {
            throw new \LogicException('This realm is not owned by a NodeHost');
        }
        return $host;
    }

    public function vm(): Vm
    {
        return $this->engine->vm;
    }

    public function realm(): Realm
    {
        return $this->engine->realm;
    }

    /** @param array<string, string> $env */
    public function setEnv(array $env): void
    {
        $this->env = $env;
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return $this->env;
    }

    /** @return list<string> */
    public function argv(): array
    {
        return $this->argv;
    }

    public function write(string $s): void
    {
        if ($this->captureOutput) {
            $this->output .= $s;
        } else {
            fwrite(STDOUT, $s);
        }
    }

    public function takeOutput(): string
    {
        $out = $this->output;
        $this->output = '';
        return $out;
    }

    private function installGlobals(): void
    {
        $realm = $this->engine->realm;
        $global = $realm->globalObject;
        $flags = JSObject::W | JSObject::C;

        // Node exposes the global object under these names; some bundles sniff
        // for them to decide which environment they are in.
        $global->defineOwnData('global', $global, $flags);
        $global->defineOwnData('globalThis', $global, $flags);
        $global->defineOwnData('process', ProcessBuiltins::makeObject($realm), $flags);
        $global->defineOwnData('require', $this->modules->makeRequire($this->root), $flags);

        foreach (TimerQueue::globals($realm) as $name => $fn) {
            $global->defineOwnData($name, $fn, $flags);
        }

        // Natives first: the polyfill file only defines what is missing, so
        // installing here makes the JS versions a fallback rather than a
        // competitor. Math.clz32 alone is 20% of a React 19 render when it is
        // interpreted (docs/aot-php.md §2).
        MathExtras::install($realm, $this->engine->vm);
        CollectionBuiltins::install($realm);

        // Compiling this file is ~30 ms and it is the same file every time, so
        // it is worth not doing per host: cached for the process
        // ($polyfillTemplate), and warmPolyfillTemplate() above may already
        // have filled that cache from an AOT cache directory, in which case
        // this never runs at all.
        self::$polyfillTemplate ??= \PhpJs\Compiler\Compiler::compile(ModuleLoader::wrapAsModule(self::polyfillSource()));
        $vm = $this->engine->vm;
        $vm->invoke($vm->runProgram(self::$polyfillTemplate), JSUndefined::$undefined, []);
    }

    /** @var array<string, mixed>|null process-level cache of the polyfill template */
    private static ?array $polyfillTemplate = null;

    /** The ES2015+ library polyfills this package installs, as source. */
    public static function polyfillSource(): string
    {
        return (string)file_get_contents(__DIR__ . '/../js/polyfills.js');
    }

    /**
     * Fill the process-level polyfill-template cache from an AOT cache
     * directory, if it happens to hold an artifact for polyfills.js's own
     * content hash (`writePolyfillArtifact()`, the write side) — the same
     * directory and `{contentHash}.php` shape ordinary modules are already
     * looked up in (`ModuleLoader::aotArtifactTemplate()`), so nothing here
     * is a second caching concept.
     *
     * The constructor already calls this with whatever directory it resolved
     * for ordinary module lookups. The one reason to call it directly is a
     * host built with `aotCacheDir: false` (disabling that lookup, because it
     * only ever runs preloaded templates and has no use for it) that still
     * wants the polyfill cached — polyfills.js is never covered by
     * `preloadTemplates()`, so this is its only route to skipping a
     * recompile, and it is independent of that per-instance setting on
     * purpose. Safe to call with a directory that has no such artifact, or
     * more than once — a hit only ever fills the cache, never clears it.
     */
    public static function warmPolyfillTemplate(string $cacheDir): void
    {
        if (self::$polyfillTemplate !== null) {
            return;
        }
        $file = rtrim($cacheDir, '/') . '/' . hash('xxh128', self::polyfillSource()) . '.php';
        if (!is_file($file)) {
            return;
        }
        $artifact = require $file;
        if (is_array($artifact) && isset($artifact['template']) && is_array($artifact['template'])) {
            self::$polyfillTemplate = $artifact['template'];
        }
    }

    /**
     * Write the polyfill's own compiled template into an AOT cache directory
     * under its content hash, so `warmPolyfillTemplate()` (called by every
     * `NodeHost` construction that resolves that same directory) finds it
     * instead of compiling polyfills.js from scratch. Nothing about this
     * file converts to native PHP — `MathExtras`/`CollectionBuiltins` already
     * cover the parts worth making native; this is a bytecode fallback
     * only — so the artifact's `'natives'` half is always empty.
     *
     * @return string the file written
     */
    public static function writePolyfillArtifact(string $cacheDir): string
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException("Cannot create $cacheDir");
        }
        $source = self::polyfillSource();
        $template = self::$polyfillTemplate ??= \PhpJs\Compiler\Compiler::compile(ModuleLoader::wrapAsModule($source));
        $file = rtrim($cacheDir, '/') . '/' . hash('xxh128', $source) . '.php';
        file_put_contents($file, "<?php\n\n// Generated by NodeHost::writePolyfillArtifact(). Do not edit.\n\nreturn [\n"
            . "    'template' => " . var_export($template, true) . ",\n"
            . "    'natives' => [],\n"
            . "];\n");
        return $file;
    }

    /**
     * Register every native an AOT cache directory holds, all at once.
     *
     * `ModuleLoader::aotArtifactTemplate()` already does this lazily, one
     * artifact at a time, as each module is actually required — but a
     * template that arrives preloaded (`ModuleLoader::preloadTemplates()`)
     * skips that lookup entirely, so a native ID a preloaded template was
     * stamped with would otherwise never get registered and would silently
     * fall back to bytecode. Call this first, before preloading AOT-stamped
     * templates, and the stamps resolve correctly regardless of which host
     * originally compiled them.
     *
     * Safe to call more than once, from more than one process's build
     * artifacts sharing one `BuiltinRegistry` — already-registered IDs are
     * left alone the same way `Artifact::register()` treats them.
     *
     * @return int natives newly registered (0 if the directory has none, or does not exist)
     */
    public static function registerAotCacheDir(string $dir): int
    {
        $registered = 0;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $artifact = require $file;
            if (!is_array($artifact) || !isset($artifact['natives']) || !is_array($artifact['natives'])) {
                continue;
            }
            $fresh = [];
            foreach ($artifact['natives'] as $id => $fn) {
                if (!BuiltinRegistry::hasHost($id)) {
                    $fresh[$id] = $fn;
                }
            }
            if ($fresh !== []) {
                BuiltinRegistry::registerHost($fresh);
                $registered += count($fresh);
            }
        }
        return $registered;
    }

    /** Load a CommonJS module and return its `exports`. */
    public function requireModule(string $specifier): mixed
    {
        try {
            $exports = $this->modules->load($specifier, $this->root);
            $this->drain();
            return $exports;
        } catch (\PhpJs\Runtime\JSThrowSignal $e) {
            throw \PhpJs\JSException::from($this->engine->vm, $e->value);
        }
    }

    /** Call a JS function value, draining microtasks and timers afterwards. */
    public function call(mixed $fn, mixed $thisVal = null, array $args = []): mixed
    {
        try {
            $result = $this->engine->vm->invoke($fn, $thisVal ?? JSUndefined::$undefined, $args);
            $this->drain();
            return $result;
        } catch (\PhpJs\Runtime\JSThrowSignal $e) {
            throw \PhpJs\JSException::from($this->engine->vm, $e->value);
        }
    }

    /** Run microtasks, then due timers, until both are exhausted. */
    public function drain(): void
    {
        $realm = $this->engine->realm;
        $vm = $this->engine->vm;
        do {
            $realm->drainMicrotasks($vm);
        } while ($this->timers->runDue($vm) && true);
    }
}

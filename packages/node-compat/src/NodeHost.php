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
        if ($aotCacheDir === false) {
            // Explicitly disabled -- leave ModuleLoader's default (no lookup).
        } elseif ($aotCacheDir !== null) {
            $this->modules->setAotCacheDir($aotCacheDir);
        } elseif (is_dir($this->root . '/' . self::AOT_CACHE_SUBDIR)) {
            $this->modules->setAotCacheDir($this->root . '/' . self::AOT_CACHE_SUBDIR);
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
        // it is worth not doing per host: cached for the process, and a build
        // step can hand over a precompiled template so it is never compiled at
        // all (see preloadPolyfillTemplate).
        self::$polyfillTemplate ??= \PhpJs\Compiler\Compiler::compile(self::polyfillSource());
        $this->engine->runTemplate(self::$polyfillTemplate);
    }

    /** @var array<string, mixed>|null process-level cache of the polyfill template */
    private static ?array $polyfillTemplate = null;

    /** The ES2015+ library polyfills this package installs, as source. */
    public static function polyfillSource(): string
    {
        return (string)file_get_contents(__DIR__ . '/../js/polyfills.js');
    }

    /**
     * Supply a precompiled polyfill template, so constructing a host compiles
     * no JavaScript at all.
     *
     * PHP is shared-nothing, so a server that builds a host per request pays
     * every compile per request. Templates are plain `var_export`-able arrays
     * (DESIGN.md §11.1), so a build step can write this one to a file that
     * opcache keeps in shared memory and pass it back in here. Pass null to
     * drop the cache and go back to compiling on first use.
     *
     * @param array<string, mixed>|null $template
     */
    public static function preloadPolyfillTemplate(?array $template): void
    {
        self::$polyfillTemplate = $template;
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

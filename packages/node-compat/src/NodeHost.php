<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Cache\ArtifactCache;
use PhpJs\Engine;
use PhpJs\Host\Environment;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Node, as an environment the engine can be given: CommonJS `require`,
 * `process`, timers and a read-only `fs`.
 *
 * None of this is the engine's. The engine implements ECMAScript and its
 * standard library and has no way out of the process; everything here is what
 * *Node* additionally claims exists, supplied through `PhpJs\Host\Environment`
 * — the same interface a `deno-compat` would implement, needing nothing from
 * this package to do it.
 *
 * What that boundary rules out is worth stating, because this package used to
 * be on the wrong side of it: `Map`, `Set`, `Object.assign` and `Math.clz32`
 * are not Node features and are not here. They are ECMAScript, they live in
 * core, and an engine has them whether or not anyone installed an
 * environment.
 *
 * Nothing reachable from the JS heap holds a PHP object from this package —
 * native functions carry only strings in their `$data`, and the host handle
 * hangs off the realm, which the heap never points at (DESIGN.md §11.3).
 */
final class NodeHost implements Environment
{
    public readonly Engine $engine;
    public readonly ModuleLoader $modules;
    public readonly TimerQueue $timers;
    public readonly FileSystem $fs;
    /** Canonical form of $root: resolution compares paths by prefix. */
    public readonly string $root;

    /**
     * Held directly rather than reached through `$this->engine`, because
     * `install()` runs *during* the Engine's constructor — `$this->engine` is
     * not assigned yet at that point, and everything installed there needs a
     * realm.
     */
    private Realm $realm;
    private Vm $vm;

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

        // Everything `install()` touches has to exist before the Engine is
        // built, because the Engine calls it from its own constructor.
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
            // The same directory can also hold the engine's own standard
            // library, by the same content-hash convention -- so a project
            // that ran a build gets a first host that compiles nothing at
            // all, not just modules that skip compilation.
            Engine::warmEcmaScriptLibrary($resolvedAotCacheDir);
        }
        // Soft dependency, the same shape as the AOT cache above: this
        // package never requires packages/strip-types, so a project that
        // never installed it pays nothing and behaves exactly as before.
        // One that did gets `.ts`/`.tsx`/`.jsx` support with no call of its
        // own to make -- `node --experimental-strip-types`'s own shape.
        if ($stripTypes && class_exists(\PhpJs\StripTypes\Stripper::class)) {
            $fingerprint = \PhpJs\StripTypes\Stripper::fingerprint();
            foreach (\PhpJs\StripTypes\Stripper::EXTENSIONS as $extension) {
                $this->modules->registerSourceTransform(
                    $extension,
                    [\PhpJs\StripTypes\Stripper::class, 'strip'],
                    $fingerprint,
                );
            }
        }

        $this->engine = new Engine($this, function (string $s): void {
            if ($this->captureOutput) {
                $this->output .= $s;
            } else {
                fwrite(STDOUT, $s);
            }
        });
    }

    /**
     * Everything Node adds to a realm that already has all of ECMAScript.
     *
     * Called by the Engine during construction (`PhpJs\Host\Environment`).
     */
    public function install(Realm $realm, Vm $vm): void
    {
        $this->realm = $realm;
        $this->vm = $vm;
        $realm->hostContext = $this;

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

        // Node's own console extensions. Bundles call these unconditionally in
        // development builds; the standard `console` they hang off of is the
        // engine's.
        $console = $global->get('console', $vm);
        if ($console instanceof JSObject) {
            foreach (['group', 'groupEnd', 'groupCollapsed', 'table', 'trace', 'time', 'timeEnd', 'dir'] as $name) {
                if ($console->get($name, $vm) instanceof JSUndefined) {
                    $realm->defineMethod($console, $name, 'node.console.noop', 0);
                }
            }
        }
    }

    /** @see Environment::loadModule() */
    public function loadModule(string $specifier, ?string $referrer, Vm $vm): mixed
    {
        return $this->modules->load($specifier, $referrer ?? $this->root, $vm);
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
            ['node.console.noop' => static fn (): mixed => JSUndefined::$undefined],
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
        return $this->vm;
    }

    public function realm(): Realm
    {
        return $this->realm;
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

    /**
     * Register every native an AOT cache directory holds, all at once.
     *
     * `ArtifactCache::compile()` already does this lazily, one artifact at a
     * time, as each module is actually reached — but a template that arrives
     * preloaded (`ModuleLoader::preloadTemplates()`) never passes through
     * there, so a native ID it was stamped with would silently fall back to
     * bytecode. Call this first, before preloading AOT-stamped templates.
     *
     * @return int natives newly registered (0 if the directory has none, or does not exist)
     */
    public static function registerAotCacheDir(string $dir): int
    {
        return ArtifactCache::registerAllNatives($dir);
    }

    /** Load a CommonJS module and return its `exports`. */
    public function requireModule(string $specifier): mixed
    {
        try {
            $exports = $this->modules->load($specifier, $this->root);
            $this->drain();
            return $exports;
        } catch (\PhpJs\Runtime\JSThrowSignal $e) {
            throw \PhpJs\JSException::from($this->vm, $e->value);
        }
    }

    /** Call a JS function value, draining microtasks and timers afterwards. */
    public function call(mixed $fn, mixed $thisVal = null, array $args = []): mixed
    {
        try {
            $result = $this->vm->invoke($fn, $thisVal ?? JSUndefined::$undefined, $args);
            $this->drain();
            return $result;
        } catch (\PhpJs\Runtime\JSThrowSignal $e) {
            throw \PhpJs\JSException::from($this->vm, $e->value);
        }
    }

    /** Run microtasks, then due timers, until both are exhausted. */
    public function drain(): void
    {
        $realm = $this->realm;
        $vm = $this->vm;
        do {
            $realm->drainMicrotasks($vm);
        } while ($this->timers->runDue($vm) && true);
    }
}

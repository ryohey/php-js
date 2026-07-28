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
     * @param string $root       module resolution root; also the fs sandbox root
     * @param bool   $captureOutput keep console/stdout text instead of printing
     */
    public function __construct(
        string $root,
        private readonly bool $captureOutput = false,
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

        $this->engine->evaluate(file_get_contents(__DIR__ . '/../js/polyfills.js'));
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

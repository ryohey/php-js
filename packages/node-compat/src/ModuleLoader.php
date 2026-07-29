<?php

declare(strict_types=1);

namespace PhpJs\Node;

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
 */
final class ModuleLoader
{
    /** @var array<string, mixed> resolved path => exports */
    private array $cache = [];
    /** @var array<string, JSObject> resolved path => module object (for cycles) */
    private array $loading = [];
    /** @var array<string, array<string, mixed>> resolved path => compiled template */
    private array $templates = [];
    /** @var array<string, mixed> built-in module name => exports */
    private array $builtins = [];

    public int $compileCount = 0;
    public float $compileSeconds = 0.0;
    /**
     * Optional per-module compile hook, passed straight to `Compiler::compile`
     * as its per-function callback. The ahead-of-time PHP compiler
     * (packages/php-transpile, docs/aot-php.md) installs one here to claim the
     * functions it can compile; nothing else uses it, and with none installed
     * the loader behaves exactly as before.
     *
     * `fn(string $path): ?callable` — given a module path, return the hook for
     * that module, or null to compile it as ordinary bytecode.
     * @var null|callable(string): ?callable
     */
    public $onCompileModule = null;

    public function __construct(
        private readonly NodeHost $host,
        private readonly string $root,
    ) {
    }

    public static function entries(): array
    {
        return [
            'node.require' => [self::class, 'requireNative'],
            'node.require.resolve' => [self::class, 'resolveNative'],
        ];
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
    private const STUBS = ['stream', 'util', 'events', 'crypto', 'async_hooks'];

    private function loadStub(string $name, Vm $vm): mixed
    {
        $file = __DIR__ . '/../js/stubs/' . $name . '.js';
        if (!is_file($file)) {
            return null;
        }
        $realm = $this->host->realm();
        $exports = $realm->newObject();
        $module = $realm->newObject();
        $module->defineOwnData('exports', $exports);
        $wrapped = '(function (exports, require, module, __filename, __dirname) {'
            . file_get_contents($file) . "\n})";
        $fn = $vm->runProgram(Compiler::compile($wrapped));
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

    /** @return array<string, mixed> */
    private function templateFor(string $path): array
    {
        if (isset($this->templates[$path])) {
            return $this->templates[$path];
        }
        $source = $this->host->fs->read($path);
        // A trailing newline before the closing brace guards against a source
        // that ends inside a line comment.
        $wrapped = "(function (exports, require, module, __filename, __dirname) {"
            . $source . "\n})";
        $started = microtime(true);
        $hook = $this->onCompileModule === null ? null : ($this->onCompileModule)($path);
        $template = Compiler::compile($wrapped, $hook);
        $this->compileSeconds += microtime(true) - $started;
        $this->compileCount++;
        return $this->templates[$path] = $template;
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
        foreach (['', '.js', '.json'] as $ext) {
            $candidate = $path . $ext;
            if ($this->host->fs->isFile($candidate)) {
                return $this->host->fs->realpath($candidate);
            }
        }
        return null;
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

<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Compiler\Ctx;
use PhpJs\Node\ModuleLoader;
use PhpJs\Node\NodeHost;
use PhpParser\Node as P;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter;

/**
 * Ahead-of-time compiles the modules a `node-compat` host loads.
 *
 * This is the build-time half in its most direct form: it hooks module
 * compilation, emits PHP for every function the emitter accepts, and registers
 * the result immediately. A production build would write the PHP to disk and
 * preload it (docs/aot-php.md §4 / phase 3); doing it in-process keeps phase 1
 * about whether the emitter is correct and fast enough, which is the open
 * question.
 *
 * `attach()` is a no-op for modules the filter rejects, so a build can convert
 * one library and leave the rest interpreted.
 */
final class NodeIntegration
{
    /** @var array<string, int> module path => functions converted */
    public array $converted = [];
    /** @var array<string, int> module path => functions considered */
    public array $seen = [];
    /** @var list<array{module: string, id: string, reason: string}> */
    public array $refused = [];
    public float $emitSeconds = 0.0;

    /** @var array<string, Expr\Closure> */
    private array $emitted = [];
    /** @var array<string, string> module path => the content-hash key its IDs are prefixed with */
    private array $moduleKeys = [];

    public function __construct(
        /** Return true for modules that should be compiled ahead of time. */
        private readonly \Closure $accept,
        /**
         * Build mode emits PHP and registers it in-process. Run mode only
         * stamps the IDs on the templates and expects the natives to have been
         * loaded from a generated file already — that is the deployable shape,
         * because opcache caches files and never caches eval'd code.
         */
        private readonly bool $emit = true,
        /** What the build may take for granted; see Assumptions. */
        private readonly Assumptions $assume = new Assumptions(),
    ) {
    }

    /**
     * The accept filter to use unless there is a reason not to: only modules
     * inside a `node_modules` directory.
     *
     * Generated PHP is code, and running it gives up two things the interpreter
     * guarantees — the wall-clock limit, which only the dispatch loop checks,
     * and a JS call stack that is not the PHP call stack. Both are fine for a
     * dependency a lockfile pins and reviewed as a version bump. Neither is fine
     * for code that arrives at run time. Anything this rejects still runs, as
     * bytecode, with those guarantees intact.
     *
     * @param ?string $package restrict further to one package, e.g. 'react'
     */
    public static function pinnedDependencies(?string $package = null): \Closure
    {
        $needle = '/node_modules/' . ($package === null ? '' : $package . '/');
        return static fn (string $path): bool => str_contains($path, $needle);
    }

    /** Compiles and registers in-process; for tests and one-shot scripts. */
    public static function forBuild(\Closure $accept, ?Assumptions $assume = null): self
    {
        return new self($accept, true, $assume ?? new Assumptions());
    }

    /**
     * Stamps IDs only; pair with `Artifact::register()` of a built file.
     *
     * The assumptions must match the build's. They are part of the ID, so a
     * mismatch does not run the wrong code — nothing matches and every
     * function falls back to bytecode — but it does silently lose the
     * optimization, so pass the same value both sides.
     */
    public static function forRun(\Closure $accept, ?Assumptions $assume = null): self
    {
        return new self($accept, false, $assume ?? new Assumptions());
    }

    /** Compile every module whose path the filter accepts. */
    public function attach(NodeHost $host): void
    {
        $host->modules->onCompileModule = function (string $path): ?callable {
            if (!($this->accept)($path)) {
                return null;
            }
            $counter = 0;
            $this->seen[$path] = 0;
            $this->converted[$path] = 0;
            // Derived from the module's *contents*, so a build and a later run
            // agree, and an upgraded dependency simply stops matching its stale
            // natives instead of binding them to the wrong functions. Not the
            // assumptions too, unlike an earlier version of this: the ID format
            // is shared with `ModuleLoader::aotLookupHook()` (node-compat, which
            // this key must exactly agree with to ever be found by a plain
            // `require`), and that lookup has no assumptions concept of its own
            // to fold in. What that makes true instead is a directory-level
            // invariant, not a hash-level one: every artifact under one cache
            // directory must have been built under the same `Assumptions`, and
            // nothing here enforces that automatically -- `phpjs-aot` (the CLI)
            // is the one place that populates a cache directory, and it always
            // builds under one fixed profile for exactly this reason.
            $moduleSource = (string)@file_get_contents($path);
            $moduleKey = hash('xxh128', $moduleSource);
            $this->moduleKeys[$path] = $moduleKey;
            // Module-wide proofs a per-function emitter cannot make itself.
            // Only the build needs the proofs; run mode just matches IDs, and
            // scanning a 240 KB module there would be pure boot cost.
            $facts = ($this->emit && $this->assume->standardBuiltins)
                ? ModuleFacts::scan($moduleSource)
                : ModuleFacts::none();
            return function (object $node, Ctx $ctx, bool $isProgram) use ($path, $moduleKey, $facts, &$counter): ?string {
                if ($isProgram) {
                    return null;
                }
                $this->seen[$path]++;
                $id = ModuleLoader::aotFunctionId($moduleKey, $counter++, $ctx->name);
                if (!$this->emit) {
                    // Run mode: the native either came from the generated file
                    // or it did not, and JSFunction falls back to bytecode when
                    // it did not. Nothing to compile here.
                    if (!BuiltinRegistry::hasHost($id)) {
                        return null;
                    }
                    $this->converted[$path]++;
                    return $id;
                }
                $t = microtime(true);
                try {
                    $closure = (new FunctionEmitter($ctx, $this->assume, $facts))->emit($node);
                } catch (Unsupported $e) {
                    $this->refused[] = ['module' => $path, 'id' => $id, 'reason' => $e->getMessage()];
                    return null;
                } finally {
                    $this->emitSeconds += microtime(true) - $t;
                }
                $this->emitted[$id] = $closure;
                $this->converted[$path]++;
                // Register eagerly: the template is instantiated as soon as the
                // module runs, and JSFunction resolves its native at that point.
                // IDs are content-derived, so a second build of the same module
                // in one process would produce the same code -- reuse it rather
                // than treating it as a conflict.
                if (!BuiltinRegistry::hasHost($id)) {
                    BuiltinRegistry::registerHost([$id => $this->materialize($id, $closure)]);
                }
                return $id;
            };
        };
    }

    /** @return list<array{reason: string, count: int}> refusals, most common first */
    public function refusalSummary(): array
    {
        $counts = [];
        foreach ($this->refused as $r) {
            $counts[$r['reason']] = ($counts[$r['reason']] ?? 0) + 1;
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $reason => $count) {
            $out[] = ['reason' => $reason, 'count' => $count];
        }
        return $out;
    }

    public function totalConverted(): int
    {
        return array_sum($this->converted);
    }

    public function totalSeen(): int
    {
        return array_sum($this->seen);
    }

    /** Write the generated corpus where opcache can hold it; returns the path. */
    public function writePhp(string $path): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        file_put_contents($path, $this->php());
        return $path;
    }

    /**
     * Write one file per compiled module into an AOT cache directory
     * (`NodeHost::AOT_CACHE_SUBDIR` by convention), named by exactly the
     * content-hash key `ModuleLoader::aotLookupHook()` looks for — so a plain
     * `require()` against that directory, from any process, picks these up
     * with no further wiring. This is `phpjs-aot`'s (the CLI) own job; `attach()`
     * still has to run first to populate `$this->emitted`/`$this->moduleKeys`.
     *
     * Unlike `writePhp()`, a module that converted nothing writes no file at
     * all — an empty array is a valid but pointless artifact, and skipping it
     * keeps a cache directory's contents equal to "modules with at least one
     * native", which is also exactly what a directory listing of it means.
     *
     * @return array<string, string> module path => the cache file written for it
     */
    public function writePerModule(string $cacheDir): array
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException("Cannot create $cacheDir");
        }
        $printer = new PrettyPrinter\Standard(['shortArraySyntax' => true]);
        $written = [];
        foreach ($this->moduleKeys as $path => $moduleKey) {
            $prefix = "aot:$moduleKey#";
            $items = [];
            foreach ($this->emitted as $id => $closure) {
                if (str_starts_with($id, $prefix)) {
                    $items[] = new P\ArrayItem($closure, new \PhpParser\Node\Scalar\String_($id));
                }
            }
            if ($items === []) {
                continue;
            }
            $file = rtrim($cacheDir, '/') . '/' . $moduleKey . '.php';
            file_put_contents($file, "<?php\n\n// Generated by php-js-transpile. Do not edit.\n\n"
                . $printer->prettyPrint([new Stmt\Return_(new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT]))])
                . "\n");
            $written[$path] = $file;
        }
        return $written;
    }

    /** The whole generated corpus as one loadable PHP file. */
    public function php(): string
    {
        $items = [];
        foreach ($this->emitted as $id => $closure) {
            $items[] = new P\ArrayItem($closure, new \PhpParser\Node\Scalar\String_($id));
        }
        $printer = new PrettyPrinter\Standard(['shortArraySyntax' => true]);
        return "<?php\n\n// Generated by php-js-transpile. Do not edit.\n\n"
            . $printer->prettyPrint([new Stmt\Return_(new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT]))])
            . "\n";
    }

    /**
     * Turn one emitted closure into a callable.
     *
     * `eval` is used here and only here, because this path compiles in-process;
     * the file-based path (§4) exists so opcache can hold the result, which
     * eval'd code never gets.
     */
    private function materialize(string $id, Expr\Closure $closure): callable
    {
        $printer = new PrettyPrinter\Standard(['shortArraySyntax' => true]);
        $code = $printer->prettyPrintExpr($closure);
        /** @var callable $fn */
        $fn = eval('return ' . $code . ';');
        return $fn;
    }
}

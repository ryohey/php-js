<?php

declare(strict_types=1);

namespace PhpJs\Test262;

use PhpJs\Compiler\CompileError;
use PhpJs\Engine;
use PhpJs\JSException;
use PhpJs\Runtime\JSObject;

/**
 * test262 runner for the ES5.1 subset (DESIGN.md §12). The pass rate it
 * reports is the project's progress metric.
 */
final class Runner
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    /** @var array<string, string> path (relative to test/) => reason */
    private array $skipList = [];
    /** @var list<string> path prefixes under test/ to include */
    private array $includePaths = [];
    /** @var array<string, bool> features that force a skip */
    private array $excludedFeatures = [];
    /** @var array<string, string> harness file cache */
    private array $harnessCache = [];
    /**
     * Compiled harness programs, keyed by include-set + mode. Compiling the
     * harness costs ~45x what running it does, and the same handful of
     * include-sets repeat across the whole suite, so this is the difference
     * between a 40-minute and a 1-minute run.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $harnessTemplates = [];

    public int $passed = 0;
    public int $failed = 0;
    public int $skipped = 0;
    /** @var list<array{0: string, 1: string}> [path, message] */
    public array $failures = [];

    public function __construct(
        private readonly string $test262Dir,
        private readonly bool $verbose = false,
        /** Per-test wall-clock limit; a mis-executed test can loop forever. */
        private readonly float $timeLimit = 5.0,
    ) {
    }

    public function loadIncludePaths(string $file): void
    {
        $this->includePaths = self::readListFile($file);
    }

    public function loadExcludedFeatures(string $file): void
    {
        foreach (self::readListFile($file) as $feature) {
            $this->excludedFeatures[$feature] = true;
        }
    }

    /**
     * Skip entries are `<path> <reason>`. A path ending in `/` skips the whole
     * subtree, which is how post-ES5 builtins (Math.trunc, Object.assign, …)
     * are excluded: they carry no `features:` tag to filter on.
     */
    public function loadSkipList(string $file): void
    {
        foreach (self::readListFile($file) as $line) {
            $parts = preg_split('/\s+/', $line, 2);
            $this->skipList[$parts[0]] = $parts[1] ?? '(no reason given)';
        }
    }

    private function skipReason(string $relPath): ?string
    {
        if (isset($this->skipList[$relPath])) {
            return $this->skipList[$relPath];
        }
        foreach ($this->skipList as $prefix => $reason) {
            if (str_ends_with($prefix, '/') && str_starts_with($relPath, $prefix)) {
                return $reason;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function readListFile(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @return list<string> test file paths relative to <test262>/test */
    public function collectTests(?string $filter = null): array
    {
        $testRoot = $this->test262Dir . '/test';
        $out = [];
        foreach ($this->includePaths as $prefix) {
            $dir = $testRoot . '/' . $prefix;
            if (is_file($dir)) {
                $out[] = $prefix;
                continue;
            }
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.js') || str_contains($path, '_FIXTURE')) {
                    continue;
                }
                $out[] = substr($path, strlen($testRoot) + 1);
            }
        }
        sort($out);
        if ($filter !== null) {
            $out = array_values(array_filter($out, fn ($p) => str_contains($p, $filter)));
        }
        return $out;
    }

    public function runAll(?string $filter = null): void
    {
        foreach ($this->collectTests($filter) as $path) {
            [$status, $message] = $this->runTest($path);
            match ($status) {
                self::PASS => $this->passed++,
                self::SKIP => $this->skipped++,
                self::FAIL => (function () use ($path, $message) {
                    $this->failed++;
                    $this->failures[] = [$path, $message];
                })(),
            };
            if ($this->verbose && $status !== self::PASS) {
                fwrite(STDERR, sprintf("%s: %s (%s)\n", strtoupper($status), $path, $message));
            }
        }
    }

    /** @return array{0: string, 1: string} [status, message] */
    public function runTest(string $relPath): array
    {
        $skip = $this->skipReason($relPath);
        if ($skip !== null) {
            return [self::SKIP, $skip];
        }
        $file = $this->test262Dir . '/test/' . $relPath;
        $source = @file_get_contents($file);
        if ($source === false) {
            return [self::FAIL, 'cannot read file'];
        }
        $fm = FrontMatter::parse($source);
        if ($fm->hasFlag('module')) {
            return [self::SKIP, 'modules are out of scope'];
        }
        if ($fm->hasFlag('CanBlockIsFalse') || $fm->hasFlag('CanBlockIsTrue')) {
            return [self::SKIP, 'agents are out of scope'];
        }
        foreach ($fm->features as $feature) {
            if (isset($this->excludedFeatures[$feature])) {
                return [self::SKIP, "feature out of scope: $feature"];
            }
        }

        $modes = match (true) {
            $fm->hasFlag('raw') => ['raw'],
            $fm->hasFlag('onlyStrict') => ['strict'],
            $fm->hasFlag('noStrict') => ['sloppy'],
            default => ['sloppy', 'strict'],
        };
        foreach ($modes as $mode) {
            [$status, $message] = $this->runOnce($source, $fm, $mode);
            if ($status !== self::PASS) {
                return [$status, "[$mode] $message"];
            }
        }
        return [self::PASS, ''];
    }

    /** @return array{0: string, 1: string} [status, message] */
    private function runOnce(string $source, FrontMatter $fm, string $mode): array
    {
        $output = '';
        $engine = new Engine(function (string $s) use (&$output) {
            $output .= $s;
        });
        // test262 uses `print` for async completion signalling.
        $engine->realm->globalObject->defineOwnData(
            'print',
            $engine->realm->nativeFn('console.log', 'print', 1),
            JSObject::W | JSObject::C
        );

        $engine->vm->setTimeLimit($this->timeLimit);
        $directive = $mode === 'strict' ? "\"use strict\";\n" : '';
        if ($mode !== 'raw') {
            // The harness runs as its own program. Program-level declarations
            // become global object properties, so the test still sees them.
            try {
                $engine->runTemplate($this->harnessTemplate($fm, $mode, $directive));
            } catch (\Throwable $e) {
                return [self::FAIL, 'harness failed: ' . $e->getMessage()];
            }
        }

        try {
            $engine->evaluate($directive . $source);
        } catch (CompileError $e) {
            if ($fm->negativePhase === 'parse' || $fm->negativePhase === 'early') {
                return [self::PASS, ''];
            }
            if ($e->unsupportedSyntax) {
                // The test source itself is written in post-ES5 syntax, so an
                // ES5.1 engine cannot run it. Not an engine defect: input code
                // is expected to be downleveled (DESIGN.md scope).
                return [self::SKIP, 'out-of-scope syntax: ' . $e->getMessage()];
            }
            return [self::FAIL, 'compile error: ' . $e->getMessage()];
        } catch (JSException $e) {
            if ($fm->negativeType !== null && $fm->negativePhase !== 'parse') {
                $name = $this->errorName($engine, $e->jsValue);
                if ($name === $fm->negativeType) {
                    return [self::PASS, ''];
                }
                return [self::FAIL, "expected {$fm->negativeType}, got $name"];
            }
            return [self::FAIL, $e->getMessage()];
        } catch (\Throwable $e) {
            return [self::FAIL, 'HOST CRASH: ' . get_class($e) . ': ' . $e->getMessage()];
        }

        if ($fm->negativeType !== null) {
            return [self::FAIL, "expected {$fm->negativeType} but completed normally"];
        }
        if ($fm->hasFlag('async') && !str_contains($output, 'Test262:AsyncTestComplete')) {
            $firstLine = strtok($output, "\n");
            return [self::FAIL, 'async test did not complete: ' . ($firstLine === false ? '(no output)' : $firstLine)];
        }
        return [self::PASS, ''];
    }

    private function errorName(Engine $engine, mixed $value): string
    {
        if ($value instanceof JSObject) {
            try {
                $name = $value->get('name', $engine->vm);
                if (is_string($name)) {
                    return $name;
                }
            } catch (\Throwable) {
            }
        }
        return get_debug_type($value);
    }

    /** @return array<string, mixed> compiled harness program for this include-set */
    private function harnessTemplate(FrontMatter $fm, string $mode, string $directive): array
    {
        $includes = ['assert.js', 'sta.js'];
        if ($fm->hasFlag('async')) {
            $includes[] = 'doneprintHandle.js';
        }
        foreach ($fm->includes as $include) {
            $includes[] = $include;
        }
        $key = $mode . '|' . implode(',', $includes);
        if (isset($this->harnessTemplates[$key])) {
            return $this->harnessTemplates[$key];
        }
        $src = $directive;
        foreach ($includes as $include) {
            $src .= $this->harness($include) . "\n";
        }
        return $this->harnessTemplates[$key] = \PhpJs\Compiler\Compiler::compile($src);
    }

    private function harness(string $name): string
    {
        return $this->harnessCache[$name] ??= (function () use ($name) {
            $file = $this->test262Dir . '/harness/' . $name;
            $src = @file_get_contents($file);
            if ($src === false) {
                throw new \RuntimeException("Missing harness file: $file");
            }
            return $src;
        })();
    }

    public function report(): string
    {
        $total = $this->passed + $this->failed;
        $rate = $total > 0 ? $this->passed / $total * 100 : 0.0;
        return sprintf(
            "test262: %d passed, %d failed, %d skipped — pass rate %.2f%% (of %d run)\n",
            $this->passed,
            $this->failed,
            $this->skipped,
            $rate,
            $total
        );
    }
}

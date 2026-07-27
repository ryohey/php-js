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

    public int $passed = 0;
    public int $failed = 0;
    public int $skipped = 0;
    /** @var list<array{0: string, 1: string}> [path, message] */
    public array $failures = [];

    public function __construct(
        private readonly string $test262Dir,
        private readonly bool $verbose = false,
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

    public function loadSkipList(string $file): void
    {
        foreach (self::readListFile($file) as $line) {
            $parts = preg_split('/\s+/', $line, 2);
            $this->skipList[$parts[0]] = $parts[1] ?? '(no reason given)';
        }
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
        if (isset($this->skipList[$relPath])) {
            return [self::SKIP, $this->skipList[$relPath]];
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
            [$ok, $message] = $this->runOnce($source, $fm, $mode);
            if (!$ok) {
                return [self::FAIL, "[$mode] $message"];
            }
        }
        return [self::PASS, ''];
    }

    /** @return array{0: bool, 1: string} */
    private function runOnce(string $source, FrontMatter $fm, string $mode): array
    {
        $prefix = '';
        if ($mode !== 'raw') {
            if ($mode === 'strict') {
                $prefix .= "\"use strict\";\n";
            }
            $harness = $this->harness('assert.js') . "\n" . $this->harness('sta.js') . "\n";
            if ($fm->hasFlag('async')) {
                $harness .= $this->harness('doneprintHandle.js') . "\n";
            }
            foreach ($fm->includes as $include) {
                $harness .= $this->harness($include) . "\n";
            }
            $prefix .= $harness;
        }

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

        try {
            $engine->evaluate($prefix . $source);
        } catch (CompileError $e) {
            if ($fm->negativePhase === 'parse' || $fm->negativePhase === 'early') {
                return [true, ''];
            }
            return [false, 'compile error: ' . $e->getMessage()];
        } catch (JSException $e) {
            if ($fm->negativeType !== null && $fm->negativePhase !== 'parse') {
                $name = $this->errorName($engine, $e->jsValue);
                if ($name === $fm->negativeType) {
                    return [true, ''];
                }
                return [false, "expected {$fm->negativeType}, got $name"];
            }
            return [false, $e->getMessage()];
        } catch (\Throwable $e) {
            return [false, 'HOST CRASH: ' . get_class($e) . ': ' . $e->getMessage()];
        }

        if ($fm->negativeType !== null) {
            return [false, "expected {$fm->negativeType} but completed normally"];
        }
        if ($fm->hasFlag('async') && !str_contains($output, 'Test262:AsyncTestComplete')) {
            $firstLine = strtok($output, "\n");
            return [false, 'async test did not complete: ' . ($firstLine === false ? '(no output)' : $firstLine)];
        }
        return [true, ''];
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

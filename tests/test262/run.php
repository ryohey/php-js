<?php

declare(strict_types=1);

/**
 * test262 CLI (DESIGN.md §12).
 *
 * Usage:
 *   php tests/test262/run.php --test262 /path/to/test262 [--filter substr] [--verbose] [--list-failures N]
 *
 * The pass rate printed on the last line is the project progress metric.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpJs\Test262\Runner;

$test262Dir = null;
$filter = null;
$verbose = false;
$listFailures = 25;
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--test262':
            $test262Dir = $argv[++$i] ?? null;
            break;
        case '--filter':
            $filter = $argv[++$i] ?? null;
            break;
        case '--verbose':
            $verbose = true;
            break;
        case '--list-failures':
            $listFailures = (int)($argv[++$i] ?? 25);
            break;
        default:
            fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
            exit(2);
    }
}
$test262Dir ??= getenv('TEST262_DIR') ?: null;
if ($test262Dir === null || !is_dir($test262Dir . '/test')) {
    fwrite(STDERR, "Pass --test262 <dir> (or set TEST262_DIR) pointing at a tc39/test262 checkout.\n");
    exit(2);
}

$runner = new Runner($test262Dir, $verbose);
$runner->loadIncludePaths(__DIR__ . '/include-paths.txt');
$runner->loadExcludedFeatures(__DIR__ . '/excluded-features.txt');
$runner->loadSkipList(__DIR__ . '/skip.txt');

$start = microtime(true);
$runner->runAll($filter);
$elapsed = microtime(true) - $start;

if ($runner->failures !== [] && $listFailures > 0) {
    fwrite(STDERR, "--- first $listFailures failures ---\n");
    foreach (array_slice($runner->failures, 0, $listFailures) as [$path, $message]) {
        fwrite(STDERR, "$path\n    $message\n");
    }
}
printf("elapsed: %.1fs\n", $elapsed);
echo $runner->report();

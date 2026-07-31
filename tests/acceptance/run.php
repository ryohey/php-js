<?php

/**
 * How much real, published JavaScript this compiler accepts.
 *
 * test262 measures conformance on the language we claim to implement. This
 * measures *reach*: point it at a node_modules directory and it reports the
 * share of shipped files that compile, and what the rest tripped on. Those are
 * different questions, and while the syntax surface is growing this is the one
 * that says whether the growth is aimed at anything.
 *
 * The failure histogram is the useful part. It is a work queue in priority
 * order, and it is why the first features to land were the ones they were.
 *
 *   $ php tests/acceptance/run.php --dir packages/ssg-demo/node_modules
 *   $ php tests/acceptance/run.php --dir ... --sample 400 --seed 1 --verbose
 *
 * Deterministic by default: the same seed samples the same files, so two runs
 * are comparable and a number can be quoted.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use PhpJs\Compiler\Compiler;

$options = ['dir' => null, 'sample' => 400, 'seed' => 1, 'verbose' => false, 'max' => 400000];
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--dir': $options['dir'] = $argv[++$i]; break;
        case '--sample': $options['sample'] = (int)$argv[++$i]; break;
        case '--seed': $options['seed'] = (int)$argv[++$i]; break;
        case '--max-bytes': $options['max'] = (int)$argv[++$i]; break;
        case '--verbose': $options['verbose'] = true; break;
        case '--help':
            fwrite(STDOUT, <<<TXT
            Usage: php tests/acceptance/run.php --dir <node_modules> [options]
              --sample N      files to try (default 400, 0 for all)
              --seed N        sampling seed, for a comparable number (default 1)
              --max-bytes N   skip files larger than this (default 400000)
              --verbose       list the files that failed

            TXT);
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
            exit(2);
    }
}

if ($options['dir'] === null || !is_dir($options['dir'])) {
    fwrite(STDERR, "Pass --dir <a node_modules directory>. See --help.\n");
    exit(2);
}

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($options['dir'], FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile() || !preg_match('/\.(c?js)$/', $file->getFilename())) {
        continue;
    }
    // Tiny files say nothing, huge ones dominate the runtime, and .bin is
    // symlinked executables rather than library code.
    if ($file->getSize() > $options['max'] || $file->getSize() < 500) {
        continue;
    }
    if (str_contains($file->getPathname(), '/.bin/')) {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files);                       // stable before seeding, so the seed decides
mt_srand($options['seed']);
usort($files, static fn (): int => mt_rand(-1, 1));
if ($options['sample'] > 0) {
    $files = array_slice($files, 0, $options['sample']);
}

$ok = 0;
$failed = 0;
$reasons = [];
$failures = [];
$started = microtime(true);
foreach ($files as $path) {
    $source = @file_get_contents($path);
    if ($source === false) {
        continue;
    }
    try {
        // The CommonJS wrapper node-compat uses, so this measures what the
        // module loader would actually be handed.
        Compiler::compile(
            '(function (exports, require, module, __filename, __dirname) {' . $source . "\n})"
        );
        $ok++;
    } catch (\Throwable $e) {
        $failed++;
        $reason = summarize($e->getMessage());
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        $failures[] = [$path, $reason];
    }
}

$total = $ok + $failed;
printf(
    "%s\n%d files sampled (seed %d), %.1fs\n\n",
    realpath($options['dir']),
    $total,
    $options['seed'],
    microtime(true) - $started
);
printf("  accepted  %4d  %5.1f%%\n", $ok, $total > 0 ? 100 * $ok / $total : 0);
printf("  rejected  %4d  %5.1f%%\n\n", $failed, $total > 0 ? 100 * $failed / $total : 0);

if ($reasons !== []) {
    arsort($reasons);
    echo "what the rejections tripped on:\n";
    foreach ($reasons as $reason => $count) {
        printf("  %-52s %4d  %4.1f%%\n", $reason, $count, 100 * $count / $total);
    }
}

if ($options['verbose'] && $failures !== []) {
    echo "\nfailing files:\n";
    foreach ($failures as [$path, $reason]) {
        printf("  %-52s %s\n", $reason, $path);
    }
}

/** Collapse a compiler message to the construct it names, so they group. */
function summarize(string $message): string
{
    if (preg_match('/Unsupported syntax: (\w+)/', $message, $m)) {
        return $m[1];
    }
    if (preg_match("/'(const|let)' declarations/", $message, $m)) {
        return "'{$m[1]}' declaration";
    }
    if (preg_match('/^(Generators|Async functions|Expression-bodied functions|Destructuring)/', $message, $m)) {
        return $m[1];
    }
    if (preg_match('/(Parameter patterns|Spread properties|Spread arguments|Catch parameter patterns)/', $message, $m)) {
        return $m[1];
    }
    if (preg_match('/Unexpected:?\s*(\S+)/', $message, $m)) {
        return 'unexpected ' . trim($m[1], "\"' ");
    }
    if (str_contains($message, 'Invalid regular expression')) {
        return 'regexp translation';
    }
    return rtrim(substr($message, 0, 50));
}

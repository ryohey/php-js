<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

use PhpJs\Builtins\BuiltinRegistry;

/**
 * A transpiled module: the bytecode template plus the generated PHP that
 * implements some of its functions natively.
 *
 * The two halves are matched by native ID and are independent — a template
 * whose natives are not registered runs entirely on bytecode, which is what
 * makes it safe to ship the generated PHP separately (or not at all).
 */
final class Artifact
{
    /**
     * @param array<string, mixed> $template
     * @param list<array{id: string, reason: string}> $refused
     */
    private function __construct(
        public readonly array $template,
        public readonly string $php,
        public readonly int $converted,
        public readonly int $seen,
        public readonly array $refused,
    ) {
    }

    public static function build(string $source, string $moduleId): self
    {
        $r = (new Transpiler($moduleId))->run($source);
        return new self($r['template'], $r['php'], $r['converted'], $r['seen'], $r['refused']);
    }

    /** Write the generated PHP where opcache can hold it, and return the path. */
    public function writePhp(string $dir, string $name): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        // Content-hashed so an upgraded dependency cannot silently reuse a
        // stale artifact (docs/aot-php.md §4).
        $path = rtrim($dir, '/') . '/' . $name . '.' . hash('xxh128', $this->php) . '.php';
        file_put_contents($path, $this->php);
        return $path;
    }

    /**
     * Load generated PHP and register it. Returns the number of natives added.
     *
     * `require` rather than `eval` on purpose: opcache caches files and not
     * eval'd code, which is the entire deployment argument (§4).
     */
    public static function register(string $path): int
    {
        $entries = require $path;
        if (!is_array($entries)) {
            throw new \RuntimeException("$path did not return an array of natives");
        }
        // Loading the same build twice in one process is not an error; the
        // second copy is the same generated code under a content-derived ID.
        $fresh = [];
        foreach ($entries as $id => $fn) {
            if (!BuiltinRegistry::hasHost($id)) {
                $fresh[$id] = $fn;
            }
        }
        BuiltinRegistry::registerHost($fresh);
        return count($fresh);
    }

    /** Register in-memory, for tests and for callers that do not want a file. */
    public function registerDirect(): int
    {
        $entries = eval('?>' . $this->php);
        BuiltinRegistry::registerHost($entries);
        return count($entries);
    }
}

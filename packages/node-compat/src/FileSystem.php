<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Read-only `fs`, confined to a root directory.
 *
 * Guest code decides the paths, so every access is resolved and checked
 * against the root before it touches the disk; there is no write surface at
 * all. Widening either of those is a deliberate decision for whoever needs it,
 * not a default.
 */
final class FileSystem
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false) {
            throw new \InvalidArgumentException("fs root does not exist: $root");
        }
        $this->root = $real;
    }

    public static function entries(): array
    {
        return [
            'node.fs.readFileSync' => [self::class, 'readFileSync'],
            'node.fs.existsSync' => [self::class, 'existsSync'],
        ];
    }

    public static function makeObject(Realm $realm): JSObject
    {
        $fs = $realm->newObject();
        $realm->defineMethod($fs, 'readFileSync', 'node.fs.readFileSync', 2);
        $realm->defineMethod($fs, 'existsSync', 'node.fs.existsSync', 1);
        return $fs;
    }

    /** Absolute, symlink-resolved path, or null when outside the root. */
    private function confine(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            return null;
        }
        return $real;
    }

    public function isFile(string $path): bool
    {
        $real = $this->confine($path);
        return $real !== null && is_file($real);
    }

    public function realpath(string $path): string
    {
        $real = $this->confine($path);
        if ($real === null) {
            throw new \RuntimeException("Path escapes the fs root: $path");
        }
        return $real;
    }

    public function read(string $path): string
    {
        $real = $this->confine($path);
        if ($real === null || !is_file($real)) {
            throw new \RuntimeException("Cannot read file: $path");
        }
        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read file: $path");
        }
        return $contents;
    }

    public static function readFileSync(Vm $vm, mixed $t, array $args): mixed
    {
        $host = NodeHost::of($vm);
        $path = Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined);
        try {
            return $host->fs->read($path);
        } catch (\RuntimeException $e) {
            $vm->throwError('Error', $e->getMessage());
        }
    }

    public static function existsSync(Vm $vm, mixed $t, array $args): mixed
    {
        $host = NodeHost::of($vm);
        $path = Conversions::toString($vm, $args[0] ?? JSUndefined::$undefined);
        return $host->fs->isFile($path);
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Aot;

/**
 * Which libraries `phpjs-aot build` should compile, and where that list came
 * from — either a dedicated `phpjs-aot.json`, or a `"phpjsAot"` key in
 * `package.json`, whichever a project would rather keep it in. Nothing about
 * the rest of this package cares which; both paths produce the same shape.
 */
final class Manifest
{
    public const DEFAULT_FILENAME = 'phpjs-aot.json';

    /** @param list<string> $libraries module specifiers, resolved the same way `require()` would */
    private function __construct(
        public readonly array $libraries,
        public readonly string $source,
    ) {
    }

    /**
     * @param ?string $configPath an explicit manifest file, skipping the
     *                            dedicated-file-then-package.json lookup
     */
    public static function discover(string $root, ?string $configPath = null): self
    {
        if ($configPath !== null) {
            return self::fromFile($configPath);
        }
        $dedicated = rtrim($root, '/') . '/' . self::DEFAULT_FILENAME;
        if (is_file($dedicated)) {
            return self::fromFile($dedicated);
        }
        $packageJson = rtrim($root, '/') . '/package.json';
        if (is_file($packageJson)) {
            $data = self::readJson($packageJson);
            if (isset($data['phpjsAot'])) {
                return self::fromData($data['phpjsAot'], $packageJson . ' ("phpjsAot")');
            }
        }
        throw new \RuntimeException(
            "No AOT manifest found: expected $dedicated, or a \"phpjsAot\" key in $packageJson"
        );
    }

    private static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("No such file: $path");
        }
        return self::fromData(self::readJson($path), $path);
    }

    /** @param array<mixed> $data */
    private static function fromData(array $data, string $source): self
    {
        $libraries = $data['libraries'] ?? null;
        if (!is_array($libraries) || $libraries === []) {
            throw new \RuntimeException("$source: \"libraries\" must be a non-empty array of module specifiers");
        }
        return new self(array_values(array_map('strval', $libraries)), $source);
    }

    /** @return array<mixed> */
    private static function readJson(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("$path is not valid JSON");
        }
        return $data;
    }
}

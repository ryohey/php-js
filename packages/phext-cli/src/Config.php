<?php

declare(strict_types=1);

namespace PhpJs\PhextCli;

use PhpJs\Phext\App;

/**
 * A project's settings, from `"phext"` in its package.json.
 *
 * One file rather than a second config format: a phext project already has a
 * package.json (it has npm dependencies), and a `phext.config.js` would have
 * to be *evaluated* to be read — which means booting the JS runtime before
 * knowing how to configure it.
 *
 * ```json
 * {
 *   "phext": {
 *     "cacheDir": "public/cache",
 *     "ttl": 3600,
 *     "render": "renderToString",
 *     "aot": ["some-other-pinned-dependency"]
 *   }
 * }
 * ```
 *
 * Every key is optional and the defaults are what a static site wants: cache
 * under `public/cache` so a web server can serve a hit without PHP, and no
 * TTL, because a site whose content changes only when you rebuild has nothing
 * to expire.
 */
final class Config
{
    private function __construct(
        public readonly string $root,
        public readonly string $cacheDir,
        public readonly ?int $ttl,
        public readonly string $render,
    ) {
    }

    public static function load(string $root): self
    {
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \InvalidArgumentException("No such directory: $root");
        }
        $settings = [];
        $file = $resolved . '/package.json';
        if (is_file($file)) {
            $json = json_decode((string)file_get_contents($file), true);
            $settings = is_array($json['phext'] ?? null) ? $json['phext'] : [];
        }

        $cacheDir = is_string($settings['cacheDir'] ?? null) ? $settings['cacheDir'] : 'public/cache';
        $render = $settings['render'] ?? 'renderToString';
        if ($render !== 'renderToString' && $render !== 'renderToStaticMarkup') {
            throw new \InvalidArgumentException(
                "phext.render must be renderToString or renderToStaticMarkup, got: " . var_export($render, true)
            );
        }

        return new self(
            $resolved,
            self::absolute($resolved, $cacheDir),
            isset($settings['ttl']) ? (int)$settings['ttl'] : null,
            $render,
        );
    }

    /** @param ?string $cacheDir override the configured cache, or '' for none */
    public function app(?string $cacheDir = null): App
    {
        $dir = $cacheDir ?? $this->cacheDir;
        return new App($this->root, $dir === '' ? null : $dir, $this->ttl, $this->render);
    }

    private static function absolute(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }
}

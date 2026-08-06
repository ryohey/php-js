<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Where everything lives, in one place.
 *
 * The package directory doubles as the module root, because that is where
 * both `app/` (the site's own TSX, `require`d directly now — see
 * `AppCompiler`) and `node_modules/` (where React lives) are, and the
 * runtime's filesystem sandbox is confined to it.
 */
final class Paths
{
    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    /** Module resolution root, and the filesystem sandbox root. */
    public static function appRoot(): string
    {
        return self::packageRoot();
    }

    public static function buildDir(): string
    {
        return self::packageRoot() . '/build';
    }

    /** Where on-demand rendered pages are cached; must be under the docroot. */
    public static function cacheDir(): string
    {
        return self::publicDir() . '/cache';
    }

    /** Default target for `phpjs-ssg package`. */
    public static function distributionDir(): string
    {
        return self::packageRoot() . '/package';
    }

    /** Default target for the static export. */
    public static function distDir(): string
    {
        return self::packageRoot() . '/dist';
    }

    public static function publicDir(): string
    {
        return self::packageRoot() . '/public';
    }

    public static function assetsDir(): string
    {
        return self::publicDir() . '/assets';
    }
}

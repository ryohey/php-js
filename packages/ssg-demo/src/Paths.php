<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Where everything lives, in one place.
 *
 * The package directory doubles as the module root, because that is where both
 * `bundle/` (what Vite wrote) and `node_modules/` (where React lives) are, and
 * the runtime's filesystem sandbox is confined to it.
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

    /** The Vite bundle the runtime loads, relative to the module root. */
    public static function entry(): string
    {
        return './bundle/entry.cjs';
    }

    /** Same components, rendered by Node, for the byte-identity check. */
    public static function nodeEntry(): string
    {
        return self::packageRoot() . '/bundle/entry.node.cjs';
    }
}

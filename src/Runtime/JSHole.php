<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * Internal sentinel for array-literal elisions ([1, , 3]). Never observable
 * from JS code: NEW_ARRAY consumes it while building the array.
 */
final class JSHole
{
    public static JSHole $hole;

    private function __construct()
    {
    }

    public static function init(): void
    {
        self::$hole ??= new self();
    }
}

JSHole::init();

<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * The singleton representing JS `undefined`. All other primitives map to
 * native PHP values (see DESIGN.md §3); only undefined needs a sentinel
 * because PHP null is taken by JS null.
 */
final class JSUndefined
{
    public static JSUndefined $undefined;

    private function __construct()
    {
    }

    public static function init(): void
    {
        self::$undefined ??= new self();
    }
}

JSUndefined::init();

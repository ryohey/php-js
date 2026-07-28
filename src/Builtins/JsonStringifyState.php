<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\JSFunctionBase;

/** Mutable state threaded through one JSON.stringify call (15.12.3). */
final class JsonStringifyState
{
    public ?JSFunctionBase $replacerFn = null;
    /** @var list<string>|null explicit key list from an array replacer */
    public ?array $propertyList = null;
    public string $gap = '';
    public string $indent = '';
    /** @var \SplObjectStorage<\PhpJs\Runtime\JSObject, null> cycle-detection stack */
    public \SplObjectStorage $stack;

    public function __construct()
    {
        $this->stack = new \SplObjectStorage();
    }
}

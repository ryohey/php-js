<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * Environment record for captured variables (DESIGN.md §4.5). Uncaptured
 * variables live in frame local slots and never touch this class.
 */
final class JSEnv
{
    /** @var array<int, mixed> */
    public array $slots;

    public function __construct(
        public ?JSEnv $parent,
        int $size,
    ) {
        $this->slots = $size > 0 ? array_fill(0, $size, JSUndefined::$undefined) : [];
    }
}

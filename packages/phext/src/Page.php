<?php

declare(strict_types=1);

namespace PhpJs\Phext;

/** One rendered page. */
final class Page
{
    public function __construct(
        public readonly string $path,
        public readonly int $status,
        public readonly string $html,
        public readonly float $renderMs = 0.0,
    ) {
    }

    public function bytes(): int
    {
        return \strlen($this->html);
    }
}

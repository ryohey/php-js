<?php

declare(strict_types=1);

namespace PhpJs\Phext;

/** A route plus the parameter values a particular URL filled it in with. */
final class RouteMatch
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly Route $route,
        public readonly array $params = [],
    ) {
    }
}

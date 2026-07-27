<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/** A statically resolved variable binding (DESIGN.md §2.2). */
final class Binding
{
    public bool $captured = false;
    /** Local frame slot when not captured. */
    public int $slot = -1;
    /** Environment-record slot when captured. */
    public int $envIndex = -1;

    public function __construct(
        public Ctx $owner,
        public string $name,
        /** 'param' | 'var' | 'func' | 'catch' | 'self' */
        public string $kind,
        /** Parameter position (params keep positional slots even when captured). */
        public int $paramIndex = -1,
    ) {
    }
}

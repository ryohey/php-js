<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A String Iterator (22.1.5). Separate from JSArrayIterator because it steps by
 * code point, not by index: a surrogate pair is one iteration, which is the
 * whole reason `for (const c of s)` is not `for (i = 0; i < s.length; i++)`.
 *
 * `$index` counts UTF-16 code units, so it stays comparable with `length`.
 */
final class JSStringIterator extends JSObject
{
    public function __construct(
        ?JSObject $proto,
        /** Null once exhausted. */
        public ?string $target,
        public int $index = 0,
    ) {
        parent::__construct($proto);
        $this->className = 'String Iterator';
    }
}

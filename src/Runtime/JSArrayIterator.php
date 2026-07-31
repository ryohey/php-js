<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * An Array Iterator (23.1.5). Also serves `arguments`, which borrows
 * `Array.prototype.values`, and anything else array-like reached through it.
 *
 * The state is three plain fields rather than internal slots on $props, so it
 * stays out of `[[OwnPropertyKeys]]` and out of a heap snapshot's property
 * table (DESIGN.md §11.3). `$target` holds a JSObject, never a PHP value that
 * could not be re-created from one.
 */
final class JSArrayIterator extends JSObject
{
    public const KEYS = 'key';
    public const VALUES = 'value';
    public const ENTRIES = 'key+value';

    public function __construct(
        ?JSObject $proto,
        /** Null once exhausted, which is what makes `next` idempotent. */
        public ?JSObject $target,
        /** self::KEYS | self::VALUES | self::ENTRIES */
        public string $kind,
        public int $index = 0,
    ) {
        parent::__construct($proto);
        $this->className = 'Array Iterator';
    }
}

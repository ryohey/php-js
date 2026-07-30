<?php

declare(strict_types=1);

namespace PhpJs\Node;

use PhpJs\Runtime\JSObject;

/**
 * The object `Map.prototype.entries()` and friends return.
 *
 * Its state is a reference to the collection and a cursor, held as PHP fields
 * rather than as JS properties, because a real iterator exposes neither. Both
 * are values this package owns, so DESIGN.md §11.3 is satisfied — the rule
 * forbids *foreign* PHP objects on the JS heap, and `JSCollection` is a
 * `JSObject`.
 *
 * Walking the entry list by index, past tombstones, is what makes this agree
 * with `forEach`: entries appended during iteration are visited and deleted ones
 * are skipped.
 */
final class JSCollectionIterator extends JSObject
{
    public const KEYS = 'keys';
    public const VALUES = 'values';
    public const ENTRIES = 'entries';

    public int $cursor = 0;

    public function __construct(
        public readonly JSCollection $collection,
        /** @var self::KEYS|self::VALUES|self::ENTRIES */
        public readonly string $kind,
        ?JSObject $proto,
    ) {
        parent::__construct($proto);
        $this->className = 'Map Iterator';
    }
}

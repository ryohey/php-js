<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A builtin implemented in PHP. Holds only a string ID into BuiltinRegistry —
 * never a PHP callable — so the heap stays snapshot-safe (DESIGN.md §11).
 * $data carries per-instance JS-heap-safe state (e.g. the promise a resolve
 * function settles).
 */
final class JSNativeFunction extends JSFunctionBase
{
    public function __construct(
        public string $fnId,
        string $name,
        int $arity,
        ?JSObject $proto = null,
        /** Separate construct behavior; null means [[Construct]] = [[Call]]. */
        public ?string $ctorId = null,
        public mixed $data = null,
        /** One-shot guard for spec functions that may only run once. */
        public bool $alreadyCalled = false,
    ) {
        parent::__construct($proto);
        $this->name = $name;
        $this->arity = $arity;
    }
}

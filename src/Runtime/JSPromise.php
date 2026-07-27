<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/** Promise exotic object. State machine lives in PromiseBuiltins (native PHP). */
final class JSPromise extends JSObject
{
    public const PENDING = 0;
    public const FULFILLED = 1;
    public const REJECTED = 2;

    public int $state = self::PENDING;
    public mixed $result = null;
    /** @var list<array{0: mixed, 1: mixed, 2: JSPromise}> [onFulfilled, onRejected, chained] */
    public array $reactions = [];
    public bool $handled = false;
    /** Guards against double resolution (a resolve fn already consumed). */
    public bool $alreadyResolved = false;

    public function __construct(?JSObject $proto = null)
    {
        parent::__construct($proto);
        $this->className = 'Promise';
    }
}

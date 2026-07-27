<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/** Result of Function.prototype.bind. */
final class JSBoundFunction extends JSFunctionBase
{
    public function __construct(
        public JSFunctionBase $target,
        public mixed $boundThis,
        /** @var list<mixed> */
        public array $boundArgs,
        ?JSObject $proto = null,
    ) {
        parent::__construct($proto);
        $this->name = 'bound ' . $target->name;
        $this->arity = max(0, $target->arity - count($boundArgs));
    }
}

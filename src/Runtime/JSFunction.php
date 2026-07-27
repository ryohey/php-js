<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A bytecode (user-defined) function: a function template closed over its
 * defining environment. The .prototype property is materialized lazily —
 * most closures are never used as constructors.
 */
final class JSFunction extends JSFunctionBase
{
    private bool $protoMade = false;

    public function __construct(
        /** @var array<string, mixed> function template (plain array, shared) */
        public array $template,
        public ?JSEnv $env,
        public Realm $realm,
    ) {
        parent::__construct($realm->functionPrototype());
        $this->name = $template['name'];
        $this->arity = $template['nparams'];
    }

    protected function ensureOwn(string $key): void
    {
        if ($key === 'prototype' && !$this->protoMade) {
            $this->protoMade = true;
            $proto = new JSObject($this->realm->objectPrototype());
            $proto->defineOwnData('constructor', $this, self::W | self::C);
            $this->defineOwnData('prototype', $proto, self::W);
        } else {
            parent::ensureOwn($key);
        }
    }

    public function ensureAllOwn(): void
    {
        $this->ensureOwn('prototype');
        parent::ensureAllOwn();
    }
}

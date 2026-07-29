<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A bytecode (user-defined) function: a function template closed over its
 * defining environment. The .prototype property is materialized lazily —
 * most closures are never used as constructors.
 *
 * When the template carries a `nativeId` whose native is registered, calls run
 * that PHP body instead of the bytecode (docs/aot-php.md §3). It stays a
 * JSFunction either way, so `.prototype`, `[[Construct]]`, `.length` and
 * `.name` are unchanged — an ahead-of-time compiled function must not be
 * distinguishable from the one it replaced.
 */
final class JSFunction extends JSFunctionBase
{
    private bool $protoMade = false;
    /** Registered native implementing this function's body, or null. */
    public ?string $nativeId = null;

    public function __construct(
        /** @var array<string, mixed> function template (plain array, shared) */
        public array $template,
        public ?JSEnv $env,
        public Realm $realm,
    ) {
        parent::__construct($realm->functionPrototype());
        $this->name = $template['name'];
        $this->arity = $template['nparams'];
        $id = $template['nativeId'] ?? null;
        if ($id !== null && \PhpJs\Builtins\BuiltinRegistry::hasHost($id)) {
            $this->nativeId = $id;
        }
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

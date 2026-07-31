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
    /**
     * For an arrow function, the `this` of the frame that created it.
     *
     * An arrow has no `this` binding of its own, so rather than teach the
     * scope analyser to capture one, the value travels on the function object
     * -- which is where a bound function already keeps its receiver. Nested
     * arrows chain correctly for free: the inner one closes over the outer
     * one's frame, whose `this` is already the captured value.
     */
    public mixed $lexicalThis = null;

    public function __construct(
        /** @var array<string, mixed> function template (plain array, shared) */
        public array $template,
        public ?JSEnv $env,
        public Realm $realm,
    ) {
        parent::__construct($realm->functionPrototype());
        $this->isArrow = !empty($template['arrow']);
        $this->name = $template['name'];
        $this->arity = $template['length'] ?? $template['nparams'];
        $id = $template['nativeId'] ?? null;
        if ($id !== null && \PhpJs\Builtins\BuiltinRegistry::hasHost($id)) {
            $this->nativeId = $id;
        }
    }

    protected function ensureOwn(string $key): void
    {
        // An arrow has no `prototype` at all, which is what makes `new` on one
        // a TypeError rather than a call with an empty object.
        if ($key === 'prototype' && !$this->protoMade && !$this->isArrow) {
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
        if (!$this->isArrow) {
            $this->ensureOwn('prototype');
        }
        parent::ensureAllOwn();
    }
}

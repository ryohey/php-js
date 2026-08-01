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
    /**
     * `[[Call]]` on a class constructor is a TypeError -- it must be `new`ed.
     * Set from the template rather than inferred, because NEW_CLASS is the
     * only opcode that produces one and it already knows.
     */
    public bool $isClassConstructor = false;
    /**
     * `[[HomeObject]]` (15.7.9): the object `super.prop` / `super.method()`
     * resolve against -- the class's `.prototype` for an instance method, the
     * class (constructor) itself for a static one. Null for anything that was
     * never a class member, which is also what makes `super` outside a class
     * a compile-time refusal rather than a runtime null-check.
     */
    public ?JSObject $homeObject = null;
    /**
     * `[[Call]]` on a generator function does not run the body: it creates
     * and returns a Generator object instead (27.5.5.2), and the body only
     * starts on the first `.next()`. Set from the template, which is also
     * what the compiler's `analyzeFunction` uses to decide whether `yield`
     * is legal in this body at all.
     */
    public bool $isGenerator = false;
    /**
     * `async function`/`async () => {}`/`async m(){}`: `[[Call]]` returns a
     * Promise immediately rather than running the body straight through or
     * handing back a Generator object (`Vm::createAsyncCall`). Like an
     * arrow, has no `prototype` at all -- an async function is never
     * constructible either.
     */
    public bool $isAsync = false;

    public function __construct(
        /** @var array<string, mixed> function template (plain array, shared) */
        public array $template,
        public ?JSEnv $env,
        public Realm $realm,
        /**
         * Non-null only for a class constructor: the already-built prototype
         * object NEW_CLASS constructed from the superclass (or Object.prototype,
         * or null for `extends null`). Passed in rather than materialized lazily
         * like an ordinary function's, because .prototype has to exist -- with
         * non-writable/non-enumerable/non-configurable attributes an ordinary
         * function's never has -- before the class body's methods can be
         * attached to it.
         */
        ?JSObject $classProto = null,
    ) {
        parent::__construct($realm->functionPrototype());
        $this->isArrow = !empty($template['arrow']);
        $this->isGenerator = !empty($template['generator']);
        $this->isAsync = !empty($template['async']);
        $this->name = $template['name'];
        $this->arity = $template['length'] ?? $template['nparams'];
        $id = $template['nativeId'] ?? null;
        if ($id !== null && \PhpJs\Builtins\BuiltinRegistry::hasHost($id)) {
            $this->nativeId = $id;
        }
        if ($classProto !== null) {
            $this->isClassConstructor = !empty($template['classCtor']);
            $this->protoMade = true;
            $this->defineOwnData('prototype', $classProto, 0);
        }
    }

    protected function ensureOwn(string $key): void
    {
        // An arrow or an async function has no `prototype` at all, which is
        // what makes `new` on either a TypeError rather than a call with an
        // empty object.
        if ($key === 'prototype' && !$this->protoMade && !$this->isArrow && !$this->isAsync) {
            $this->protoMade = true;
            if ($this->isGenerator) {
                // No `constructor` link: OrdinaryFunctionCreate does not add
                // one for a generator's own (implicit) prototype (27.5.3).
                $proto = new JSObject($this->realm->generatorPrototype());
            } else {
                $proto = new JSObject($this->realm->objectPrototype());
                $proto->defineOwnData('constructor', $this, self::W | self::C);
            }
            $this->defineOwnData('prototype', $proto, self::W);
        } else {
            parent::ensureOwn($key);
        }
    }

    public function ensureAllOwn(): void
    {
        if (!$this->isArrow && !$this->isAsync) {
            $this->ensureOwn('prototype');
        }
        parent::ensureAllOwn();
    }
}

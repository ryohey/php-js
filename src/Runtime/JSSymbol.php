<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * A Symbol value.
 *
 * Symbol is the one ES2015 addition that a library polyfill cannot provide,
 * because it is a *primitive type* rather than a function: no amount of
 * JavaScript makes `typeof x` answer `"symbol"`. That is not academic. React
 * brands every element type with `Symbol.for("react.fragment")` and friends, and
 * its renderer dispatches on `typeof type === "string"` *before* it
 * identity-compares against those brands — so a symbol that is really a string
 * takes the host-element path, and `<></>` fails with `Invalid tag`. The engine
 * has to own the type.
 *
 * This is a symbol, not a `JSObject`: `typeof` is `"symbol"`, it has no
 * properties of its own, and `Conversions::toObject()` wraps it on demand the
 * way a string or a number is wrapped. Equality is PHP object identity, which
 * `TypeOps::strictEquals` already falls through to.
 *
 * **Symbol-keyed properties.** Property tables are PHP arrays keyed by string
 * (DESIGN.md §5), and reworking that into string-or-symbol keys would touch
 * every property operation in the engine. Instead each symbol owns one private
 * string — NUL-prefixed, so no key a program writes by hand collides with it in
 * practice — and `Vm::propertyKey()` is the single place that translation
 * happens. `JSObject::orderKeys()` then filters those keys out of every
 * enumeration, which is what makes symbol-keyed properties invisible to
 * `Object.keys`, `for-in`, `JSON.stringify` and `Object.getOwnPropertyNames`
 * without any of them knowing symbols exist.
 *
 * What this deliberately does not do is give the well-known symbols meaning.
 * `Symbol.iterator` exists as a value and works as a key, but this is an ES5.1
 * realm: there is no iteration protocol for it to hook into, exactly as
 * `@@species` has nothing to do (DESIGN.md §15). Code that looks for them finds
 * nothing, which is the answer it would get from an ES5 engine anyway.
 */
final class JSSymbol
{
    /**
     * Marks the private property key of a symbol. `\0` cannot begin an
     * identifier and no ordinary key starts with it; a program that goes out of
     * its way to build such a string can reach a symbol's slot, which is a
     * deliberate trade against reworking every property operation.
     */
    public const KEY_PREFIX = "\0@@";

    /** Unique per symbol; only ever meaningful inside a property table. */
    public readonly string $propertyKey;

    private static int $counter = 0;

    /**
     * @param ?string $description  the argument to `Symbol()`, if any
     * @param ?string $registryKey  set for `Symbol.for()`, which `Symbol.keyFor()` reads back
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $registryKey = null,
    ) {
        $this->propertyKey = self::KEY_PREFIX . (++self::$counter);
    }

    /** Whether a property key belongs to a symbol rather than to a name. */
    public static function isSymbolKey(string $key): bool
    {
        return isset($key[2]) && $key[0] === "\0" && $key[1] === '@' && $key[2] === '@';
    }

    /** `String(sym)` and `sym.toString()`; never an implicit conversion. */
    public function display(): string
    {
        return 'Symbol(' . ($this->description ?? '') . ')';
    }
}

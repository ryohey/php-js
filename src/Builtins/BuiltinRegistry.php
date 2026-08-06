<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

/**
 * Maps native function IDs (strings stored on the heap, DESIGN.md §5.2) to
 * PHP callables. Callable conventions:
 *   call behavior:      fn(Vm $vm, mixed $thisVal, array $args, ?JSNativeFunction $fn): mixed
 *   construct behavior: fn(Vm $vm, array $args, ?JSNativeFunction $fn): mixed
 */
final class BuiltinRegistry
{
    /** @var array<string, callable>|null */
    private static ?array $table = null;
    /** @var array<string, callable> host-supplied entries, kept across resets */
    private static array $hostTable = [];

    public static function get(string $id): callable
    {
        self::$table ??= self::build();
        return self::$table[$id]
            ?? self::$hostTable[$id]
            ?? throw new \LogicException("Unknown builtin function id: $id");
    }

    /**
     * Register host natives (a module loader, timers, an fs bridge) without
     * touching the core table. The heap only ever stores the string ID, so
     * host functions stay outside the snapshot-safe object graph the same way
     * builtins do (DESIGN.md §5.2).
     *
     * IDs are process-wide, matching the engine's own: a template compiled
     * against one set of host natives must not be run against another.
     *
     * @param array<string, callable> $entries
     */
    public static function registerHost(array $entries): void
    {
        foreach ($entries as $id => $fn) {
            if (isset(self::$hostTable[$id]) && self::$hostTable[$id] !== $fn) {
                throw new \LogicException("Host builtin '$id' is already registered");
            }
            self::$hostTable[$id] = $fn;
        }
    }

    public static function hasHost(string $id): bool
    {
        return isset(self::$hostTable[$id]);
    }

    private static function build(): array
    {
        return array_merge(
            GlobalBuiltins::entries(),
            ObjectBuiltins::entries(),
            FunctionBuiltins::entries(),
            ArrayBuiltins::entries(),
            StringBuiltins::entries(),
            NumberBuiltins::entries(),
            BooleanBuiltins::entries(),
            SymbolBuiltins::entries(),
            IteratorBuiltins::entries(),
            GeneratorBuiltins::entries(),
            MathBuiltins::entries(),
            JsonBuiltins::entries(),
            ErrorBuiltins::entries(),
            ConsoleBuiltins::entries(),
            RegExpBuiltins::entries(),
            DateBuiltins::entries(),
            PromiseBuiltins::entries(),
            AsyncBuiltins::entries(),
            ArrayBufferBuiltins::entries(),
            TypedArrayBuiltins::entries(),
            DataViewBuiltins::entries(),
            CollectionBuiltins::entries(),
        );
    }
}

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

    public static function get(string $id): callable
    {
        self::$table ??= self::build();
        return self::$table[$id]
            ?? throw new \LogicException("Unknown builtin function id: $id");
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
            MathBuiltins::entries(),
            JsonBuiltins::entries(),
            ErrorBuiltins::entries(),
            ConsoleBuiltins::entries(),
            RegExpBuiltins::entries(),
            DateBuiltins::entries(),
            PromiseBuiltins::entries(),
        );
    }
}

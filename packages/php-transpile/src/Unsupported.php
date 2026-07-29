<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

/**
 * Thrown when the emitter meets something it will not compile.
 *
 * This is a normal outcome, not an error: the function stays as bytecode and
 * the runtime keeps interpreting it. The message is the specification of what
 * the emitter does not cover yet, so it is worth being specific
 * (docs/aot-php.md §6 phase 2 — "record which construct rejected them").
 */
final class Unsupported extends \RuntimeException
{
    public static function node(object $node, string $why): self
    {
        return new self($node->getType() . ': ' . $why);
    }
}

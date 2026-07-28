<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/** Compile-time failure: parse error, unsupported syntax, or an early error. */
final class CompileError extends \Exception
{
    /**
     * True when the source used a construct outside the ES5.1 target rather
     * than being genuinely invalid. Callers (notably the test262 runner) treat
     * these as "cannot run this input" instead of an engine defect: the input
     * contract is that code is downleveled to ES5 first (DESIGN.md scope).
     */
    public bool $unsupportedSyntax = false;

    public static function unsupported(string $message): self
    {
        $e = new self($message);
        $e->unsupportedSyntax = true;
        return $e;
    }
}

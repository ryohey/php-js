<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

/**
 * Per-function compilation context: binding tables from the analysis pass and
 * emission state for the codegen pass. Produces one function template.
 */
final class Ctx
{
    // ---- analysis ----
    public ?Ctx $parent = null;
    public bool $isProgram = false;
    public bool $strict = false;
    public bool $usesArguments = false;
    public string $name = '';
    /** @var list<string> parameter names in positional order */
    public array $params = [];
    /** @var array<string, Binding> function-scoped names (params/vars/function decls) */
    public array $bindings = [];
    public ?Binding $selfBinding = null;
    /** @var list<Binding> catch-parameter bindings owned by this function */
    public array $extraBindings = [];
    /** @var list<object> FunctionDeclaration nodes hoisted to the prologue */
    public array $fnDecls = [];
    /** @var list<string> program-level var names (global object properties) */
    public array $globalDecls = [];
    /**
     * Environment slot backing each parameter position, or -1 when that
     * position is not aliased. Empty unless a mapped arguments object is needed.
     * @var array<int, int>
     */
    public array $argMap = [];
    public int $nparams = 0;
    public int $nlocals = 0;
    public int $nenv = 0;

    // ---- codegen ----
    /** @var list<int> */
    public array $code = [];
    /** @var list<mixed> */
    public array $consts = [];
    /** @var array<string, int> */
    private array $constMap = [];
    /** @var list<array<string, mixed>> child templates */
    public array $children = [];
    /** @var list<array{0: int, 1: int}> [pc, line] */
    public array $lines = [];
    public int $lastLine = -1;
    /**
     * Active protected regions, innermost last.
     * @var list<array{finalizer: ?object}>
     */
    public array $tryStack = [];
    /**
     * Break/continue targets, innermost last.
     * @var list<array{label: ?string, breaks: list<int>, continueTarget: int, isLoop: bool, tryDepth: int}>
     */
    public array $loopStack = [];
    /** @var list<int> recycled temp slots */
    private array $tempPool = [];

    public function emit(int ...$words): void
    {
        foreach ($words as $w) {
            $this->code[] = $w;
        }
    }

    /**
     * Emit an opcode whose LAST operand is a to-be-patched jump target;
     * $mid are operands preceding it. Returns the patch site.
     */
    public function emitJump(int $op, int ...$mid): int
    {
        $this->code[] = $op;
        foreach ($mid as $w) {
            $this->code[] = $w;
        }
        $this->code[] = -1;
        return count($this->code) - 1;
    }

    public function patch(int $site): void
    {
        $this->code[$site] = count($this->code);
    }

    public function patchTo(int $site, int $target): void
    {
        $this->code[$site] = $target;
    }

    public function here(): int
    {
        return count($this->code);
    }

    public function constIndex(mixed $v): int
    {
        $key = is_string($v) ? 's:' . $v : 'f:' . var_export($v, true);
        if (isset($this->constMap[$key])) {
            return $this->constMap[$key];
        }
        $this->consts[] = $v;
        return $this->constMap[$key] = count($this->consts) - 1;
    }

    public function markLine(int $line): void
    {
        if ($line !== $this->lastLine && $line > 0) {
            $this->lastLine = $line;
            $this->lines[] = [count($this->code), $line];
        }
    }

    public function tempAlloc(): int
    {
        if ($this->tempPool !== []) {
            return array_pop($this->tempPool);
        }
        return $this->nlocals++;
    }

    public function tempFree(int $slot): void
    {
        $this->tempPool[] = $slot;
    }

    /** @return array<string, mixed> the finished template (plain array, var_export-able) */
    public function toTemplate(): array
    {
        return [
            'name' => $this->name,
            'strict' => $this->strict,
            'nparams' => $this->nparams,
            'nlocals' => $this->nlocals,
            'nenv' => $this->nenv,
            'usesArgs' => $this->usesArguments,
            'argMap' => $this->argMap,
            'code' => $this->code,
            'consts' => $this->consts,
            'children' => $this->children,
            'lines' => $this->lines,
            'flags' => 0,
            // Set by Compiler's per-function hook; null for ordinary bytecode.
            'nativeId' => null,
        ];
    }
}

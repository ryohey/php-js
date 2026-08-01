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
    /**
     * An arrow function: no `this` of its own, no `prototype`, not
     * constructible. See DESIGN.md §2.5.
     */
    public bool $isArrow = false;
    /**
     * `function*`/`*method(){}`: `[[Call]]` creates a Generator object rather
     * than running the body (DESIGN.md §2.5). `yield` never needs a context
     * flag to validate its placement -- Peast itself refuses it anywhere but
     * directly in a generator's own body, never even inside a nested arrow.
     */
    public bool $isGenerator = false;
    /**
     * A class's constructor: `[[Call]]` is refused (must be `new`ed), and
     * `.prototype` is non-writable/non-enumerable/non-configurable rather than
     * the writable slot an ordinary function gets.
     */
    public bool $isClassConstructor = false;
    /**
     * Directly inside a class method or the constructor -- not a nested
     * ordinary function, and not (yet) an arrow, which would need to inherit
     * this the way it inherits `this` and is refused instead (DESIGN.md §2.5).
     * Enables `super.prop` / `super.method()`, which read the current
     * function's `[[HomeObject]]`.
     */
    public bool $inClassMethod = false;
    /**
     * Directly the constructor of a class with an `extends` clause. Enables
     * `super(...)`, which is a SyntaxError anywhere else -- including a
     * non-derived constructor, which has no superclass to call.
     */
    public bool $isDerivedConstructor = false;
    /** For `x => expr`, the expression standing in for a body. */
    public ?object $arrowBody = null;
    /**
     * Parameter index => its default's initializer. The parameter still takes
     * its positional slot; the prologue overwrites it when the caller passed
     * nothing.
     * @var array<int, object>
     */
    public array $paramInits = [];
    /** Name of the rest parameter, which takes no positional slot. */
    public ?string $restParam = null;
    /**
     * Parameter index => its destructuring pattern. The position holds the
     * argument under a name no source can reach; the prologue unpacks it into
     * the names the pattern declares.
     * @var array<int, object>
     */
    public array $paramPatterns = [];
    /**
     * Reported `length`: parameters before the first default or rest. Distinct
     * from nparams, which is how many slots receive positional arguments.
     */
    public int $length = 0;
    public string $name = '';
    /** @var list<string> parameter names in positional order */
    public array $params = [];
    /** @var array<string, Binding> function-scoped names (params/vars/function decls) */
    public array $bindings = [];
    public ?Binding $selfBinding = null;
    /** @var list<Binding> catch-parameter bindings owned by this function */
    public array $extraBindings = [];
    /**
     * CatchClause node -> its parameter binding, for consumers that work from
     * the AST rather than the bytecode (the ahead-of-time PHP compiler needs
     * the slot the analysis assigned; see docs/aot-php.md §3).
     * @var \SplObjectStorage<object, Binding>
     */
    public \SplObjectStorage $catchBindings;

    public function __construct()
    {
        $this->catchBindings = new \SplObjectStorage();
    }
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
            'length' => $this->length,
            'nlocals' => $this->nlocals,
            'nenv' => $this->nenv,
            'usesArgs' => $this->usesArguments,
            'arrow' => $this->isArrow,
            'generator' => $this->isGenerator,
            'classCtor' => $this->isClassConstructor,
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

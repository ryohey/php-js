<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

use Peast\Peast;
use Peast\Syntax\Node\BigIntLiteral;
use Peast\Syntax\Node\BooleanLiteral;
use Peast\Syntax\Node\NullLiteral;
use Peast\Syntax\Node\NumericLiteral;
use Peast\Syntax\Node\RegExpLiteral;
use Peast\Syntax\Node\StringLiteral;
use PhpJs\RegExp\RegExpSyntaxError;
use PhpJs\RegExp\RegExpTranslator;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\StringOps;
use PhpJs\Vm\Op;

/**
 * AST -> bytecode compiler (DESIGN.md §2). Two passes per function:
 * scope analysis (binding classification + capture detection), then direct
 * code emission. Produces var_export-able function templates.
 */
final class Compiler
{
    /**
     * The name a derived class's constructor closes over to find its parent
     * constructor for `super(...)`. Prefixed with a NUL the way a pattern
     * parameter's synthetic name is, so no source identifier can collide with
     * it, and scoped to one class body via the same lexStack push/pop
     * enterBlock uses for a block's bindings.
     */
    private const SUPERCLASS_SLOT = "\0superclass";

    /** @var \SplObjectStorage<object, Ctx> function node -> analyzed context */
    private \SplObjectStorage $fnCtx;
    /**
     * CatchClause -> its parameter's bindings, keyed by name -- one entry for
     * a plain identifier, one per bound name for a destructuring pattern.
     * Absent for a handler with no parameter at all (optional catch binding).
     * @var \SplObjectStorage<object, array<string, Binding>>
     */
    private \SplObjectStorage $catchBind;
    /**
     * ClassDeclaration/ClassExpression node -> its synthesized default
     * constructor's FunctionExpression, for a class that wrote none (12.2.2).
     * Parsed once per class in the analysis pass and looked up again in
     * codegen, the same arrangement every other node-keyed table here uses --
     * the node object is the only thing that ties one pass's work to the
     * other's.
     * @var \SplObjectStorage<object, object>
     */
    private \SplObjectStorage $syntheticCtors;
    /** @var list<array{ctx: Ctx, names: array<string, Binding>}> */
    private array $lexStack = [];
    private Ctx $cur;
    /** @var list<string> labels waiting to attach to the next statement */
    private array $pendingLabels = [];
    /**
     * Optional per-function hook: `fn(object $node, Ctx $ctx, bool $isProgram): ?string`.
     * Returning a native function ID stamps it on the template as `nativeId`,
     * which makes NEW_FUNC instantiate that native instead of a bytecode
     * function when the ID is registered at run time (docs/aot-php.md §3).
     * The bytecode is still emitted either way, so a template compiled with a
     * hook runs unchanged on a runtime that has no such native.
     */
    private $onFunction = null;

    /**
     * A tagged template's cache key (GetTemplateObject, 13.2.8.3) has to
     * distinguish this compilation from any other one that happened to
     * produce identical source text -- two `eval` calls of the same string
     * must each get their own template object (test262
     * cache-identical-source-eval.js). One counter per process, bumped once
     * per `compile()` call, does that: every call site within a compilation
     * gets a local index (`$nextTemplateIndex`, below) under this ID, and no
     * two compilations ever share an ID to collide the indices under.
     */
    private static int $nextCompilationId = 0;
    private int $compilationId;
    /** Local index of the next tagged-template call site within this compilation. */
    private int $nextTemplateIndex = 0;

    private function __construct()
    {
        $this->blockScopes = new \SplObjectStorage();
        $this->fnCtx = new \SplObjectStorage();
        $this->catchBind = new \SplObjectStorage();
        $this->syntheticCtors = new \SplObjectStorage();
        $this->loopEnvScopes = new \SplObjectStorage();
        $this->compilationId = self::$nextCompilationId++;
    }

    /**
     * @param null|callable(object, Ctx, bool): ?string $onFunction
     * @return array<string, mixed> program template
     */
    public static function compile(string $source, ?callable $onFunction = null): array
    {
        $c = new self();
        $c->onFunction = $onFunction;
        try {
            $ast = Peast::latest($source, ['sourceType' => 'script'])->parse();
        } catch (\Throwable $e) {
            throw new CompileError('SyntaxError: ' . $e->getMessage(), 0, $e);
        }
        $ctx = $c->analyzeFunction($ast, null, true);
        return $c->genFunction($ctx, $ast, true);
    }

    // =========================================================================
    // Pass 1: scope analysis
    // =========================================================================

    /**
     * @param string|null $inferredName The name to give the function
     *     (SetFunctionName), when $node does not already carry one of its
     *     own: always non-null for a class method or constructor, whose
     *     FunctionExpression is anonymous in the AST by grammar; passed by
     *     `analyzeMaybeNamed` for every other NamedEvaluation trigger
     *     (8.6.2) -- a `var`/`let`/`const` declarator, a plain assignment to
     *     an identifier, a parameter default, a non-computed object-literal
     *     property -- but only when $node is genuinely anonymous there too.
     */
    private function analyzeFunction(
        object $node,
        Ctx|EnvScope|null $parent,
        bool $isProgram,
        bool $inClassMethod = false,
        bool $isDerivedConstructor = false,
        bool $isClassConstructor = false,
        ?string $inferredName = null,
    ): Ctx {
        // Both are compile-time position, not lexical nesting: a nested
        // function's own body is a fresh call each time regardless of which
        // iteration of an enclosing loop declared it, so neither an outer
        // loop's depth nor its still-open EnvScope may leak into this
        // function's own analysis (a `let` in *this* function's own loop is
        // not automatically "in a loop" just because the function itself
        // happens to sit inside one, and this function's own loops parent
        // themselves on its own Ctx, not on the enclosing function's).
        $savedLoopDepth = $this->loopDepth;
        $savedLoopEnvStack = $this->loopEnvStack;
        $this->loopDepth = 0;
        $this->loopEnvStack = [];

        $ctx = new Ctx();
        $ctx->parent = $parent;
        $ctx->isProgram = $isProgram;
        // Strictness is a function-level concept -- a loop layer in between
        // (a class or function declared inside a loop with a per-iteration
        // environment) has no strictness of its own to inherit, so this
        // walks through any to the nearest real enclosing function.
        $ctx->strict = (self::nearestCtx($parent)?->strict ?? false) || $inClassMethod;
        $ctx->inClassMethod = $inClassMethod;
        $ctx->isDerivedConstructor = $isDerivedConstructor;
        $ctx->isClassConstructor = $isClassConstructor;

        if ($isProgram) {
            $body = $node->getBody();
        } else {
            $ctx->isArrow = $node->getType() === 'ArrowFunctionExpression';
            // Arrow functions cannot be generators (no such syntax exists),
            // so this is always exactly `function*`/`*method(){}`.
            $ctx->isGenerator = $node->getGenerator();
            $ctx->isAsync = method_exists($node, 'getAsync') && $node->getAsync();
            if ($ctx->isAsync && $ctx->isGenerator) {
                $this->unsupported($node, 'Async generators are not supported');
            }
            $ctx->name = $inferredName
                ?? ((!$ctx->isArrow && $node->getId() !== null) ? $node->getId()->getName() : '');
            $bodyNode = $node->getBody();
            if ($bodyNode->getType() === 'BlockStatement') {
                $body = $bodyNode->getBody();
            } elseif ($ctx->isArrow) {
                // `x => expr` is `x => { return expr; }`; the expression is
                // analysed here and re-found by genFunction.
                $body = [];
                $ctx->arrowBody = $bodyNode;
            } else {
                $this->unsupported($node, 'Expression-bodied functions are not supported');
            }
            // A body directive makes the parameter list strict too, so
            // strictness has to be known before the parameters are bound.
            $ctx->strict = $ctx->strict || $this->hasUseStrict($body);
            if ($ctx->strict && $node->getId() !== null) {
                $this->checkBindingName($ctx, $node->getId()->getName(), $node);
            }
            // No default and no rest seen yet, which is exactly the rule for
            // `length`: a pattern parameter does not stop the count, it only
            // makes the list non-simple (which $strictList tracks separately).
            $simple = true;
            $boundNames = [];    // every name the list binds, patterns included
            foreach ($node->getParams() as $p) {
                $init = null;
                $isRest = false;
                if ($p->getType() === 'AssignmentPattern') {
                    $init = $p->getRight();
                    $p = $p->getLeft();
                } elseif ($p->getType() === 'RestElement') {
                    $isRest = true;
                    $p = $p->getArgument();
                    if ($ctx->restParam !== null) {
                        $this->fail($p, 'Only one rest parameter is allowed');
                    }
                }
                // A pattern parameter binds names of its own and takes the
                // position under a name no source can reach, which the prologue
                // unpacks. A rest element still has to be a plain name: its
                // array is built by REST_ARGS, not destructured.
                $pattern = null;
                if (self::isPattern($p)) {
                    if ($isRest) {
                        $this->unsupported($p, 'A rest parameter cannot be a pattern');
                    }
                    $pattern = $p;
                    $name = "\0param" . count($ctx->params);
                } else {
                    if ($p->getType() !== 'Identifier') {
                        $this->unsupported($p, 'Unsupported parameter: ' . $p->getType());
                    }
                    $name = $p->getName();
                }
                // A sloppy function may repeat a parameter name, but only while
                // its list stays simple; an arrow never may (14.2.1, 15.1.3).
                // A pattern anywhere makes the list non-simple.
                $strictList = $ctx->strict || $ctx->isArrow || !$simple || $init !== null || $isRest
                    || $ctx->paramPatterns !== [] || $pattern !== null;
                foreach ($pattern !== null ? $this->patternNames($pattern) : [$name] as $bound) {
                    if ($strictList && in_array($bound, $boundNames, true)) {
                        $this->fail($p, "Duplicate parameter name '$bound' is not allowed here");
                    }
                    // checkBindingName gates its own strict-only rules, so the
                    // reserved-word check runs in both modes.
                    $this->checkBindingName($ctx, $bound, $p);
                    $boundNames[] = $bound;
                }
                if ($pattern !== null) {
                    $ctx->paramPatterns[count($ctx->params)] = $pattern;
                    foreach ($this->patternNames($pattern) as $bound) {
                        // Not positional: filled by the prologue, like the rest
                        // parameter's array.
                        $ctx->bindings[$bound] = new Binding($ctx, $bound, 'var');
                    }
                }
                if ($isRest) {
                    $simple = false;
                    $ctx->restParam = $name;
                    // Not a positional slot: it is built in the prologue from
                    // the argument list, which is why the list has to be kept.
                    $ctx->usesArguments = true;
                    $ctx->bindings[$name] = new Binding($ctx, $name, 'var');
                    continue;
                }
                if ($init !== null) {
                    $ctx->paramInits[count($ctx->params)] = $init;
                }
                if ($simple && $init === null) {
                    $ctx->length++;
                }
                if ($init !== null) {
                    $simple = false;
                }
                $ctx->bindings[$name] = new Binding($ctx, $name, 'param', count($ctx->params));
                $ctx->params[] = $name;
            }
        }
        // A body directive may not follow a parameter list carrying defaults or
        // a rest element (14.1.2): the directive would change how the list is
        // read after it has already been read.
        if (!$isProgram
            && ($ctx->paramInits !== [] || $ctx->restParam !== null || $ctx->paramPatterns !== [])
            && $this->hasUseStrict($body)) {
            $this->fail($node, "'use strict' is not allowed in a function with a non-simple parameter list");
        }
        $ctx->strict = $ctx->strict || $this->hasUseStrict($body);

        // 9.2.12 FunctionDeclarationInstantiation: a default or a destructuring
        // pattern anywhere in the parameter list (a bare rest parameter alone
        // does not count -- nothing then distinguishes the two environments)
        // gives the body its own variable environment, a child of the one the
        // parameters live in. Decided before hoist() so it can route the
        // body's own var/function declarations into $ctx->bodyBindings
        // instead of $ctx->bindings.
        if ($ctx->paramInits !== [] || $ctx->paramPatterns !== []) {
            $ctx->paramEnvScope = new EnvScope($ctx);
        }

        $this->hoist($body, $ctx);

        // Named function expressions bind their own name in an outer mini-scope.
        $selfNames = [];
        if (!$isProgram && $node->getType() === 'FunctionExpression' && $node->getId() !== null) {
            $name = $node->getId()->getName();
            if (!isset($ctx->bindings[$name]) && !isset($ctx->bodyBindings[$name])) {
                $ctx->selfBinding = new Binding($ctx, $name, 'self');
                $selfNames = [$name => $ctx->selfBinding];
            }
        }

        if ($selfNames !== []) {
            $this->lexStack[] = ['ctx' => $ctx, 'names' => $selfNames];
        }
        $this->lexStack[] = ['ctx' => $ctx, 'names' => $ctx->bindings];
        // A function body and a program are blocks too: `let` at their top
        // level belongs to them, not to the surrounding var scope.
        $bodyOwner = $isProgram ? $node : ($ctx->arrowBody !== null ? null : $node->getBody());
        $paramNames = $boundNames ?? [];
        if ($ctx->restParam !== null) {
            $paramNames[] = $ctx->restParam;
        }
        // The body proper -- its own var/function declarations, and (via
        // enterBlock reading $this->loopEnvStack below) any let/const/class at
        // its own top level too, since those live in the same varEnv per spec
        // -- resolves against $ctx->bodyBindings first. Scoped to exactly the
        // statement list: a parameter default or pattern is evaluated after
        // this pops, in paramEnv, where none of these names are visible.
        if ($ctx->paramEnvScope !== null) {
            $this->lexStack[] = ['ctx' => $ctx, 'names' => $ctx->bodyBindings];
            $this->loopEnvStack[] = $ctx->paramEnvScope;
        }
        $bodyBlock = $bodyOwner !== null
            && $this->enterBlock($bodyOwner, $body, $ctx, true, $paramNames);
        foreach ($body as $stmt) {
            $this->analyzeNode($stmt, $ctx);
        }
        if ($bodyBlock) {
            array_pop($this->lexStack);
        }
        if ($ctx->paramEnvScope !== null) {
            array_pop($this->loopEnvStack);
            array_pop($this->lexStack);
        }
        foreach ($ctx->paramInits as $index => $init) {
            $this->checkInitializerOrder($ctx, $index, $init);
            $this->analyzeMaybeNamed($init, $ctx, $ctx->params[$index]);
        }
        // A pattern's own computed keys and defaults are expressions evaluated
        // in the function's scope, like a parameter default.
        foreach ($ctx->paramPatterns as $pattern) {
            $this->analyzePattern($pattern, $ctx);
        }
        if ($ctx->arrowBody !== null) {
            $this->analyzeNode($ctx->arrowBody, $ctx);
        }
        array_pop($this->lexStack);
        if ($selfNames !== []) {
            array_pop($this->lexStack);
        }

        $this->assignSlots($ctx);
        if (!$isProgram) {
            $this->fnCtx[$node] = $ctx;
        }
        $this->loopDepth = $savedLoopDepth;
        $this->loopEnvStack = $savedLoopEnvStack;
        return $ctx;
    }

    /**
     * A class declaration or expression. The superclass expression (if any)
     * is evaluated in the *enclosing* scope -- `extends` cannot see the
     * class's own name or `super(...)`'s target, both of which exist only
     * from here down.
     *
     * Every method (constructor included) closes over up to two names pushed
     * onto `lexStack` for exactly this class body: the superclass reference
     * `super(...)` resolves against, and -- for a named class expression only
     * -- the class's own name, visible inside the body the way a named
     * function expression's is (`$ctx->selfBinding`), but shared across every
     * method rather than owned by one function's `Ctx`.
     */
    private function analyzeClass(object $node, Ctx $ctx, ?string $inferredName = null): void
    {
        $superClass = $node->getSuperClass();
        if ($superClass !== null) {
            $this->analyzeNode($superClass, $ctx);
        }

        $names = [];
        if ($superClass !== null) {
            $superBinding = new Binding($ctx, self::SUPERCLASS_SLOT, 'var');
            // A class declared inside a loop and captured by a closure --
            // e.g. a method that outlives its own iteration -- needs the
            // same per-iteration treatment as a captured `let`, the same as
            // enterBlock already tags for every other binding.
            $superBinding->inLoop = $this->loopDepth > 0;
            $superBinding->envScope = end($this->loopEnvStack) ?: null;
            $ctx->extraBindings[] = $superBinding;
            $names[self::SUPERCLASS_SLOT] = $superBinding;
        }
        if ($node->getType() === 'ClassExpression' && $node->getId() !== null) {
            $selfName = $node->getId()->getName();
            // Always strict, for the reason enterBlock's 'class' case is.
            $this->checkIdentifierName($selfName, true, $node->getId());
            $selfBinding = new Binding($ctx, $selfName, 'class');
            $selfBinding->inLoop = $this->loopDepth > 0;
            $selfBinding->envScope = end($this->loopEnvStack) ?: null;
            $ctx->extraBindings[] = $selfBinding;
            $names[$selfName] = $selfBinding;
        }
        if ($names !== []) {
            // Recorded the way enterBlock records a block's bindings, so
            // genClass can push the very same Binding objects back onto
            // lexStack instead of building new ones (which assignSlots has
            // already assigned slots to, by the time codegen runs).
            $this->blockScopes[$node] = $names;
            $this->lexStack[] = ['ctx' => $ctx, 'names' => $names];
        }

        $className = $node->getId()?->getName() ?? $inferredName ?? '';
        $hasCtor = false;
        foreach ($node->getBody()->getBody() as $el) {
            $key = $el->getKey();
            if ($key->getType() === 'PrivateIdentifier') {
                // An object-model change (DESIGN.md §2.5's "deliberately out of
                // scope"): a private name is not a property key at all, it is
                // its own kind of slot with its own access rules.
                $this->unsupported($el, 'Private class members are not supported');
            }
            if ($el->getType() === 'PropertyDefinition') {
                $this->unsupported($el, 'Class fields are not supported yet');
            }
            if ($el->getComputed()) {
                $this->analyzeNode($key, $ctx);
            } else {
                // Two early errors that only apply to a literal key -- a
                // computed one is never known until it runs, so the spec does
                // not (and cannot) reach these for it (15.7.1).
                $literalKey = $this->propertyKeyString($key);
                $kind = $el->getKind();
                if (!$el->getStatic() && $literalKey === 'constructor' && ($kind === 'get' || $kind === 'set')) {
                    $this->fail($el, "Class constructor may not be an accessor");
                }
                if ($el->getStatic() && $literalKey === 'prototype') {
                    $this->fail($el, "Classes may not have a static property named 'prototype'");
                }
            }
            $isCtor = $el->getKind() === 'constructor';
            if ($isCtor) {
                if ($hasCtor) {
                    $this->fail($el, 'A class may only have one constructor');
                }
                $hasCtor = true;
            }
            // A computed key's name is not known until it runs (and might
            // never be a string at all), so it is left empty here -- codegen
            // (genClass) fills it in at run time with SET_FUNC_NAME instead
            // of this compile-time template field.
            $methodName = $isCtor ? $className
                : ($el->getComputed() ? null : $this->methodDisplayName($el));
            $this->analyzeFunction(
                $el->getValue(),
                $this->loopEnvParent($ctx),
                false,
                inClassMethod: true,
                isDerivedConstructor: $isCtor && $superClass !== null,
                isClassConstructor: $isCtor,
                inferredName: $methodName,
            );
        }
        if (!$hasCtor) {
            $ctorNode = self::parseExpression(
                $superClass !== null
                    ? '(function (...args) { super(...args); })'
                    : '(function () {})'
            );
            $this->syntheticCtors[$node] = $ctorNode;
            $this->analyzeFunction(
                $ctorNode,
                $this->loopEnvParent($ctx),
                false,
                inClassMethod: true,
                isDerivedConstructor: $superClass !== null,
                isClassConstructor: true,
                inferredName: $className,
            );
        }

        if ($names !== []) {
            array_pop($this->lexStack);
        }
    }

    /** Parse a single expression from a source fragment (used to synthesize AST nodes). */
    private static function parseExpression(string $src): object
    {
        $expr = Peast::latest($src . ';', ['sourceType' => 'script'])->parse()->getBody()[0]->getExpression();
        return self::unwrapParens($expr);
    }

    /** A method's `.name` (15.4.5): the key, `get `/`set `-prefixed for an accessor. */
    private function methodDisplayName(object $method): string
    {
        $name = $this->propertyKeyString($method->getKey());
        return match ($method->getKind()) {
            'get' => "get $name",
            'set' => "set $name",
            default => $name,
        };
    }

    /** Collect var/function declarations without descending into nested functions. */
    private function hoist(array $stmts, Ctx $ctx): void
    {
        foreach ($stmts as $stmt) {
            $this->hoistStmt($stmt, $ctx);
        }
    }

    private function hoistStmt(?object $node, Ctx $ctx): void
    {
        if ($node === null) {
            return;
        }
        switch ($node->getType()) {
            case 'VariableDeclaration':
                if ($node->getKind() !== 'var') {
                    // Block scoped, so nothing to hoist to the function: the
                    // owning block binds it (see enterBlock).
                    return;
                }
                foreach ($node->getDeclarations() as $d) {
                    $id = $d->getId();
                    foreach ($this->patternNames($id) as $name) {
                        $this->checkBindingName($ctx, $name, $id);
                        if ($ctx->isProgram) {
                            // Program-level vars are global object properties,
                            // not frame slots (visible across scripts).
                            if (!in_array($name, $ctx->globalDecls, true)) {
                                $ctx->globalDecls[] = $name;
                            }
                        } elseif ($ctx->paramEnvScope !== null) {
                            if (!isset($ctx->bodyBindings[$name])) {
                                $b = new Binding($ctx, $name, 'var');
                                $b->envScope = $ctx->paramEnvScope;
                                $ctx->bodyBindings[$name] = $b;
                            }
                        } elseif (!isset($ctx->bindings[$name])) {
                            $ctx->bindings[$name] = new Binding($ctx, $name, 'var');
                        }
                    }
                }
                break;
            case 'FunctionDeclaration':
                $name = $node->getId()->getName();
                $this->checkBindingName($ctx, $name, $node);
                if ($ctx->isProgram) {
                    if (!in_array($name, $ctx->globalDecls, true)) {
                        $ctx->globalDecls[] = $name;
                    }
                } elseif ($ctx->paramEnvScope !== null) {
                    $b = $ctx->bodyBindings[$name] ?? null;
                    if ($b === null || $b->kind === 'var') {
                        $b = new Binding($ctx, $name, 'func');
                        $b->envScope = $ctx->paramEnvScope;
                        $ctx->bodyBindings[$name] = $b;
                    }
                } else {
                    $b = $ctx->bindings[$name] ?? null;
                    if ($b === null || $b->kind === 'var') {
                        $ctx->bindings[$name] = new Binding($ctx, $name, 'func');
                    }
                }
                $ctx->fnDecls[] = $node;
                break;
            case 'BlockStatement':
                $this->hoist($node->getBody(), $ctx);
                break;
            case 'IfStatement':
                $this->hoistStmt($node->getConsequent(), $ctx);
                $this->hoistStmt($node->getAlternate(), $ctx);
                break;
            case 'ForStatement':
                if ($node->getInit() !== null && $node->getInit()->getType() === 'VariableDeclaration') {
                    $this->hoistStmt($node->getInit(), $ctx);
                }
                $this->hoistStmt($node->getBody(), $ctx);
                break;
            case 'ForInStatement':
            case 'ForOfStatement':
                if ($node->getLeft()->getType() === 'VariableDeclaration') {
                    $this->hoistStmt($node->getLeft(), $ctx);
                }
                $this->hoistStmt($node->getBody(), $ctx);
                break;
            case 'WhileStatement':
            case 'DoWhileStatement':
                $this->hoistStmt($node->getBody(), $ctx);
                break;
            case 'LabeledStatement':
                $this->hoistStmt($node->getBody(), $ctx);
                break;
            case 'SwitchStatement':
                foreach ($node->getCases() as $case) {
                    $this->hoist($case->getConsequent(), $ctx);
                }
                break;
            case 'TryStatement':
                $this->hoist($node->getBlock()->getBody(), $ctx);
                if ($node->getHandler() !== null) {
                    $this->hoist($node->getHandler()->getBody()->getBody(), $ctx);
                }
                if ($node->getFinalizer() !== null) {
                    $this->hoist($node->getFinalizer()->getBody(), $ctx);
                }
                break;
            default:
                break;
        }
    }

    private function analyzeNode(?object $node, Ctx $ctx): void
    {
        if ($node === null) {
            return;
        }
        $type = $node->getType();
        switch ($type) {
            case 'Identifier':
                $this->analyzeReference($node->getName(), $ctx, $node);
                return;
            case 'ParenthesizedExpression':
                $this->analyzeNode($node->getExpression(), $ctx);
                return;
            case 'Literal':
            case 'RegExpLiteral':
            case 'ThisExpression':
            case 'EmptyStatement':
            case 'DebuggerStatement':
            case 'BreakStatement':
            case 'ContinueStatement':
                return;
            case 'FunctionDeclaration':
            case 'FunctionExpression':
            case 'ArrowFunctionExpression':
                $this->analyzeFunction($node, $this->loopEnvParent($ctx), false);
                return;
            case 'ClassDeclaration':
            case 'ClassExpression':
                $this->analyzeClass($node, $ctx);
                return;
            case 'VariableDeclaration':
                foreach ($node->getDeclarations() as $d) {
                    $id = $d->getId();
                    if ($id->getType() === 'Identifier' && $d->getInit() !== null) {
                        $this->analyzeMaybeNamed($d->getInit(), $ctx, $id->getName());
                    } else {
                        $this->analyzeNode($d->getInit(), $ctx);
                    }
                    // A pattern carries expressions of its own -- computed keys
                    // and defaults -- which are evaluated where the pattern is.
                    $this->analyzePattern($d->getId(), $ctx);
                    if ($node->getKind() !== 'var') {
                        // Reaching the name binds it in the block that declared
                        // it, which enterBlock already created.
                        foreach ($this->patternNames($d->getId()) as $name) {
                            $this->analyzeReference($name, $ctx);
                        }
                    }
                }
                return;
            case 'MemberExpression':
                if ($node->getObject()->getType() === 'Super') {
                    $this->checkSuperAllowed($ctx, $node);
                } else {
                    $this->analyzeNode($node->getObject(), $ctx);
                }
                if ($node->getComputed()) {
                    $this->analyzeNode($node->getProperty(), $ctx);
                }
                return;
            case 'ChainExpression':
                // Optional chaining (`?.`) is a codegen-only concern -- which
                // steps in the chain might not run at runtime changes nothing
                // about which identifiers this subtree references or
                // captures, so analysis just sees through the wrapper.
                $this->analyzeNode($node->getExpression(), $ctx);
                return;
            case 'ObjectExpression':
                foreach ($node->getProperties() as $p) {
                    if ($p->getType() === 'SpreadElement') {
                        $this->analyzeNode($p->getArgument(), $ctx);
                        continue;
                    }
                    if ($p->getComputed()) {
                        $this->analyzeNode($p->getKey(), $ctx);
                        // The name (SetFunctionName's key, "get "/"set "
                        // prefix included) is a runtime value here; codegen's
                        // SET_FUNC_NAME handles it once the key is known.
                        $this->analyzeNode($p->getValue(), $ctx);
                        continue;
                    }
                    if ($p->getKind() === 'init') {
                        $this->analyzeMaybeNamed($p->getValue(), $ctx, $this->propertyKeyString($p->getKey()));
                    } else {
                        // A getter/setter's value is always an anonymous
                        // FunctionExpression by grammar -- there is no "own
                        // name" that could ever win over the key.
                        $this->analyzeFunction($p->getValue(), $this->loopEnvParent($ctx), false, inferredName: $this->methodDisplayName($p));
                    }
                }
                return;
            case 'SpreadElement':
                $this->analyzeNode($node->getArgument(), $ctx);
                return;
            case 'Property':
                $this->analyzeNode($node->getValue(), $ctx);
                return;
            case 'ArrayExpression':
                foreach ($node->getElements() as $el) {
                    if ($el !== null) {
                        $this->analyzeNode($el, $ctx);
                    }
                }
                return;
            case 'ExpressionStatement':
                $this->analyzeNode($node->getExpression(), $ctx);
                return;
            case 'SequenceExpression':
                foreach ($node->getExpressions() as $e) {
                    $this->analyzeNode($e, $ctx);
                }
                return;
            case 'AssignmentExpression': {
                $left = self::unwrapParens($node->getLeft());
                if (self::isPattern($left)) {
                    // Destructuring assignment: the leaves are references to
                    // existing bindings, not declarations of new ones.
                    $this->analyzePattern($left, $ctx, true);
                } else {
                    $this->analyzeNode($left, $ctx);
                }
                // NamedEvaluation (8.6.2) only reaches a plain `=` to a bare
                // identifier -- not a compound operator, and not a member
                // expression, which is why `o.x = function(){}` stays
                // nameless. IsIdentifierRef checks the *original* left side,
                // not unwrapped: unlike the right side (where parens are
                // always transparent to naming), `(fn) = function(){}` stays
                // nameless too -- a parenthesized reference is not "an
                // IdentifierRef" even though it is still a legal assignment
                // target once evaluated as one (test262 fn-name-lhs-cover.js).
                $rawLeft = $node->getLeft();
                if ($node->getOperator() === '=' && $rawLeft->getType() === 'Identifier') {
                    $this->analyzeMaybeNamed($node->getRight(), $ctx, $rawLeft->getName());
                } else {
                    $this->analyzeNode($node->getRight(), $ctx);
                }
                return;
            }
            case 'BinaryExpression':
            case 'LogicalExpression':
                $this->analyzeNode($node->getLeft(), $ctx);
                $this->analyzeNode($node->getRight(), $ctx);
                return;
            case 'UnaryExpression':
            case 'UpdateExpression':
                $this->analyzeNode($node->getArgument(), $ctx);
                return;
            case 'YieldExpression':
            case 'AwaitExpression':
                $this->analyzeNode($node->getArgument(), $ctx);
                return;
            case 'ConditionalExpression':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getConsequent(), $ctx);
                $this->analyzeNode($node->getAlternate(), $ctx);
                return;
            case 'NewExpression':
                $this->analyzeNode($node->getCallee(), $ctx);
                foreach ($node->getArguments() as $a) {
                    $this->analyzeNode($a, $ctx);
                }
                return;
            case 'CallExpression': {
                $callee = self::unwrapParens($node->getCallee());
                if ($callee->getType() === 'Super') {
                    if (!$ctx->isDerivedConstructor) {
                        $this->fail($node, "'super' keyword is only valid inside a derived class constructor");
                    }
                    // The reference the codegen pass will resolve to find the
                    // parent constructor -- an ordinary closure capture of the
                    // name enterClass bound around the whole class body.
                    $this->analyzeReference(self::SUPERCLASS_SLOT, $ctx, $node);
                } elseif ($callee->getType() === 'MemberExpression' && $callee->getObject()->getType() === 'Super') {
                    $this->checkSuperAllowed($ctx, $callee);
                    if ($callee->getComputed()) {
                        $this->analyzeNode($callee->getProperty(), $ctx);
                    }
                } else {
                    $this->analyzeNode($callee, $ctx);
                }
                foreach ($node->getArguments() as $a) {
                    $this->analyzeNode($a, $ctx);
                }
                return;
            }
            case 'TaggedTemplateExpression':
                $this->analyzeNode($node->getTag(), $ctx);
                foreach ($node->getQuasi()->getExpressions() as $expr) {
                    $this->analyzeNode($expr, $ctx);
                }
                return;
            case 'BlockStatement': {
                $opened = $this->enterBlock($node, $node->getBody(), $ctx, true);
                foreach ($node->getBody() as $s) {
                    $this->analyzeNode($s, $ctx);
                }
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'IfStatement':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getConsequent(), $ctx);
                $this->analyzeNode($node->getAlternate(), $ctx);
                return;
            case 'ForStatement': {
                // `for (let i …)` binds `i` to the loop, not to the enclosing
                // scope, so the head gets a scope of its own around everything.
                $init = $node->getInit();
                $head = $init !== null && $init->getType() === 'VariableDeclaration'
                    && $init->getKind() !== 'var';
                // The head's binding is per-iteration too -- the loop counts as
                // entered before it is created, so a closure over it is caught
                // by the same check as one over a `let` in the body.
                $this->loopDepth++;
                $iterScope = $this->enterLoopEnv($ctx);
                // The body's vars land in the enclosing function but are visible
                // through the head's scope, so they cannot share a name with it.
                $opened = $head && $this->enterBlock(
                    $node,
                    [$init],
                    $ctx,
                    true,
                    array_keys($this->varNamesIn([$node->getBody()], false))
                );
                $this->analyzeNode($init, $ctx);
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getUpdate(), $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
                $this->leaveLoopEnv($node, $iterScope);
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'ForInStatement': {
                // Same shape as for-of: the object is evaluated outside the
                // loop's scope, and a lexical head binds afresh each pass.
                $this->analyzeNode($node->getRight(), $ctx);
                $left = $node->getLeft();
                $head = $left->getType() === 'VariableDeclaration' && $left->getKind() !== 'var';
                $this->loopDepth++;
                $iterScope = $this->enterLoopEnv($ctx);
                $opened = $head && $this->enterBlock(
                    $node,
                    [$left],
                    $ctx,
                    true,
                    array_keys($this->varNamesIn([$node->getBody()], false)),
                    false
                );
                $this->analyzeForTarget($left, $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
                $this->leaveLoopEnv($node, $iterScope);
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'ForOfStatement': {
                if ($node->getAwait()) {
                    $this->unsupported($node, "'for await' is not supported yet");
                }
                // The iterable is evaluated before the loop's scope exists, so
                // the head's binding cannot be in scope for it.
                $this->analyzeNode($node->getRight(), $ctx);
                $left = $node->getLeft();
                $head = $left->getType() === 'VariableDeclaration' && $left->getKind() !== 'var';
                // A for-of head binds afresh on every pass, so it counts as
                // being inside the loop for the capture check.
                $this->loopDepth++;
                $iterScope = $this->enterLoopEnv($ctx);
                $opened = $head && $this->enterBlock(
                    $node,
                    [$left],
                    $ctx,
                    true,
                    array_keys($this->varNamesIn([$node->getBody()], false)),
                    false
                );
                $this->analyzeForTarget($left, $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
                $this->leaveLoopEnv($node, $iterScope);
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'WhileStatement':
            case 'DoWhileStatement':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->loopDepth++;
                $iterScope = $this->enterLoopEnv($ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
                $this->leaveLoopEnv($node, $iterScope);
                return;
            case 'SwitchStatement': {
                // The discriminant is evaluated before the case block is
                // entered, so it sees the outer scope; the case tests do not.
                // All the cases share one scope, which is why the lists are
                // merged rather than entered one at a time.
                $this->analyzeNode($node->getDiscriminant(), $ctx);
                $opened = $this->enterBlock($node, $this->switchBody($node), $ctx, true);
                foreach ($node->getCases() as $case) {
                    $this->analyzeNode($case->getTest(), $ctx);
                    foreach ($case->getConsequent() as $s) {
                        $this->analyzeNode($s, $ctx);
                    }
                }
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'ReturnStatement':
            case 'ThrowStatement':
                $this->analyzeNode($node->getArgument(), $ctx);
                return;
            case 'TryStatement':
                $this->analyzeNode($node->getBlock(), $ctx);
                if (($handler = $node->getHandler()) !== null) {
                    $param = $handler->getParam();
                    $body = $handler->getBody();
                    if ($param === null) {
                        // Optional catch binding: the thrown value is simply
                        // discarded, and the body is an ordinary block scope
                        // with nothing reserved in it.
                        $opened = $this->enterBlock($body, $body->getBody(), $ctx, true);
                        foreach ($body->getBody() as $s) {
                            $this->analyzeNode($s, $ctx);
                        }
                        if ($opened) {
                            array_pop($this->lexStack);
                        }
                        $this->analyzeNode($node->getFinalizer(), $ctx);
                        return;
                    }
                    $isPattern = self::isPattern($param);
                    if (!$isPattern && $param->getType() !== 'Identifier') {
                        $this->unsupported($param, 'Unsupported catch parameter: ' . $param->getType());
                    }
                    $catchNames = [];
                    foreach ($isPattern ? $this->patternNames($param) : [$param->getName()] as $name) {
                        if (isset($catchNames[$name])) {
                            $this->fail($param, "Identifier '$name' has already been declared");
                        }
                        $this->checkBindingName($ctx, $name, $param);
                        $b = new Binding($ctx, $name, 'catch');
                        $ctx->extraBindings[] = $b;
                        $catchNames[$name] = $b;
                    }
                    $this->catchBind[$handler] = $catchNames;
                    if (!$isPattern) {
                        $ctx->catchBindings[$handler] = $catchNames[$param->getName()];
                    }
                    $this->lexStack[] = ['ctx' => $ctx, 'names' => $catchNames];
                    // A pattern's own computed keys and defaults are
                    // expressions evaluated with the pattern's own names
                    // already visible, same as a `var`/`let` pattern's are --
                    // `catch ({a, b = a}) {}`'s `b` may reach `a`.
                    if ($isPattern) {
                        $this->analyzePattern($param, $ctx);
                    }
                    $opened = $this->enterBlock($body, $body->getBody(), $ctx, true, array_keys($catchNames));
                    foreach ($body->getBody() as $s) {
                        $this->analyzeNode($s, $ctx);
                    }
                    if ($opened) {
                        array_pop($this->lexStack);
                    }
                    array_pop($this->lexStack);
                }
                $this->analyzeNode($node->getFinalizer(), $ctx);
                return;
            case 'LabeledStatement':
                $this->analyzeNode($node->getBody(), $ctx);
                return;
            case 'TemplateLiteral':
                foreach ($node->getExpressions() as $expr) {
                    $this->analyzeNode($expr, $ctx);
                }
                return;
            case 'WithStatement':
                $this->unsupported($node, "'with' statements are not supported");
                // no break (fail throws)
            default:
                $this->unsupported($node, "Unsupported syntax: $type (ES5 target; downlevel first)");
        }
    }

    /**
     * 'eval' and 'arguments' may not be bound in strict mode (ES5.1 12.2.1,
     * 13.1). Called for var/function/parameter/catch bindings.
     */
    /**
     * A default may not reach a parameter declared after it.
     *
     * Parameters are in their own temporal dead zone while the list is being
     * initialized, so `function f(b = a, a = 1)` is a ReferenceError at the
     * moment `b` is evaluated. Nothing here implements that dead zone yet, and
     * answering `undefined` would be silently wrong, so the shape is refused
     * outright.
     *
     * Deliberately conservative: it also refuses an initializer that only
     * mentions a later parameter inside a nested function, which the spec would
     * allow if that function is never called early. Rejecting a program that
     * almost certainly throws beats running one that quietly does not.
     */
    private function checkInitializerOrder(Ctx $ctx, int $index, object $init): void
    {
        $later = array_slice($ctx->params, $index);
        if ($ctx->restParam !== null) {
            $later[] = $ctx->restParam;
        }
        if ($later === []) {
            return;
        }
        $traverser = new \Peast\Traverser();
        $traverser->addFunction(function ($node) use ($later, $init): void {
            if ($node->getType() === 'Identifier' && in_array($node->getName(), $later, true)) {
                $this->fail(
                    $init,
                    "Parameter default cannot reference '{$node->getName()}', which is declared later"
                );
            }
        });
        $traverser->traverse($init);
    }

    /**
     * Block-scoped bindings, keyed by the statement list that owns them.
     *
     * Both passes walk the same tree and must agree exactly on what is in
     * scope where, so the bindings are created once during analysis and looked
     * up again during code generation rather than rebuilt. This is the same
     * arrangement catch parameters already use.
     */
    private \SplObjectStorage $blockScopes;
    /** Loop nesting during analysis; see Binding::$inLoop. */
    private int $loopDepth = 0;
    /**
     * Currently-open per-iteration loop layers, innermost last -- both
     * passes push and pop these around a qualifying loop the same way
     * `lexStack` brackets a block, so `envDepth`'s walk (and a nested
     * function's own `Ctx::$parent`, via `loopEnvParent`) sees exactly the
     * loop layers actually enclosing the current position.
     * @var list<EnvScope>
     */
    private array $loopEnvStack = [];
    /**
     * Codegen-only, pushed and popped around a `ChainExpression`'s own
     * wrapped expression (nesting for an optional chain used as, say, a call
     * argument inside an outer one): each entry collects the patch sites of
     * every `?.` short-circuit jump `genChain` finds while compiling that
     * one chain, so they can all be pointed at the single shared landing pad
     * once the whole chain is known.
     * @var list<list<int>>
     */
    private array $chainStack = [];
    /**
     * Loop node -> its EnvScope, decided once during analysis (`Compiler::
     * analyzeLoopEnv`) and looked up again during codegen (`Compiler::
     * genLoopEnvEnter`) -- the same node-keyed handoff `blockScopes`/`fnCtx`
     * use elsewhere. Every qualifying loop gets an entry, even one that turns
     * out not to need an environment at all (`size === 0`): codegen treats
     * that as "nothing to do here," rather than "nothing was decided yet."
     * @var \SplObjectStorage<object, EnvScope>
     */
    private \SplObjectStorage $loopEnvScopes;

    /**
     * The `let` and `const` names declared directly in a statement list.
     *
     * Only directly: a nested block owns its own, and `var` is not lexical.
     *
     * @param  list<object> $stmts
     * @return array<string, string> name => 'let' | 'const'
     */
    private function lexicalNames(array $stmts, bool $requireInit = true): array
    {
        $names = [];
        foreach ($stmts as $stmt) {
            if ($stmt->getType() === 'ClassDeclaration') {
                // A class binding is lexical too, and immutable like `const`
                // (12.2.1): `emitStoreName`'s const check already covers
                // 'class', so nothing else has to know it isn't 'const' itself.
                $id = $stmt->getId();
                $name = $id->getName();
                if (isset($names[$name])) {
                    $this->fail($id, "Identifier '$name' has already been declared");
                }
                $names[$name] = 'class';
                continue;
            }
            if ($stmt->getType() !== 'VariableDeclaration') {
                continue;
            }
            $kind = $stmt->getKind();
            if ($kind !== 'let' && $kind !== 'const') {
                continue;
            }
            foreach ($stmt->getDeclarations() as $d) {
                $id = $d->getId();
                if ($requireInit && $kind === 'const' && $d->getInit() === null) {
                    // A `for (const x of …)` head has no initializer: the
                    // iteration supplies one on every pass.
                    $this->fail($id, "Missing initializer in const declaration");
                }
                foreach ($this->patternNames($id) as $name) {
                    if ($name === 'let') {
                        // `let` is not a reserved word, but a lexical
                        // declaration may not bind it: `let let` has no
                        // unambiguous reading.
                        $this->fail($id, "'let' is not a valid name for a lexical declaration");
                    }
                    if (isset($names[$name])) {
                        $this->fail($id, "Identifier '$name' has already been declared");
                    }
                    $names[$name] = $kind;
                }
            }
        }
        return $names;
    }

    /**
     * Every name a binding target declares: the target itself when it is a
     * plain identifier, and otherwise the leaves of the pattern.
     *
     * This is the one place that decides what a pattern binds, so the four
     * callers that need it -- `var` hoisting, lexical declarations, the
     * var/lexical collision check and the parameter list -- cannot drift apart.
     * It is also where an unsupported pattern shape is refused, so a shape that
     * cannot be bound never reaches codegen.
     *
     * @return list<string>
     */
    private function patternNames(object $target): array
    {
        $names = [];
        $this->collectPatternNames($target, $names);
        return $names;
    }

    /** @param list<?object> $items */
    private static function hasSpread(array $items): bool
    {
        foreach ($items as $item) {
            if ($item !== null && $item->getType() === 'SpreadElement') {
                return true;
            }
        }
        return false;
    }

    /**
     * An argument list carrying a spread, as one array on the stack. Built with
     * the same append/spread pair as an array literal, so the two cannot
     * disagree about what spreading means.
     *
     * @param list<object> $args
     */
    private function genArgumentArray(array $args): void
    {
        $c = $this->cur;
        $c->emit(Op::NEW_ARRAY, 0);
        foreach ($args as $a) {
            if ($a->getType() === 'SpreadElement') {
                $this->genExpr($a->getArgument());
                $c->emit(Op::ARR_SPREAD);
                continue;
            }
            $this->genExpr($a);
            $c->emit(Op::ARR_APPEND);
        }
    }

    private static function isPattern(object $node): bool
    {
        $t = $node->getType();
        return $t === 'ObjectPattern' || $t === 'ArrayPattern';
    }

    /**
     * NamedEvaluation (8.6.2): analyze $node, giving it $name if -- and only
     * if -- it is a genuinely nameless function/arrow/class expression.
     * SetFunctionName never overrides a name the expression already carries
     * of its own (`var f = function foo(){}` keeps "foo", not "f"), so this
     * has to check before threading $name through the same mechanism a
     * class or object-literal method's own key already uses
     * (`analyzeFunction`'s `inferredName` / `analyzeClass`'s
     * `inferredName`). Parens are transparent to NamedEvaluation at any
     * depth -- `var f = ((function(){}))` still names it "f" -- so this
     * unwraps them, the same as `isAnonymousFunctionDefinition` does for
     * the codegen sites where the name is a runtime value instead (a
     * computed property/method key) and `SET_FUNC_NAME` has to do the
     * naming after the fact rather than the template already carrying it.
     */
    private function analyzeMaybeNamed(?object $node, Ctx $ctx, string $name): void
    {
        if ($node === null) {
            $this->analyzeNode($node, $ctx);
            return;
        }
        $inner = self::unwrapParens($node);
        $type = $inner->getType();
        if ($type === 'ArrowFunctionExpression') {
            $this->analyzeFunction($inner, $this->loopEnvParent($ctx), false, inferredName: $name);
            return;
        }
        if ($type === 'FunctionExpression') {
            if ($inner->getId() !== null) {
                $this->analyzeNode($inner, $ctx);
                return;
            }
            $this->analyzeFunction($inner, $this->loopEnvParent($ctx), false, inferredName: $name);
            return;
        }
        if ($type === 'ClassExpression') {
            if ($inner->getId() !== null) {
                $this->analyzeNode($inner, $ctx);
                return;
            }
            $this->analyzeClass($inner, $ctx, $name);
            return;
        }
        $this->analyzeNode($node, $ctx);
    }

    /**
     * IsAnonymousFunctionDefinition (8.6.1): is $node -- unwrapped of any
     * parens -- a function/arrow/class expression with no name of its own?
     * Used at the codegen sites where a NamedEvaluation-eligible name is
     * only known at run time (a computed property/method key), to decide
     * whether `SET_FUNC_NAME` is worth emitting at all; `analyzeMaybeNamed`
     * is the analysis-pass counterpart, for every other site, where the
     * name is a compile-time string that ends up baked into the function's
     * own template instead.
     */
    private static function isAnonymousFunctionDefinition(object $node): bool
    {
        $node = self::unwrapParens($node);
        $type = $node->getType();
        if ($type === 'ArrowFunctionExpression') {
            return true;
        }
        if ($type === 'FunctionExpression' || $type === 'ClassExpression') {
            return $node->getId() === null;
        }
        return false;
    }

    /**
     * The head of a `for…of` or `for…in`: either a declaration, whose names the
     * enclosing block already bound, or an assignment target.
     */
    private function analyzeForTarget(object $left, Ctx $ctx): void
    {
        if ($left->getType() === 'VariableDeclaration') {
            foreach ($left->getDeclarations() as $d) {
                $this->analyzePattern($d->getId(), $ctx);
                if ($left->getKind() !== 'var') {
                    foreach ($this->patternNames($d->getId()) as $name) {
                        $this->analyzeReference($name, $ctx);
                    }
                }
            }
            return;
        }
        $left = self::unwrapParens($left);
        if (self::isPattern($left)) {
            $this->analyzePattern($left, $ctx, true);
            return;
        }
        $this->analyzeNode($left, $ctx);
    }

    /**
     * Analyse the expressions a pattern carries: computed keys and defaults,
     * which are ordinary expressions evaluated where the pattern sits.
     *
     * With `$assigning` the leaves are assignment targets rather than
     * declarations, so they are references to bindings that already exist.
     */
    private function analyzePattern(?object $target, Ctx $ctx, bool $assigning = false): void
    {
        if ($target === null) {
            return;
        }
        switch ($target->getType()) {
            case 'ObjectPattern':
                foreach ($target->getProperties() as $p) {
                    if ($p->getType() === 'RestElement') {
                        $this->analyzePattern($p->getArgument(), $ctx, $assigning);
                        continue;
                    }
                    if ($p->getComputed()) {
                        $this->analyzeNode($p->getKey(), $ctx);
                    }
                    $this->analyzePattern($p->getValue(), $ctx, $assigning);
                }
                return;
            case 'AssignmentPattern': {
                $left = $target->getLeft();
                $this->analyzePattern($left, $ctx, $assigning);
                // NamedEvaluation only reaches a default on a bare
                // `SingleNameBinding` -- not one behind a nested pattern
                // (`[{x} = f]`'s `f` stays nameless; the default belongs to
                // the pattern `{x}`, not to a single name).
                if ($left->getType() === 'Identifier') {
                    $this->analyzeMaybeNamed($target->getRight(), $ctx, $left->getName());
                } else {
                    $this->analyzeNode($target->getRight(), $ctx);
                }
                return;
            }
            case 'RestElement':
                $this->analyzePattern($target->getArgument(), $ctx, $assigning);
                return;
            case 'Identifier':
                if ($assigning) {
                    $this->analyzeReference($target->getName(), $ctx, $target);
                }
                return;
            case 'MemberExpression':
                if (!$assigning) {
                    $this->unsupported($target, 'A member expression cannot be declared');
                }
                $this->analyzeNode($target, $ctx);
                return;
            case 'ArrayPattern':
                foreach ($target->getElements() as $el) {
                    $this->analyzePattern($el, $ctx, $assigning);
                }
                return;
            default:
                $this->unsupported($target, 'Unsupported binding target: ' . $target->getType());
        }
    }

    /** @param list<string> $names */
    private function collectPatternNames(?object $target, array &$names): void
    {
        if ($target === null) {
            return;                      // an elision, which binds nothing
        }
        switch ($target->getType()) {
            case 'Identifier':
                $names[] = $target->getName();
                return;
            case 'ObjectPattern':
                foreach ($target->getProperties() as $p) {
                    if ($p->getType() === 'RestElement') {
                        $this->collectPatternNames($p->getArgument(), $names);
                        continue;
                    }
                    $this->collectPatternNames($p->getValue(), $names);
                }
                return;
            case 'AssignmentPattern':
                $this->collectPatternNames($target->getLeft(), $names);
                return;
            case 'RestElement':
                $this->collectPatternNames($target->getArgument(), $names);
                return;
            case 'ArrayPattern':
                foreach ($target->getElements() as $el) {
                    $this->collectPatternNames($el, $names);   // null is an elision
                }
                return;
            default:
                $this->unsupported($target, 'Unsupported binding target: ' . $target->getType());
        }
    }

    /**
     * The names a statement list contributes to the enclosing var scope.
     *
     * A lexical name may not collide with one of these, and `var` hoists
     * straight through blocks, so the whole subtree counts -- but not nested
     * functions, which start a var scope of their own.
     *
     * Function declarations count only at the top of the list: a nested block
     * owns its own, so `let g; { function g() {} }` is legal while
     * `{ let g; function g() {} }` is not.
     *
     * @param  list<object>       $stmts
     * @return array<string, true>
     */
    private function varNamesIn(array $stmts, bool $top = true): array
    {
        $names = [];
        foreach ($stmts as $stmt) {
            $names += $this->varNamesInStmt($stmt, $top);
        }
        return $names;
    }

    /** @return array<string, true> */
    private function varNamesInStmt(?object $node, bool $top): array
    {
        if ($node === null) {
            return [];
        }
        switch ($node->getType()) {
            case 'VariableDeclaration':
                if ($node->getKind() !== 'var') {
                    return [];
                }
                $names = [];
                foreach ($node->getDeclarations() as $d) {
                    foreach ($this->patternNames($d->getId()) as $name) {
                        $names[$name] = true;
                    }
                }
                return $names;
            case 'FunctionDeclaration':
                return $top ? [$node->getId()->getName() => true] : [];
            case 'BlockStatement':
                return $this->varNamesIn($node->getBody(), false);
            case 'IfStatement':
                return $this->varNamesInStmt($node->getConsequent(), false)
                    + $this->varNamesInStmt($node->getAlternate(), false);
            case 'ForStatement':
                return $this->varNamesInStmt($node->getInit(), false)
                    + $this->varNamesInStmt($node->getBody(), false);
            case 'ForInStatement':
            case 'ForOfStatement':
                return $this->varNamesInStmt($node->getLeft(), false)
                    + $this->varNamesInStmt($node->getBody(), false);
            case 'WhileStatement':
            case 'DoWhileStatement':
            case 'LabeledStatement':
                return $this->varNamesInStmt($node->getBody(), false);
            case 'SwitchStatement':
                $names = [];
                foreach ($node->getCases() as $case) {
                    $names += $this->varNamesIn($case->getConsequent(), false);
                }
                return $names;
            case 'TryStatement':
                $names = $this->varNamesIn($node->getBlock()->getBody(), false);
                if ($node->getHandler() !== null) {
                    $names += $this->varNamesIn($node->getHandler()->getBody()->getBody(), false);
                }
                if ($node->getFinalizer() !== null) {
                    $names += $this->varNamesIn($node->getFinalizer()->getBody(), false);
                }
                return $names;
            default:
                return [];
        }
    }

    /**
     * A switch's cases as one statement list: they share a single block scope,
     * so `case 1: let x;` and `case 2: let x;` collide.
     *
     * @return list<object>
     */
    private function switchBody(object $node): array
    {
        $stmts = [];
        foreach ($node->getCases() as $case) {
            foreach ($case->getConsequent() as $s) {
                $stmts[] = $s;
            }
        }
        return $stmts;
    }

    /**
     * Open a block scope for a statement list, if it declares anything.
     *
     * Returns whether a frame was pushed, so the caller can pop symmetrically.
     * During analysis the bindings are created and remembered; during code
     * generation the remembered ones are pushed again.
     *
     * `$reserved` names already occupy this scope without appearing in
     * `$stmts` -- parameters for a function body, the catch parameter for a
     * catch body, the loop body's vars for a `for` head.
     *
     * @param list<object> $stmts
     * @param list<string> $reserved
     */
    private function enterBlock(
        object $owner,
        array $stmts,
        Ctx $ctx,
        bool $analysing,
        array $reserved = [],
        bool $requireInit = true,
    ): bool {
        if ($analysing) {
            $names = $this->lexicalNames($stmts, $requireInit);
            if ($names === []) {
                return false;
            }
            $taken = $this->varNamesIn($stmts) + array_fill_keys($reserved, true);
            $bindings = [];
            foreach ($names as $name => $kind) {
                if (isset($taken[$name])) {
                    $this->fail($owner, "Identifier '$name' has already been declared");
                }
                if ($kind === 'class') {
                    // A class's own name is always evaluated as strict-mode
                    // code (15.7.1), regardless of whether the declaration
                    // itself sits in sloppy code -- so `let`/`static`/`yield`
                    // are reserved for it even outside a "use strict" file.
                    $this->checkIdentifierName($name, true, $owner);
                } else {
                    $this->checkBindingName($ctx, $name, $owner);
                }
                $b = new Binding($ctx, $name, $kind);
                $b->inLoop = $this->loopDepth > 0;
                // Whichever loop's per-iteration layer is innermost right
                // now, if any -- assignSlots only actually uses this once
                // $b also turns out captured, but it costs nothing to record
                // unconditionally, and every binding created while a given
                // loop's layer is open belongs to that same loop by
                // construction (its own head, or its body's own block).
                $b->envScope = end($this->loopEnvStack) ?: null;
                $ctx->extraBindings[] = $b;
                $bindings[$name] = $b;
            }
            $this->blockScopes[$owner] = $bindings;
        } elseif (!$this->blockScopes->contains($owner)) {
            return false;
        }
        $this->lexStack[] = ['ctx' => $ctx, 'names' => $this->blockScopes[$owner]];
        return true;
    }

    /**
     * Put every binding of a freshly entered block into its dead zone.
     *
     * Reads before the declaration runs are the whole reason `let` is not
     * `var`, and the marker is what makes them observable rather than
     * `undefined`.
     */
    private function emitBlockPrologue(object $owner): void
    {
        if (!$this->blockScopes->contains($owner)) {
            return;
        }
        foreach ($this->blockScopes[$owner] as $b) {
            $this->cur->emit(Op::PUSH_TDZ);
            $this->emitStoreBinding($b);
            $this->cur->emit(Op::POP);
        }
    }

    /**
     * Generate a statement list inside the block scope its owner was analysed
     * with. Every list that can hold a `let` goes through here, so that the two
     * passes stay in step about what is in scope.
     *
     * @param list<object> $stmts
     */
    private function genScopedList(object $owner, array $stmts): void
    {
        $opened = $this->enterBlock($owner, $stmts, $this->cur, false);
        $this->emitBlockPrologue($owner);
        foreach ($stmts as $s) {
            $this->genStmt($s);
        }
        if ($opened) {
            array_pop($this->lexStack);
        }
    }

    /**
     * Reserved in every mode (11.6.2.1). A keyword written with a unicode
     * escape is still the keyword, so it may not be an identifier -- and the
     * escape is exactly how one reaches here, because the parser turns an
     * unescaped keyword into a keyword token and never into an Identifier.
     */
    private const RESERVED_WORDS = [
        'break' => true, 'case' => true, 'catch' => true, 'class' => true,
        'const' => true, 'continue' => true, 'debugger' => true, 'default' => true,
        'delete' => true, 'do' => true, 'else' => true, 'enum' => true,
        'export' => true, 'extends' => true, 'false' => true, 'finally' => true,
        'for' => true, 'function' => true, 'if' => true, 'import' => true,
        'in' => true, 'instanceof' => true, 'new' => true, 'null' => true,
        'return' => true, 'super' => true, 'switch' => true, 'this' => true,
        'throw' => true, 'true' => true, 'try' => true, 'typeof' => true,
        'var' => true, 'void' => true, 'while' => true, 'with' => true,
    ];

    /** Reserved only in strict code (11.6.2.2); ordinary identifiers otherwise. */
    private const STRICT_RESERVED_WORDS = [
        'implements' => true, 'interface' => true, 'let' => true, 'package' => true,
        'private' => true, 'protected' => true, 'public' => true, 'static' => true,
        'yield' => true,
    ];

    private function checkIdentifierName(string $name, bool $strict, ?object $node): void
    {
        if (isset(self::RESERVED_WORDS[$name])
            || ($strict && isset(self::STRICT_RESERVED_WORDS[$name]))) {
            $this->fail($node, "'$name' is a reserved word and cannot be used as an identifier");
        }
    }

    private function checkBindingName(Ctx $ctx, string $name, ?object $node): void
    {
        $this->checkIdentifierName($name, $ctx->strict, $node);
        if ($ctx->strict && ($name === 'eval' || $name === 'arguments')) {
            $this->fail($node, "Binding '$name' is not allowed in strict mode");
        }
    }

    private function analyzeReference(string $name, Ctx $ctx, ?object $node = null): void
    {
        $this->checkIdentifierName($name, $ctx->strict, $node);
        for ($i = count($this->lexStack) - 1; $i >= 0; $i--) {
            $names = $this->lexStack[$i]['names'];
            if (isset($names[$name])) {
                $b = $names[$name];
                if ($b->owner !== $ctx) {
                    $b->captured = true;
                }
                return;
            }
        }
        if ($name === 'arguments' && !$ctx->isProgram) {
            if ($ctx->isArrow) {
                // An arrow has no `arguments` of its own -- it means the
                // enclosing function's. Handing back the arrow's own would be
                // silently wrong, so refuse until it is captured properly.
                $this->unsupported(null, "'arguments' inside an arrow function is not supported yet");
            }
            $ctx->usesArguments = true;
        }
    }

    /**
     * `super.prop` / `super.method()`: legal directly inside a class method,
     * including the constructor -- not inside a further-nested ordinary
     * function, which would need its own `[[HomeObject]]` and has none.
     *
     * An arrow *should* inherit `super` the way it inherits `this`
     * (`$ctx->inClassMethod` is not threaded onto one for exactly that
     * reason), but resolving it would need [[HomeObject]] carried through the
     * closure the way `lexicalThis` is, which is not implemented -- so it is
     * refused here rather than resolved to the wrong scope.
     */
    private function checkSuperAllowed(Ctx $ctx, object $node): void
    {
        if ($ctx->isArrow) {
            $this->unsupported($node, "'super' inside an arrow function is not supported yet");
        }
        if (!$ctx->inClassMethod) {
            $this->fail($node, "'super' keyword is only valid inside a class");
        }
    }

    private function assignSlots(Ctx $ctx): void
    {
        $ctx->nparams = count($ctx->params);
        $ctx->nlocals = $ctx->nparams;
        $simpleList = $ctx->paramInits === [] && $ctx->restParam === null
            && $ctx->paramPatterns === [];
        if ($simpleList) {
            $ctx->length = $ctx->nparams;
        }
        // A mapped arguments object aliases the parameters, and the only
        // heap-safe place to share them is an environment slot (§11.3). A
        // parameter list with defaults or a rest element is never mapped
        // (9.2.12), which is just as well: there is nothing to alias.
        $mapParams = $ctx->usesArguments && !$ctx->strict && $simpleList;
        foreach ($ctx->bindings as $b) {
            if ($mapParams && $b->kind === 'param') {
                $b->captured = true;
            }
        }
        foreach ($ctx->bindings as $b) {
            if ($b->kind === 'param') {
                $b->slot = $b->paramIndex;
                if ($b->captured) {
                    $b->envIndex = $ctx->nenv++;
                }
            } elseif ($b->captured) {
                $b->envIndex = $ctx->nenv++;
            } else {
                $b->slot = $ctx->nlocals++;
            }
        }
        foreach ($ctx->extraBindings as $b) {
            if ($b->captured && $b->envScope !== null) {
                // A closure captured it, and it lives in a loop that turned
                // out to need per-iteration environments -- its slot is in
                // that loop's own environment, not this function's flat one,
                // however many loop layers are nested at this point.
                $b->envIndex = $b->envScope->size++;
            } elseif ($b->captured) {
                $b->envIndex = $ctx->nenv++;
            } else {
                $b->slot = $ctx->nlocals++;
            }
        }
        foreach ($ctx->bodyBindings as $b) {
            if ($b->captured && $b->envScope !== null) {
                // Lives in this function's own separate variable environment
                // (9.2.12), not its flat one -- same reasoning as a captured
                // per-iteration loop binding above, just a different EnvScope.
                $b->envIndex = $b->envScope->size++;
            } elseif ($b->captured) {
                $b->envIndex = $ctx->nenv++;
            } else {
                $b->slot = $ctx->nlocals++;
            }
        }
        if ($ctx->selfBinding !== null) {
            $b = $ctx->selfBinding;
            if ($b->captured) {
                $b->envIndex = $ctx->nenv++;
            } else {
                $b->slot = $ctx->nlocals++;
            }
        }
        if ($mapParams) {
            foreach ($ctx->params as $i => $name) {
                // With a repeated parameter name only the last position is
                // aliased; the earlier ones are shadowed and unmapped.
                $shadowed = in_array($name, array_slice($ctx->params, $i + 1), true);
                $ctx->argMap[$i] = $shadowed ? -1 : $ctx->bindings[$name]->envIndex;
            }
        }
    }

    private function hasUseStrict(array $body): bool
    {
        foreach ($body as $stmt) {
            if ($stmt->getType() !== 'ExpressionStatement') {
                return false;
            }
            $e = $stmt->getExpression();
            if (!$e instanceof StringLiteral) {
                return false;
            }
            $raw = $e->getRaw();
            if ($raw === '"use strict"' || $raw === "'use strict'") {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Pass 2: code generation
    // =========================================================================

    /** @return array<string, mixed> */
    private function genFunction(Ctx $ctx, object $node, bool $isProgram): array
    {
        $prevCur = $this->cur ?? null;
        $prevLabels = $this->pendingLabels;
        // Mirrors analyzeFunction's own save/reset: codegen re-descends into
        // this function via compileChild from wherever the enclosing
        // function's own codegen currently is -- possibly inside one of
        // *its* loops -- and that loop's EnvScope must not leak into
        // this function's own envDepth walks. This function's own loops (or
        // none) push fresh entries as they're reached below; nothing here
        // needs the enclosing ones, since every binding this function
        // actually references already recorded, at analysis time, exactly
        // how many layers separate it (Ctx::$parent / Binding::$envScope).
        $prevLoopEnvStack = $this->loopEnvStack;
        $this->loopEnvStack = [];
        $this->cur = $ctx;
        $this->pendingLabels = [];

        $selfPushed = false;
        if ($ctx->selfBinding !== null) {
            $this->lexStack[] = ['ctx' => $ctx, 'names' => [$ctx->selfBinding->name => $ctx->selfBinding]];
            $selfPushed = true;
        }
        $this->lexStack[] = ['ctx' => $ctx, 'names' => $ctx->bindings];

        $body = $isProgram ? $node->getBody() : ($ctx->arrowBody !== null ? [] : $node->getBody()->getBody());

        // Prologue, in this order: a default fills a parameter the caller left
        // out, the rest element gathers what is past the declared ones, and only
        // then are captured parameters copied into the environment -- so the
        // environment sees the finished values rather than the raw arguments.
        foreach ($ctx->paramInits as $index => $init) {
            $slot = $ctx->bindings[$ctx->params[$index]]->slot;
            $ctx->emit(Op::GET_LOCAL, $slot);
            $ctx->emit(Op::PUSH_UNDEF);
            $ctx->emit(Op::SEQ);
            // A default applies only to `undefined`, not to every falsy value
            // and not to an explicitly passed `null`.
            $skip = $ctx->emitJump(Op::JF);
            $this->genExpr($init);
            $ctx->emit(Op::SET_LOCAL, $slot);
            $ctx->emit(Op::POP);
            $ctx->patch($skip);
        }
        if ($ctx->restParam !== null) {
            $ctx->emit(Op::REST_ARGS, $ctx->nparams);
            $this->emitStoreBinding($ctx->bindings[$ctx->restParam]);
            $ctx->emit(Op::POP);
        }
        // After the defaults, so a pattern parameter destructures the value the
        // default supplied rather than the `undefined` it replaced.
        foreach ($ctx->paramPatterns as $index => $pattern) {
            $slot = $ctx->bindings[$ctx->params[$index]]->slot;
            $this->genPattern($pattern, static fn () => $ctx->emit(Op::GET_LOCAL, $slot), true);
        }

        foreach ($ctx->bindings as $b) {
            if ($b->kind === 'param' && $b->captured) {
                $ctx->emit(Op::GET_LOCAL, $b->slot);
                $ctx->emit(Op::SET_ENV, 0, $b->envIndex);
                $ctx->emit(Op::POP);
            }
        }
        // Named function expression: bind own name.
        if ($ctx->selfBinding !== null) {
            $ctx->emit(Op::PUSH_CALLEE);
            $this->emitStoreBinding($ctx->selfBinding);
            $ctx->emit(Op::POP);
        }
        // Program: declare global vars.
        if ($isProgram) {
            foreach ($ctx->globalDecls as $name) {
                $ctx->emit(Op::DECL_GLOBAL, $ctx->constIndex($name));
            }
        }
        // 9.2.12: give the body its own separate variable environment, if the
        // parameter list needed one at all and analysis found something
        // there actually captured. Mirrors analyzeFunction's own push of
        // $ctx->bodyBindings, so hoisted function declarations and the body's
        // statements below resolve their own var/function names against it
        // before falling through to the parameters.
        if ($ctx->paramEnvScope !== null) {
            $this->beginParamEnv($ctx);
            $this->lexStack[] = ['ctx' => $ctx, 'names' => $ctx->bodyBindings];
        }
        // Hoisted function declarations.
        $bodyOwner = $isProgram ? $node : ($ctx->arrowBody !== null ? null : $node->getBody());
        $bodyBlock = $bodyOwner !== null && $this->enterBlock($bodyOwner, $body, $ctx, false);
        if ($bodyOwner !== null) {
            $this->emitBlockPrologue($bodyOwner);
        }

        foreach ($ctx->fnDecls as $fn) {
            $childCtx = $this->fnCtx[$fn];
            $idx = $this->compileChild($childCtx, $fn);
            $ctx->emit(Op::NEW_FUNC, $idx);
            $name = $fn->getId()->getName();
            $b = $this->resolve($name);
            if ($b instanceof Binding) {
                $this->emitStoreBinding($b);
            } else {
                $ctx->emit(Op::SET_GLOBAL, $ctx->constIndex($name));
            }
            $ctx->emit(Op::POP);
        }

        if ($ctx->isGenerator) {
            // GeneratorStart (27.5.3.2): parameter binding and hoisting --
            // everything above -- run *now*, synchronously, as part of
            // calling the generator function (so e.g. a bad destructuring
            // parameter throws from the call itself); the body proper does
            // not start until the first `.next()`. Reusing the ordinary
            // yield-suspend primitive for that boundary costs nothing extra
            // to support: a `.throw()`/`.return()` before any `.next()`
            // hits it with no exception handlers registered yet (nothing
            // has run to register one), which already gives exactly
            // GeneratorResumeAbrupt's suspendedStart behavior for free.
            $ctx->emit(Op::PUSH_UNDEF);
            $this->genYieldSuspend();
            $ctx->emit(Op::POP);
        }

        foreach ($body as $stmt) {
            $this->genStmt($stmt);
        }
        if ($bodyBlock) {
            array_pop($this->lexStack);
        }
        if ($ctx->paramEnvScope !== null) {
            array_pop($this->lexStack);
        }
        if ($ctx->arrowBody !== null) {
            // `x => expr` returns the expression; there is no other statement.
            $this->genExpr($ctx->arrowBody);
            $ctx->emit(Op::RETURN);
        }

        if ($isProgram) {
            $ctx->emit(Op::RETURN_COMPLETION);
        } else {
            $ctx->emit(Op::RETURN_UNDEF);
        }

        array_pop($this->lexStack);
        if ($selfPushed) {
            array_pop($this->lexStack);
        }
        if ($prevCur !== null) {
            $this->cur = $prevCur;
        }
        $this->pendingLabels = $prevLabels;
        $this->loopEnvStack = $prevLoopEnvStack;
        $tpl = $ctx->toTemplate();
        if ($this->onFunction !== null) {
            $nativeId = ($this->onFunction)($node, $ctx, $isProgram);
            if ($nativeId !== null) {
                $tpl['nativeId'] = $nativeId;
            }
        }
        return Peephole::run($tpl);
    }

    /** Compile a nested function into the current template's children; returns its index. */
    private function compileChild(Ctx $childCtx, object $node): int
    {
        $parentCtx = $this->cur;
        $tpl = $this->genFunction($childCtx, $node, false);
        $this->cur = $parentCtx;
        $parentCtx->children[] = $tpl;
        return count($parentCtx->children) - 1;
    }

    /**
     * A class declaration or expression, as an expression that leaves the
     * constructor on the stack. `genStmt`'s ClassDeclaration case binds it to
     * the class's name; ClassExpression's own case in genExpr just returns
     * here.
     *
     * The constructor and prototype come from one NEW_CLASS -- see its
     * doc comment for why extends is resolved there rather than with
     * ordinary Object.create-style opcodes -- and everything after holds
     * both in temp slots rather than juggling stack order, since a method
     * definition needs whichever of the two is its target (prototype for an
     * instance member, the constructor itself for a static one) both before
     * and after building the method function.
     */
    private function genClass(object $node): void
    {
        $c = $this->cur;
        $superClass = $node->getSuperClass();
        $hasSuper = $superClass !== null;

        // Re-push the same lexStack entry the analysis pass used, so
        // `super(...)` and a named class expression's self-reference resolve
        // to the Binding objects assignSlots already gave slots to.
        $scoped = $this->blockScopes->contains($node);
        if ($scoped) {
            $this->lexStack[] = ['ctx' => $c, 'names' => $this->blockScopes[$node]];
        }

        if ($hasSuper) {
            $this->genExpr($superClass);
            // SET_LOCAL/SET_ENV keep the value on the stack, so this needs no
            // DUP: NEW_CLASS still finds it on top right after.
            $this->emitStoreBinding($this->resolve(self::SUPERCLASS_SLOT));
        }
        $c->emit(Op::NEW_CLASS, $this->compileClassCtor($node), $hasSuper ? 1 : 0);

        // NEW_CLASS leaves [ctor, proto] -- proto on top -- so it comes off
        // the stack first.
        $protoSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $protoSlot);
        $c->emit(Op::POP);
        $ctorSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $ctorSlot);
        $c->emit(Op::POP);

        if ($node->getType() === 'ClassExpression' && $node->getId() !== null) {
            $c->emit(Op::GET_LOCAL, $ctorSlot);
            $this->emitStoreBinding($this->resolve($node->getId()->getName()));
            $c->emit(Op::POP);
        }

        foreach ($node->getBody()->getBody() as $el) {
            if ($el->getKind() === 'constructor') {
                continue; // already built into the constructor template itself
            }
            $targetSlot = $el->getStatic() ? $ctorSlot : $protoSlot;
            $c->emit(Op::GET_LOCAL, $targetSlot);
            $computed = $el->getComputed();
            $kidx = null;
            $keySlot = null;
            if ($computed) {
                $this->genExpr($el->getKey());
                $c->emit(Op::TO_KEY);
                // A copy survives in a temp slot for SET_FUNC_NAME below,
                // the same way ObjectExpression's computed keys do: a class
                // method is always anonymous by grammar, but its name is not
                // known until this runs.
                $keySlot = $c->tempAlloc();
                $c->emit(Op::SET_LOCAL, $keySlot);
            } else {
                $kidx = $c->constIndex($this->propertyKeyString($el->getKey()));
            }
            $childCtx = $this->fnCtx[$el->getValue()];
            $idx = $this->compileChild($childCtx, $el->getValue());
            $c->emit(Op::NEW_FUNC, $idx);
            $c->emit(Op::GET_LOCAL, $targetSlot);
            $c->emit(Op::SET_HOME_OBJECT);
            $kind = $el->getKind();
            if ($kind === 'get' || $kind === 'set') {
                $isGet = $kind === 'get';
                if ($computed) {
                    $c->emit(Op::GET_LOCAL, $keySlot);
                    $c->emit(Op::SET_FUNC_NAME, $c->constIndex($isGet ? 'get ' : 'set '));
                    $c->emit($isGet ? Op::DEFINE_CLASS_GETTER_ELEM : Op::DEFINE_CLASS_SETTER_ELEM);
                } else {
                    $c->emit($isGet ? Op::DEFINE_CLASS_GETTER : Op::DEFINE_CLASS_SETTER, $kidx);
                }
            } else {
                if ($computed) {
                    $c->emit(Op::GET_LOCAL, $keySlot);
                    $c->emit(Op::SET_FUNC_NAME, $c->constIndex(''));
                }
                $c->emit($computed ? Op::DEFINE_METHOD_ELEM : Op::DEFINE_METHOD, ...($computed ? [] : [$kidx]));
            }
            if ($keySlot !== null) {
                $c->tempFree($keySlot);
            }
            $c->emit(Op::POP);
        }

        $c->emit(Op::GET_LOCAL, $ctorSlot);
        $c->tempFree($protoSlot);
        $c->tempFree($ctorSlot);
        if ($scoped) {
            array_pop($this->lexStack);
        }
    }

    /**
     * The constructor's child template index -- the explicit `constructor`
     * method if the class wrote one, otherwise the node `analyzeClass`
     * synthesized and parked in `$syntheticCtors` keyed by this same class
     * node (12.2.2's default constructor).
     */
    private function compileClassCtor(object $node): int
    {
        $ctorNode = null;
        foreach ($node->getBody()->getBody() as $el) {
            if ($el->getKind() === 'constructor') {
                $ctorNode = $el->getValue();
                break;
            }
        }
        $ctorNode ??= $this->syntheticCtors[$node];
        $childCtx = $this->fnCtx[$ctorNode];
        return $this->compileChild($childCtx, $ctorNode);
    }

    // ---- statements ---------------------------------------------------------

    private function genStmt(?object $node): void
    {
        if ($node === null) {
            return;
        }
        $c = $this->cur;
        $c->markLine($node->getLocation()->getStart()->getLine());
        $labels = $this->pendingLabels;
        $this->pendingLabels = [];
        $type = $node->getType();
        if ($labels !== [] && !isset(self::CONSUMES_LABELS[$type])) {
            // Any statement may carry a label, and `break label` must exit it.
            // Statement kinds that manage their own break/continue targets are
            // listed in CONSUMES_LABELS; everything else gets a plain wrapper.
            $wrapper = $this->pushLoop($labels, false);
            $this->genStmt($node);
            $this->popLoop($wrapper);
            return;
        }
        if ($c->isProgram && isset(self::RESETS_COMPLETION[$type])) {
            // These statements start with V = undefined, so a preceding
            // expression statement's value must not leak out of them
            // (`eval('1; for (k in {}) {}')` is undefined, not 1).
            $c->emit(Op::PUSH_UNDEF);
            $c->emit(Op::SET_COMPLETION);
        }
        switch ($type) {
            case 'ExpressionStatement':
                if (!$c->isProgram && $this->emitDiscardedUpdate($node->getExpression())) {
                    return; // ++/-- on a local: no value to keep or discard
                }
                $this->genExpr($node->getExpression());
                $c->emit($c->isProgram ? Op::SET_COMPLETION : Op::POP);
                return;
            case 'VariableDeclaration': {
                $lexical = $node->getKind() !== 'var';
                foreach ($node->getDeclarations() as $d) {
                    if (self::isPattern($d->getId())) {
                        // The parser already rejects a pattern without one.
                        $init = $d->getInit();
                        $this->genPattern($d->getId(), fn () => $this->genExpr($init), $lexical);
                        continue;
                    }
                    if ($d->getInit() !== null) {
                        $this->genExpr($d->getInit());
                    } elseif ($lexical) {
                        // `let x;` is undefined, but it must still *leave* the
                        // dead zone -- a later read is legal and answers
                        // undefined rather than throwing.
                        $c->emit(Op::PUSH_UNDEF);
                    } else {
                        continue;
                    }
                    $this->emitStoreName($d->getId()->getName(), $lexical);
                    $c->emit(Op::POP);
                }
                return;
            }
            case 'FunctionDeclaration':
                return; // hoisted in the prologue
            case 'ClassDeclaration':
                $this->genClass($node);
                $this->emitStoreName($node->getId()->getName(), true);
                $c->emit(Op::POP);
                return;
            case 'EmptyStatement':
            case 'DebuggerStatement':
                return;
            case 'BlockStatement':
                if ($labels !== []) {
                    $this->genLabeledBlock($node, $labels);
                    return;
                }
                $this->genScopedList($node, $node->getBody());
                return;
            case 'IfStatement':
                $this->requireStatementBody($node->getConsequent());
                $this->requireStatementBody($node->getAlternate());
                $this->genExpr($node->getTest());
                $jElse = $c->emitJump(Op::JF);
                $this->genStmt($node->getConsequent());
                if ($node->getAlternate() !== null) {
                    $jEnd = $c->emitJump(Op::JMP);
                    $c->patch($jElse);
                    $this->genStmt($node->getAlternate());
                    $c->patch($jEnd);
                } else {
                    $c->patch($jElse);
                }
                return;
            case 'WhileStatement': {
                $this->requireStatementBody($node->getBody());
                $loop = $this->pushLoop($labels, true);
                $iterEnv = $this->beginLoopEnv($node);
                $lCond = $c->here();
                $this->genExpr($node->getTest());
                $jEnd = $c->emitJump(Op::JF);
                if ($iterEnv !== null) {
                    $this->freshLoopEnv($iterEnv);
                }
                $this->genStmt($node->getBody());
                $this->patchContinues($loop, $lCond);
                $c->emit(Op::JMP, $lCond);
                $c->patch($jEnd);
                $this->popLoop($loop);
                if ($iterEnv !== null) {
                    $this->endLoopEnv($iterEnv);
                }
                return;
            }
            case 'DoWhileStatement': {
                $this->requireStatementBody($node->getBody());
                $loop = $this->pushLoop($labels, true);
                $iterEnv = $this->beginLoopEnv($node);
                $lBody = $c->here();
                if ($iterEnv !== null) {
                    $this->freshLoopEnv($iterEnv);
                }
                $this->genStmt($node->getBody());
                $lCond = $c->here();
                $this->patchContinues($loop, $lCond);
                $this->genExpr($node->getTest());
                $c->emit(Op::JT, $lBody);
                $this->popLoop($loop);
                if ($iterEnv !== null) {
                    $this->endLoopEnv($iterEnv);
                }
                return;
            }
            case 'ForStatement': {
                $this->requireStatementBody($node->getBody());
                $init = $node->getInit();
                $headScope = $this->enterBlock($node, $init === null ? [] : [$init], $this->cur, false);
                $iterEnv = $this->beginLoopEnv($node);
                if ($iterEnv !== null) {
                    // The first per-iteration environment, so that even the
                    // head's own initializer (`let i = 0`) writes into it
                    // rather than into what briefly wouldn't otherwise exist.
                    $this->freshLoopEnv($iterEnv);
                }
                if ($headScope) {
                    $this->emitBlockPrologue($node);
                }
                if ($init !== null) {
                    if ($init->getType() === 'VariableDeclaration') {
                        $this->genStmt($init);
                    } else {
                        $this->genExpr($init);
                        $c->emit(Op::POP);
                    }
                }
                $loop = $this->pushLoop($labels, true);
                $lCond = $c->here();
                $jEnd = -1;
                if ($node->getTest() !== null) {
                    $this->genExpr($node->getTest());
                    $jEnd = $c->emitJump(Op::JF);
                }
                $this->genStmt($node->getBody());
                $lCont = $c->here();
                $this->patchContinues($loop, $lCont);
                if ($iterEnv !== null) {
                    // CreatePerIterationEnvironment runs again here, before
                    // the update -- so the update mutates (and the next
                    // test/body reads) the *new* per-iteration copy, not the
                    // one any closure made during this pass already captured.
                    $this->copyForwardLoopEnv($node, $iterEnv);
                }
                if ($node->getUpdate() !== null) {
                    if ($c->isProgram || !$this->emitDiscardedUpdate($node->getUpdate())) {
                        $this->genExpr($node->getUpdate());
                        $c->emit(Op::POP);
                    }
                }
                $c->emit(Op::JMP, $lCond);
                if ($jEnd >= 0) {
                    $c->patch($jEnd);
                }
                $this->popLoop($loop);
                if ($iterEnv !== null) {
                    $this->endLoopEnv($iterEnv);
                }
                if ($headScope) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'ForInStatement': {
                $this->requireStatementBody($node->getBody());
                $this->genExpr($node->getRight());
                $iterSlot = $c->tempAlloc();
                $c->emit(Op::FORIN_INIT, $iterSlot);
                $loop = $this->pushLoop($labels, true);
                $left = $node->getLeft();
                $headScope = $this->enterBlock($node, [$left], $c, false);
                $iterEnv = $this->beginLoopEnv($node);
                $lNext = $c->here();
                if ($iterEnv !== null) {
                    // A fresh environment every pass, like for…of below --
                    // for…in's own head binds afresh from the enumerated key
                    // each time, never carrying a value forward.
                    $this->freshLoopEnv($iterEnv);
                }
                if ($headScope) {
                    $this->emitBlockPrologue($node);
                }
                $jEnd = $c->emitJump(Op::FORIN_NEXT, $iterSlot);
                // The key is on the stack; bind it the same way for-of binds a
                // value, so a pattern head works in both.
                $keyTmp = $c->tempAlloc();
                $c->emit(Op::SET_LOCAL, $keyTmp);
                $c->emit(Op::POP);
                $this->genForTarget($left, static fn () => $c->emit(Op::GET_LOCAL, $keyTmp));
                $c->tempFree($keyTmp);
                $this->genStmt($node->getBody());
                $this->patchContinues($loop, $lNext);
                $c->emit(Op::JMP, $lNext);
                $c->patch($jEnd);
                $this->popLoop($loop);
                if ($iterEnv !== null) {
                    $this->endLoopEnv($iterEnv);
                }
                if ($headScope) {
                    array_pop($this->lexStack);
                }
                $c->tempFree($iterSlot);
                return;
            }
            case 'ForOfStatement': {
                $this->genForOf($node, $labels);
                return;
            }
            case 'SwitchStatement': {
                $this->genExpr($node->getDiscriminant());
                $t = $c->tempAlloc();
                $c->emit(Op::SET_LOCAL, $t);
                $c->emit(Op::POP);
                $cases = $this->enterBlock($node, $this->switchBody($node), $c, false);
                $this->emitBlockPrologue($node);
                $loop = $this->pushLoop($labels, false);
                $caseJumps = [];
                $defaultIndex = -1;
                foreach ($node->getCases() as $i => $case) {
                    if ($case->getTest() === null) {
                        $defaultIndex = $i;
                        continue;
                    }
                    $c->emit(Op::GET_LOCAL, $t);
                    $this->genExpr($case->getTest());
                    $c->emit(Op::SEQ);
                    $caseJumps[$i] = $c->emitJump(Op::JT);
                }
                $jDefault = $c->emitJump(Op::JMP);
                foreach ($node->getCases() as $i => $case) {
                    if ($i === $defaultIndex) {
                        $c->patch($jDefault);
                    }
                    if (isset($caseJumps[$i])) {
                        $c->patch($caseJumps[$i]);
                    }
                    foreach ($case->getConsequent() as $s) {
                        $this->genStmt($s);
                    }
                }
                if ($defaultIndex === -1) {
                    $c->patch($jDefault);
                }
                $this->popLoop($loop);
                if ($cases) {
                    array_pop($this->lexStack);
                }
                $c->tempFree($t);
                return;
            }
            case 'BreakStatement': {
                $label = $node->getLabel()?->getName();
                $target = $this->findLoop($label, false);
                $this->emitExitCleanup($target['tryDepth']);
                $c->loopStack[$target['index']]['breaks'][] = $c->emitJump(Op::JMP);
                return;
            }
            case 'ContinueStatement': {
                $label = $node->getLabel()?->getName();
                $target = $this->findLoop($label, true);
                $this->emitExitCleanup($target['tryDepth']);
                $c->loopStack[$target['index']]['continues'][] = $c->emitJump(Op::JMP);
                return;
            }
            case 'ReturnStatement':
                if ($c->isProgram) {
                    $this->fail($node, "'return' outside of function");
                }
                if ($node->getArgument() !== null) {
                    $this->genExpr($node->getArgument());
                } else {
                    $c->emit(Op::PUSH_UNDEF);
                }
                $this->emitExitCleanup(0);
                $c->emit(Op::RETURN);
                return;
            case 'ThrowStatement':
                $this->genExpr($node->getArgument());
                $c->emit(Op::THROW);
                return;
            case 'TryStatement':
                $this->genTry($node);
                return;
            case 'LabeledStatement':
                $this->pendingLabels = array_merge($labels, [$node->getLabel()->getName()]);
                $this->genStmt($node->getBody());
                return;
            case 'WithStatement':
                $this->unsupported($node, "'with' statements are not supported");
                // no break (fail throws)
            default:
                $this->unsupported($node, "Unsupported statement: $type");
        }
    }

    /**
     * Statement types whose completion value starts as undefined rather than
     * inheriting the previous statement's value.
     */
    /** Statement kinds that push their own break/continue target for a label. */
    private const CONSUMES_LABELS = [
        'WhileStatement' => true,
        'DoWhileStatement' => true,
        'ForStatement' => true,
        'ForInStatement' => true,
        'ForOfStatement' => true,
        'SwitchStatement' => true,
        'BlockStatement' => true,
        'LabeledStatement' => true,
    ];

    private const RESETS_COMPLETION = [
        'WhileStatement' => true,
        'DoWhileStatement' => true,
        'ForStatement' => true,
        'ForInStatement' => true,
        'ForOfStatement' => true,
        'IfStatement' => true,
        'SwitchStatement' => true,
        'TryStatement' => true,
    ];

    /**
     * A FunctionDeclaration is not a Statement, so it cannot be the body of a
     * loop or an if — not even wrapped in a label.
     */
    private function requireStatementBody(?object $node): void
    {
        for ($n = $node; $n !== null && $n->getType() === 'LabeledStatement'; $n = $n->getBody()) {
        }
        if ($n !== null && $n->getType() === 'FunctionDeclaration') {
            $this->fail($n, 'Function declarations cannot appear in statement position');
        }
    }

    /**
     * `i++` whose value is thrown away, on an uncaptured local: emit the fused
     * INC_LOCAL/DEC_LOCAL instead of the eight-instruction generic form.
     * Only valid where the result is truly unused, so the ToNumber-then-add
     * ordering is unobservable.
     */
    private function emitDiscardedUpdate(object $node): bool
    {
        if ($node->getType() !== 'UpdateExpression') {
            return false;
        }
        $arg = self::unwrapParens($node->getArgument());
        if ($arg->getType() !== 'Identifier') {
            return false;
        }
        $binding = $this->resolve($arg->getName());
        if (!$binding instanceof Binding || $binding->captured) {
            return false;
        }
        if ($binding->kind === 'let' || $binding->kind === 'const' || $binding->kind === 'class') {
            // The fused form is a bare slot bump: it would skip the dead-zone
            // check on the read and the TypeError on writing a `const`
            // (a class binding is just as immutable).
            return false;
        }
        if ($this->cur->strict) {
            $this->checkAssignmentTarget($arg->getName(), $arg);
        }
        $this->cur->emit(
            $node->getOperator() === '++' ? Op::INC_LOCAL : Op::DEC_LOCAL,
            $binding->slot
        );
        return true;
    }

    /** Non-loop labeled statement: only `break label` targets it. */
    private function genLabeledBlock(object $node, array $labels): void
    {
        $c = $this->cur;
        $loop = $this->pushLoop($labels, false);
        $this->genScopedList($node, $node->getBody());
        $this->popLoop($loop);
    }

    /**
     * `for…of`, over the iteration protocol rather than over an index.
     *
     * The whole loop sits inside a protected region so that a throw from the
     * body closes the iterator; `break` and `return` close it on their way out
     * (through emitExitCleanup, or at the break target below), and `continue`
     * deliberately does not. Running to exhaustion does not close it either --
     * the iterator already finished, and the spec does not ask twice.
     */
    private function genForOf(object $node, array $labels): void
    {
        $c = $this->cur;
        $this->requireStatementBody($node->getBody());
        $left = $node->getLeft();

        $this->genExpr($node->getRight());
        $iterSlot = $c->tempAlloc();
        $nextSlot = $c->tempAlloc();
        $c->emit(Op::ITER_GET);                 // -> [iterator, nextMethod]
        $c->emit(Op::SET_LOCAL, $nextSlot);
        $c->emit(Op::POP);
        $c->emit(Op::SET_LOCAL, $iterSlot);
        $c->emit(Op::POP);

        $jThrow = $c->emitJump(Op::TRY_ENTER);
        $c->tryStack[] = ['finalizer' => null, 'iterSlot' => $iterSlot];
        $headScope = $this->enterBlock($node, [$left], $c, false);
        // Pushed after iterSlot's entry, so it is innermost: an ordinary
        // break/continue targeting this same loop bypasses both the same
        // way (handled by hand below, not emitExitCleanup -- see pushLoop's
        // tryDepth), and an exit crossing further out restores the
        // environment before closing the iterator, though nothing actually
        // depends on that relative order (closing it is just a call, which
        // runs under its own closure environment regardless of this one).
        $iterEnv = $this->beginLoopEnv($node);
        // Pushed after the region, so the loop's own break and continue do not
        // unwind it: continue must not close, and break closes at its target.
        $loop = $this->pushLoop($labels, true);

        $lNext = $c->here();
        if ($iterEnv !== null) {
            // A fresh environment every pass: for…of's own head binds afresh
            // from the iterator each time, never carrying a value forward.
            $this->freshLoopEnv($iterEnv);
        }
        if ($headScope) {
            // A fresh dead zone every pass: the head binds anew each iteration.
            $this->emitBlockPrologue($node);
        }
        $c->emit(Op::GET_LOCAL, $nextSlot);     // [func, this] for CALL
        $c->emit(Op::GET_LOCAL, $iterSlot);
        $c->emit(Op::CALL, 0);
        $jDone = $c->emitJump(Op::ITER_NEXT);   // -> [value], or jump when done
        $valSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $valSlot);
        $c->emit(Op::POP);
        $this->genForTarget($left, static fn () => $c->emit(Op::GET_LOCAL, $valSlot));
        $c->tempFree($valSlot);
        $this->genStmt($node->getBody());
        $this->patchContinues($loop, $lNext);
        $c->emit(Op::JMP, $lNext);

        $c->patch($jDone);                      // exhausted: nothing to close
        // $iterEnv's tryStack entry sits above iterSlot's (pushed later), so
        // it must come off first.
        if ($iterEnv !== null) {
            $this->endLoopEnv($iterEnv);
        }
        array_pop($c->tryStack);
        $c->emit(Op::TRY_LEAVE);
        $jEndDone = $c->emitJump(Op::JMP);

        $this->popLoop($loop);                  // every `break` lands here
        if ($iterEnv !== null) {
            $this->endLoopEnv($iterEnv);
        }
        $c->emit(Op::TRY_LEAVE);
        $c->emit(Op::GET_LOCAL, $iterSlot);
        $c->emit(Op::ITER_CLOSE, 0);
        $jEndBreak = $c->emitJump(Op::JMP);

        $c->patch($jThrow);                     // the exception is on the stack
        $excSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $excSlot);
        $c->emit(Op::POP);
        $c->emit(Op::GET_LOCAL, $iterSlot);
        $c->emit(Op::ITER_CLOSE, 1);            // quiet: the throw in flight wins
        $c->emit(Op::GET_LOCAL, $excSlot);
        $c->emit(Op::THROW);
        $c->tempFree($excSlot);

        $c->patch($jEndDone);
        $c->patch($jEndBreak);
        if ($headScope) {
            array_pop($this->lexStack);
        }
        $c->tempFree($nextSlot);
        $c->tempFree($iterSlot);
    }

    /** Bind one iteration's value to a `for…of` / `for…in` head. */
    private function genForTarget(object $left, callable $pushValue): void
    {
        $c = $this->cur;
        if ($left->getType() === 'VariableDeclaration') {
            $id = $left->getDeclarations()[0]->getId();
            $this->genPattern($id, $pushValue, $left->getKind() !== 'var');
            return;
        }
        $left = self::unwrapParens($left);
        $this->genPattern($left, $pushValue, false);
    }

    private function genTry(object $node): void
    {
        $c = $this->cur;
        $finalizer = $node->getFinalizer();
        $handler = $node->getHandler();

        if ($finalizer !== null) {
            // try/finally wrapping (optionally) try/catch.
            $jFin = $c->emitJump(Op::TRY_ENTER);
            $c->tryStack[] = ['finalizer' => $finalizer];
            $this->genTryCatchPart($node, $handler);
            array_pop($c->tryStack);
            $c->emit(Op::TRY_LEAVE);
            $this->genFinalizerInline($finalizer);       // normal path
            $jEnd = $c->emitJump(Op::JMP);
            $c->patch($jFin);                             // exception path
            $excTmp = $c->tempAlloc();
            $c->emit(Op::SET_LOCAL, $excTmp);
            $c->emit(Op::POP);
            $this->genFinalizerInline($finalizer);
            $c->emit(Op::GET_LOCAL, $excTmp);
            $c->emit(Op::THROW);
            $c->tempFree($excTmp);
            $c->patch($jEnd);
            return;
        }
        $this->genTryCatchPart($node, $handler);
    }

    private function genTryCatchPart(object $node, ?object $handler): void
    {
        $c = $this->cur;
        $block = $node->getBlock();
        if ($handler === null) {
            $this->genScopedList($block, $block->getBody());
            return;
        }
        $jCatch = $c->emitJump(Op::TRY_ENTER);
        $c->tryStack[] = ['finalizer' => null];
        $this->genScopedList($block, $block->getBody());
        array_pop($c->tryStack);
        $c->emit(Op::TRY_LEAVE);
        $jEnd = $c->emitJump(Op::JMP);
        $c->patch($jCatch);
        $param = $handler->getParam();
        if ($param === null) {
            // Optional catch binding: nothing to store, no name to shadow.
            $c->emit(Op::POP);
            $this->genScopedList($handler->getBody(), $handler->getBody()->getBody());
            $c->patch($jEnd);
            return;
        }
        $names = $this->catchBind[$handler];
        // Pushed before the pattern is destructured (not just before the
        // body, below): a leaf store resolves its own name through lexStack
        // same as any other, and the catch parameter sits outside the body's
        // block scope, so a `let` in the body may not reuse any of its names
        // either (see the analysis pass).
        $this->lexStack[] = ['ctx' => $c, 'names' => $names];
        if (self::isPattern($param)) {
            // The thrown value is materialised once into a temp, the same
            // way a destructuring assignment's right-hand side is, since the
            // pattern's leaves may read it more than once.
            $t = $c->tempAlloc();
            $c->emit(Op::SET_LOCAL, $t);
            $c->emit(Op::POP);
            $this->genPattern($param, static fn () => $c->emit(Op::GET_LOCAL, $t), true);
            $c->tempFree($t);
        } else {
            $this->emitStoreBinding(reset($names));
            $c->emit(Op::POP);
        }
        $this->genScopedList($handler->getBody(), $handler->getBody()->getBody());
        array_pop($this->lexStack);
        $c->patch($jEnd);
    }

    /**
     * Inline a finally block (duplication strategy, DESIGN.md §4.4): each
     * early exit and both normal/exception paths get their own copy.
     */
    private function genFinalizerInline(object $finalizer): void
    {
        $this->genScopedList($finalizer, $finalizer->getBody());
    }

    /**
     * Emit TRY_LEAVE (+ inlined finalizers) for protected regions crossed by
     * break/continue/return.
     */
    private function emitExitCleanup(int $targetTryDepth): void
    {
        $c = $this->cur;
        for ($i = count($c->tryStack) - 1; $i >= $targetTryDepth; $i--) {
            $entry = $c->tryStack[$i];
            $iterEnvOuterSlot = $entry['iterEnvOuterSlot'] ?? null;
            if ($iterEnvOuterSlot === null) {
                // Every other entry backs a real TRY_ENTER, which this must
                // leave to match; a loop's per-iteration environment does
                // not (see beginLoopEnv) -- there is no live handler here to
                // unregister.
                $c->emit(Op::TRY_LEAVE);
            }
            if (($entry['iterSlot'] ?? null) !== null) {
                // Leaving a `for…of` other than by exhausting it: the iterator
                // is told, so a generator's `finally` runs (7.4.9).
                $c->emit(Op::GET_LOCAL, $entry['iterSlot']);
                $c->emit(Op::ITER_CLOSE, 0);
                continue;
            }
            if (($entry['iterRec'] ?? null) !== null) {
                // The same, for an array pattern left part-way through: a
                // `return` inside one of its defaults, say.
                $c->emit(Op::ITER_FIN, $entry['iterRec'], 0);
                continue;
            }
            if ($iterEnvOuterSlot !== null) {
                // Leaving a loop with a per-iteration environment other than
                // by exhausting it: the environment active outside the loop
                // must be current again before whatever this exit reaches
                // next runs (a `catch`/`finally` at the target, or ordinary
                // code after the loop).
                $c->emit(Op::GET_LOCAL, $iterEnvOuterSlot);
                $c->emit(Op::RESTORE_ENV);
                continue;
            }
            if ($entry['finalizer'] !== null) {
                // Inline a copy with the outer regions only, so exits inside
                // this copy don't re-run the same finalizer.
                $saved = $c->tryStack;
                $c->tryStack = array_slice($c->tryStack, 0, $i);
                $this->genFinalizerInline($entry['finalizer']);
                $c->tryStack = $saved;
            }
        }
    }

    // ---- per-iteration loop bindings -------------------------------------------

    /**
     * Open a loop's per-iteration environment for codegen, if analysis
     * decided it needs one at all (`$scope->size > 0` -- the overwhelming
     * majority of loops have no entry, or an empty one, and this returns
     * null for either, changing nothing about how they compile). Emits
     * `CAPTURE_ENV` once, for the whole loop's lifetime, and records a
     * bookkeeping-only `tryStack` entry for `emitExitCleanup` -- not a real
     * `TRY_ENTER` -- since a `break`/`return` crossing the loop is the only
     * thing that needs telling to restore the outer environment. An
     * exception unwinding through needs nothing done here at all: whichever
     * real handler actually catches it (inside the loop, or an ordinary
     * `try` outside it) already captured the environment that was live *at
     * its own* `TRY_ENTER`, which is exactly the one that should be current
     * once it runs -- the per-iteration environment if the handler is
     * inside the loop, the outer one if it is not. Nothing about a loop in
     * between needs to add anything to that.
     *
     * @return array{scope: EnvScope, outerSlot: int}|null
     */
    private function beginLoopEnv(object $node): ?array
    {
        $scope = $this->loopEnvScopes[$node] ?? null;
        if ($scope === null || $scope->size === 0) {
            return null;
        }
        $c = $this->cur;
        $outerSlot = $c->tempAlloc();
        $c->emit(Op::CAPTURE_ENV, $outerSlot);
        $c->tryStack[] = ['finalizer' => null, 'iterEnvOuterSlot' => $outerSlot];
        $this->loopEnvStack[] = $scope;
        return ['scope' => $scope, 'outerSlot' => $outerSlot];
    }

    /**
     * Replace the current environment with a brand new one -- for a loop
     * form with no per-iteration value to carry forward (`for…of`, `for…in`,
     * `while`, `do…while`: their own bound names, if any, are freshly
     * (re-)declared every pass regardless, the same as entering an ordinary
     * block would do). Called once per iteration, right before whatever
     * declares those names runs.
     */
    private function freshLoopEnv(array $iterEnv): void
    {
        $this->cur->emit(Op::NEW_ITER_ENV, $iterEnv['outerSlot'], $iterEnv['scope']->size);
    }

    /**
     * The one loop form that does carry a value forward: a classic `for`'s
     * own head bindings (14.7.4.3's CreatePerIterationEnvironment). Reads
     * each one's current value out of the environment about to be replaced,
     * creates the fresh one, then writes them back -- the body's own block
     * bindings (if it has one) are not among these, and need no carrying
     * forward at all, only the fresh environment itself.
     */
    private function copyForwardLoopEnv(object $node, array $iterEnv): void
    {
        $c = $this->cur;
        /** @var list<Binding> $carried */
        $carried = array_values(array_filter(
            $this->blockScopes[$node] ?? [],
            static fn (Binding $b): bool => $b->captured && $b->envScope !== null,
        ));
        foreach ($carried as $b) {
            $c->emit(Op::GET_ENV, 0, $b->envIndex);
        }
        $this->freshLoopEnv($iterEnv);
        foreach (array_reverse($carried) as $b) {
            $c->emit(Op::SET_ENV, 0, $b->envIndex);
            $c->emit(Op::POP);
        }
    }

    /**
     * Close a loop's per-iteration environment on the normal-exit path
     * (running off the end, or every `break` -- both land here the same way
     * `popLoop` already unifies them for everything else). Restores the
     * environment that was current before the loop; see `beginLoopEnv` for
     * why the exception path needs no equivalent of its own.
     */
    private function endLoopEnv(array $iterEnv): void
    {
        $c = $this->cur;
        array_pop($this->loopEnvStack);
        array_pop($c->tryStack);
        $c->emit(Op::GET_LOCAL, $iterEnv['outerSlot']);
        $c->emit(Op::RESTORE_ENV);
        $c->tempFree($iterEnv['outerSlot']);
    }

    // ---- parameter/body variable scope (9.2.12) --------------------------------

    /**
     * Carry a parameter's current value into its body-declared namesake, and
     * -- only if analysis found a closure capturing something the body
     * declares (`$scope->size > 0`; the overwhelming majority of non-simple
     * parameter lists have none, and this changes nothing for them, same as
     * an empty loop per-iteration environment) -- give the body its own
     * separate variable environment to hold it in.
     *
     * The carrying-forward happens either way, captured or not: unlike a
     * loop's per-iteration binding, where "uncaptured" means every iteration
     * keeps reusing the very same slot (so there is nothing to carry, it is
     * already the same value), a parameter and its same-named body `var` are
     * always two distinct slots, one in this function's own frame/environment
     * and one that is either another plain local slot or a slot in the new
     * environment -- either way it starts with no value of its own until
     * this copies one in.
     *
     * Called once, after the parameter prologue and before any hoisted
     * function declaration is stored -- those need to land in the new
     * environment when it exists. Unlike a loop's per-iteration environment,
     * this one is never restored: it is current for the rest of the
     * function's own execution, so the `loopEnvStack` push below has no
     * matching pop here -- `genFunction` discards its own entry wholesale on
     * return, the same way it already does for a loop's.
     *
     * Every value is read before the environment is (maybe) replaced, since
     * only then does depth 0 still mean the parameter's own; a name with no
     * such collision simply starts undefined, same as `NEW_ITER_ENV` already
     * leaves any slot nothing writes into.
     */
    private function beginParamEnv(Ctx $ctx): void
    {
        $scope = $ctx->paramEnvScope;
        $c = $this->cur;
        /** @var list<Binding> $carried */
        $carried = array_values(array_filter(
            $ctx->bodyBindings,
            static fn (Binding $b): bool => isset($ctx->bindings[$b->name]),
        ));
        foreach ($carried as $b) {
            $this->emitLoadBinding($ctx->bindings[$b->name]);
        }
        if ($scope->size > 0) {
            $outerSlot = $c->tempAlloc();
            $c->emit(Op::CAPTURE_ENV, $outerSlot);
            $c->emit(Op::NEW_ITER_ENV, $outerSlot, $scope->size);
            $c->tempFree($outerSlot);
            $this->loopEnvStack[] = $scope;
        }
        foreach (array_reverse($carried) as $b) {
            $this->emitStoreBinding($b);
            $c->emit(Op::POP);
        }
    }

    // ---- generators -----------------------------------------------------------

    private function genYield(object $node): void
    {
        if ($node->getDelegate()) {
            $this->genYieldDelegate($node);
            return;
        }
        if ($node->getArgument() !== null) {
            $this->genExpr($node->getArgument());
        } else {
            $this->cur->emit(Op::PUSH_UNDEF);
        }
        $this->genYieldSuspend();
    }

    /**
     * Emits `YIELD` plus the fixed three-way dispatch every suspension point
     * shares, driven by the resume mode a later `next`/`throw`/`return`
     * writes into the two local slots YIELD takes: NEXT leaves the sent
     * value as this expression's result; THROW re-throws it, which the
     * frame's own (already-restored) exception-handler table catches
     * exactly as it would a real `THROW` at this point; RETURN runs this
     * point's enclosing finalizers (`emitExitCleanup`, the same one a
     * written `return` statement here would use) and returns it. `yield*`'s
     * delegation loop emits a `YIELD` of its own but reads the mode itself
     * instead of this fixed shape, to decide how to forward to the inner
     * iterator.
     */
    private function genYieldSuspend(): void
    {
        $c = $this->cur;
        $sentSlot = $c->tempAlloc();
        $modeSlot = $c->tempAlloc();
        $c->emit(Op::YIELD, $sentSlot, $modeSlot);

        $c->emit(Op::GET_LOCAL, $modeSlot);
        $c->emit(Op::PUSH_INT, Op::YIELD_THROW);
        $jThrow = $c->emitJump(Op::JSEQ);
        $c->emit(Op::GET_LOCAL, $modeSlot);
        $c->emit(Op::PUSH_INT, Op::YIELD_RETURN);
        $jReturn = $c->emitJump(Op::JSEQ);
        $jNormal = $c->emitJump(Op::JMP);

        $c->patch($jThrow);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $c->emit(Op::THROW);

        $c->patch($jReturn);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $this->emitExitCleanup(0);
        $c->emit(Op::RETURN);

        $c->patch($jNormal);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $c->tempFree($modeSlot);
        $c->tempFree($sentSlot);
    }

    // ---- async functions --------------------------------------------------------

    /**
     * `await expr`'s suspend plus the fixed two-way dispatch every resume
     * reaches, driven by the mode `Vm::resumeAsync` writes into the same two
     * local slots `YIELD`'s own suspend uses: fulfilled leaves the settled
     * value as this expression's result, rejected re-throws it -- which the
     * frame's own (already-restored) exception-handler table catches exactly
     * as it would a real `THROW` here. No third mode: nothing external can
     * `.return()` an in-flight await the way it can a suspended generator, so
     * there is no enclosing-finalizer case to run, unlike `genYieldSuspend`.
     */
    private function genAwaitSuspend(): void
    {
        $c = $this->cur;
        $sentSlot = $c->tempAlloc();
        $modeSlot = $c->tempAlloc();
        $c->emit(Op::AWAIT, $sentSlot, $modeSlot);

        $c->emit(Op::GET_LOCAL, $modeSlot);
        $c->emit(Op::PUSH_INT, Op::YIELD_THROW);
        $jRejected = $c->emitJump(Op::JSEQ);
        $jFulfilled = $c->emitJump(Op::JMP);

        $c->patch($jRejected);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $c->emit(Op::THROW);

        $c->patch($jFulfilled);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $c->tempFree($modeSlot);
        $c->tempFree($sentSlot);
    }

    // ---- optional chaining ------------------------------------------------------

    /**
     * A `ChainExpression`'s wrapped expression: compiles exactly like the
     * ordinary `MemberExpression`/`CallExpression` cases (recursing into
     * `getObject()`/`getCallee()` the same way), except that each one now
     * also calls `emitOptionalCheck` for a step actually marked `?.` --
     * every other step, optional or not, is unaffected and unaware it sits
     * inside a chain at all, which is exactly why a non-optional step after
     * an earlier `?.` still gets skipped: the earlier check's jump lands
     * past all of it, not because the later step does anything different.
     *
     * `$chainStack` collects one entry per short-circuit jump found while
     * compiling `$node`; once done, they all get patched to a single shared
     * landing pad placed after the chain's own normal-path result, which
     * pops the leftover nullish value every jump site leaves behind and
     * pushes `undefined` in its place -- the chain's real result either way.
     * A chain with no `?.` step at all that somehow reached here (impossible
     * by construction: Peast only wraps a chain that has one) would just
     * compile through with nothing to patch, which is why this only emits
     * the landing pad when `$pending` is non-empty.
     */
    private function genChain(object $node): void
    {
        $this->chainStack[] = [];
        $this->genExpr($node);
        $pending = array_pop($this->chainStack);
        if ($pending === []) {
            return;
        }
        $c = $this->cur;
        $jEnd = $c->emitJump(Op::JMP);
        foreach ($pending as $site) {
            $c->patch($site);
        }
        $c->emit(Op::POP);
        $c->emit(Op::PUSH_UNDEF);
        $c->patch($jEnd);
    }

    /**
     * `?.`'s own check: the value to test is already on top of the stack:
     * `DUP` gives the check something to consume while leaving the original
     * in place for the fallthrough (not-nullish) path to keep working with,
     * same as `??`'s own `JNN_KEEP` does it the other way around. Every call
     * site in `genExpr` arranges for exactly one value -- never more -- to
     * be on the stack at this point (a member access's object, a bare
     * call's callee, or a method call's already-isolated function value),
     * which is what lets every jump this records converge on `genChain`'s
     * one shared landing pad.
     */
    private function emitOptionalCheck(): void
    {
        $c = $this->cur;
        $c->emit(Op::DUP);
        $site = $c->emitJump(Op::JNULLISH);
        $this->chainStack[count($this->chainStack) - 1][] = $site;
    }

    /**
     * `[func, this]` for a member-expression call target -- `a.b(...)` and
     * every `?.` variant of it. Honors the member's own optional step
     * (checked against whatever chain scope is already open; the caller's
     * job to have one open) and, separately, the call's own optional step
     * (`a.b?.()`/`(a.b)?.()`): `GET_METHOD`/`GET_METHOD_ELEM` normally fuse
     * `[func, this]` into one step with `this` landing on top, which makes
     * `func` awkward to check once it is buried underneath -- a temp isolates
     * `this` off the operand stack instead, so the check still runs against
     * a single value the same as every other step in a chain does, and
     * `this` is only read back once the call is known to happen at all.
     */
    private function genMemberCallTarget(object $member, bool $callOptional): void
    {
        $c = $this->cur;
        $this->genExpr($member->getObject());
        if ($member->getOptional()) {
            $this->emitOptionalCheck();
        }
        if ($callOptional) {
            $t = $c->tempAlloc();
            $c->emit(Op::SET_LOCAL, $t);
            $c->emit(Op::POP);
            $c->emit(Op::GET_LOCAL, $t);
            if ($member->getComputed()) {
                $this->genExpr($member->getProperty());
                $c->emit(Op::GET_ELEM);
            } else {
                $c->emit(Op::GET_PROP, $c->constIndex($member->getProperty()->getName()));
            }
            $this->emitOptionalCheck();
            $c->emit(Op::GET_LOCAL, $t);
            $c->tempFree($t);
        } elseif ($member->getComputed()) {
            $this->genExpr($member->getProperty());
            $c->emit(Op::GET_METHOD_ELEM);
        } else {
            $c->emit(Op::GET_METHOD, $c->constIndex($member->getProperty()->getName()));
        }
    }

    /**
     * `delete` on a chain ending in a member access (`delete a?.b`, or
     * `delete a?.b.c`, never `delete a?.b()` -- see the call site). Reuses
     * `genChain`'s own machinery for everything up to the last step (a
     * nested `?.` in the object position registers into the same
     * `$chainStack` entry exactly like it would compiling an ordinary
     * chain), but the last step is a real `DEL_ELEM` instead of a
     * `GET_ELEM`/`GET_PROP` read, and short-circuiting means "nothing to
     * delete" -- trivially `true`, not the ordinary chain's `undefined`.
     */
    private function genDeleteChain(object $member): void
    {
        $c = $this->cur;
        $this->chainStack[] = [];
        $this->genExpr($member->getObject());
        if ($member->getOptional()) {
            $this->emitOptionalCheck();
        }
        if ($member->getComputed()) {
            $this->genExpr($member->getProperty());
        } else {
            $c->emit(Op::PUSH_CONST, $c->constIndex($member->getProperty()->getName()));
        }
        $c->emit(Op::DEL_ELEM);
        $pending = array_pop($this->chainStack);
        if ($pending === []) {
            return;
        }
        $jEnd = $c->emitJump(Op::JMP);
        foreach ($pending as $site) {
            $c->patch($site);
        }
        $c->emit(Op::POP);
        $c->emit(Op::PUSH_TRUE);
        $c->patch($jEnd);
    }

    /**
     * `yield* expr` (13.3.8.1): a compiled loop around `YIELD_DELEGATE_STEP`,
     * which does one call into the inner iterable per pass and reports back
     * `[value, done]`. Not done: yield `value` out through a plain `YIELD`
     * (reusing the exact same primitive `genYieldSuspend` uses) and feed how
     * *this* yield* got resumed back in as next pass's mode/value -- which is
     * exactly what YIELD's own resume already writes into `sentSlot`/
     * `modeSlot`, so the loop reuses them as its "received completion"
     * tracking variables too. Done: `value` is the delegation's result,
     * unless the pass that produced it was itself a RETURN, in which case
     * it is an outward return propagating through this yield*'s own
     * enclosing finalizers, not this expression's value.
     */
    private function genYieldDelegate(object $node): void
    {
        $c = $this->cur;
        $this->genExpr($node->getArgument());
        $c->emit(Op::ITER_GET);
        $nextSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $nextSlot);
        $c->emit(Op::POP);
        $iterSlot = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $iterSlot);
        $c->emit(Op::POP);

        $sentSlot = $c->tempAlloc();
        $modeSlot = $c->tempAlloc();
        $c->emit(Op::PUSH_UNDEF);
        $c->emit(Op::SET_LOCAL, $sentSlot);
        $c->emit(Op::POP);
        $c->emit(Op::PUSH_INT, Op::YIELD_NEXT);
        $c->emit(Op::SET_LOCAL, $modeSlot);
        $c->emit(Op::POP);

        $lLoop = $c->here();
        $c->emit(Op::GET_LOCAL, $iterSlot);
        $c->emit(Op::GET_LOCAL, $nextSlot);
        $c->emit(Op::GET_LOCAL, $modeSlot);
        $c->emit(Op::GET_LOCAL, $sentSlot);
        $c->emit(Op::YIELD_DELEGATE_STEP);
        $jDone = $c->emitJump(Op::JT);
        $c->emit(Op::YIELD, $sentSlot, $modeSlot);
        $c->emit(Op::JMP, $lLoop);

        $c->patch($jDone);
        $c->emit(Op::GET_LOCAL, $modeSlot);
        $c->emit(Op::PUSH_INT, Op::YIELD_RETURN);
        $jPropagate = $c->emitJump(Op::JSEQ);
        $jSkip = $c->emitJump(Op::JMP);

        $c->patch($jPropagate);
        $this->emitExitCleanup(0);
        $c->emit(Op::RETURN);

        $c->patch($jSkip);
        $c->tempFree($modeSlot);
        $c->tempFree($sentSlot);
        $c->tempFree($iterSlot);
        $c->tempFree($nextSlot);
    }

    // ---- loops / labels -----------------------------------------------------

    /** @param list<string> $labels */
    private function pushLoop(array $labels, bool $isLoop): int
    {
        $this->cur->loopStack[] = [
            'labels' => $labels,
            'breaks' => [],
            'continues' => [],
            'isLoop' => $isLoop,
            'tryDepth' => count($this->cur->tryStack),
        ];
        return count($this->cur->loopStack) - 1;
    }

    private function patchContinues(int $index, int $target): void
    {
        $c = $this->cur;
        foreach ($c->loopStack[$index]['continues'] as $site) {
            $c->patchTo($site, $target);
        }
        $c->loopStack[$index]['continues'] = [];
    }

    private function popLoop(int $index): void
    {
        $c = $this->cur;
        $entry = array_pop($c->loopStack);
        foreach ($entry['breaks'] as $site) {
            $c->patch($site);
        }
    }

    /** @return array{index: int, tryDepth: int} */
    private function findLoop(?string $label, bool $forContinue): array
    {
        $c = $this->cur;
        for ($i = count($c->loopStack) - 1; $i >= 0; $i--) {
            $entry = $c->loopStack[$i];
            if ($label !== null) {
                if (in_array($label, $entry['labels'], true) && (!$forContinue || $entry['isLoop'])) {
                    return ['index' => $i, 'tryDepth' => $entry['tryDepth']];
                }
            } elseif ($forContinue ? $entry['isLoop'] : true) {
                return ['index' => $i, 'tryDepth' => $entry['tryDepth']];
            }
        }
        throw new CompileError(
            $label !== null
                ? "Undefined label '$label'"
                : ($forContinue ? "'continue' outside of loop" : "'break' outside of loop or switch")
        );
    }

    // ---- expressions --------------------------------------------------------

    private function genExpr(object $node): void
    {
        $c = $this->cur;
        $type = $node->getType();
        switch ($type) {
            case 'Literal':
            case 'RegExpLiteral':
                $this->genLiteral($node);
                return;
            case 'Identifier':
                $this->emitLoadName($node->getName());
                return;
            case 'ParenthesizedExpression':
                $this->genExpr($node->getExpression());
                return;
            case 'ThisExpression':
                $c->emit(Op::PUSH_THIS);
                return;
            case 'FunctionExpression':
            case 'ArrowFunctionExpression': {
                $childCtx = $this->fnCtx[$node];
                $idx = $this->compileChild($childCtx, $node);
                $c->emit(Op::NEW_FUNC, $idx);
                return;
            }
            case 'ClassExpression':
                $this->genClass($node);
                return;
            case 'YieldExpression':
                $this->genYield($node);
                return;
            case 'AwaitExpression':
                $this->genExpr($node->getArgument());
                $this->genAwaitSuspend();
                return;
            case 'ArrayExpression': {
                $elements = $node->getElements();
                if (!self::hasSpread($elements)) {
                    foreach ($elements as $el) {
                        if ($el === null) {
                            $c->emit(Op::PUSH_HOLE);
                        } else {
                            $this->genExpr($el);
                        }
                    }
                    $c->emit(Op::NEW_ARRAY, count($elements));
                    return;
                }
                // With a spread the length is not known until it runs, so the
                // array is grown one element at a time instead.
                $c->emit(Op::NEW_ARRAY, 0);
                foreach ($elements as $el) {
                    if ($el === null) {
                        $c->emit(Op::PUSH_HOLE);
                        $c->emit(Op::ARR_APPEND);
                        continue;
                    }
                    if ($el->getType() === 'SpreadElement') {
                        $this->genExpr($el->getArgument());
                        $c->emit(Op::ARR_SPREAD);
                        continue;
                    }
                    $this->genExpr($el);
                    $c->emit(Op::ARR_APPEND);
                }
                return;
            }
            case 'ObjectExpression':
                $c->emit(Op::NEW_OBJECT);
                foreach ($node->getProperties() as $p) {
                    if ($p->getType() === 'SpreadElement') {
                        $this->genExpr($p->getArgument());
                        $c->emit(Op::OBJ_SPREAD);
                        continue;
                    }
                    // A computed key is an expression evaluated here, in
                    // source order, and converted once. A copy survives in a
                    // temp slot for SET_FUNC_NAME below, since a computed
                    // key's SetFunctionName name (unlike every other
                    // NamedEvaluation trigger) is not known until this runs.
                    $computed = $p->getComputed();
                    $kidx = null;
                    $keySlot = null;
                    if ($computed) {
                        $this->genExpr($p->getKey());
                        $c->emit(Op::TO_KEY);
                        $keySlot = $c->tempAlloc();
                        $c->emit(Op::SET_LOCAL, $keySlot);
                    } else {
                        $kidx = $c->constIndex($this->propertyKeyString($p->getKey()));
                    }
                    if ($p->getKind() === 'init') {
                        $this->genExpr($p->getValue());
                        if ($computed && self::isAnonymousFunctionDefinition($p->getValue())) {
                            $c->emit(Op::GET_LOCAL, $keySlot);
                            $c->emit(Op::SET_FUNC_NAME, $c->constIndex(''));
                        }
                        if ($computed) {
                            $c->emit(Op::DEFINE_DATA_ELEM);
                        } else {
                            $c->emit(Op::DEFINE_DATA, $kidx);
                        }
                    } else {
                        $fnNode = $p->getValue();
                        $childCtx = $this->fnCtx[$fnNode];
                        $idx = $this->compileChild($childCtx, $fnNode);
                        $c->emit(Op::NEW_FUNC, $idx);
                        $isGet = $p->getKind() === 'get';
                        if ($computed) {
                            // Always anonymous by grammar -- no eligibility
                            // check needed, unlike the 'init' case above.
                            $c->emit(Op::GET_LOCAL, $keySlot);
                            $c->emit(Op::SET_FUNC_NAME, $c->constIndex($isGet ? 'get ' : 'set '));
                            $c->emit($isGet ? Op::DEFINE_GETTER_ELEM : Op::DEFINE_SETTER_ELEM);
                        } else {
                            $c->emit($isGet ? Op::DEFINE_GETTER : Op::DEFINE_SETTER, $kidx);
                        }
                    }
                    if ($keySlot !== null) {
                        $c->tempFree($keySlot);
                    }
                }
                return;
            case 'MemberExpression':
                if ($node->getObject()->getType() === 'Super') {
                    if ($node->getComputed()) {
                        $this->genExpr($node->getProperty());
                        $c->emit(Op::GET_SUPER_ELEM);
                    } else {
                        $c->emit(Op::GET_SUPER, $c->constIndex($node->getProperty()->getName()));
                    }
                    return;
                }
                $this->genExpr($node->getObject());
                if ($node->getOptional()) {
                    $this->emitOptionalCheck();
                }
                if ($node->getComputed()) {
                    $this->genExpr($node->getProperty());
                    $c->emit(Op::GET_ELEM);
                } else {
                    $c->emit(Op::GET_PROP, $c->constIndex($node->getProperty()->getName()));
                }
                return;
            case 'ChainExpression':
                $this->genChain($node->getExpression());
                return;
            case 'CallExpression': {
                $callee = self::unwrapParens($node->getCallee());
                $args = $node->getArguments();
                if ($callee->getType() === 'Super') {
                    // `super(...)`: the parent constructor -- closed over the
                    // way any other captured name is -- run against the `this`
                    // the derived class's own [[Construct]] already built.
                    $this->emitLoadName(self::SUPERCLASS_SLOT);
                    $c->emit(Op::PUSH_THIS);
                    if (self::hasSpread($args)) {
                        $this->genArgumentArray($args);
                        $c->emit(Op::SUPER_CALL_SPREAD);
                        return;
                    }
                    foreach ($args as $a) {
                        $this->genExpr($a);
                    }
                    $c->emit(Op::SUPER_CALL, count($args));
                    return;
                }
                if ($callee->getType() === 'MemberExpression' && $callee->getObject()->getType() === 'Super') {
                    if ($callee->getComputed()) {
                        $this->genExpr($callee->getProperty());
                        $c->emit(Op::GET_SUPER_METHOD_ELEM);
                    } else {
                        $c->emit(Op::GET_SUPER_METHOD, $c->constIndex($callee->getProperty()->getName()));
                    }
                } elseif ($callee->getType() === 'MemberExpression') {
                    $this->genMemberCallTarget($callee, $node->getOptional());
                } elseif ($callee->getType() === 'ChainExpression' && $callee->getExpression()->getType() === 'MemberExpression') {
                    // `(a?.b)()`/`(a?.b)?.()`: parens close the inner chain
                    // right here -- it never reaches past this call -- but
                    // the parenthesized member expression's `this`-preserving
                    // Reference semantics survive exactly like `(a.b)()`'s
                    // already do. Its own optional step has nowhere else to
                    // land, so it gets a chain scope of its own here rather
                    // than relying on an enclosing `genChain`'s.
                    $member = $callee->getExpression();
                    $this->chainStack[] = [];
                    $this->genMemberCallTarget($member, $node->getOptional());
                    if ($node->getOptional()) {
                        // The call is *also* optional: a short circuit from
                        // either step -- the member's own or the call's own,
                        // both landing in this same local scope -- must skip
                        // the call entirely, not just feed it two undefineds
                        // and let it throw, so the scope has to stay open
                        // through the args and CALL below (inlined here)
                        // rather than closing right after the target.
                        if (self::hasSpread($args)) {
                            $this->genArgumentArray($args);
                            $c->emit(Op::CALL_SPREAD);
                        } else {
                            foreach ($args as $a) {
                                $this->genExpr($a);
                            }
                            $c->emit(Op::CALL, count($args));
                        }
                        $pending = array_pop($this->chainStack);
                        if ($pending !== []) {
                            $jEnd = $c->emitJump(Op::JMP);
                            foreach ($pending as $site) {
                                $c->patch($site);
                            }
                            $c->emit(Op::POP);
                            $c->emit(Op::PUSH_UNDEF);
                            $c->patch($jEnd);
                        }
                        return;
                    }
                    $pending = array_pop($this->chainStack);
                    if ($pending !== []) {
                        $jEnd = $c->emitJump(Op::JMP);
                        foreach ($pending as $site) {
                            $c->patch($site);
                        }
                        $c->emit(Op::POP);
                        $c->emit(Op::PUSH_UNDEF);
                        $c->emit(Op::PUSH_UNDEF);
                        $c->patch($jEnd);
                    }
                } else {
                    $this->genExpr($callee);
                    if ($node->getOptional()) {
                        $this->emitOptionalCheck();
                    }
                    $c->emit(Op::PUSH_UNDEF);
                }
                if (self::hasSpread($args)) {
                    // [func this argsArray]: the count is only known at run
                    // time, so the arguments travel as an array.
                    $this->genArgumentArray($args);
                    $c->emit(Op::CALL_SPREAD);
                    return;
                }
                foreach ($args as $a) {
                    $this->genExpr($a);
                }
                $c->emit(Op::CALL, count($args));
                return;
            }
            case 'NewExpression': {
                $this->genExpr($node->getCallee());
                $args = $node->getArguments();
                if (self::hasSpread($args)) {
                    $this->genArgumentArray($args);
                    $c->emit(Op::NEW_SPREAD);
                    return;
                }
                foreach ($args as $a) {
                    $this->genExpr($a);
                }
                $c->emit(Op::NEW_OP, count($args));
                return;
            }
            case 'TaggedTemplateExpression': {
                // Same [func, this] setup as an ordinary call -- a tag reached
                // through a member expression still gets that object as `this`.
                $tag = self::unwrapParens($node->getTag());
                if ($tag->getType() === 'MemberExpression') {
                    $this->genExpr($tag->getObject());
                    if ($tag->getComputed()) {
                        $this->genExpr($tag->getProperty());
                        $c->emit(Op::GET_METHOD_ELEM);
                    } else {
                        $c->emit(Op::GET_METHOD, $c->constIndex($tag->getProperty()->getName()));
                    }
                } else {
                    $this->genExpr($tag);
                    $c->emit(Op::PUSH_UNDEF);
                }
                $this->genTemplateObject($node->getQuasi());
                $exprs = $node->getQuasi()->getExpressions();
                foreach ($exprs as $e) {
                    $this->genExpr($e);
                }
                $c->emit(Op::CALL, count($exprs) + 1);
                return;
            }
            case 'UnaryExpression':
                $this->genUnary($node);
                return;
            case 'UpdateExpression':
                $this->genUpdate($node);
                return;
            case 'BinaryExpression': {
                $this->genExpr($node->getLeft());
                $this->genExpr($node->getRight());
                $c->emit(self::binaryOp($node->getOperator()) ?? $this->unsupported($node, 'Unsupported operator ' . $node->getOperator()));
                return;
            }
            case 'LogicalExpression': {
                $op = $node->getOperator();
                $jumpOp = match ($op) {
                    '&&' => Op::JF_KEEP,
                    '||' => Op::JT_KEEP,
                    '??' => Op::JNN_KEEP,
                    default => $this->unsupported($node, "Operator '$op' is not supported (ES5 target)"),
                };
                $this->genExpr($node->getLeft());
                $j = $c->emitJump($jumpOp);
                $this->genExpr($node->getRight());
                $c->patch($j);
                return;
            }
            case 'ConditionalExpression': {
                $this->genExpr($node->getTest());
                $jElse = $c->emitJump(Op::JF);
                $this->genExpr($node->getConsequent());
                $jEnd = $c->emitJump(Op::JMP);
                $c->patch($jElse);
                $this->genExpr($node->getAlternate());
                $c->patch($jEnd);
                return;
            }
            case 'AssignmentExpression':
                $this->genAssignment($node);
                return;
            case 'TemplateLiteral':
                $this->genTemplate($node);
                return;
            case 'SequenceExpression': {
                $exprs = $node->getExpressions();
                $n = count($exprs);
                foreach ($exprs as $i => $e) {
                    $this->genExpr($e);
                    if ($i < $n - 1) {
                        $c->emit(Op::POP);
                    }
                }
                return;
            }
            default:
                $this->unsupported($node, "Unsupported expression: $type (ES5 target; downlevel first)");
        }
    }

    /**
     * The `[cooked...]` array (with `raw` attached) a tag function receives as
     * its first argument. Emits NEW_TAG_TEMPLATE, which resolves this call
     * site's identity from the cache -- see NEW_TAG_TEMPLATE and
     * Realm::templateObject.
     *
     * The cache key only has to be unique within this compilation
     * ($compilationId does that across compilations) and stable across every
     * evaluation of this exact site, so a plain incrementing counter over the
     * tagged templates this compiler visits is enough; it does not need to
     * mean anything.
     */
    private function genTemplateObject(object $quasiNode): void
    {
        $c = $this->cur;
        $cooked = [];
        $raw = [];
        foreach ($quasiNode->getQuasis() as $quasi) {
            $cooked[] = $this->cookTemplate($quasi, true);
            $raw[] = str_replace(["\r\n", "\r"], "\n", $quasi->getRawValue());
        }
        $key = $this->compilationId . ':' . $this->nextTemplateIndex++;
        $c->emit(
            Op::NEW_TAG_TEMPLATE,
            $c->constIndex($key),
            $c->constIndex($cooked),
            $c->constIndex($raw)
        );
    }

    /**
     * A template literal is string concatenation, with one wrinkle that stops
     * it being a plain rewrite to `+`: each substitution is converted with
     * ToString (13.2.8.5), while `+` converts with ToPrimitive under the
     * default hint. An object carrying both `valueOf` and `toString` tells the
     * two apart, so the substitutions get an explicit TOSTR.
     *
     * Once the accumulator is a string every later ADD takes the string fast
     * path, which is why an empty quasi can simply be skipped.
     */
    private function genTemplate(object $node): void
    {
        $c = $this->cur;
        $quasis = $node->getQuasis();
        $exprs = $node->getExpressions();

        $cooked = [];
        foreach ($quasis as $i => $quasi) {
            $cooked[$i] = $this->cookTemplate($quasi);
        }

        if ($exprs === []) {
            $c->emit(Op::PUSH_CONST, $c->constIndex($cooked[0]));
            return;
        }

        $started = false;
        foreach ($quasis as $i => $quasi) {
            $text = $cooked[$i];
            if ($text !== '') {
                $c->emit(Op::PUSH_CONST, $c->constIndex($text));
                if ($started) {
                    $c->emit(Op::ADD);
                }
                $started = true;
            }
            if (!isset($exprs[$i])) {
                continue;
            }
            $this->genExpr($exprs[$i]);
            $c->emit(Op::TOSTR);
            if ($started) {
                $c->emit(Op::ADD);
            }
            $started = true;
        }
        if (!$started) {
            // Every quasi empty and no substitutions left: `` produces "".
            $c->emit(Op::PUSH_CONST, $c->constIndex(''));
        }
    }

    /**
     * A plain template rejects the escapes a sloppy string tolerates; a tagged
     * one does not, because its cooked value is allowed to be `undefined`
     * instead (12.9.6) -- the tag still sees the raw text, and a template
     * built for one syntax (e.g. a regex DSL) can freely use escapes that mean
     * nothing to JS.
     *
     * Peast itself refuses a malformed `\u` at parse time, but only outside a
     * tag -- inside one it hands back the raw text unexamined, which is why
     * this scan has to check `\x` and `\u` itself rather than leaving them to
     * `decodeStringLiteral` (which never throws: an escape it does not
     * recognise as well-formed it treats as standing for itself, the
     * permissive reading a plain string needs it *not* to have here).
     *
     * @return string|null null only when `$tagged` and the text is malformed,
     *                      meaning the cooked value is `undefined`
     */
    private function cookTemplate(object $quasi, bool $tagged = false): ?string
    {
        $raw = $quasi->getRawValue();
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '\\') {
                continue;
            }
            $next = $raw[++$i] ?? '';
            if ($next === '8' || $next === '9') {
                if ($tagged) {
                    return null;
                }
                $this->fail($quasi, "'\\$next' is not a valid escape in a template literal");
            }
            if ($next >= '0' && $next <= '7') {
                // \0 not followed by a digit is the NUL escape and is fine;
                // anything else in that range is a legacy octal.
                $following = $raw[$i + 1] ?? '';
                if ($next !== '0' || ($following >= '0' && $following <= '9')) {
                    if ($tagged) {
                        return null;
                    }
                    $this->fail($quasi, 'Octal escapes are not allowed in a template literal');
                }
            }
            if ($tagged && $next === 'x') {
                $hex = substr($raw, $i + 1, 2);
                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    return null;
                }
            }
            if ($tagged && $next === 'u') {
                if (($raw[$i + 1] ?? '') === '{') {
                    $close = strpos($raw, '}', $i + 2);
                    $digits = $close === false ? '' : substr($raw, $i + 2, $close - $i - 2);
                    if ($digits === '' || !ctype_xdigit($digits) || hexdec($digits) > 0x10FFFF) {
                        return null;
                    }
                } else {
                    $hex = substr($raw, $i + 1, 4);
                    if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                        return null;
                    }
                }
            }
        }

        // Cooked here rather than taken from the parser, which leaves `\0`
        // undecoded. Correcting the parser's cooked value afterwards is not
        // possible: `\\0` legitimately cooks to a backslash and a zero, so by
        // then the two are indistinguishable. decodeStringLiteral strips one
        // delimiter from each end, so any delimiter will do -- and a line
        // terminator is normalised first, which a string literal never has to
        // do because it cannot contain one.
        return self::decodeStringLiteral('`' . str_replace(["\r\n", "\r"], "\n", $raw) . '`');
    }

    private function genLiteral(object $node): void
    {
        $c = $this->cur;
        if ($node instanceof NullLiteral) {
            $c->emit(Op::PUSH_NULL);
        } elseif ($node instanceof BooleanLiteral) {
            $c->emit($node->getValue() ? Op::PUSH_TRUE : Op::PUSH_FALSE);
        } elseif ($node instanceof NumericLiteral) {
            $v = $node->getValue();
            // Only literals a double holds exactly may become PHP ints
            // (Conversions::MAX_EXACT_INT); larger ones must keep the double
            // they actually denote.
            $exact = Conversions::MAX_EXACT_INT;
            if (is_int($v) && $v >= -$exact && $v <= $exact) {
                $c->emit(Op::PUSH_INT, $v);
            } elseif (is_float($v) && $v == floor($v) && abs($v) <= $exact && $v != 0.0) {
                $c->emit(Op::PUSH_INT, (int)$v);
            } else {
                $c->emit(Op::PUSH_CONST, $c->constIndex(is_float($v) ? $v : (float)$v));
            }
        } elseif ($node instanceof StringLiteral) {
            if ($c->strict) {
                $this->checkStrictStringEscapes($node);
            }
            $c->emit(Op::PUSH_CONST, $c->constIndex(self::decodeStringLiteral($node->getRaw())));
        } elseif ($node instanceof RegExpLiteral) {
            // A regexp literal is an early error, so translate it here rather
            // than at runtime: bad patterns and flags become SyntaxErrors at
            // compile time, and the PCRE form rides the constant pool into
            // opcache (DESIGN.md §8).
            $raw = $node->getRaw();
            if (preg_match('/[\r\n\x{2028}\x{2029}]/u', $raw)) {
                $this->fail($node, 'Regular expression literal may not span lines');
            }
            $pattern = $node->getPattern();
            $flags = $node->getFlags();
            try {
                $pcre = RegExpTranslator::translate($pattern, $flags);
            } catch (RegExpSyntaxError $e) {
                $this->fail($node, $e->getMessage());
            }
            $c->emit(
                Op::NEW_REGEXP,
                $c->constIndex($pattern),
                $c->constIndex($flags),
                $c->constIndex($pcre)
            );
        } elseif ($node instanceof BigIntLiteral) {
            $this->unsupported($node, 'BigInt literals are not supported (ES5 target)');
        } else {
            $this->unsupported($node, 'Unsupported literal');
        }
    }

    /**
     * Strict mode forbids legacy octal escapes and \8 / \9 in string literals
     * (ES5.1 7.8.4 / Annex B). Peast accepts them, so the check lives here.
     */
    private function checkStrictStringEscapes(object $node): void
    {
        $raw = $node->getRaw();
        $len = strlen($raw);
        for ($i = 0; $i < $len - 1; $i++) {
            if ($raw[$i] !== '\\') {
                continue;
            }
            $next = $raw[$i + 1];
            $i++; // consume the escaped character
            if ($next === '8' || $next === '9') {
                $this->fail($node, "\\$next is not allowed in strict mode");
            }
            if ($next < '0' || $next > '7') {
                continue;
            }
            // \0 is legal as long as no decimal digit follows it.
            $following = $raw[$i + 1] ?? '';
            if ($next === '0' && ($following < '0' || $following > '9')) {
                continue;
            }
            $this->fail($node, 'Octal escape sequences are not allowed in strict mode');
        }
    }

    /**
     * Decode a string literal from its source text.
     *
     * Peast's getValue() leaves legacy octal escapes undecoded ("\\0" comes
     * back as two characters) and decodes "\\101" inconsistently, so the
     * escape grammar is handled here. Working in UTF-16 code units lets an
     * adjacent \uD83D\uDE00 pair combine into one code point while lone
     * surrogates survive as WTF-8.
     */
    public static function decodeStringLiteral(string $raw): string
    {
        $body = substr($raw, 1, -1);
        $len = strlen($body);
        $units = [];
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch !== '\\') {
                if (ord($ch) < 0x80) {
                    $units[] = ord($ch);
                    continue;
                }
                $width = match (true) {
                    (ord($ch) & 0xE0) === 0xC0 => 2,
                    (ord($ch) & 0xF0) === 0xE0 => 3,
                    (ord($ch) & 0xF8) === 0xF0 => 4,
                    default => 1,
                };
                foreach (StringOps::toCodeUnits(substr($body, $i, $width)) as $u) {
                    $units[] = $u;
                }
                $i += $width - 1;
                continue;
            }
            $i++;
            if ($i >= $len) {
                break;
            }
            $e = $body[$i];
            // U+2028/U+2029 are LineTerminatorSequence too (11.8.6.1), so
            // `\` followed by either is a line continuation exactly like `\n`
            // and `\r` -- it generates nothing. Checked before the switch
            // because `$e` is one byte and these are three-byte UTF-8.
            if ($e === "\xE2" && substr($body, $i, 3) === "\xE2\x80\xA8") {
                $i += 2;                                // LINE SEPARATOR
                continue;
            }
            if ($e === "\xE2" && substr($body, $i, 3) === "\xE2\x80\xA9") {
                $i += 2;                                // PARAGRAPH SEPARATOR
                continue;
            }
            switch ($e) {
                case 'b': $units[] = 0x08; break;
                case 'f': $units[] = 0x0C; break;
                case 'n': $units[] = 0x0A; break;
                case 'r': $units[] = 0x0D; break;
                case 't': $units[] = 0x09; break;
                case 'v': $units[] = 0x0B; break;
                case "\n": break;                       // line continuation
                case "\r":
                    if (($body[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    break;
                case 'x':
                    $hex = substr($body, $i + 1, 2);
                    if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                        $units[] = (int)hexdec($hex);
                        $i += 2;
                    } else {
                        $units[] = ord($e);
                    }
                    break;
                case 'u':
                    if (($body[$i + 1] ?? '') === '{') {
                        $close = strpos($body, '}', $i + 2);
                        $digits = $close === false ? '' : substr($body, $i + 2, $close - $i - 2);
                        if ($digits !== '' && ctype_xdigit($digits)) {
                            foreach (StringOps::toCodeUnits(StringOps::encodeCp((int)hexdec($digits))) as $u) {
                                $units[] = $u;
                            }
                            $i = $close;
                            break;
                        }
                    }
                    $hex = substr($body, $i + 1, 4);
                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $units[] = (int)hexdec($hex);
                        $i += 4;
                    } else {
                        $units[] = ord($e);
                    }
                    break;
                default:
                    if ($e >= '0' && $e <= '7') {
                        // LegacyOctalEscapeSequence: up to three digits, and at
                        // most two when the first is 4-7.
                        $maxDigits = $e <= '3' ? 3 : 2;
                        $octal = $e;
                        while (strlen($octal) < $maxDigits) {
                            $next = $body[$i + 1] ?? '';
                            if ($next < '0' || $next > '7') {
                                break;
                            }
                            $octal .= $next;
                            $i++;
                        }
                        $units[] = (int)octdec($octal);
                        break;
                    }
                    // Any other escaped character stands for itself.
                    if (ord($e) < 0x80) {
                        $units[] = ord($e);
                    } else {
                        $width = match (true) {
                            (ord($e) & 0xE0) === 0xC0 => 2,
                            (ord($e) & 0xF0) === 0xE0 => 3,
                            (ord($e) & 0xF8) === 0xF0 => 4,
                            default => 1,
                        };
                        foreach (StringOps::toCodeUnits(substr($body, $i, $width)) as $u) {
                            $units[] = $u;
                        }
                        $i += $width - 1;
                    }
            }
        }
        return StringOps::fromCodeUnits($units);
    }

    private function genUnary(object $node): void
    {
        $c = $this->cur;
        $op = $node->getOperator();
        $arg = self::unwrapParens($node->getArgument());
        switch ($op) {
            case '-':
                $this->genExpr($arg);
                $c->emit(Op::NEG);
                return;
            case '+':
                $this->genExpr($arg);
                $c->emit(Op::TONUM);
                return;
            case '!':
                $this->genExpr($arg);
                $c->emit(Op::NOT);
                return;
            case '~':
                $this->genExpr($arg);
                $c->emit(Op::BNOT);
                return;
            case 'void':
                $this->genExpr($arg);
                $c->emit(Op::POP);
                $c->emit(Op::PUSH_UNDEF);
                return;
            case 'typeof':
                if ($arg->getType() === 'Identifier') {
                    $b = $this->resolve($arg->getName());
                    if ($b instanceof Binding) {
                        $this->emitLoadBinding($b);
                        $c->emit(Op::TYPEOF);
                    } elseif ($arg->getName() === 'arguments' && !$c->isProgram) {
                        $c->emit(Op::ARGUMENTS);
                        $c->emit(Op::TYPEOF);
                    } else {
                        // Only a genuinely free name may take the
                        // "unresolvable is undefined" path.
                        $c->emit(Op::TYPEOF_GLOBAL, $c->constIndex($arg->getName()));
                    }
                } else {
                    $this->genExpr($arg);
                    $c->emit(Op::TYPEOF);
                }
                return;
            case 'delete':
                if ($arg->getType() === 'MemberExpression') {
                    $this->genExpr($arg->getObject());
                    if ($arg->getComputed()) {
                        $this->genExpr($arg->getProperty());
                    } else {
                        $c->emit(Op::PUSH_CONST, $c->constIndex($arg->getProperty()->getName()));
                    }
                    $c->emit(Op::DEL_ELEM);
                } elseif ($arg->getType() === 'ChainExpression' && $arg->getExpression()->getType() === 'MemberExpression') {
                    // `delete a?.b`: unlike every other chain, a short circuit
                    // here means "there was nothing to delete", which is
                    // trivially successful -- `true`, not `undefined` -- and
                    // reaching the end for real means an actual DEL_ELEM, not
                    // a GET_ELEM/GET_PROP read. `delete a?.b()` doesn't reach
                    // here at all: deleting a call's result is exactly the
                    // ordinary "evaluate for side effects, then true" case
                    // below regardless of what chain it ends in.
                    $this->genDeleteChain($arg->getExpression());
                } elseif ($arg->getType() === 'Identifier') {
                    if ($this->cur->strict) {
                        $this->fail($node, 'Delete of an unqualified identifier in strict mode');
                    }
                    $b = $this->resolve($arg->getName());
                    if ($b instanceof Binding) {
                        $c->emit(Op::PUSH_FALSE);
                    } else {
                        $c->emit(Op::DEL_GLOBAL, $c->constIndex($arg->getName()));
                    }
                } else {
                    $this->genExpr($arg);
                    $c->emit(Op::POP);
                    $c->emit(Op::PUSH_TRUE);
                }
                return;
            default:
                $this->unsupported($node, "Unsupported unary operator '$op'");
        }
    }

    private function genUpdate(object $node): void
    {
        $c = $this->cur;
        $isAdd = $node->getOperator() === '++';
        $prefix = $node->getPrefix();
        $arg = self::unwrapParens($node->getArgument());
        $mathOp = $isAdd ? Op::ADD : Op::SUB;
        if ($arg->getType() === 'Identifier') {
            if ($c->strict) {
                $this->checkAssignmentTarget($arg->getName(), $arg);
            }
            $this->emitLoadName($arg->getName());
            $c->emit(Op::TONUM);
            if ($prefix) {
                $c->emit(Op::PUSH_INT, 1);
                $c->emit($mathOp);
                $this->emitStoreName($arg->getName());
            } else {
                $c->emit(Op::DUP);
                $c->emit(Op::PUSH_INT, 1);
                $c->emit($mathOp);
                $this->emitStoreName($arg->getName());
                $c->emit(Op::POP);
            }
            return;
        }
        if ($arg->getType() !== 'MemberExpression') {
            $this->fail($node, 'Invalid update expression target');
        }
        $computed = $arg->getComputed();
        $this->genExpr($arg->getObject());
        if ($computed) {
            $this->genExpr($arg->getProperty());
            $c->emit(Op::DUP2);
            $c->emit(Op::GET_ELEM);
        } else {
            $kidx = $c->constIndex($arg->getProperty()->getName());
            $c->emit(Op::DUP);
            $c->emit(Op::GET_PROP, $kidx);
        }
        $c->emit(Op::TONUM);
        if ($prefix) {
            $c->emit(Op::PUSH_INT, 1);
            $c->emit($mathOp);
            $c->emit($computed ? Op::SET_ELEM : Op::SET_PROP, ...($computed ? [] : [$kidx]));
            return;
        }
        $tmp = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $tmp);
        $c->emit(Op::PUSH_INT, 1);
        $c->emit($mathOp);
        if ($computed) {
            $c->emit(Op::SET_ELEM);
        } else {
            $c->emit(Op::SET_PROP, $kidx);
        }
        $c->emit(Op::POP);
        $c->emit(Op::GET_LOCAL, $tmp);
        $c->tempFree($tmp);
    }

    private function genAssignment(object $node): void
    {
        $c = $this->cur;
        $op = $node->getOperator();
        $left = self::unwrapParens($node->getLeft());
        $right = $node->getRight();
        if (self::isPattern($left)) {
            if ($op !== '=') {
                $this->fail($node, "A destructuring assignment may not use '$op'");
            }
            // The expression's value is the right-hand side, not the pattern,
            // so it is materialised once and handed back at the end.
            $t = $c->tempAlloc();
            $this->genExpr($right);
            $c->emit(Op::SET_LOCAL, $t);
            $c->emit(Op::POP);
            $this->genPattern($left, static fn () => $c->emit(Op::GET_LOCAL, $t), false);
            $c->emit(Op::GET_LOCAL, $t);
            $c->tempFree($t);
            return;
        }
        if ($left->getType() !== 'Identifier' && $left->getType() !== 'MemberExpression') {
            $this->unsupported($left, 'Unsupported assignment target: ' . $left->getType());
        }
        if ($left->getType() === 'Identifier' && $c->strict) {
            $this->checkAssignmentTarget($left->getName(), $left);
        }
        if ($op === '=') {
            if ($left->getType() === 'Identifier') {
                $this->genExpr($right);
                $this->emitStoreName($left->getName());
            } else {
                $this->genAssignToMember($left, fn () => $this->genExpr($right));
            }
            return;
        }
        $binOp = self::binaryOp(substr($op, 0, -1));
        if ($binOp === null) {
            $this->unsupported($node, "Unsupported assignment operator '$op'");
        }
        if ($left->getType() === 'Identifier') {
            $this->emitLoadName($left->getName());
            $this->genExpr($right);
            $c->emit($binOp);
            $this->emitStoreName($left->getName());
            return;
        }
        if ($left->getComputed()) {
            $this->genExpr($left->getObject());
            $this->genExpr($left->getProperty());
            $c->emit(Op::DUP2);
            $c->emit(Op::GET_ELEM);
            $this->genExpr($right);
            $c->emit($binOp);
            $c->emit(Op::SET_ELEM);
        } else {
            $kidx = $c->constIndex($left->getProperty()->getName());
            $this->genExpr($left->getObject());
            $c->emit(Op::DUP);
            $c->emit(Op::GET_PROP, $kidx);
            $this->genExpr($right);
            $c->emit($binOp);
            $c->emit(Op::SET_PROP, $kidx);
        }
    }

    /** Strict mode forbids assigning to 'eval' and 'arguments' (ES5.1 11.13.1). */
    private function checkAssignmentTarget(string $name, ?object $node): void
    {
        if ($name === 'eval' || $name === 'arguments') {
            $this->fail($node, "Cannot assign to '$name' in strict mode");
        }
    }

    /**
     * Destructure into $target. `$pushSource` emits code leaving the value to
     * destructure on the stack; nothing is left behind when this returns.
     *
     * The source is a thunk rather than a value already on the stack because
     * the spec fixes an order the stack cannot express: for `{a: obj.x} = src`
     * the reference `obj.x` is evaluated *before* `src.a` is read, and a
     * default is applied after the reference and before the store. Handing the
     * leaf a thunk lets it pull the value at its own moment.
     *
     * `$declaring` separates a binding pattern, which initialises fresh
     * bindings and so may write a `const`, from an assignment pattern, whose
     * leaves are ordinary assignment targets.
     */
    private function genPattern(object $target, callable $pushSource, bool $declaring): void
    {
        $c = $this->cur;
        switch ($target->getType()) {
            case 'Identifier':
                if (!$declaring && $c->strict) {
                    $this->checkAssignmentTarget($target->getName(), $target);
                }
                $pushSource();
                $this->emitStoreName($target->getName(), $declaring);
                $c->emit(Op::POP);
                return;
            case 'MemberExpression':
                $this->genAssignToMember($target, $pushSource);
                $c->emit(Op::POP);
                return;
            case 'AssignmentPattern': {
                // A default applies to `undefined` only. Folding it into the
                // thunk keeps it after the target's own evaluation.
                $init = $target->getRight();
                $withDefault = function () use ($c, $pushSource, $init): void {
                    $pushSource();
                    $c->emit(Op::DUP);
                    $c->emit(Op::PUSH_UNDEF);
                    $c->emit(Op::SEQ);
                    $skip = $c->emitJump(Op::JF);
                    $c->emit(Op::POP);
                    $this->genExpr($init);
                    $c->patch($skip);
                };
                $this->genPattern($target->getLeft(), $withDefault, $declaring);
                return;
            }
            case 'ObjectPattern':
                $this->genObjectPattern($target, $pushSource, $declaring);
                return;
            case 'ArrayPattern':
                $this->genArrayPattern($target, $pushSource, $declaring);
                return;
            default:
                $this->unsupported($target, 'Unsupported binding target: ' . $target->getType());
        }
    }

    /**
     * An array pattern, over the iteration protocol rather than over indices --
     * so it destructures a Set, a Map or a generator, not just an Array.
     *
     * The iterator record carries a `done` flag because a pattern longer than
     * its source keeps taking `undefined` without asking the iterator again,
     * and because whether to close on the way out depends on it: an element
     * list that stopped early leaves the iterator open, and the spec says to
     * close it (8.5.2). A rest element drains it, so nothing is left to close.
     */
    private function genArrayPattern(object $pattern, callable $pushSource, bool $declaring): void
    {
        $c = $this->cur;
        $rec = $c->tempAlloc();
        $pushSource();
        $c->emit(Op::ITER_REC, $rec);

        // Binding an element can run arbitrary code -- a default, a setter, a
        // nested pattern -- and if it throws the iterator still has to be told.
        $jThrow = $c->emitJump(Op::TRY_ENTER);
        $c->tryStack[] = ['finalizer' => null, 'iterRec' => $rec];
        foreach ($pattern->getElements() as $el) {
            if ($el === null) {
                $c->emit(Op::ITER_TAKE, $rec);      // an elision still steps
                $c->emit(Op::POP);
                continue;
            }
            if ($el->getType() === 'RestElement') {
                $this->genPattern(
                    $el->getArgument(),
                    static fn () => $c->emit(Op::ITER_REST, $rec),
                    $declaring
                );
                continue;
            }
            $this->genPattern($el, static fn () => $c->emit(Op::ITER_TAKE, $rec), $declaring);
        }
        array_pop($c->tryStack);
        $c->emit(Op::TRY_LEAVE);
        $c->emit(Op::ITER_FIN, $rec, 0);            // no-op once the record is done
        $jEnd = $c->emitJump(Op::JMP);

        $c->patch($jThrow);
        $exc = $c->tempAlloc();
        $c->emit(Op::SET_LOCAL, $exc);
        $c->emit(Op::POP);
        $c->emit(Op::ITER_FIN, $rec, 1);            // quiet: the throw in flight wins
        $c->emit(Op::GET_LOCAL, $exc);
        $c->emit(Op::THROW);
        $c->tempFree($exc);

        $c->patch($jEnd);
        $c->tempFree($rec);
    }

    private function genObjectPattern(object $pattern, callable $pushSource, bool $declaring): void
    {
        $c = $this->cur;
        // Read once into a temp: the properties all come from the same value,
        // and re-running the source expression would run its effects again.
        $src = $c->tempAlloc();
        $pushSource();
        $c->emit(Op::SET_LOCAL, $src);
        $c->emit(Op::POP);
        // RequireObjectCoercible happens before any property is read, and still
        // happens when there is no property to read at all.
        $c->emit(Op::GET_LOCAL, $src);
        $c->emit(Op::REQ_COERCIBLE);
        $c->emit(Op::POP);

        /** @var list<callable> $pushKey emits each matched key, for a rest element */
        $pushKey = [];
        $keyTemps = [];
        $rest = null;
        foreach ($pattern->getProperties() as $p) {
            if ($p->getType() === 'RestElement') {
                $rest = $p;                 // always last; the parser enforces it
                continue;
            }
            if ($p->getComputed()) {
                // Converted once here and reused for the rest element, so a
                // user-supplied `toString` runs exactly once.
                $kt = $c->tempAlloc();
                $keyTemps[] = $kt;
                $this->genExpr($p->getKey());
                $c->emit(Op::TO_KEY);
                $c->emit(Op::SET_LOCAL, $kt);
                $c->emit(Op::POP);
                $pushKey[] = static fn () => $c->emit(Op::GET_LOCAL, $kt);
                $read = static function () use ($c, $src, $kt): void {
                    $c->emit(Op::GET_LOCAL, $src);
                    $c->emit(Op::GET_LOCAL, $kt);
                    $c->emit(Op::GET_ELEM);
                };
            } else {
                $key = $this->propertyKeyString($p->getKey());
                $kidx = $c->constIndex($key);
                $pushKey[] = static fn () => $c->emit(Op::PUSH_CONST, $kidx);
                $read = static function () use ($c, $src, $kidx): void {
                    $c->emit(Op::GET_LOCAL, $src);
                    $c->emit(Op::GET_PROP, $kidx);
                };
            }
            $this->genPattern($p->getValue(), $read, $declaring);
        }
        if ($rest !== null) {
            $this->genPattern($rest->getArgument(), function () use ($c, $src, $pushKey): void {
                $c->emit(Op::GET_LOCAL, $src);
                foreach ($pushKey as $push) {
                    $push();
                }
                $c->emit(Op::COPY_REST, count($pushKey));
            }, $declaring);
        }
        foreach ($keyTemps as $kt) {
            $c->tempFree($kt);
        }
        $c->tempFree($src);
    }

    /** Assign to obj.prop / obj[key]; $pushValue emits the RHS. Leaves value on stack. */
    private function genAssignToMember(object $member, callable $pushValue): void
    {
        $c = $this->cur;
        $this->genExpr($member->getObject());
        if ($member->getComputed()) {
            $this->genExpr($member->getProperty());
            $pushValue();
            $c->emit(Op::SET_ELEM);
        } else {
            $pushValue();
            $c->emit(Op::SET_PROP, $c->constIndex($member->getProperty()->getName()));
        }
    }

    private static function unwrapParens(object $node): object
    {
        while ($node->getType() === 'ParenthesizedExpression') {
            $node = $node->getExpression();
        }
        return $node;
    }

    private static function binaryOp(string $op): ?int
    {
        return match ($op) {
            '+' => Op::ADD, '-' => Op::SUB, '*' => Op::MUL, '/' => Op::DIV, '%' => Op::MOD,
            '==' => Op::EQ, '!=' => Op::NEQ, '===' => Op::SEQ, '!==' => Op::SNEQ,
            '<' => Op::LT, '<=' => Op::LE, '>' => Op::GT, '>=' => Op::GE,
            '&' => Op::BAND, '|' => Op::BOR, '^' => Op::BXOR,
            '<<' => Op::SHL, '>>' => Op::SHR, '>>>' => Op::USHR,
            'in' => Op::IN_OP, 'instanceof' => Op::INSTANCEOF,
            default => null,
        };
    }

    private function propertyKeyString(object $key): string
    {
        if ($key->getType() === 'Identifier') {
            return $key->getName();
        }
        if ($key instanceof StringLiteral) {
            return $key->getValue();
        }
        if ($key instanceof NumericLiteral) {
            $v = $key->getValue();
            return Conversions::numberToString(is_int($v) || is_float($v) ? $v : (float)$v);
        }
        $this->unsupported($key, 'Unsupported property key');
    }

    // ---- name access --------------------------------------------------------

    private function resolve(string $name): ?Binding
    {
        for ($i = count($this->lexStack) - 1; $i >= 0; $i--) {
            if (isset($this->lexStack[$i]['names'][$name])) {
                return $this->lexStack[$i]['names'][$name];
            }
        }
        return null;
    }

    /**
     * How many environment records `GET_ENV`/`SET_ENV` must walk from
     * wherever codegen currently is to reach `$target`'s own -- a function
     * (`Ctx`, counted only if it turned out to need one at all, `nenv > 0`)
     * or a loop's per-iteration layer (`EnvScope`, counted only if it
     * turned out to need one, `size > 0`). The two compose uniformly: a
     * closure declared inside a loop has that loop's `EnvScope` as its
     * own `Ctx::$parent` (`loopEnvParent`, at the point it was analyzed), so
     * this walk crosses however many loop layers and function layers
     * actually separate the two, in whatever order they nest, without
     * needing to know which kind it is looking at except to decide whether
     * that particular layer ended up owning an environment.
     */
    private function envDepth(Ctx|EnvScope $target): int
    {
        $d = 0;
        $f = end($this->loopEnvStack) ?: $this->cur;
        while ($f !== $target) {
            if ($f instanceof EnvScope) {
                if ($f->size > 0) {
                    $d++;
                }
                $f = $f->parent;
            } else {
                if ($f->nenv > 0) {
                    $d++;
                }
                $f = $f->parent
                    ?? throw new CompileError('Compiler bug: binding owner not on scope chain');
            }
        }
        return $d;
    }

    /**
     * The scope a function/class declared right here would list as its own
     * `Ctx::$parent` -- the innermost currently-open loop layer if there is
     * one, otherwise the enclosing function itself. Passed at every
     * `analyzeFunction`/`analyzeClass` call site that used to just pass
     * `$ctx`, so a closure declared inside a loop links through that loop's
     * `EnvScope` rather than skipping straight to the function -- which
     * is what makes `envDepth` correctly cross that loop layer later.
     */
    private function loopEnvParent(Ctx $ctx): Ctx|EnvScope
    {
        return end($this->loopEnvStack) ?: $ctx;
    }

    /** Unwrap any loop layers to the nearest real enclosing function. */
    private static function nearestCtx(Ctx|EnvScope|null $scope): ?Ctx
    {
        while ($scope instanceof EnvScope) {
            $scope = $scope->parent;
        }
        return $scope;
    }

    /**
     * Open this loop's own per-iteration layer for the analysis pass, so
     * every binding `enterBlock` creates while it is open -- the loop's own
     * head and its body's block alike -- is tagged with it
     * (`Binding::$envScope`), and any function declared inside links its
     * own `Ctx::$parent` through it (`loopEnvParent`). Whether the loop
     * turns out to need an environment at all is not known until analysis of
     * its own body finishes; every loop gets one of these regardless; an
     * unused one costs nothing beyond the object itself (`leaveLoopEnv`).
     */
    private function enterLoopEnv(Ctx $ctx): EnvScope
    {
        $scope = new EnvScope($this->loopEnvParent($ctx));
        $this->loopEnvStack[] = $scope;
        return $scope;
    }

    /**
     * Close $scope and hand the finished decision (its final `$size`) to
     * codegen, keyed by the loop node the same way `blockScopes`/`fnCtx`
     * hand other analysis results across.
     */
    private function leaveLoopEnv(object $node, EnvScope $scope): void
    {
        array_pop($this->loopEnvStack);
        $this->loopEnvScopes[$node] = $scope;
    }

    private function emitLoadName(string $name): void
    {
        $c = $this->cur;
        $b = $this->resolve($name);
        if ($b instanceof Binding) {
            $this->emitLoadBinding($b);
            return;
        }
        if ($name === 'arguments' && !$c->isProgram) {
            $c->emit(Op::ARGUMENTS);
            return;
        }
        if ($name === 'undefined') {
            // Bare `undefined` is overwhelmingly the global constant; load it
            // directly unless shadowed (the shadowed case resolved above).
            $c->emit(Op::PUSH_UNDEF);
            return;
        }
        $c->emit(Op::GET_GLOBAL, $c->constIndex($name));
    }

    private function emitLoadBinding(Binding $b): void
    {
        $c = $this->cur;
        if ($b->captured) {
            $c->emit(Op::GET_ENV, $this->envDepth($b->envScope ?? $b->owner), $b->envIndex);
        } else {
            $c->emit(Op::GET_LOCAL, $b->slot);
        }
        if ($b->kind === 'let' || $b->kind === 'const' || $b->kind === 'class') {
            // Unconditional for now. Most reads are provably past their
            // declaration, but proving it needs flow analysis this compiler
            // does not have, and answering `undefined` inside the dead zone
            // would be wrong rather than slow.
            $c->emit(Op::TDZ_CHECK, $c->constIndex($b->name));
        }
    }

    /** @param bool $declaring true for the declaration itself, which a const allows */
    private function emitStoreName(string $name, bool $declaring = false): void
    {
        $c = $this->cur;
        $b = $this->resolve($name);
        if ($b instanceof Binding) {
            if ($b->kind === 'const' && !$declaring) {
                // The value is evaluated first and then discarded, because the
                // spec evaluates the right-hand side before it complains.
                $c->emit(Op::THROW_CONST);
                return;
            }
            if ($b->kind === 'self') {
                // A named function expression's own binding is immutable,
                // but -- unlike `const` -- created with CreateImmutableBinding's
                // strict parameter false (13.2's NamedEvaluation), which means
                // an assignment silently does nothing in sloppy code rather
                // than throwing; strict mode throws same as `const` does.
                // Either way PutValue itself never runs, so this must not
                // emit a store: the assignment *expression* still evaluates
                // to the right-hand value sitting on the stack, unstored.
                if ($c->strict) {
                    $c->emit(Op::THROW_CONST);
                }
                return;
            }
            if (($b->kind === 'let' || $b->kind === 'class') && !$declaring) {
                // Writing to a `let` (or a class binding, which despite being
                // no more reassignable in practice than a function
                // declaration's name is actually mutable per spec -- 15.7.14
                // creates it with CreateMutableBinding, not Immutable) before
                // its declaration runs is a ReferenceError just as reading one
                // is, so the dead zone is checked on the way in too. A load
                // already carries the check.
                $this->emitLoadBinding($b);
                $c->emit(Op::POP);
            }
            $this->emitStoreBinding($b);
            return;
        }
        if ($name === 'arguments' && !$c->isProgram) {
            $this->unsupported(null, 'Assignment to arguments is not supported');
        }
        $c->emit(Op::SET_GLOBAL, $c->constIndex($name));
    }

    private function emitStoreBinding(Binding $b): void
    {
        $c = $this->cur;
        if ($b->captured) {
            $c->emit(Op::SET_ENV, $this->envDepth($b->envScope ?? $b->owner), $b->envIndex);
        } else {
            $c->emit(Op::SET_LOCAL, $b->slot);
        }
    }

    /** A genuine early error: the source is invalid ECMAScript. @return never */
    private function fail(?object $node, string $message): mixed
    {
        throw new CompileError($message . $this->at($node));
    }

    /**
     * The source is valid ECMAScript but uses a construct outside the ES5.1
     * target. Callers distinguish this from a real syntax error.
     * @return never
     */
    private function unsupported(?object $node, string $message): mixed
    {
        throw CompileError::unsupported($message . $this->at($node));
    }

    private function at(?object $node): string
    {
        if ($node !== null && method_exists($node, 'getLocation')) {
            return ' (line ' . $node->getLocation()->getStart()->getLine() . ')';
        }
        return '';
    }
}

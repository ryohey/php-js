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
    /** @var \SplObjectStorage<object, Ctx> function node -> analyzed context */
    private \SplObjectStorage $fnCtx;
    /** @var \SplObjectStorage<object, Binding> CatchClause -> catch binding */
    private \SplObjectStorage $catchBind;
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

    private function __construct()
    {
        $this->blockScopes = new \SplObjectStorage();
        $this->fnCtx = new \SplObjectStorage();
        $this->catchBind = new \SplObjectStorage();
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

    private function analyzeFunction(object $node, ?Ctx $parent, bool $isProgram): Ctx
    {
        $ctx = new Ctx();
        $ctx->parent = $parent;
        $ctx->isProgram = $isProgram;
        $ctx->strict = ($parent?->strict ?? false);

        if ($isProgram) {
            $body = $node->getBody();
        } else {
            if ($node->getGenerator()) {
                $this->unsupported($node, 'Generators are not supported (ES5 target)');
            }
            if (method_exists($node, 'getAsync') && $node->getAsync()) {
                $this->unsupported($node, 'Async functions are not supported (ES5 target)');
            }
            $ctx->isArrow = $node->getType() === 'ArrowFunctionExpression';
            $ctx->name = (!$ctx->isArrow && $node->getId() !== null) ? $node->getId()->getName() : '';
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

        $this->hoist($body, $ctx);

        // Named function expressions bind their own name in an outer mini-scope.
        $selfNames = [];
        if (!$isProgram && $node->getType() === 'FunctionExpression' && $node->getId() !== null) {
            $name = $node->getId()->getName();
            if (!isset($ctx->bindings[$name])) {
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
        $bodyBlock = $bodyOwner !== null
            && $this->enterBlock($bodyOwner, $body, $ctx, true, $paramNames);
        foreach ($body as $stmt) {
            $this->analyzeNode($stmt, $ctx);
        }
        if ($bodyBlock) {
            array_pop($this->lexStack);
        }
        foreach ($ctx->paramInits as $index => $init) {
            $this->checkInitializerOrder($ctx, $index, $init);
            $this->analyzeNode($init, $ctx);
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

        foreach ($ctx->extraBindings as $b) {
            if ($b->inLoop && $b->captured && ($b->kind === 'let' || $b->kind === 'const')) {
                // Each iteration should give `$b->name` a binding of its own, so
                // that closures made in different iterations see different
                // values. One slot is reused instead, which would hand every
                // closure the last value -- a wrong answer, not a slow one.
                $this->unsupported(
                    null,
                    "'{$b->kind} {$b->name}' is captured by a closure inside a loop, "
                        . 'which needs a fresh binding per iteration (not supported yet)'
                );
            }
        }

        $this->assignSlots($ctx);
        if (!$isProgram) {
            $this->fnCtx[$node] = $ctx;
        }
        return $ctx;
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
                $this->analyzeFunction($node, $ctx, false);
                return;
            case 'VariableDeclaration':
                foreach ($node->getDeclarations() as $d) {
                    $this->analyzeNode($d->getInit(), $ctx);
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
                $this->analyzeNode($node->getObject(), $ctx);
                if ($node->getComputed()) {
                    $this->analyzeNode($node->getProperty(), $ctx);
                }
                return;
            case 'ObjectExpression':
                foreach ($node->getProperties() as $p) {
                    if ($p->getType() !== 'Property') {
                        $this->unsupported($p, 'Spread properties are not supported (ES5 target)');
                    }
                    $this->analyzeNode($p->getValue(), $ctx);
                }
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
                $this->analyzeNode($node->getRight(), $ctx);
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
            case 'ConditionalExpression':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getConsequent(), $ctx);
                $this->analyzeNode($node->getAlternate(), $ctx);
                return;
            case 'CallExpression':
            case 'NewExpression':
                $this->analyzeNode($node->getCallee(), $ctx);
                foreach ($node->getArguments() as $a) {
                    $this->analyzeNode($a, $ctx);
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
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'ForInStatement': {
                $left = $node->getLeft();
                if ($left->getType() === 'VariableDeclaration' && $left->getKind() !== 'var') {
                    $this->unsupported($left, "'{$left->getKind()}' in a for-in head is not supported yet");
                }
                $this->analyzeNode($left, $ctx);
                $this->analyzeNode($node->getRight(), $ctx);
                $this->loopDepth++;
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
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
                if ($opened) {
                    array_pop($this->lexStack);
                }
                return;
            }
            case 'WhileStatement':
            case 'DoWhileStatement':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->loopDepth++;
                $this->analyzeNode($node->getBody(), $ctx);
                $this->loopDepth--;
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
                    if ($param === null || $param->getType() !== 'Identifier') {
                        $this->unsupported($handler, 'Catch parameter patterns are not supported (ES5 target)');
                    }
                    $this->checkBindingName($ctx, $param->getName(), $param);
                    $b = new Binding($ctx, $param->getName(), 'catch');
                    $ctx->extraBindings[] = $b;
                    $this->catchBind[$handler] = $b;
                    $ctx->catchBindings[$handler] = $b;
                    $this->lexStack[] = ['ctx' => $ctx, 'names' => [$param->getName() => $b]];
                    $body = $handler->getBody();
                    $opened = $this->enterBlock($body, $body->getBody(), $ctx, true, [$param->getName()]);
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

    private static function isPattern(object $node): bool
    {
        $t = $node->getType();
        return $t === 'ObjectPattern' || $t === 'ArrayPattern';
    }

    /**
     * A shorthand property binds the key itself, so `{x = d}` must arrive as
     * key `x` with target `x`.
     *
     * Peast breaks that for a destructuring *assignment* whose default is
     * itself an assignment: `({x = f = 1} = v)` comes back with the target
     * `f`, having lost `x`. Declarations and parameters are parsed correctly,
     * so this only guards the assignment path -- but it is written as the
     * invariant rather than as the special case, because the tree is wrong
     * either way and unpacking it would produce a confidently wrong answer.
     */
    private function checkShorthand(object $prop): void
    {
        if (!$prop->getShorthand()) {
            return;
        }
        $target = $prop->getValue();
        if ($target->getType() === 'AssignmentPattern') {
            $target = $target->getLeft();
        }
        if ($target->getType() !== 'Identifier' || $target->getName() !== $prop->getKey()->getName()) {
            $this->unsupported(
                $prop,
                "Shorthand '{$prop->getKey()->getName()}' with an assignment as its default"
                    . ' is not supported yet (the parser loses the target)'
            );
        }
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
                    $this->checkShorthand($p);
                    if ($p->getComputed()) {
                        $this->analyzeNode($p->getKey(), $ctx);
                    }
                    $this->analyzePattern($p->getValue(), $ctx, $assigning);
                }
                return;
            case 'AssignmentPattern':
                $this->analyzePattern($target->getLeft(), $ctx, $assigning);
                $this->analyzeNode($target->getRight(), $ctx);
                return;
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
                $this->unsupported($target, 'Array destructuring is not supported yet');
                // no break (unsupported throws)
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
                    $this->checkShorthand($p);
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
                // An array pattern is defined over the iterator protocol
                // (GetIterator / IteratorStep / IteratorClose), which the engine
                // does not have yet. Reading by index instead would answer for a
                // Set, a Map or a generator by handing back undefined.
                $this->unsupported($target, 'Array destructuring is not supported yet');
                // no break (unsupported throws)
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
                $this->checkBindingName($ctx, $name, $owner);
                $b = new Binding($ctx, $name, $kind);
                $b->inLoop = $this->loopDepth > 0;
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
            if ($b->captured) {
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

        foreach ($body as $stmt) {
            $this->genStmt($stmt);
        }
        if ($bodyBlock) {
            array_pop($this->lexStack);
        }
        if ($bodyBlock) {
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
                $lCond = $c->here();
                $this->genExpr($node->getTest());
                $jEnd = $c->emitJump(Op::JF);
                $this->genStmt($node->getBody());
                $this->patchContinues($loop, $lCond);
                $c->emit(Op::JMP, $lCond);
                $c->patch($jEnd);
                $this->popLoop($loop);
                return;
            }
            case 'DoWhileStatement': {
                $this->requireStatementBody($node->getBody());
                $loop = $this->pushLoop($labels, true);
                $lBody = $c->here();
                $this->genStmt($node->getBody());
                $lCond = $c->here();
                $this->patchContinues($loop, $lCond);
                $this->genExpr($node->getTest());
                $c->emit(Op::JT, $lBody);
                $this->popLoop($loop);
                return;
            }
            case 'ForStatement': {
                $this->requireStatementBody($node->getBody());
                $init = $node->getInit();
                $headScope = $this->enterBlock($node, $init === null ? [] : [$init], $this->cur, false);
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
                $lNext = $c->here();
                $jEnd = $c->emitJump(Op::FORIN_NEXT, $iterSlot);
                // Assign the key to the loop variable.
                $left = $node->getLeft();
                if ($left->getType() === 'VariableDeclaration') {
                    $name = $left->getDeclarations()[0]->getId()->getName();
                    $this->emitStoreName($name);
                    $c->emit(Op::POP);
                } elseif ($left->getType() === 'Identifier') {
                    $this->emitStoreName($left->getName());
                    $c->emit(Op::POP);
                } elseif ($left->getType() === 'MemberExpression') {
                    $keyTmp = $c->tempAlloc();
                    $c->emit(Op::SET_LOCAL, $keyTmp);
                    $c->emit(Op::POP);
                    $this->genAssignToMember($left, function () use ($c, $keyTmp) {
                        $c->emit(Op::GET_LOCAL, $keyTmp);
                    });
                    $c->emit(Op::POP);
                    $c->tempFree($keyTmp);
                } else {
                    $this->unsupported($left, 'Unsupported for-in target');
                }
                $this->genStmt($node->getBody());
                $this->patchContinues($loop, $lNext);
                $c->emit(Op::JMP, $lNext);
                $c->patch($jEnd);
                $this->popLoop($loop);
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
        if ($binding->kind === 'let' || $binding->kind === 'const') {
            // The fused form is a bare slot bump: it would skip the dead-zone
            // check on the read and the TypeError on writing a `const`.
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
        // Pushed after the region, so the loop's own break and continue do not
        // unwind it: continue must not close, and break closes at its target.
        $loop = $this->pushLoop($labels, true);
        $headScope = $this->enterBlock($node, [$left], $c, false);

        $lNext = $c->here();
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
        array_pop($c->tryStack);
        $c->emit(Op::TRY_LEAVE);
        $jEndDone = $c->emitJump(Op::JMP);

        $this->popLoop($loop);                  // every `break` lands here
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
        $b = $this->catchBind[$handler];
        $this->emitStoreBinding($b);
        $c->emit(Op::POP);
        // The catch parameter sits outside the body's block scope, so a `let`
        // in the body may not reuse its name (see the analysis pass).
        $this->lexStack[] = ['ctx' => $c, 'names' => [$b->name => $b]];
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
            $c->emit(Op::TRY_LEAVE);
            if (($entry['iterSlot'] ?? null) !== null) {
                // Leaving a `for…of` other than by exhausting it: the iterator
                // is told, so a generator's `finally` runs (7.4.9).
                $c->emit(Op::GET_LOCAL, $entry['iterSlot']);
                $c->emit(Op::ITER_CLOSE, 0);
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
            case 'ArrayExpression': {
                $elements = $node->getElements();
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
            case 'ObjectExpression':
                $c->emit(Op::NEW_OBJECT);
                foreach ($node->getProperties() as $p) {
                    $key = $this->propertyKeyString($p->getKey());
                    $kidx = $c->constIndex($key);
                    if ($p->getKind() === 'init') {
                        $this->genExpr($p->getValue());
                        $c->emit(Op::DEFINE_DATA, $kidx);
                    } else {
                        $fnNode = $p->getValue();
                        $childCtx = $this->fnCtx[$fnNode];
                        $idx = $this->compileChild($childCtx, $fnNode);
                        $c->emit(Op::NEW_FUNC, $idx);
                        $c->emit($p->getKind() === 'get' ? Op::DEFINE_GETTER : Op::DEFINE_SETTER, $kidx);
                    }
                }
                return;
            case 'MemberExpression':
                $this->genExpr($node->getObject());
                if ($node->getComputed()) {
                    $this->genExpr($node->getProperty());
                    $c->emit(Op::GET_ELEM);
                } else {
                    $c->emit(Op::GET_PROP, $c->constIndex($node->getProperty()->getName()));
                }
                return;
            case 'CallExpression': {
                $callee = self::unwrapParens($node->getCallee());
                if ($callee->getType() === 'MemberExpression') {
                    $this->genExpr($callee->getObject());
                    if ($callee->getComputed()) {
                        $this->genExpr($callee->getProperty());
                        $c->emit(Op::GET_METHOD_ELEM);
                    } else {
                        $c->emit(Op::GET_METHOD, $c->constIndex($callee->getProperty()->getName()));
                    }
                } else {
                    $this->genExpr($callee);
                    $c->emit(Op::PUSH_UNDEF);
                }
                $args = $node->getArguments();
                foreach ($args as $a) {
                    if ($a->getType() === 'SpreadElement') {
                        $this->unsupported($a, 'Spread arguments are not supported (ES5 target)');
                    }
                    $this->genExpr($a);
                }
                $c->emit(Op::CALL, count($args));
                return;
            }
            case 'NewExpression': {
                $this->genExpr($node->getCallee());
                $args = $node->getArguments();
                foreach ($args as $a) {
                    $this->genExpr($a);
                }
                $c->emit(Op::NEW_OP, count($args));
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
                if ($op !== '&&' && $op !== '||') {
                    $this->unsupported($node, "Operator '$op' is not supported (ES5 target)");
                }
                $this->genExpr($node->getLeft());
                $j = $c->emitJump($op === '&&' ? Op::JF_KEEP : Op::JT_KEEP);
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
     * A template rejects the escapes a sloppy string tolerates.
     *
     * `\8`, `\9` and legacy octals are errors here whatever the surrounding
     * strictness (12.9.6), because a template's cooked value is only allowed to
     * be undefined in a *tagged* template, and this compiler has no tagged form
     * yet. Peast catches the malformed unicode escapes; these are the rest.
     */
    private function cookTemplate(object $quasi): string
    {
        $raw = $quasi->getRawValue();
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '\\') {
                continue;
            }
            $next = $raw[++$i] ?? '';
            if ($next === '8' || $next === '9') {
                $this->fail($quasi, "'\\$next' is not a valid escape in a template literal");
            }
            if ($next >= '0' && $next <= '7') {
                // \0 not followed by a digit is the NUL escape and is fine;
                // anything else in that range is a legacy octal.
                $following = $raw[$i + 1] ?? '';
                if ($next !== '0' || ($following >= '0' && $following <= '9')) {
                    $this->fail($quasi, 'Octal escapes are not allowed in a template literal');
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
            default:
                $this->unsupported($target, 'Unsupported binding target: ' . $target->getType());
        }
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

    private function envDepth(Ctx $owner): int
    {
        $d = 0;
        $f = $this->cur;
        while ($f !== $owner) {
            if ($f->nenv > 0) {
                $d++;
            }
            $f = $f->parent
                ?? throw new CompileError('Compiler bug: binding owner not on scope chain');
        }
        return $d;
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
            $c->emit(Op::GET_ENV, $this->envDepth($b->owner), $b->envIndex);
        } else {
            $c->emit(Op::GET_LOCAL, $b->slot);
        }
        if ($b->kind === 'let' || $b->kind === 'const') {
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
            if ($b->kind === 'let' && !$declaring) {
                // Writing to a `let` before its declaration runs is a
                // ReferenceError just as reading one is, so the dead zone is
                // checked on the way in too. A load already carries the check.
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
            $c->emit(Op::SET_ENV, $this->envDepth($b->owner), $b->envIndex);
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

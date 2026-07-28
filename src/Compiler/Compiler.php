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
use PhpJs\Runtime\Conversions;
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

    private function __construct()
    {
        $this->fnCtx = new \SplObjectStorage();
        $this->catchBind = new \SplObjectStorage();
    }

    /** @return array<string, mixed> program template */
    public static function compile(string $source): array
    {
        $c = new self();
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
            $ctx->name = $node->getId() !== null ? $node->getId()->getName() : '';
            foreach ($node->getParams() as $i => $p) {
                if ($p->getType() !== 'Identifier') {
                    $this->unsupported($p, 'Parameter patterns are not supported (ES5 target)');
                }
                $name = $p->getName();
                $ctx->params[] = $name;
                $ctx->bindings[$name] = new Binding($ctx, $name, 'param', $i);
            }
            $bodyNode = $node->getBody();
            if ($bodyNode->getType() !== 'BlockStatement') {
                $this->unsupported($node, 'Expression-bodied functions are not supported (ES5 target)');
            }
            $body = $bodyNode->getBody();
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
        foreach ($body as $stmt) {
            $this->analyzeNode($stmt, $ctx);
        }
        array_pop($this->lexStack);
        if ($selfNames !== []) {
            array_pop($this->lexStack);
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
                    $this->unsupported($node, "'{$node->getKind()}' declarations are not supported (ES5 target; downlevel first)");
                }
                foreach ($node->getDeclarations() as $d) {
                    $id = $d->getId();
                    if ($id->getType() !== 'Identifier') {
                        $this->unsupported($id, 'Destructuring is not supported (ES5 target)');
                    }
                    $name = $id->getName();
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
                break;
            case 'FunctionDeclaration':
                $name = $node->getId()->getName();
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
                $this->analyzeReference($node->getName(), $ctx);
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
                $this->analyzeFunction($node, $ctx, false);
                return;
            case 'VariableDeclaration':
                foreach ($node->getDeclarations() as $d) {
                    $this->analyzeNode($d->getInit(), $ctx);
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
            case 'AssignmentExpression':
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
            case 'BlockStatement':
                foreach ($node->getBody() as $s) {
                    $this->analyzeNode($s, $ctx);
                }
                return;
            case 'IfStatement':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getConsequent(), $ctx);
                $this->analyzeNode($node->getAlternate(), $ctx);
                return;
            case 'ForStatement':
                $this->analyzeNode($node->getInit(), $ctx);
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getUpdate(), $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                return;
            case 'ForInStatement':
                if ($node->getLeft()->getType() === 'VariableDeclaration') {
                    $this->analyzeNode($node->getLeft(), $ctx);
                } else {
                    $this->analyzeNode($node->getLeft(), $ctx);
                }
                $this->analyzeNode($node->getRight(), $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                return;
            case 'WhileStatement':
            case 'DoWhileStatement':
                $this->analyzeNode($node->getTest(), $ctx);
                $this->analyzeNode($node->getBody(), $ctx);
                return;
            case 'SwitchStatement':
                $this->analyzeNode($node->getDiscriminant(), $ctx);
                foreach ($node->getCases() as $case) {
                    $this->analyzeNode($case->getTest(), $ctx);
                    foreach ($case->getConsequent() as $s) {
                        $this->analyzeNode($s, $ctx);
                    }
                }
                return;
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
                    $b = new Binding($ctx, $param->getName(), 'catch');
                    $ctx->extraBindings[] = $b;
                    $this->catchBind[$handler] = $b;
                    $this->lexStack[] = ['ctx' => $ctx, 'names' => [$param->getName() => $b]];
                    $this->analyzeNode($handler->getBody(), $ctx);
                    array_pop($this->lexStack);
                }
                $this->analyzeNode($node->getFinalizer(), $ctx);
                return;
            case 'LabeledStatement':
                $this->analyzeNode($node->getBody(), $ctx);
                return;
            case 'WithStatement':
                $this->unsupported($node, "'with' statements are not supported");
                // no break (fail throws)
            default:
                $this->unsupported($node, "Unsupported syntax: $type (ES5 target; downlevel first)");
        }
    }

    private function analyzeReference(string $name, Ctx $ctx): void
    {
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
            $ctx->usesArguments = true;
        }
    }

    private function assignSlots(Ctx $ctx): void
    {
        $ctx->nparams = count($ctx->params);
        $ctx->nlocals = $ctx->nparams;
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

        $body = $isProgram ? $node->getBody() : $node->getBody()->getBody();

        // Prologue: copy captured params into the environment record.
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
        return $ctx->toTemplate();
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
        switch ($type) {
            case 'ExpressionStatement':
                $this->genExpr($node->getExpression());
                $c->emit($c->isProgram ? Op::SET_COMPLETION : Op::POP);
                return;
            case 'VariableDeclaration':
                foreach ($node->getDeclarations() as $d) {
                    if ($d->getInit() !== null) {
                        $this->genExpr($d->getInit());
                        $this->emitStoreName($d->getId()->getName());
                        $c->emit(Op::POP);
                    }
                }
                return;
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
                foreach ($node->getBody() as $s) {
                    $this->genStmt($s);
                }
                return;
            case 'IfStatement':
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
                $init = $node->getInit();
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
                    $this->genExpr($node->getUpdate());
                    $c->emit(Op::POP);
                }
                $c->emit(Op::JMP, $lCond);
                if ($jEnd >= 0) {
                    $c->patch($jEnd);
                }
                $this->popLoop($loop);
                return;
            }
            case 'ForInStatement': {
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
            case 'SwitchStatement': {
                $this->genExpr($node->getDiscriminant());
                $t = $c->tempAlloc();
                $c->emit(Op::SET_LOCAL, $t);
                $c->emit(Op::POP);
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

    /** Non-loop labeled statement: only `break label` targets it. */
    private function genLabeledBlock(object $node, array $labels): void
    {
        $c = $this->cur;
        $loop = $this->pushLoop($labels, false);
        foreach ($node->getBody() as $s) {
            $this->genStmt($s);
        }
        $this->popLoop($loop);
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
        if ($handler === null) {
            foreach ($node->getBlock()->getBody() as $s) {
                $this->genStmt($s);
            }
            return;
        }
        $jCatch = $c->emitJump(Op::TRY_ENTER);
        $c->tryStack[] = ['finalizer' => null];
        foreach ($node->getBlock()->getBody() as $s) {
            $this->genStmt($s);
        }
        array_pop($c->tryStack);
        $c->emit(Op::TRY_LEAVE);
        $jEnd = $c->emitJump(Op::JMP);
        $c->patch($jCatch);
        $b = $this->catchBind[$handler];
        $this->emitStoreBinding($b);
        $c->emit(Op::POP);
        $this->lexStack[] = ['ctx' => $c, 'names' => [$b->name => $b]];
        foreach ($handler->getBody()->getBody() as $s) {
            $this->genStmt($s);
        }
        array_pop($this->lexStack);
        $c->patch($jEnd);
    }

    /**
     * Inline a finally block (duplication strategy, DESIGN.md §4.4): each
     * early exit and both normal/exception paths get their own copy.
     */
    private function genFinalizerInline(object $finalizer): void
    {
        foreach ($finalizer->getBody() as $s) {
            $this->genStmt($s);
        }
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
            case 'FunctionExpression': {
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
            $c->emit(Op::PUSH_CONST, $c->constIndex($node->getValue()));
        } elseif ($node instanceof RegExpLiteral) {
            $c->emit(Op::NEW_REGEXP, $c->constIndex($node->getPattern()), $c->constIndex($node->getFlags()));
        } elseif ($node instanceof BigIntLiteral) {
            $this->unsupported($node, 'BigInt literals are not supported (ES5 target)');
        } else {
            $this->unsupported($node, 'Unsupported literal');
        }
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
                    } else {
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
        if ($left->getType() !== 'Identifier' && $left->getType() !== 'MemberExpression') {
            $this->unsupported($left, 'Destructuring assignment is not supported (ES5 target)');
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
    }

    private function emitStoreName(string $name): void
    {
        $c = $this->cur;
        $b = $this->resolve($name);
        if ($b instanceof Binding) {
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

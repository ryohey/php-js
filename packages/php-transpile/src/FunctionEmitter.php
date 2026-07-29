<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

use PhpParser\BuilderFactory;
use PhpParser\Node as P;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpJs\Compiler\Ctx;
use Peast\Syntax\Node\BooleanLiteral;
use Peast\Syntax\Node\NullLiteral;
use Peast\Syntax\Node\NumericLiteral;
use Peast\Syntax\Node\StringLiteral;
use PhpJs\Runtime\Conversions;

/**
 * Emits one JS function as a PHP closure, as php-parser AST nodes.
 *
 * Never by string concatenation: every literal goes through php-parser's
 * builders, so a string from the source cannot become PHP syntax
 * (docs/aot-php.md §3).
 *
 * The generated function is an ordinary native for this runtime —
 * `fn(Vm, mixed $this, array $args, ?JSNativeFunction $self): mixed` — so it
 * shares the heap and the value representation with the interpreter and needs
 * no marshaling in either direction.
 *
 * ## What it does not do
 *
 * Conservative by construction: property writes go through the full `[[Set]]`
 * path, arithmetic goes through `Ops`, calls go through `Vm::invoke`. No type
 * speculation, no escape analysis, no assumption that a write target is a
 * fresh object. That costs about 2x against a hand-written native (measured,
 * docs/aot-php.md §8) and it is the right starting point: every specialization
 * beyond this has to be justified separately.
 *
 * ## Statement hoisting
 *
 * JS expressions can contain statements' worth of work — a sequence operator,
 * an assignment, a short-circuit. Expression emission may therefore append to
 * the current statement list, and any construct with a conditionally evaluated
 * operand (`&&`, `||`, `?:`) emits its branches into a nested buffer; if a
 * branch turns out to need statements, the whole thing becomes an `if` writing
 * to a temporary instead of a ternary. That is what keeps short-circuiting
 * honest.
 */
final class FunctionEmitter
{
    private BuilderFactory $b;
    private Scope $scope;
    /** @var list<Stmt> the statement list currently being built */
    private array $stmts = [];
    private int $tempSeq = 0;
    private bool $strict;
    /** Nesting depth of loops, for break/continue. */
    private int $loopDepth = 0;
    /**
     * Expressions this emitter knows produce a PHP bool. Not type inference on
     * the JS program — a static fact about our own output, which lets `if`
     * conditions skip a ToBoolean call that could never do anything.
     * @var \SplObjectStorage<Expr, bool>
     */
    private \SplObjectStorage $isBool;

    public function __construct(private readonly Ctx $ctx)
    {
        $this->b = new BuilderFactory();
        $this->isBool = new \SplObjectStorage();
        $this->scope = new Scope($ctx);
        $this->strict = $ctx->strict;
    }

    /**
     * @param object $node the FunctionExpression / FunctionDeclaration
     * @return Expr\Closure a static closure with the native calling convention
     * @throws Unsupported when any part of the body is out of scope
     */
    public function emit(object $node): Expr\Closure
    {
        $body = $node->getBody()->getBody();
        $this->stmts = [];
        $this->prologue();
        foreach ($body as $stmt) {
            $this->stmt($stmt);
        }
        if (!(end($this->stmts) instanceof Stmt\Return_)) {
            $this->stmts[] = new Stmt\Return_($this->undef());
        }

        // fn(Vm $vm, mixed $thisVal, array $args, ?JSNativeFunction $self): mixed
        $vm = new P\Param(new Expr\Variable('vm'));
        $thisVal = new P\Param(new Expr\Variable('thisVal'));
        $args = new P\Param(new Expr\Variable('args'));
        $args->type = new P\Identifier('array');
        $self = new P\Param(new Expr\Variable('self'));
        $self->default = new Expr\ConstFetch(new P\Name('null'));

        return new Expr\Closure([
            'static' => true,
            'params' => [$vm, $thisVal, $args, $self],
            'stmts' => $this->stmts,
        ]);
    }

    /** Bind parameters, declare locals, and expose the environment record. */
    private function prologue(): void
    {
        if ($this->ctx->nenv > 0) {
            throw new Unsupported('function creates its own environment record (its locals are captured)');
        }

        $argc = new Expr\FuncCall(new P\Name('count'), [new P\Arg($this->var('args'))]);
        $this->stmts[] = $this->assign($this->var('argc'), $argc);

        foreach ($this->ctx->params as $i => $name) {
            $b = $this->ctx->bindings[$name] ?? null;
            if ($b === null || $b->captured) {
                throw new Unsupported("parameter '$name' is captured");
            }
            // Missing argument is `undefined`; a present `null` stays null.
            $this->stmts[] = $this->assign(
                $this->local($name),
                new Expr\Ternary(
                    new Expr\BinaryOp\Greater($this->var('argc'), $this->b->val($i)),
                    new Expr\ArrayDimFetch($this->var('args'), $this->b->val($i)),
                    $this->undef()
                )
            );
        }
        foreach ($this->ctx->bindings as $name => $b) {
            if ($b->kind === 'param' || $b->captured) {
                continue;
            }
            $this->stmts[] = $this->assign($this->local($name), $this->undef());
        }
    }

    // ---- statements --------------------------------------------------------

    private function stmt(object $node): void
    {
        switch ($node->getType()) {
            case 'ExpressionStatement':
                $e = $this->expr($node->getExpression());
                $this->stmts[] = new Stmt\Expression($e);
                return;
            case 'VariableDeclaration':
                foreach ($node->getDeclarations() as $d) {
                    $init = $d->getInit();
                    if ($init === null) {
                        continue; // hoisted to undefined in the prologue
                    }
                    $this->stmts[] = new Stmt\Expression(
                        $this->store($d->getId()->getName(), $this->expr($init))
                    );
                }
                return;
            case 'BlockStatement':
                foreach ($node->getBody() as $s) {
                    $this->stmt($s);
                }
                return;
            case 'EmptyStatement':
                return;
            case 'IfStatement': {
                $cond = $this->truthy($this->expr($node->getTest()));
                $then = $this->block(fn () => $this->stmt($node->getConsequent()));
                $if = new Stmt\If_($cond, ['stmts' => $then]);
                if ($node->getAlternate() !== null) {
                    $if->else = new Stmt\Else_($this->block(fn () => $this->stmt($node->getAlternate())));
                }
                $this->stmts[] = $if;
                return;
            }
            case 'ReturnStatement': {
                $arg = $node->getArgument();
                $this->stmts[] = new Stmt\Return_($arg === null ? $this->undef() : $this->expr($arg));
                return;
            }
            case 'ThrowStatement':
                $this->stmts[] = new Stmt\Expression(
                    new Expr\MethodCall($this->var('vm'), 'throwValue', [new P\Arg($this->expr($node->getArgument()))])
                );
                return;
            case 'WhileStatement':
                $this->whileLoop($node);
                return;
            case 'DoWhileStatement':
                $this->doWhileLoop($node);
                return;
            case 'ForStatement':
                $this->forLoop($node);
                return;
            case 'ForInStatement':
                $this->forInLoop($node);
                return;
            case 'BreakStatement':
                if ($node->getLabel() !== null) {
                    throw new Unsupported('labelled break');
                }
                if ($this->loopDepth === 0) {
                    throw new Unsupported('break outside a loop (switch is not supported)');
                }
                $this->stmts[] = new Stmt\Break_();
                return;
            case 'ContinueStatement':
                if ($node->getLabel() !== null) {
                    throw new Unsupported('labelled continue');
                }
                if ($this->loopDepth === 0) {
                    throw new Unsupported('continue outside a loop');
                }
                $this->stmts[] = new Stmt\Continue_();
                return;
            case 'FunctionDeclaration':
                throw new Unsupported('nested function declaration');
            default:
                throw Unsupported::node($node, 'statement not supported');
        }
    }

    /**
     * A JS loop test can require statements (an assignment, a short-circuit),
     * and those must re-run every iteration — so the loop is emitted as
     * `while (true) { <test statements>; if (!cond) break; ... }`.
     */
    private function whileLoop(object $node): void
    {
        $this->loopDepth++;
        $body = $this->block(function () use ($node) {
            $this->breakUnless($node->getTest());
            $this->stmt($node->getBody());
        });
        $this->loopDepth--;
        $this->stmts[] = new Stmt\While_($this->b->val(true), $body);
    }

    private function doWhileLoop(object $node): void
    {
        $this->loopDepth++;
        $body = $this->block(function () use ($node) {
            $this->stmt($node->getBody());
            $this->breakUnless($node->getTest());
        });
        $this->loopDepth--;
        $this->stmts[] = new Stmt\While_($this->b->val(true), $body);
    }

    private function forLoop(object $node): void
    {
        $init = $node->getInit();
        if ($init !== null) {
            if ($init->getType() === 'VariableDeclaration') {
                $this->stmt($init);
            } else {
                $this->stmts[] = new Stmt\Expression($this->expr($init));
            }
        }
        $this->loopDepth++;
        $body = $this->block(function () use ($node) {
            if ($node->getTest() !== null) {
                $this->breakUnless($node->getTest());
            }
            $this->stmt($node->getBody());
            if ($node->getUpdate() !== null) {
                $this->stmts[] = new Stmt\Expression($this->expr($node->getUpdate()));
            }
        });
        $this->loopDepth--;
        // `continue` must still run the update, so the update is appended to
        // the body rather than living in a PHP for-header. A `continue` inside
        // would therefore skip it -- refuse that combination.
        if ($node->getUpdate() !== null && $this->containsContinue($node->getBody())) {
            throw new Unsupported('continue inside a for loop with an update expression');
        }
        $this->stmts[] = new Stmt\While_($this->b->val(true), $body);
    }

    private function forInLoop(object $node): void
    {
        $left = $node->getLeft();
        if ($left->getType() === 'VariableDeclaration') {
            $decls = $left->getDeclarations();
            if (count($decls) !== 1 || $decls[0]->getInit() !== null) {
                throw new Unsupported('for-in with a complex declaration');
            }
            $target = $decls[0]->getId();
        } else {
            $target = $left;
        }
        if ($target->getType() !== 'Identifier') {
            throw new Unsupported('for-in into a member expression');
        }

        $obj = $this->temp();
        $this->stmts[] = $this->assign($obj, $this->expr($node->getRight()));
        $keys = $this->temp();
        $this->stmts[] = $this->assign($keys, $this->staticCall('Ops', 'forInKeys', [$this->var('vm'), $obj]));
        $k = $this->temp();

        $this->loopDepth++;
        $body = $this->block(function () use ($node, $target, $obj, $k) {
            // A property deleted mid-iteration is skipped, as in the VM.
            $this->stmts[] = new Stmt\If_(
                new Expr\BooleanNot($this->staticCall('Ops', 'forInLive', [$obj, $k])),
                ['stmts' => [new Stmt\Continue_()]]
            );
            $this->stmts[] = new Stmt\Expression($this->store($target->getName(), $k));
            $this->stmt($node->getBody());
        });
        $this->loopDepth--;
        $this->stmts[] = new Stmt\Foreach_($keys, $k, ['stmts' => $body]);
    }

    private function breakUnless(object $test): void
    {
        $cond = $this->truthy($this->expr($test));
        $this->stmts[] = new Stmt\If_(new Expr\BooleanNot($cond), ['stmts' => [new Stmt\Break_()]]);
    }

    /** Collect a nested statement list produced by $build. */
    private function block(callable $build): array
    {
        $saved = $this->stmts;
        $this->stmts = [];
        $build();
        $inner = $this->stmts;
        $this->stmts = $saved;
        return $inner;
    }

    private function containsContinue(object $node): bool
    {
        $type = $node->getType();
        if ($type === 'ContinueStatement') {
            return true;
        }
        // Do not descend into nested loops: their `continue` belongs to them.
        if (in_array($type, ['ForStatement', 'ForInStatement', 'WhileStatement', 'DoWhileStatement', 'FunctionExpression', 'FunctionDeclaration'], true)) {
            return false;
        }
        foreach (['getBody', 'getConsequent', 'getAlternate'] as $getter) {
            if (!method_exists($node, $getter)) {
                continue;
            }
            $child = $node->$getter();
            if ($child === null) {
                continue;
            }
            foreach (is_array($child) ? $child : [$child] as $c) {
                if (is_object($c) && method_exists($c, 'getType') && $this->containsContinue($c)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---- expressions -------------------------------------------------------

    private function expr(object $node): Expr
    {
        // Peast keeps parentheses as nodes; they mean nothing to us.
        while ($node->getType() === 'ParenthesizedExpression') {
            $node = $node->getExpression();
        }
        switch ($node->getType()) {
            case 'Literal':
                if ($node instanceof NullLiteral) {
                    return new Expr\ConstFetch(new P\Name('null'));
                }
                if ($node instanceof NumericLiteral || $node instanceof StringLiteral || $node instanceof BooleanLiteral) {
                    return $this->b->val($node->getValue());
                }
                // RegExp literals need a compiled pattern from the constant
                // pool, which only the bytecode path carries.
                throw Unsupported::node($node, 'literal kind not supported');
            case 'Identifier':
                return $this->load($node->getName());
            case 'ThisExpression':
                return $this->var('thisVal');
            case 'ObjectExpression':
                return $this->objectLiteral($node);
            case 'ArrayExpression':
                return $this->arrayLiteral($node);
            case 'MemberExpression':
                return $this->member($node);
            case 'CallExpression':
                return $this->callExpr($node);
            case 'NewExpression':
                return new Expr\MethodCall($this->var('vm'), 'construct', [
                    new P\Arg($this->expr($node->getCallee())),
                    new P\Arg($this->argList($node->getArguments())),
                ]);
            case 'UnaryExpression':
                return $this->unary($node);
            case 'BinaryExpression':
                return $this->binary($node);
            case 'LogicalExpression':
                return $this->logical($node);
            case 'ConditionalExpression':
                return $this->conditional($node);
            case 'AssignmentExpression':
                return $this->assignment($node);
            case 'UpdateExpression':
                return $this->update($node);
            case 'SequenceExpression': {
                $parts = $node->getExpressions();
                $last = array_pop($parts);
                foreach ($parts as $p) {
                    $this->stmts[] = new Stmt\Expression($this->expr($p));
                }
                return $this->expr($last);
            }
            default:
                throw Unsupported::node($node, 'expression not supported');
        }
    }

    private function objectLiteral(object $node): Expr
    {
        $o = $this->temp();
        $this->stmts[] = $this->assign($o, new Expr\New_(
            new P\Name('\\PhpJs\\Runtime\\JSObject'),
            [new P\Arg(new Expr\MethodCall($this->realm(), 'objectPrototype'))]
        ));
        foreach ($node->getProperties() as $prop) {
            if ($prop->getKind() !== 'init') {
                throw new Unsupported('accessor property in an object literal');
            }
            $key = $prop->getKey();
            if ($prop->getComputed()) {
                throw new Unsupported('computed key in an object literal');
            }
            $name = match (true) {
                $key instanceof StringLiteral => $key->getValue(),
                $key instanceof NumericLiteral => Conversions::numberToString($key->getValue()),
                $key->getType() === 'Identifier' => $key->getName(),
                default => throw new Unsupported('object literal key kind'),
            };
            // DEFINE_DATA semantics: define, never invoke a setter.
            $this->stmts[] = new Stmt\Expression(new Expr\MethodCall($o, 'defineOwnData', [
                new P\Arg($this->b->val((string)$name)),
                new P\Arg($this->expr($prop->getValue())),
            ]));
        }
        return $o;
    }

    private function arrayLiteral(object $node): Expr
    {
        $a = $this->temp();
        $this->stmts[] = $this->assign($a, new Expr\New_(
            new P\Name('\\PhpJs\\Runtime\\JSArray'),
            [new P\Arg(new Expr\MethodCall($this->realm(), 'arrayPrototype'))]
        ));
        $n = 0;
        foreach ($node->getElements() as $el) {
            if ($el !== null) {
                $this->stmts[] = new Stmt\Expression(new Expr\Assign(
                    new Expr\ArrayDimFetch(new Expr\PropertyFetch($a, 'elements'), $this->b->val($n)),
                    $this->expr($el)
                ));
            }
            $n++;
        }
        $this->stmts[] = new Stmt\Expression(new Expr\Assign(new Expr\PropertyFetch($a, 'length'), $this->b->val($n)));
        return $a;
    }

    /** @return array{0: Expr, 1: Expr} [object, key] with the object evaluated once */
    private function memberParts(object $node): array
    {
        $objNode = $node->getObject();
        if ($objNode->getType() === 'Identifier' && $objNode->getName() === 'arguments' && $this->ctx->usesArguments) {
            throw new Unsupported('`arguments` used other than as .length or [i]');
        }
        $obj = $this->expr($objNode);
        if ($node->getComputed()) {
            return [$obj, $this->expr($node->getProperty())];
        }
        return [$obj, $this->b->val($node->getProperty()->getName())];
    }

    private function member(object $node): Expr
    {
        $objNode = $node->getObject();
        // `arguments.length` and `arguments[i]` compile to direct argument
        // access; no arguments object is ever built. Anything else is refused
        // by memberParts().
        if ($objNode->getType() === 'Identifier' && $objNode->getName() === 'arguments'
            && $this->resolvesToArguments()) {
            if (!$node->getComputed() && $node->getProperty()->getName() === 'length') {
                return $this->var('argc');
            }
            if ($node->getComputed()) {
                return $this->staticCall('Ops', 'arg', [
                    $this->var('args'),
                    $this->staticCall('Conversions', 'toInt32', [$this->var('vm'), $this->expr($node->getProperty())]),
                ]);
            }
            throw new Unsupported('`arguments` used other than as .length or [i]');
        }
        [$obj, $key] = $this->memberParts($node);
        return $this->staticCall('Ops', 'getProp', [$this->var('vm'), $obj, $key]);
    }

    private function resolvesToArguments(): bool
    {
        // Only when `arguments` is the implicit binding, not a shadowing local.
        return !isset($this->ctx->bindings['arguments']);
    }

    private function callExpr(object $node): Expr
    {
        $callee = $node->getCallee();
        if ($callee->getType() === 'MemberExpression') {
            [$obj, $key] = $this->memberParts($callee);
            $recv = $this->temp();
            $this->stmts[] = $this->assign($recv, $obj);
            $fn = $this->staticCall('Ops', 'getProp', [$this->var('vm'), $recv, $key]);
            return new Expr\MethodCall($this->var('vm'), 'invoke', [
                new P\Arg($fn),
                new P\Arg($recv),
                new P\Arg($this->argList($node->getArguments())),
            ]);
        }
        return new Expr\MethodCall($this->var('vm'), 'invoke', [
            new P\Arg($this->expr($callee)),
            new P\Arg($this->undef()),
            new P\Arg($this->argList($node->getArguments())),
        ]);
    }

    private function argList(array $args): Expr
    {
        $items = [];
        foreach ($args as $a) {
            if ($a->getType() === 'SpreadElement') {
                throw new Unsupported('spread argument');
            }
            $items[] = new P\ArrayItem($this->expr($a));
        }
        return new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT]);
    }

    private function unary(object $node): Expr
    {
        $op = $node->getOperator();
        $argNode = $node->getArgument();
        if ($op === 'delete') {
            if ($argNode->getType() !== 'MemberExpression') {
                throw new Unsupported('delete of a non-member');
            }
            [$obj, $key] = $this->memberParts($argNode);
            return new Expr\MethodCall($this->var('vm'), 'deleteMember', [
                new P\Arg($obj), new P\Arg($key), new P\Arg($this->b->val($this->strict)),
            ]);
        }
        if ($op === 'typeof' && $argNode->getType() === 'Identifier') {
            $r = $this->scope->resolve($argNode->getName());
            if ($r['kind'] === 'global') {
                throw new Unsupported('typeof on a possibly-undeclared global');
            }
        }
        $a = $this->expr($argNode);
        return match ($op) {
            '-' => $this->staticCall('Ops', 'neg', [$this->var('vm'), $a]),
            '+' => $this->staticCall('Conversions', 'toNumber', [$this->var('vm'), $a]),
            '!' => $this->bool(new Expr\BooleanNot($this->truthy($a))),
            '~' => $this->staticCall('Ops', 'bnot', [$this->var('vm'), $a]),
            'typeof' => $this->staticCall('TypeOps', 'typeofOp', [$a]),
            'void' => $this->voidOf($argNode, $a),
            default => throw new Unsupported("unary operator $op"),
        };
    }

    /** `void x` discards x; when x cannot have side effects, discard it here. */
    private function voidOf(object $argNode, Expr $a): Expr
    {
        if ($argNode->getType() === 'Literal' || $argNode->getType() === 'Identifier') {
            return $this->undef();
        }
        return $this->seqValue($a, $this->undef());
    }

    private function binary(object $node): Expr
    {
        $op = $node->getOperator();
        $l = $this->expr($node->getLeft());
        $r = $this->expr($node->getRight());
        $vm = $this->var('vm');
        return match ($op) {
            '+' => $this->staticCall('TypeOps', 'add', [$vm, $l, $r]),
            '-' => $this->staticCall('Ops', 'sub', [$vm, $l, $r]),
            '*' => $this->staticCall('Ops', 'mul', [$vm, $l, $r]),
            '/' => $this->staticCall('Ops', 'div', [$vm, $l, $r]),
            '%' => $this->staticCall('Ops', 'mod', [$vm, $l, $r]),
            '<' => $this->bool($this->staticCall('Ops', 'lt', [$vm, $l, $r])),
            '>' => $this->bool($this->staticCall('Ops', 'gt', [$vm, $l, $r])),
            '<=' => $this->bool($this->staticCall('Ops', 'le', [$vm, $l, $r])),
            '>=' => $this->bool($this->staticCall('Ops', 'ge', [$vm, $l, $r])),
            '==' => $this->bool($this->staticCall('TypeOps', 'looseEquals', [$vm, $l, $r])),
            '!=' => $this->bool(new Expr\BooleanNot($this->staticCall('TypeOps', 'looseEquals', [$vm, $l, $r]))),
            '===' => $this->bool($this->staticCall('TypeOps', 'strictEquals', [$l, $r])),
            '!==' => $this->bool(new Expr\BooleanNot($this->staticCall('TypeOps', 'strictEquals', [$l, $r]))),
            'instanceof' => $this->bool($this->staticCall('TypeOps', 'instanceofOp', [$vm, $l, $r])),
            'in' => $this->bool($this->staticCall('TypeOps', 'inOp', [$vm, $l, $r])),
            '&', '|', '^', '<<', '>>', '>>>' => $this->staticCall(
                'Ops',
                'bitwise',
                [$vm, $this->b->val($op), $l, $r]
            ),
            default => throw new Unsupported("binary operator $op"),
        };
    }

    /**
     * `&&` / `||`. The right operand is conditional, so it is emitted into a
     * nested buffer; if it needed statements, the result is an `if` rather than
     * a ternary, which is what keeps the short circuit real.
     */
    private function logical(object $node): Expr
    {
        $op = $node->getOperator();
        if ($op !== '&&' && $op !== '||') {
            throw new Unsupported("logical operator $op");
        }
        $lhs = $this->expr($node->getLeft());
        // Boolness has to be carried across the temporary by hand: the marker
        // is keyed on the expression node, and the temp is a different node.
        // Chained `&&` builds a temp per level, so losing it here means a
        // ToBoolean call on every link of the chain.
        $lhsIsBool = $this->isBool->contains($lhs);
        $t = $this->temp();
        $this->stmts[] = $this->assign($t, $lhs);

        $rhs = null;
        $rhsStmts = $this->block(function () use ($node, &$rhs) {
            $rhs = $this->expr($node->getRight());
        });
        $rhsIsBool = $this->isBool->contains($rhs);

        $cond = $lhsIsBool ? $t : $this->staticCall('Conversions', 'toBoolean', [$t]);
        if ($op === '||') {
            $cond = new Expr\BooleanNot($cond);
        }
        $rhsStmts[] = new Stmt\Expression(new Expr\Assign($t, $rhs));
        $this->stmts[] = new Stmt\If_($cond, ['stmts' => $rhsStmts]);
        // The result is one side or the other, so it is a bool only if both are.
        return ($lhsIsBool && $rhsIsBool) ? $this->bool($t) : $t;
    }

    private function conditional(object $node): Expr
    {
        $cond = $this->truthy($this->expr($node->getTest()));
        $t = $this->temp();
        $a = null;
        $aStmts = $this->block(function () use ($node, &$a) {
            $a = $this->expr($node->getConsequent());
        });
        $b = null;
        $bStmts = $this->block(function () use ($node, &$b) {
            $b = $this->expr($node->getAlternate());
        });
        $bothBool = $this->isBool->contains($a) && $this->isBool->contains($b);
        if ($aStmts === [] && $bStmts === []) {
            $r = new Expr\Ternary($cond, $a, $b);
            return $bothBool ? $this->bool($r) : $r;
        }
        $aStmts[] = new Stmt\Expression(new Expr\Assign($t, $a));
        $bStmts[] = new Stmt\Expression(new Expr\Assign($t, $b));
        $this->stmts[] = new Stmt\If_($cond, ['stmts' => $aStmts, 'else' => new Stmt\Else_($bStmts)]);
        return $bothBool ? $this->bool($t) : $t;
    }

    private function assignment(object $node): Expr
    {
        $op = $node->getOperator();
        $target = $node->getLeft();
        $value = $this->expr($node->getRight());

        if ($op !== '=') {
            $binOp = rtrim($op, '=');
            $current = $this->expr($target);
            $value = $this->applyBinary($binOp, $current, $value);
        }

        if ($target->getType() === 'Identifier') {
            return $this->store($target->getName(), $value);
        }
        if ($target->getType() === 'MemberExpression') {
            [$obj, $key] = $this->memberParts($target);
            $v = $this->temp();
            $this->stmts[] = $this->assign($v, $value);
            $this->stmts[] = new Stmt\Expression(new Expr\MethodCall($this->var('vm'), 'setMember', [
                new P\Arg($obj), new P\Arg($key), new P\Arg($v), new P\Arg($this->b->val($this->strict)),
            ]));
            return $v;
        }
        throw new Unsupported('assignment target');
    }

    private function applyBinary(string $op, Expr $l, Expr $r): Expr
    {
        $vm = $this->var('vm');
        return match ($op) {
            '+' => $this->staticCall('TypeOps', 'add', [$vm, $l, $r]),
            '-' => $this->staticCall('Ops', 'sub', [$vm, $l, $r]),
            '*' => $this->staticCall('Ops', 'mul', [$vm, $l, $r]),
            '/' => $this->staticCall('Ops', 'div', [$vm, $l, $r]),
            '%' => $this->staticCall('Ops', 'mod', [$vm, $l, $r]),
            '&', '|', '^', '<<', '>>', '>>>' => $this->staticCall('Ops', 'bitwise', [$vm, $this->b->val($op), $l, $r]),
            default => throw new Unsupported("compound assignment $op="),
        };
    }

    private function update(object $node): Expr
    {
        $target = $node->getArgument();
        while ($target->getType() === 'ParenthesizedExpression') {
            $target = $target->getExpression();
        }
        $delta = $node->getOperator() === '++' ? 1 : -1;

        if ($target->getType() === 'Identifier') {
            $read = $this->load($target->getName());
            $writeBack = fn (Expr $v) => $this->store($target->getName(), $v);
        } elseif ($target->getType() === 'MemberExpression') {
            // The object and key are evaluated once, then read and written.
            [$obj, $key] = $this->memberParts($target);
            $o = $this->temp();
            $k = $this->temp();
            $this->stmts[] = $this->assign($o, $obj);
            $this->stmts[] = $this->assign($k, $key);
            $read = $this->staticCall('Ops', 'getProp', [$this->var('vm'), $o, $k]);
            $writeBack = fn (Expr $v) => new Expr\MethodCall($this->var('vm'), 'setMember', [
                new P\Arg($o), new P\Arg($k), new P\Arg($v), new P\Arg($this->b->val($this->strict)),
            ]);
        } else {
            throw new Unsupported('++/-- on this target');
        }

        // The old value is the ToNumber of the current one, which is what both
        // the postfix result and the increment are computed from.
        $old = $this->temp();
        $this->stmts[] = $this->assign($old, $this->staticCall('Conversions', 'toNumber', [$this->var('vm'), $read]));
        $new = $this->temp();
        $this->stmts[] = $this->assign($new, $this->staticCall('Ops', 'sub', [
            $this->var('vm'), $old, $this->b->val(-$delta),
        ]));
        $this->stmts[] = new Stmt\Expression($writeBack($new));
        return $node->getPrefix() ? $new : $old;
    }

    // ---- variable access ---------------------------------------------------

    private function load(string $name): Expr
    {
        if ($name === 'undefined') {
            return $this->undef();
        }
        $r = $this->scope->resolve($name);
        return match ($r['kind']) {
            'local' => $this->local($r['name']),
            'env' => $this->envSlot($r['depth'], $r['slot']),
            'global' => new Expr\MethodCall($this->var('vm'), 'globalGet', [new P\Arg($this->b->val($name))]),
        };
    }

    private function store(string $name, Expr $value): Expr
    {
        $r = $this->scope->resolve($name);
        return match ($r['kind']) {
            'local' => new Expr\Assign($this->local($r['name']), $value),
            'env' => new Expr\Assign($this->envSlot($r['depth'], $r['slot']), $value),
            'global' => $this->storeGlobal($name, $value),
        };
    }

    private function storeGlobal(string $name, Expr $value): Expr
    {
        // The value has to land in a temporary first: it is both written to the
        // global and the result of the assignment expression, and evaluating it
        // twice would repeat its side effects.
        $t = $this->temp();
        $this->stmts[] = $this->assign($t, $value);
        $this->stmts[] = new Stmt\Expression(new Expr\MethodCall($this->var('vm'), 'globalSet', [
            new P\Arg($this->b->val($name)),
            new P\Arg($t),
            new P\Arg($this->b->val($this->strict)),
        ]));
        return $t;
    }

    private function envSlot(int $depth, int $slot): Expr
    {
        // The defining environment travels on the JSFunction we were called as.
        $env = new Expr\PropertyFetch($this->var('self'), 'env');
        for ($i = 0; $i < $depth; $i++) {
            $env = new Expr\PropertyFetch($env, 'parent');
        }
        return new Expr\ArrayDimFetch(new Expr\PropertyFetch($env, 'slots'), $this->b->val($slot));
    }

    // ---- helpers -----------------------------------------------------------

    private function var(string $name): Expr\Variable
    {
        return new Expr\Variable($name);
    }

    private function local(string $jsName): Expr\Variable
    {
        // Prefixed so a JS name can never collide with an emitter temporary
        // or with the calling convention's own variables.
        return new Expr\Variable('js_' . preg_replace('/[^A-Za-z0-9_]/', '_', $jsName));
    }

    private function temp(): Expr\Variable
    {
        return new Expr\Variable('t' . (++$this->tempSeq));
    }

    private function assign(Expr $target, Expr $value): Stmt
    {
        return new Stmt\Expression(new Expr\Assign($target, $value));
    }

    private function undef(): Expr
    {
        return new Expr\StaticPropertyFetch(new P\Name('\\PhpJs\\Runtime\\JSUndefined'), 'undefined');
    }

    private function realm(): Expr
    {
        return new Expr\PropertyFetch($this->var('vm'), 'realm');
    }

    private function truthy(Expr $e): Expr
    {
        if ($this->isBool->contains($e)) {
            return $e;
        }
        return $this->staticCall('Conversions', 'toBoolean', [$e]);
    }

    /** Mark an emitted expression as already being a PHP bool. */
    private function bool(Expr $e): Expr
    {
        $this->isBool->attach($e);
        return $e;
    }

    /** Evaluate $effect for its side effect, then yield $value. */
    private function seqValue(Expr $effect, Expr $value): Expr
    {
        $t = $this->temp();
        $this->stmts[] = new Stmt\Expression($effect);
        $this->stmts[] = $this->assign($t, $value);
        return $t;
    }

    /** @param list<Expr> $args */
    private function staticCall(string $class, string $method, array $args): Expr
    {
        return new Expr\StaticCall(
            new P\Name('\\PhpJs\\Runtime\\' . $class),
            $method,
            array_map(static fn (Expr $a) => new P\Arg($a), $args)
        );
    }
}

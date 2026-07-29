<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

use Peast\Peast;

/**
 * Facts about a whole module that a single function's emitter cannot see.
 *
 * The emitter works one function at a time, which is the right granularity for
 * codegen and the wrong one for questions like "is this binding still the
 * builtin it was initialized from". Those need the module, so they are settled
 * here, once, before any function is emitted.
 *
 * Everything is a *proof over the module text*, not a guess: a binding counts
 * as the builtin only if it is assigned that builtin exactly once and never
 * assigned again anywhere in the module. What is assumed rather than proved —
 * that the builtin was still itself when the module loaded — lives in
 * `Assumptions`, and the caller opts into it.
 */
final class ModuleFacts
{
    /**
     * @param array<string, true> $hasOwnPropertyBindings names proved to hold
     *        Object.prototype.hasOwnProperty for the whole module's lifetime
     */
    private function __construct(
        public readonly array $hasOwnPropertyBindings,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** Scan a module's source. Returns no facts if it cannot be parsed. */
    public static function scan(string $source): self
    {
        try {
            $ast = Peast::latest($source, ['sourceType' => 'script'])->parse();
        } catch (\Throwable) {
            return self::none();
        }

        $initialized = [];   // name => times assigned Object.prototype.hasOwnProperty
        $assigned = [];      // name => total assignments seen
        self::walk($ast, function (object $node) use (&$initialized, &$assigned): void {
            $type = $node->getType();
            if ($type === 'VariableDeclarator') {
                $id = $node->getId();
                if ($id->getType() !== 'Identifier') {
                    return;
                }
                $name = $id->getName();
                $init = $node->getInit();
                if ($init === null) {
                    return; // a declaration without an initializer assigns nothing
                }
                $assigned[$name] = ($assigned[$name] ?? 0) + 1;
                if (self::isHasOwnProperty($init)) {
                    $initialized[$name] = ($initialized[$name] ?? 0) + 1;
                }
                return;
            }
            if ($type === 'AssignmentExpression') {
                $left = $node->getLeft();
                if ($left->getType() === 'Identifier') {
                    $name = $left->getName();
                    $assigned[$name] = ($assigned[$name] ?? 0) + 1;
                    if ($node->getOperator() === '=' && self::isHasOwnProperty($node->getRight())) {
                        $initialized[$name] = ($initialized[$name] ?? 0) + 1;
                    }
                }
                return;
            }
            if ($type === 'UpdateExpression') {
                $arg = $node->getArgument();
                if ($arg->getType() === 'Identifier') {
                    $assigned[$arg->getName()] = ($assigned[$arg->getName()] ?? 0) + 1;
                }
            }
        });

        $proved = [];
        foreach ($initialized as $name => $count) {
            // Exactly one assignment in the module, and it was the builtin.
            if ($count === 1 && ($assigned[$name] ?? 0) === 1) {
                $proved[$name] = true;
            }
        }
        return new self($proved);
    }

    /** `Object.prototype.hasOwnProperty` or the `{}.hasOwnProperty` shorthand. */
    private static function isHasOwnProperty(object $node): bool
    {
        while ($node->getType() === 'ParenthesizedExpression') {
            $node = $node->getExpression();
        }
        if ($node->getType() !== 'MemberExpression' || $node->getComputed()) {
            return false;
        }
        $prop = $node->getProperty();
        if ($prop->getType() !== 'Identifier' || $prop->getName() !== 'hasOwnProperty') {
            return false;
        }
        $obj = $node->getObject();
        while ($obj->getType() === 'ParenthesizedExpression') {
            $obj = $obj->getExpression();
        }
        // {}.hasOwnProperty
        if ($obj->getType() === 'ObjectExpression' && $obj->getProperties() === []) {
            return true;
        }
        // Object.prototype.hasOwnProperty
        return $obj->getType() === 'MemberExpression'
            && !$obj->getComputed()
            && $obj->getProperty()->getType() === 'Identifier'
            && $obj->getProperty()->getName() === 'prototype'
            && $obj->getObject()->getType() === 'Identifier'
            && $obj->getObject()->getName() === 'Object';
    }

    /** Depth-first walk over every node reachable from $node. */
    private static function walk(object $node, callable $visit): void
    {
        $visit($node);
        foreach (get_class_methods($node) as $method) {
            if (!str_starts_with($method, 'get') || $method === 'getType' || $method === 'getLocation') {
                continue;
            }
            try {
                $child = $node->$method();
            } catch (\Throwable) {
                continue;
            }
            foreach (is_array($child) ? $child : [$child] as $c) {
                if (is_object($c) && method_exists($c, 'getType')) {
                    self::walk($c, $visit);
                }
            }
        }
    }
}

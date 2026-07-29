<?php

declare(strict_types=1);

namespace PhpJs\Transpile;

use PhpJs\Compiler\Binding;
use PhpJs\Compiler\Ctx;

/**
 * Resolves an identifier the way the bytecode compiler resolves it.
 *
 * This is the load-bearing part of the whole scheme (docs/aot-php.md §3): the
 * generated PHP reads captured variables as `$env->slots[N]`, and N has to be
 * the slot the compiler assigned or the function silently reads the wrong
 * variable. The only safe way to get that is to run against the compiler's own
 * analysis output — the `Ctx` chain, after `assignSlots` has run — rather than
 * re-deriving scope rules here.
 *
 * Anything this cannot resolve with certainty is refused, not guessed.
 */
final class Scope
{
    public function __construct(private readonly Ctx $ctx)
    {
    }

    /**
     * @return array{kind: 'local'|'env'|'global', slot?: int, depth?: int, name: string}
     */
    public function resolve(string $name): array
    {
        $depth = 0;
        for ($c = $this->ctx; $c !== null; $c = $c->parent) {
            $b = $this->bindingIn($c, $name);
            if ($b !== null) {
                if (!$b->captured) {
                    if ($c !== $this->ctx) {
                        // An uncaptured binding in an enclosing frame is not
                        // reachable from here. The compiler would never produce
                        // this, so it means our view of the scope chain is off.
                        throw new Unsupported("'$name' resolves to an uncaptured outer local");
                    }
                    return ['kind' => 'local', 'slot' => $b->slot, 'name' => $name];
                }
                return ['kind' => 'env', 'depth' => $this->envDepth($c), 'slot' => $b->envIndex, 'name' => $name];
            }
            // A program-level `var` is a property of the global object, not a
            // slot, so it resolves as a global below.
            if ($c->isProgram) {
                break;
            }
            $depth++;
        }
        return ['kind' => 'global', 'name' => $name];
    }

    /**
     * Catch parameters live in `extraBindings` and are block-scoped, which the
     * Ctx chain alone cannot place. A function containing one is refused, so
     * they never need resolving.
     */
    private function bindingIn(Ctx $c, string $name): ?Binding
    {
        if (isset($c->bindings[$name])) {
            return $c->bindings[$name];
        }
        if ($c->selfBinding !== null && $c->selfBinding->name === $name) {
            return $c->selfBinding;
        }
        foreach ($c->extraBindings as $b) {
            if ($b->name === $name) {
                throw new Unsupported("'$name' is a catch parameter");
            }
        }
        return null;
    }

    /** Environment records to walk from this function's own env to $owner's. */
    private function envDepth(Ctx $owner): int
    {
        $d = 0;
        for ($f = $this->ctx; $f !== $owner; $f = $f->parent) {
            if ($f === null) {
                throw new Unsupported('binding owner is not on the scope chain');
            }
            if ($f->nenv > 0) {
                $d++;
            }
        }
        return $d;
    }
}

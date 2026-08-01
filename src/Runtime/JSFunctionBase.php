<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * Common base for everything `typeof x === 'function'`. The VM dispatches
 * callables by concrete class (JSFunction / JSNativeFunction / JSBoundFunction).
 */
abstract class JSFunctionBase extends JSObject
{
    public string $name = '';
    public int $arity = 0;
    /** Arrow functions are callable but not constructible (DESIGN.md §2.5). */
    public bool $isArrow = false;
    protected bool $lengthMade = false;
    protected bool $nameMade = false;

    protected function ensureOwn(string $key): void
    {
        if ($key === 'length' && !$this->lengthMade) {
            $this->lengthMade = true;
            // A class may declare its own static `length`/`name` member,
            // installed directly via defineOwnData/defineOwnAccessor before
            // this ever runs (ClassDefinitionEvaluation's static members
            // overwrite whatever the constructor would otherwise get, DESIGN.md
            // §2.5 / test262 fn-name-static-precedence.js) -- which must win
            // over the auto-generated default rather than being clobbered by it.
            if ($this->hasOwnRaw('length')) {
                return;
            }
            $this->defineOwnData('length', $this->arity, self::C);
        } elseif ($key === 'name' && !$this->nameMade) {
            $this->nameMade = true;
            if ($this->hasOwnRaw('name')) {
                return;
            }
            $this->defineOwnData('name', $this->name, self::C);
        }
    }

    /** hasOwn() without ensureOwn()'s own recursion into this method. */
    private function hasOwnRaw(string $key): bool
    {
        return array_key_exists($key, $this->props) || ($this->descs !== null && isset($this->descs[$key]));
    }

    public function ensureAllOwn(): void
    {
        $this->ensureOwn('length');
        $this->ensureOwn('name');
    }
}

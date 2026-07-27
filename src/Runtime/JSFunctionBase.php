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
    protected bool $lengthMade = false;
    protected bool $nameMade = false;

    protected function ensureOwn(string $key): void
    {
        if ($key === 'length' && !$this->lengthMade) {
            $this->lengthMade = true;
            $this->defineOwnData('length', $this->arity, self::C);
        } elseif ($key === 'name' && !$this->nameMade) {
            $this->nameMade = true;
            $this->defineOwnData('name', $this->name, self::C);
        }
    }

    public function ensureAllOwn(): void
    {
        $this->ensureOwn('length');
        $this->ensureOwn('name');
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * The global object. Builtins are materialized lazily on first property miss
 * via the realm's global table (DESIGN.md §11.2).
 */
final class JSGlobalObject extends JSObject
{
    public Realm $realm;

    protected function ensureOwn(string $key): void
    {
        if (!array_key_exists($key, $this->props)
            && ($this->descs === null || !isset($this->descs[$key]))) {
            $this->realm->materializeGlobal($key);
        }
    }

    public function ensureAllOwn(): void
    {
        $this->realm->materializeAllGlobals();
    }
}

<?php

declare(strict_types=1);

namespace PhpJs\RegExp;

use PhpJs\Runtime\JSObject;

/** RegExp exotic object: JS source/flags plus the cached PCRE translation. */
final class JSRegExp extends JSObject
{
    public string $jsSource = '';
    public string $jsFlags = '';
    public string $pcre = '';
    public bool $global = false;
    public bool $sticky = false;

    public function __construct(?JSObject $proto = null)
    {
        parent::__construct($proto);
        $this->className = 'RegExp';
    }

    public static function from(mixed $v): ?self
    {
        return $v instanceof self ? $v : null;
    }
}

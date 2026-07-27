<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * PHP exception used ONLY to carry a JS exception value across the native
 * boundary (DESIGN.md §4.4). Inside the VM, JS throws are ordinary control
 * flow; this signal is thrown by native builtins/helpers and converted back
 * at the VM's call sites.
 */
final class JSThrowSignal extends \RuntimeException
{
    public function __construct(public mixed $value)
    {
        parent::__construct('Uncaught JS exception');
    }
}

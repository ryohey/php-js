<?php

declare(strict_types=1);

namespace PhpJs;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Vm\Vm;

/** Host-facing exception carrying an uncaught JS exception value. */
final class JSException extends \RuntimeException
{
    public mixed $jsValue = null;

    public static function from(Vm $vm, mixed $value): self
    {
        $message = 'Uncaught';
        try {
            if ($value instanceof JSObject && $value->className === 'Error') {
                $message = 'Uncaught ' . Conversions::toString($vm, $value);
            } else {
                $message = 'Uncaught ' . Conversions::toString($vm, $value);
            }
        } catch (JSThrowSignal) {
            $message = 'Uncaught <value not convertible to string>';
        }
        $e = new self($message);
        $e->jsValue = $value;
        return $e;
    }
}

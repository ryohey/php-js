<?php

declare(strict_types=1);

namespace PhpJs\Host;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSSymbol;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Vm\Vm;

/** console.log-style value formatting (host-side, not part of the JS heap). */
final class Display
{
    public static function stringify(Vm $vm, mixed $v, bool $topLevel = true, int $depth = 0): string
    {
        if (is_string($v)) {
            return $topLevel ? $v : "'" . $v . "'";
        }
        if (is_int($v) || is_float($v)) {
            return Conversions::numberToString($v);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if ($v instanceof JSUndefined) {
            return 'undefined';
        }
        if ($v instanceof JSFunctionBase) {
            return $v->name !== '' ? "[Function: {$v->name}]" : '[Function (anonymous)]';
        }
        if ($depth > 4) {
            return '[...]';
        }
        if ($v instanceof JSSymbol) {
            // Never through ToString: that is a TypeError for a symbol, and
            // console.log is exactly where you want to see one, not throw.
            return $v->display();
        }
        if ($v instanceof JSArray) {
            $parts = [];
            for ($i = 0; $i < min($v->length, 100); $i++) {
                $parts[] = array_key_exists($i, $v->elements)
                    ? self::stringify($vm, $v->elements[$i], false, $depth + 1)
                    : '<empty>';
            }
            if ($v->length > 100) {
                $parts[] = '... ' . ($v->length - 100) . ' more';
            }
            return '[ ' . implode(', ', $parts) . ' ]';
        }
        if ($v instanceof JSPrimitiveWrapper) {
            return '[' . $v->className . ': ' . self::stringify($vm, $v->primitiveValue, false, $depth + 1) . ']';
        }
        if ($v instanceof JSObject) {
            if ($v->className === 'Error') {
                $stack = $v->hasOwn('stack') ? $v->get('stack', $vm) : null;
                return is_string($stack) ? $stack : Conversions::toString($vm, $v);
            }
            $parts = [];
            foreach ($v->ownEnumerableKeys() as $key) {
                $parts[] = "$key: " . self::stringify($vm, $v->get($key, $vm), false, $depth + 1);
                if (count($parts) >= 50) {
                    $parts[] = '...';
                    break;
                }
            }
            return $parts === [] ? '{}' : '{ ' . implode(', ', $parts) . ' }';
        }
        return '<?>';
    }
}

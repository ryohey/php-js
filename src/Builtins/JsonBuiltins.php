<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Vm\Vm;

final class JsonBuiltins
{
    public static function entries(): array
    {
        return [
            'JSON.parse' => [self::class, 'parse'],
            'JSON.stringify' => [self::class, 'stringify'],
        ];
    }

    public static function makeObject(Realm $r): JSObject
    {
        $json = new JSObject($r->objectPrototype());
        $json->className = 'JSON';
        $json->nativeId = 'JSON';
        $r->defineMethod($json, 'parse', 'JSON.parse', 2);
        $r->defineMethod($json, 'stringify', 'JSON.stringify', 3);
        return $json;
    }

    public static function parse(Vm $vm, mixed $t, array $args): mixed
    {
        $text = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        // stdClass (not assoc) keeps {"0": ...} distinguishable from arrays.
        $decoded = json_decode($text, false, 512);
        if ($decoded === null && strtolower(trim($text)) !== 'null') {
            $vm->throwError('SyntaxError', 'Unexpected token in JSON: ' . json_last_error_msg());
        }
        return self::import($vm, $decoded);
    }

    private static function import(Vm $vm, mixed $v): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $item) {
                $out[] = self::import($vm, $item);
            }
            return $vm->realm->newArray($out);
        }
        if ($v instanceof \stdClass) {
            $obj = $vm->realm->newObject();
            foreach (get_object_vars($v) as $k => $item) {
                $obj->defineOwnData((string)$k, self::import($vm, $item));
            }
            return $obj;
        }
        // int, float, string, bool, null map directly.
        return $v;
    }

    public static function stringify(Vm $vm, mixed $t, array $args): mixed
    {
        $value = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $replacer = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $spaceArg = (\array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined);

        $allow = null;
        $replacerFn = null;
        if ($replacer instanceof JSFunctionBase) {
            $replacerFn = $replacer;
        } elseif ($replacer instanceof JSArray) {
            $allow = [];
            foreach ($replacer->toList() as $k) {
                if (is_string($k)) {
                    $allow[$k] = true;
                } elseif (is_int($k) || is_float($k)) {
                    $allow[Conversions::numberToString($k)] = true;
                }
            }
        }

        $indent = '';
        if ($spaceArg instanceof JSPrimitiveWrapper) {
            $spaceArg = $spaceArg->primitiveValue;
        }
        if (is_int($spaceArg) || is_float($spaceArg)) {
            $indent = str_repeat(' ', max(0, min(10, (int)$spaceArg)));
        } elseif (is_string($spaceArg)) {
            $indent = substr($spaceArg, 0, 10);
        }

        $seen = new \SplObjectStorage();
        $result = self::serialize($vm, $value, $replacerFn, $allow, $indent, '', $seen);
        return $result ?? JSUndefined::$undefined;
    }

    private static function serialize(
        Vm $vm,
        mixed $v,
        ?JSFunctionBase $replacerFn,
        ?array $allow,
        string $indent,
        string $curIndent,
        \SplObjectStorage $seen,
    ): ?string {
        if ($v instanceof JSPrimitiveWrapper) {
            $v = $v->primitiveValue;
        }
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            return (is_nan($v) || is_infinite($v)) ? 'null' : Conversions::numberToString($v);
        }
        if (is_string($v)) {
            return self::quote($v);
        }
        if ($v instanceof JSFunctionBase || $v instanceof JSUndefined) {
            return null;
        }
        if ($v instanceof JSObject) {
            if ($seen->contains($v)) {
                $vm->throwError('TypeError', 'Converting circular structure to JSON');
            }
            $seen->attach($v);
            try {
                $newIndent = $curIndent . $indent;
                $sep = $indent === '' ? ',' : ",\n" . $newIndent;
                $open = $indent === '' ? '' : "\n" . $newIndent;
                $close = $indent === '' ? '' : "\n" . $curIndent;
                if ($v instanceof JSArray) {
                    $parts = [];
                    for ($i = 0; $i < $v->length; $i++) {
                        $item = $v->elements[$i] ?? JSUndefined::$undefined;
                        if ($replacerFn !== null) {
                            $item = $vm->invoke($replacerFn, $v, [(string)$i, $item]);
                        }
                        $parts[] = self::serialize($vm, $item, $replacerFn, $allow, $indent, $newIndent, $seen) ?? 'null';
                    }
                    if ($parts === []) {
                        return '[]';
                    }
                    return '[' . $open . implode($sep, $parts) . $close . ']';
                }
                $toJson = $v->get('toJSON', $vm);
                if ($toJson instanceof JSFunctionBase) {
                    $seen->detach($v);
                    return self::serialize($vm, $vm->invoke($toJson, $v, []), $replacerFn, $allow, $indent, $curIndent, $seen);
                }
                $parts = [];
                foreach ($v->ownEnumerableKeys() as $key) {
                    if ($allow !== null && !isset($allow[$key])) {
                        continue;
                    }
                    $item = $v->get($key, $vm);
                    if ($replacerFn !== null) {
                        $item = $vm->invoke($replacerFn, $v, [$key, $item]);
                    }
                    $s = self::serialize($vm, $item, $replacerFn, $allow, $indent, $newIndent, $seen);
                    if ($s !== null) {
                        $parts[] = self::quote($key) . ($indent === '' ? ':' : ': ') . $s;
                    }
                }
                if ($parts === []) {
                    return '{}';
                }
                return '{' . $open . implode($sep, $parts) . $close . '}';
            } finally {
                $seen->detach($v);
            }
        }
        return null;
    }

    private static function quote(string $s): string
    {
        $out = '"';
        $units = StringOps::isAscii($s) ? null : StringOps::toCodeUnits($s);
        if ($units === null) {
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $out .= self::quoteChar(ord($s[$i]), $s[$i]);
            }
        } else {
            foreach ($units as $u) {
                if ($u < 0x80) {
                    $out .= self::quoteChar($u, chr($u));
                } elseif ($u >= 0xD800 && $u <= 0xDFFF) {
                    $out .= sprintf('\\u%04x', $u);
                } else {
                    $out .= StringOps::fromCodeUnits([$u]);
                }
            }
        }
        return $out . '"';
    }

    private static function quoteChar(int $code, string $c): string
    {
        return match (true) {
            $c === '"' => '\\"',
            $c === '\\' => '\\\\',
            $c === "\n" => '\\n',
            $c === "\r" => '\\r',
            $c === "\t" => '\\t',
            $c === "\x08" => '\\b',
            $c === "\x0C" => '\\f',
            $code < 0x20 => sprintf('\\u%04x', $code),
            default => $c,
        };
    }
}

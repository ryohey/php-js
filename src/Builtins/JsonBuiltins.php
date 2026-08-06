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

/** JSON object (15.12), following the spec's Walk / Str / JO / JA structure. */
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

    // ---- JSON.parse (15.12.2) ----------------------------------------------

    public static function parse(Vm $vm, mixed $t, array $args): mixed
    {
        $text = Conversions::toString($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        try {
            $unfiltered = JsonParser::parse($text, $vm->realm);
        } catch (JsonSyntaxError $e) {
            $vm->throwError('SyntaxError', $e->getMessage());
        }
        $reviver = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        if (!$reviver instanceof JSFunctionBase) {
            return $unfiltered;
        }
        $root = $vm->realm->newObject();
        $root->defineOwnData('', $unfiltered);
        return self::walk($vm, $root, '', $reviver);
    }

    private static function walk(Vm $vm, JSObject $holder, string $name, JSFunctionBase $reviver): mixed
    {
        $val = $holder->get($name, $vm);
        if ($val instanceof JSArray) {
            for ($i = 0; $i < $val->length; $i++) {
                self::reviveInto($vm, $val, (string)$i, $reviver);
            }
        } elseif ($val instanceof JSObject) {
            foreach ($val->ownEnumerableKeys() as $key) {
                self::reviveInto($vm, $val, $key, $reviver);
            }
        }
        return $vm->invoke($reviver, $holder, [$name, $val]);
    }

    private static function reviveInto(Vm $vm, JSObject $val, string $key, JSFunctionBase $reviver): void
    {
        $newElement = self::walk($vm, $val, $key, $reviver);
        if ($newElement instanceof JSUndefined) {
            $val->deleteKey($key, $vm, false);
        } else {
            $val->defineOwnProperty($key, [
                'value' => $newElement,
                'writable' => true,
                'enumerable' => true,
                'configurable' => true,
            ], $vm, false);
        }
    }

    // ---- JSON.stringify (15.12.3) ------------------------------------------

    public static function stringify(Vm $vm, mixed $t, array $args): mixed
    {
        $value = \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined;
        $replacer = \array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined;
        $space = \array_key_exists(2, $args) ? $args[2] : JSUndefined::$undefined;

        $state = new JsonStringifyState();
        if ($replacer instanceof JSFunctionBase) {
            $state->replacerFn = $replacer;
        } elseif ($replacer instanceof JSArray) {
            $state->propertyList = self::buildPropertyList($vm, $replacer);
        }
        $state->gap = self::buildGap($vm, $space);

        $wrapper = $vm->realm->newObject();
        $wrapper->defineOwnData('', $value);
        $result = self::str($vm, '', $wrapper, $state);
        return $result ?? JSUndefined::$undefined;
    }

    /** @return list<string> the replacer array's key list, deduplicated, in order */
    private static function buildPropertyList(Vm $vm, JSArray $replacer): array
    {
        $list = [];
        $seen = [];
        foreach ($replacer->toList() as $v) {
            $item = null;
            if (is_string($v)) {
                $item = $v;
            } elseif (is_int($v) || is_float($v)) {
                $item = Conversions::numberToString($v);
            } elseif ($v instanceof JSPrimitiveWrapper && ($v->className === 'String' || $v->className === 'Number')) {
                $item = Conversions::toString($vm, $v);
            }
            if ($item !== null && !isset($seen[$item])) {
                $seen[$item] = true;
                $list[] = $item;
            }
        }
        return $list;
    }

    private static function buildGap(Vm $vm, mixed $space): string
    {
        if ($space instanceof JSPrimitiveWrapper) {
            $space = match ($space->className) {
                'Number' => Conversions::toNumber($vm, $space),
                'String' => Conversions::toString($vm, $space),
                default => $space,
            };
        }
        if (is_int($space) || is_float($space)) {
            $n = (int)max(0, min(10, Conversions::toInteger($vm, $space)));
            return str_repeat(' ', $n);
        }
        if (is_string($space)) {
            return substr($space, 0, 10);
        }
        return '';
    }

    /** SerializeJSONProperty: returns null for values JSON omits. */
    private static function str(Vm $vm, string $key, JSObject $holder, JsonStringifyState $state): ?string
    {
        $value = $holder->get($key, $vm);
        if ($value instanceof JSObject) {
            $toJson = $value->get('toJSON', $vm);
            if ($toJson instanceof JSFunctionBase) {
                $value = $vm->invoke($toJson, $value, [$key]);
            }
        }
        if ($state->replacerFn !== null) {
            $value = $vm->invoke($state->replacerFn, $holder, [$key, $value]);
        }
        if ($value instanceof JSPrimitiveWrapper) {
            $value = match ($value->className) {
                'Number' => Conversions::toNumber($vm, $value),
                'String' => Conversions::toString($vm, $value),
                'Boolean' => $value->primitiveValue,
                default => $value,
            };
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return self::quote($value);
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            return (is_nan($value) || is_infinite($value)) ? 'null' : Conversions::numberToString($value);
        }
        if ($value instanceof JSObject && !$value instanceof JSFunctionBase) {
            return $value instanceof JSArray
                ? self::serializeArray($vm, $value, $state)
                : self::serializeObject($vm, $value, $state);
        }
        return null; // undefined and callables are omitted
    }

    private static function enterCycleCheck(Vm $vm, JSObject $value, JsonStringifyState $state): void
    {
        if ($state->stack->offsetExists($value)) {
            $vm->throwError('TypeError', 'Converting circular structure to JSON');
        }
        $state->stack->offsetSet($value, null);
    }

    private static function serializeObject(Vm $vm, JSObject $value, JsonStringifyState $state): string
    {
        self::enterCycleCheck($vm, $value, $state);
        $stepback = $state->indent;
        $state->indent .= $state->gap;
        try {
            $keys = $state->propertyList ?? $value->ownEnumerableKeys();
            $parts = [];
            foreach ($keys as $key) {
                $strP = self::str($vm, $key, $value, $state);
                if ($strP !== null) {
                    $parts[] = self::quote($key) . ($state->gap === '' ? ':' : ': ') . $strP;
                }
            }
            return self::wrap($parts, '{', '}', $state, $stepback);
        } finally {
            $state->stack->offsetUnset($value);
            $state->indent = $stepback;
        }
    }

    private static function serializeArray(Vm $vm, JSArray $value, JsonStringifyState $state): string
    {
        self::enterCycleCheck($vm, $value, $state);
        $stepback = $state->indent;
        $state->indent .= $state->gap;
        try {
            $parts = [];
            for ($i = 0; $i < $value->length; $i++) {
                $parts[] = self::str($vm, (string)$i, $value, $state) ?? 'null';
            }
            return self::wrap($parts, '[', ']', $state, $stepback);
        } finally {
            $state->stack->offsetUnset($value);
            $state->indent = $stepback;
        }
    }

    /** @param list<string> $parts */
    private static function wrap(array $parts, string $open, string $close, JsonStringifyState $state, string $stepback): string
    {
        if ($parts === []) {
            return $open . $close;
        }
        if ($state->gap === '') {
            return $open . implode(',', $parts) . $close;
        }
        $sep = ",\n" . $state->indent;
        return $open . "\n" . $state->indent . implode($sep, $parts) . "\n" . $stepback . $close;
    }

    /** QuoteJSONString (15.12.3): escapes and \uXXXX for controls and lone surrogates. */
    private static function quote(string $s): string
    {
        if (StringOps::isAscii($s)) {
            $out = '"';
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $out .= self::quoteChar(ord($s[$i]), $s[$i]);
            }
            return $out . '"';
        }
        $out = '"';
        $units = StringOps::toCodeUnits($s);
        $n = count($units);
        for ($i = 0; $i < $n; $i++) {
            $u = $units[$i];
            if ($u < 0x80) {
                $out .= self::quoteChar($u, chr($u));
                continue;
            }
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $n && $units[$i + 1] >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
                $out .= StringOps::fromCodeUnits([$u, $units[$i + 1]]);
                $i++;
                continue;
            }
            if ($u >= 0xD800 && $u <= 0xDFFF) {
                $out .= sprintf('\\u%04x', $u);
                continue;
            }
            $out .= StringOps::fromCodeUnits([$u]);
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

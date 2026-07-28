<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\RegExp\JSRegExp;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Vm\Vm;

final class StringBuiltins
{
    public static function entries(): array
    {
        return [
            'String' => [self::class, 'callAsFunction'],
            'String.ctor' => [self::class, 'ctor'],
            'String.fromCharCode' => [self::class, 'fromCharCode'],
            'String.prototype.toString' => [self::class, 'toStringMethod'],
            'String.prototype.valueOf' => [self::class, 'toStringMethod'],
            'String.prototype.charAt' => [self::class, 'charAt'],
            'String.prototype.charCodeAt' => [self::class, 'charCodeAt'],
            'String.prototype.indexOf' => [self::class, 'indexOf'],
            'String.prototype.lastIndexOf' => [self::class, 'lastIndexOf'],
            'String.prototype.slice' => [self::class, 'slice'],
            'String.prototype.substring' => [self::class, 'substring'],
            'String.prototype.substr' => [self::class, 'substr'],
            'String.prototype.toUpperCase' => [self::class, 'toUpperCase'],
            'String.prototype.toLowerCase' => [self::class, 'toLowerCase'],
            'String.prototype.toLocaleUpperCase' => [self::class, 'toUpperCase'],
            'String.prototype.toLocaleLowerCase' => [self::class, 'toLowerCase'],
            'String.prototype.split' => [self::class, 'split'],
            'String.prototype.replace' => [self::class, 'replace'],
            'String.prototype.concat' => [self::class, 'concat'],
            'String.prototype.trim' => [self::class, 'trim'],
            'String.prototype.localeCompare' => [self::class, 'localeCompare'],
            'String.prototype.match' => [self::class, 'match'],
            'String.prototype.search' => [self::class, 'search'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach ([
            'toString' => 0, 'valueOf' => 0, 'charAt' => 1, 'charCodeAt' => 1,
            'indexOf' => 1, 'lastIndexOf' => 1, 'slice' => 2, 'substring' => 2,
            'substr' => 2, 'toUpperCase' => 0, 'toLowerCase' => 0,
            'toLocaleUpperCase' => 0, 'toLocaleLowerCase' => 0,
            'split' => 2, 'replace' => 2, 'concat' => 1, 'trim' => 0,
            'localeCompare' => 1, 'match' => 1, 'search' => 1,
        ] as $name => $arity) {
            $r->defineMethod($proto, $name, "String.prototype.$name", $arity);
        }
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('String', 'String', 1, 'String.ctor');
        $r->linkPair($ctor, $r->stringPrototype());
        $r->defineMethod($ctor, 'fromCharCode', 'String.fromCharCode', 1);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return count($args) === 0 ? '' : Conversions::toString($vm, $args[0]);
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $s = count($args) === 0 ? '' : Conversions::toString($vm, $args[0]);
        return new JSPrimitiveWrapper($s, 'String', $vm->realm->stringPrototype());
    }

    public static function fromCharCode(Vm $vm, mixed $t, array $args): mixed
    {
        $units = [];
        foreach ($args as $a) {
            $units[] = Conversions::toUint32($vm, $a) & 0xFFFF;
        }
        return StringOps::fromCodeUnits($units);
    }

    private static function thisString(Vm $vm, mixed $t, string $who): string
    {
        if (is_string($t)) {
            return $t;
        }
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'String') {
            return $t->primitiveValue;
        }
        if ($t === null || $t instanceof JSUndefined) {
            $vm->throwError('TypeError', "String.prototype.$who called on null or undefined");
        }
        return Conversions::toString($vm, $t);
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        if (is_string($t)) {
            return $t;
        }
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'String') {
            return $t->primitiveValue;
        }
        $vm->throwError('TypeError', 'String.prototype.toString called on incompatible receiver');
    }

    public static function charAt(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'charAt');
        $i = (int)Conversions::toInteger($vm, $args[0] ?? 0);
        return StringOps::charAt($s, $i) ?? '';
    }

    public static function charCodeAt(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'charCodeAt');
        $i = (int)Conversions::toInteger($vm, $args[0] ?? 0);
        return StringOps::charCodeAt($s, $i) ?? NAN;
    }

    public static function indexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'indexOf');
        $needle = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $from = count($args) > 1 ? max(0, (int)Conversions::toInteger($vm, $args[1])) : 0;
        $byteFrom = StringOps::cuToByte($s, $from);
        if ($needle === '') {
            return min($from, StringOps::length16($s));
        }
        $pos = strpos($s, $needle, min($byteFrom, strlen($s)));
        return $pos === false ? -1 : StringOps::byteToCu($s, $pos);
    }

    public static function lastIndexOf(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'lastIndexOf');
        $needle = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $len16 = StringOps::length16($s);
        $from = $len16;
        if (count($args) > 1) {
            $n = Conversions::toNumber($vm, $args[1]);
            if (!(is_float($n) && is_nan($n))) {
                $from = max(0, min((int)Conversions::toInteger($vm, $args[1]), $len16));
            }
        }
        if ($needle === '') {
            return $from;
        }
        $byteLimit = StringOps::cuToByte($s, $from) + strlen($needle) - 1;
        $pos = strrpos(substr($s, 0, min($byteLimit + 1, strlen($s)) ), $needle);
        return $pos === false ? -1 : StringOps::byteToCu($s, $pos);
    }

    public static function slice(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'slice');
        $len = StringOps::length16($s);
        $start = self::relIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $end = self::relIndex($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined), $len, $len);
        return StringOps::slice16($s, $start, $end);
    }

    private static function relIndex(Vm $vm, mixed $arg, int $len, int $default): int
    {
        if ($arg instanceof JSUndefined) {
            return $default;
        }
        $i = Conversions::toInteger($vm, $arg);
        if (is_float($i)) {
            $i = $i < 0 ? -PHP_INT_MAX : PHP_INT_MAX;
        }
        return $i < 0 ? max($len + $i, 0) : min($i, $len);
    }

    public static function substring(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'substring');
        $len = StringOps::length16($s);
        $a = self::clampIndex($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined), $len, 0);
        $b = self::clampIndex($vm, (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined), $len, $len);
        return StringOps::slice16($s, min($a, $b), max($a, $b));
    }

    private static function clampIndex(Vm $vm, mixed $arg, int $len, int $default): int
    {
        if ($arg instanceof JSUndefined) {
            return $default;
        }
        $i = Conversions::toInteger($vm, $arg);
        if (is_float($i)) {
            $i = $i < 0 ? 0 : PHP_INT_MAX;
        }
        return max(0, min((int)$i, $len));
    }

    public static function substr(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'substr');
        $len = StringOps::length16($s);
        $start = (int)Conversions::toInteger($vm, $args[0] ?? 0);
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        $count = ((\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined)) instanceof JSUndefined
            ? $len - $start
            : (int)Conversions::toInteger($vm, $args[1]);
        return StringOps::slice16($s, $start, $start + max(0, $count));
    }

    public static function toUpperCase(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'toUpperCase');
        if (StringOps::isAscii($s)) {
            return strtoupper($s);
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }

    public static function toLowerCase(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'toLowerCase');
        if (StringOps::isAscii($s)) {
            return strtolower($s);
        }
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    public static function split(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'split');
        $sep = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $limitArg = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        $limit = $limitArg instanceof JSUndefined ? PHP_INT_MAX : Conversions::toUint32($vm, $limitArg);
        if ($sep instanceof JSUndefined) {
            return $vm->realm->newArray($limit > 0 ? [$s] : []);
        }
        if ($sep instanceof JSObject && ($re = JSRegExp::from($sep)) !== null) {
            return RegExpBuiltins::splitWithRegExp($vm, $s, $re, $limit);
        }
        $sepStr = Conversions::toString($vm, $sep);
        if ($sepStr === '') {
            // Split into single UTF-16 code units.
            $units = StringOps::toCodeUnits($s);
            $out = [];
            foreach ($units as $u) {
                if (count($out) >= $limit) {
                    break;
                }
                $out[] = StringOps::fromCodeUnits([$u]);
            }
            return $vm->realm->newArray($out);
        }
        $parts = explode($sepStr, $s);
        if (count($parts) > $limit) {
            $parts = array_slice($parts, 0, $limit);
        }
        return $vm->realm->newArray($parts);
    }

    public static function replace(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'replace');
        $search = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $replacement = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        if ($search instanceof JSObject && ($re = JSRegExp::from($search)) !== null) {
            return RegExpBuiltins::replaceWithRegExp($vm, $s, $re, $replacement);
        }
        $searchStr = Conversions::toString($vm, $search);
        $pos = strpos($s, $searchStr);
        if ($pos === false) {
            return $s;
        }
        if ($replacement instanceof JSFunctionBase) {
            $result = $vm->invoke($replacement, JSUndefined::$undefined, [
                $searchStr, StringOps::byteToCu($s, $pos), $s,
            ]);
            $repl = Conversions::toString($vm, $result);
        } else {
            $repl = self::expandDollars(Conversions::toString($vm, $replacement), $s, $pos, $searchStr, []);
        }
        return substr($s, 0, $pos) . $repl . substr($s, $pos + strlen($searchStr));
    }

    /** GetSubstitution (15.5.4.11): $$, $&, $`, $', $n. */
    public static function expandDollars(string $tpl, string $subject, int $bytePos, string $matched, array $groups): string
    {
        $out = '';
        $len = strlen($tpl);
        for ($i = 0; $i < $len; $i++) {
            $c = $tpl[$i];
            if ($c !== '$' || $i + 1 >= $len) {
                $out .= $c;
                continue;
            }
            $n = $tpl[$i + 1];
            if ($n === '$') {
                $out .= '$';
                $i++;
            } elseif ($n === '&') {
                $out .= $matched;
                $i++;
            } elseif ($n === '`') {
                $out .= substr($subject, 0, $bytePos);
                $i++;
            } elseif ($n === "'") {
                $out .= substr($subject, $bytePos + strlen($matched));
                $i++;
            } elseif (ctype_digit($n)) {
                $num = $n;
                if ($i + 2 < $len && ctype_digit($tpl[$i + 2]) && isset($groups[(int)($n . $tpl[$i + 2]) - 1])) {
                    $num = $n . $tpl[$i + 2];
                }
                $idx = (int)$num - 1;
                if ($idx >= 0 && array_key_exists($idx, $groups)) {
                    $out .= $groups[$idx] ?? '';
                    $i += strlen($num);
                } else {
                    $out .= $c;
                }
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    public static function concat(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'concat');
        foreach ($args as $a) {
            $s .= Conversions::toString($vm, $a);
        }
        return $s;
    }

    public static function trim(Vm $vm, mixed $t, array $args): mixed
    {
        return Conversions::trimJs(self::thisString($vm, $t, 'trim'));
    }

    public static function localeCompare(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'localeCompare');
        $other = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return strcmp($s, $other) <=> 0;
    }

    public static function match(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'match');
        return RegExpBuiltins::stringMatch($vm, $s, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
    }

    public static function search(Vm $vm, mixed $t, array $args): mixed
    {
        $s = self::thisString($vm, $t, 'search');
        return RegExpBuiltins::stringSearch($vm, $s, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
    }
}

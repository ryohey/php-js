<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\RegExp\JSRegExp;
use PhpJs\RegExp\RegExpSyntaxError;
use PhpJs\RegExp\RegExpTranslator;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;
use PhpJs\Vm\Vm;

final class RegExpBuiltins
{
    public static function entries(): array
    {
        return [
            'RegExp' => [self::class, 'callAsFunction'],
            'RegExp.ctor' => [self::class, 'ctor'],
            'RegExp.prototype.test' => [self::class, 'test'],
            'RegExp.prototype.exec' => [self::class, 'exec'],
            'RegExp.prototype.toString' => [self::class, 'toStringMethod'],
            'RegExp.prototype.flagGetter' => [self::class, 'flagGetter'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        $r->defineMethod($proto, 'test', 'RegExp.prototype.test', 1);
        $r->defineMethod($proto, 'exec', 'RegExp.prototype.exec', 1);
        $r->defineMethod($proto, 'toString', 'RegExp.prototype.toString', 0);
        // ES2015 moved source/global/ignoreCase/multiline off the instance and
        // onto the prototype as accessors; instances no longer own them.
        foreach (self::FLAG_ACCESSORS as $name) {
            $getter = $r->nativeFn('RegExp.prototype.flagGetter', 'get ' . $name, 0);
            $proto->defineOwnAccessor($name, $getter, null, JSObject::C);
        }
    }

    private const FLAG_ACCESSORS = ['source', 'global', 'ignoreCase', 'multiline'];

    /** The shared getter behind RegExp.prototype.{source,global,ignoreCase,multiline}. */
    public static function flagGetter(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $name = substr($fn?->name ?? 'get source', 4);
        if (!$t instanceof JSRegExp) {
            // RegExp.prototype itself answers with the spec's placeholders.
            if ($t instanceof JSObject && $t === $vm->realm->recall('RegExp.prototype')) {
                return $name === 'source' ? '(?:)' : JSUndefined::$undefined;
            }
            $vm->throwError('TypeError', "RegExp.prototype.$name getter called on incompatible receiver");
        }
        return match ($name) {
            'source' => $t->jsSource,
            'global' => $t->global,
            'ignoreCase' => str_contains($t->jsFlags, 'i'),
            'multiline' => str_contains($t->jsFlags, 'm'),
            default => JSUndefined::$undefined,
        };
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('RegExp', 'RegExp', 2, 'RegExp.ctor');
        $r->linkPair($ctor, $r->regexpPrototype());
        return $ctor;
    }

    public static function create(Realm $realm, string $pattern, string $flags, ?string $pcre = null): JSRegExp
    {
        try {
            $pcre ??= RegExpTranslator::translate($pattern, $flags);
        } catch (RegExpSyntaxError $e) {
            if ($realm->vm !== null) {
                $realm->vm->throwError('SyntaxError', $e->getMessage());
            }
            throw $e;
        }
        $re = new JSRegExp($realm->regexpPrototype());
        $re->jsSource = $pattern === '' ? '(?:)' : $pattern;
        $re->jsFlags = $flags;
        $re->pcre = $pcre;
        $re->global = str_contains($flags, 'g');
        $re->sticky = str_contains($flags, 'y');
        $re->defineOwnData('lastIndex', 0, JSObject::W);
        return $re;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        $pattern = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $flags = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        if ($pattern instanceof JSRegExp && $flags instanceof JSUndefined) {
            return $pattern;
        }
        return self::ctor($vm, $args);
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $pattern = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        $flags = (\array_key_exists(1, $args) ? $args[1] : JSUndefined::$undefined);
        if ($pattern instanceof JSRegExp) {
            if (!$flags instanceof JSUndefined) {
                $vm->throwError('TypeError', 'Cannot supply flags when constructing one RegExp from another');
            }
            return self::create($vm->realm, $pattern->jsSource === '(?:)' ? '' : $pattern->jsSource, $pattern->jsFlags);
        }
        $patternStr = $pattern instanceof JSUndefined ? '' : Conversions::toString($vm, $pattern);
        $flagsStr = $flags instanceof JSUndefined ? '' : Conversions::toString($vm, $flags);
        return self::create($vm->realm, $patternStr, $flagsStr);
    }

    private static function thisRegExp(Vm $vm, mixed $t, string $who): JSRegExp
    {
        if (!$t instanceof JSRegExp) {
            $vm->throwError('TypeError', "RegExp.prototype.$who called on incompatible receiver");
        }
        return $t;
    }

    public static function test(Vm $vm, mixed $t, array $args): mixed
    {
        return self::exec($vm, $t, $args) !== null;
    }

    public static function exec(Vm $vm, mixed $t, array $args): mixed
    {
        $re = self::thisRegExp($vm, $t, 'exec');
        $s = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $m = self::execInternal($vm, $re, $s);
        return $m ?? null;
    }

    /**
     * Run the regexp once, honoring lastIndex for g/y. Returns the JS result
     * array or null. Offsets convert bytes <-> UTF-16 code units (§8).
     */
    public static function execInternal(Vm $vm, JSRegExp $re, string $s): ?JSArray
    {
        $useLastIndex = $re->global || $re->sticky;
        $lastIndex = 0;
        if ($useLastIndex) {
            $lastIndex = (int)Conversions::toInteger($vm, $re->get('lastIndex', $vm));
            if ($lastIndex < 0 || $lastIndex > StringOps::length16($s)) {
                $re->set('lastIndex', 0, $vm, false);
                return null;
            }
        }
        $byteOffset = StringOps::cuToByte($s, $lastIndex);
        $r = @preg_match($re->pcre, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset);
        if ($r !== 1) {
            if ($useLastIndex) {
                $re->set('lastIndex', 0, $vm, false);
            }
            return null;
        }
        $matchBytePos = $m[0][1];
        $matched = $m[0][0];
        if ($useLastIndex) {
            $endCu = StringOps::byteToCu($s, $matchBytePos + strlen($matched));
            // Zero-width match: advance to avoid infinite loops in callers.
            $re->set('lastIndex', $endCu, $vm, false);
        }
        $result = new JSArray($vm->realm->arrayPrototype());
        $n = 0;
        foreach ($m as $k => $g) {
            if (!is_int($k)) {
                continue;
            }
            $result->elements[$n++] = $g[0] === null ? JSUndefined::$undefined : $g[0];
        }
        $result->length = $n;
        $result->defineOwnData('index', StringOps::byteToCu($s, $matchBytePos));
        $result->defineOwnData('input', $s);
        return $result;
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        $re = self::thisRegExp($vm, $t, 'toString');
        return '/' . $re->jsSource . '/' . $re->jsFlags;
    }

    // ---- String.prototype integration --------------------------------------

    private static function coerceRegExp(Vm $vm, mixed $v): JSRegExp
    {
        if ($v instanceof JSRegExp) {
            return $v;
        }
        $pattern = ($v instanceof JSUndefined) ? '' : Conversions::toString($vm, $v);
        return self::create($vm->realm, $pattern, '');
    }

    public static function stringMatch(Vm $vm, string $s, mixed $arg): mixed
    {
        $re = self::coerceRegExp($vm, $arg);
        if (!$re->global) {
            return self::execInternal($vm, $re, $s) ?? null;
        }
        $re->set('lastIndex', 0, $vm, false);
        $out = [];
        for (;;) {
            $m = self::execInternal($vm, $re, $s);
            if ($m === null) {
                break;
            }
            $v = $m->elements[0] ?? '';
            $out[] = $v;
            if ($v === '') {
                $li = (int)Conversions::toInteger($vm, $re->get('lastIndex', $vm));
                $re->set('lastIndex', $li + 1, $vm, false);
            }
        }
        return $out === [] ? null : $vm->realm->newArray($out);
    }

    public static function stringSearch(Vm $vm, string $s, mixed $arg): int
    {
        $re = self::coerceRegExp($vm, $arg);
        $r = @preg_match($re->pcre, $s, $m, PREG_OFFSET_CAPTURE);
        if ($r !== 1) {
            return -1;
        }
        return StringOps::byteToCu($s, $m[0][1]);
    }

    public static function replaceWithRegExp(Vm $vm, string $s, JSRegExp $re, mixed $replacement): string
    {
        $isFn = $replacement instanceof JSFunctionBase;
        $tpl = $isFn ? '' : Conversions::toString($vm, $replacement);
        $out = '';
        $cursor = 0;
        $offset = 0;
        $flags = PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL;
        for (;;) {
            $r = @preg_match($re->pcre, $s, $m, $flags, $offset);
            if ($r !== 1) {
                break;
            }
            $pos = $m[0][1];
            $matched = $m[0][0];
            $groups = [];
            foreach ($m as $k => $g) {
                if (is_int($k) && $k > 0) {
                    $groups[] = $g[0];
                }
            }
            $out .= substr($s, $cursor, $pos - $cursor);
            if ($isFn) {
                $callArgs = [$matched];
                foreach ($groups as $g) {
                    $callArgs[] = $g ?? JSUndefined::$undefined;
                }
                $callArgs[] = StringOps::byteToCu($s, $pos);
                $callArgs[] = $s;
                $out .= Conversions::toString($vm, $vm->invoke($replacement, JSUndefined::$undefined, $callArgs));
            } else {
                $out .= StringBuiltins::expandDollars($tpl, $s, $pos, $matched, $groups);
            }
            $cursor = $pos + strlen($matched);
            if (!$re->global) {
                break;
            }
            $offset = $cursor;
            if ($matched === '') {
                if ($offset >= strlen($s)) {
                    break;
                }
                // Advance one code point on zero-width match.
                $offset += strlen(StringOps::encodeCp(StringOps::codePoints(substr($s, $offset, 4))[0] ?? 0));
            }
        }
        if ($re->global || $re->sticky) {
            $re->set('lastIndex', 0, $vm, false);
        }
        return $out . substr($s, $cursor);
    }

    public static function splitWithRegExp(Vm $vm, string $s, JSRegExp $re, int $limit): JSArray
    {
        if ($s === '') {
            $r = @preg_match($re->pcre, $s, $m);
            return $vm->realm->newArray($r === 1 ? [] : ['']);
        }
        $parts = preg_split($re->pcre, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $vm->realm->newArray([$s]);
        }
        $out = [];
        foreach ($parts as $p) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $p;
        }
        return $vm->realm->newArray($out);
    }
}

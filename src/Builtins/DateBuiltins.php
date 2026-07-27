<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Minimal Date support (UTC only): construction from a timestamp/ISO string,
 * Date.now, getTime/valueOf, toISOString. Calendar arithmetic getters are
 * deliberately deferred (DESIGN.md milestone M5).
 */
final class DateBuiltins
{
    public static function entries(): array
    {
        return [
            'Date' => [self::class, 'callAsFunction'],
            'Date.ctor' => [self::class, 'ctor'],
            'Date.now' => [self::class, 'now'],
            'Date.parse' => [self::class, 'parse'],
            'Date.prototype.getTime' => [self::class, 'getTime'],
            'Date.prototype.valueOf' => [self::class, 'getTime'],
            'Date.prototype.toISOString' => [self::class, 'toISOString'],
            'Date.prototype.toString' => [self::class, 'toStringMethod'],
            'Date.prototype.toJSON' => [self::class, 'toISOString'],
        ];
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach ([
            'getTime' => 0, 'valueOf' => 0, 'toISOString' => 0,
            'toString' => 0, 'toJSON' => 1,
        ] as $name => $arity) {
            $r->defineMethod($proto, $name, "Date.prototype.$name", $arity);
        }
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Date', 'Date', 7, 'Date.ctor');
        $r->linkPair($ctor, $r->datePrototype());
        $r->defineMethod($ctor, 'now', 'Date.now', 0);
        $r->defineMethod($ctor, 'parse', 'Date.parse', 1);
        return $ctor;
    }

    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return gmdate('D M d Y H:i:s') . ' GMT+0000';
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $ms = match (true) {
            count($args) === 0 => (int)floor(microtime(true) * 1000),
            is_string($args[0]) => self::parseDate($args[0]),
            default => Conversions::toNumber($vm, $args[0]),
        };
        $d = new JSPrimitiveWrapper($ms, 'Date', $vm->realm->datePrototype());
        return $d;
    }

    public static function now(Vm $vm, mixed $t, array $args): mixed
    {
        return (int)floor(microtime(true) * 1000);
    }

    public static function parse(Vm $vm, mixed $t, array $args): mixed
    {
        return self::parseDate(Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined)));
    }

    private static function parseDate(string $s): int|float
    {
        $ts = strtotime($s);
        return $ts === false ? NAN : $ts * 1000;
    }

    private static function thisTime(Vm $vm, mixed $t): int|float
    {
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'Date') {
            return $t->primitiveValue;
        }
        $vm->throwError('TypeError', 'this is not a Date object');
    }

    public static function getTime(Vm $vm, mixed $t, array $args): mixed
    {
        return self::thisTime($vm, $t);
    }

    public static function toISOString(Vm $vm, mixed $t, array $args): mixed
    {
        $ms = self::thisTime($vm, $t);
        if (is_float($ms) && (is_nan($ms) || is_infinite($ms))) {
            $vm->throwError('RangeError', 'Invalid time value');
        }
        $sec = intdiv((int)$ms, 1000);
        $frac = (int)$ms - $sec * 1000;
        if ($frac < 0) {
            $frac += 1000;
            $sec -= 1;
        }
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $frac);
    }

    public static function toStringMethod(Vm $vm, mixed $t, array $args): mixed
    {
        $ms = self::thisTime($vm, $t);
        if (is_float($ms) && is_nan($ms)) {
            return 'Invalid Date';
        }
        return gmdate('D M d Y H:i:s', intdiv((int)$ms, 1000)) . ' GMT+0000';
    }
}

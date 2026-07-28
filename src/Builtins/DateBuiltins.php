<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\DateOps;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSNativeFunction;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPrimitiveWrapper;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Date (15.9). Time-value arithmetic lives in DateOps; this class is the
 * property surface. Local time is fixed to UTC (see DateOps), so each local
 * getter/setter shares its UTC counterpart's implementation.
 */
final class DateBuiltins
{
    /** getter name => [DateOps method, divisor for the "get" result] */
    private const GETTERS = [
        'getTime' => 'time',
        'valueOf' => 'time',
        'getFullYear' => 'yearFromTime',
        'getUTCFullYear' => 'yearFromTime',
        'getMonth' => 'monthFromTime',
        'getUTCMonth' => 'monthFromTime',
        'getDate' => 'dateFromTime',
        'getUTCDate' => 'dateFromTime',
        'getDay' => 'weekDay',
        'getUTCDay' => 'weekDay',
        'getHours' => 'hourFromTime',
        'getUTCHours' => 'hourFromTime',
        'getMinutes' => 'minFromTime',
        'getUTCMinutes' => 'minFromTime',
        'getSeconds' => 'secFromTime',
        'getUTCSeconds' => 'secFromTime',
        'getMilliseconds' => 'msFromTime',
        'getUTCMilliseconds' => 'msFromTime',
    ];

    /**
     * setter name => [first field index, number of arguments]
     * Fields are ordered [year, month, date, hour, min, sec, ms].
     */
    private const SETTERS = [
        'setFullYear' => [0, 3],
        'setUTCFullYear' => [0, 3],
        'setMonth' => [1, 2],
        'setUTCMonth' => [1, 2],
        'setDate' => [2, 1],
        'setUTCDate' => [2, 1],
        'setHours' => [3, 4],
        'setUTCHours' => [3, 4],
        'setMinutes' => [4, 3],
        'setUTCMinutes' => [4, 3],
        'setSeconds' => [5, 2],
        'setUTCSeconds' => [5, 2],
        'setMilliseconds' => [6, 1],
        'setUTCMilliseconds' => [6, 1],
    ];

    private const STRINGIFIERS = [
        'toString' => 1, 'toUTCString' => 1, 'toISOString' => 1, 'toJSON' => 1,
        'toDateString' => 0, 'toTimeString' => 0,
        'toLocaleString' => 0, 'toLocaleDateString' => 0, 'toLocaleTimeString' => 0,
    ];

    public static function entries(): array
    {
        $e = [
            'Date' => [self::class, 'callAsFunction'],
            'Date.ctor' => [self::class, 'ctor'],
            'Date.now' => [self::class, 'now'],
            'Date.parse' => [self::class, 'parseFn'],
            'Date.UTC' => [self::class, 'utc'],
            'Date.prototype.setTime' => [self::class, 'setTime'],
            'Date.prototype.getTimezoneOffset' => [self::class, 'getTimezoneOffset'],
            'Date.prototype.getUTCTimezoneOffset' => [self::class, 'getTimezoneOffset'],
        ];
        foreach (array_keys(self::GETTERS) as $name) {
            $e["Date.prototype.$name"] = [self::class, 'getField'];
        }
        foreach (array_keys(self::SETTERS) as $name) {
            $e["Date.prototype.$name"] = [self::class, 'setField'];
        }
        foreach (array_keys(self::STRINGIFIERS) as $name) {
            $e["Date.prototype.$name"] = [self::class, 'stringify'];
        }
        // getYear/setYear are Annex B but universally present.
        $e['Date.prototype.getYear'] = [self::class, 'getYear'];
        $e['Date.prototype.setYear'] = [self::class, 'setYear'];
        return $e;
    }

    public static function populateProto(Realm $r, JSObject $proto): void
    {
        foreach (self::GETTERS as $name => $_) {
            $r->defineMethod($proto, $name, "Date.prototype.$name", 0);
        }
        foreach (self::SETTERS as $name => [$_, $argc]) {
            $r->defineMethod($proto, $name, "Date.prototype.$name", $argc);
        }
        foreach (self::STRINGIFIERS as $name => $argc) {
            $r->defineMethod($proto, $name, "Date.prototype.$name", $argc);
        }
        $r->defineMethod($proto, 'setTime', 'Date.prototype.setTime', 1);
        $r->defineMethod($proto, 'getTimezoneOffset', 'Date.prototype.getTimezoneOffset', 0);
        $r->defineMethod($proto, 'getYear', 'Date.prototype.getYear', 0);
        $r->defineMethod($proto, 'setYear', 'Date.prototype.setYear', 1);
    }

    public static function makeConstructor(Realm $r): JSNativeFunction
    {
        $ctor = $r->nativeFn('Date', 'Date', 7, 'Date.ctor');
        $r->linkPair($ctor, $r->datePrototype());
        $r->defineMethod($ctor, 'now', 'Date.now', 0);
        $r->defineMethod($ctor, 'parse', 'Date.parse', 1);
        $r->defineMethod($ctor, 'UTC', 'Date.UTC', 7);
        return $ctor;
    }

    // ---- construction ------------------------------------------------------

    /** Date() without new returns the current time as a string. */
    public static function callAsFunction(Vm $vm, mixed $t, array $args): mixed
    {
        return self::formatFull(self::currentTime());
    }

    public static function ctor(Vm $vm, array $args): mixed
    {
        $argc = count($args);
        if ($argc === 0) {
            return self::make($vm, self::currentTime());
        }
        if ($argc === 1) {
            $v = $args[0];
            if ($v instanceof JSPrimitiveWrapper && $v->className === 'Date') {
                return self::make($vm, (float)$v->primitiveValue);
            }
            $prim = Conversions::toPrimitive($vm, $v);
            if (is_string($prim)) {
                return self::make($vm, DateOps::parse($prim));
            }
            return self::make($vm, DateOps::timeClip((float)Conversions::toNumber($vm, $prim)));
        }
        return self::make($vm, self::fromComponents($vm, $args, true));
    }

    /** MakeDate from the constructor/UTC argument list. */
    private static function fromComponents(Vm $vm, array $args, bool $applyYearOffset): float
    {
        $get = static function (int $i, float $default) use ($vm, $args): float {
            if (!\array_key_exists($i, $args)) {
                return $default;
            }
            return (float)Conversions::toNumber($vm, $args[$i]);
        };
        $year = $get(0, NAN);
        $month = $get(1, 0.0);
        $date = $get(2, 1.0);
        $hour = $get(3, 0.0);
        $min = $get(4, 0.0);
        $sec = $get(5, 0.0);
        $ms = $get(6, 0.0);
        if ($applyYearOffset && !is_nan($year)) {
            $yi = $year < 0 ? -floor(-$year) : floor($year);
            if ($yi >= 0 && $yi <= 99) {
                $year = 1900 + $yi;
            }
        }
        return DateOps::timeClip(DateOps::makeDate(
            DateOps::makeDay($year, $month, $date),
            DateOps::makeTime($hour, $min, $sec, $ms)
        ));
    }

    private static function make(Vm $vm, float $time): JSPrimitiveWrapper
    {
        return new JSPrimitiveWrapper($time, 'Date', $vm->realm->datePrototype());
    }

    private static function currentTime(): float
    {
        return (float)(int)floor(microtime(true) * 1000);
    }

    public static function now(Vm $vm, mixed $t, array $args): mixed
    {
        return (int)self::currentTime();
    }

    public static function parseFn(Vm $vm, mixed $t, array $args): mixed
    {
        $s = Conversions::toString($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        return self::numberResult(DateOps::parse($s));
    }

    public static function utc(Vm $vm, mixed $t, array $args): mixed
    {
        if ($args === []) {
            return NAN;
        }
        return self::numberResult(self::fromComponents($vm, $args, true));
    }

    // ---- accessors ---------------------------------------------------------

    private static function thisDate(Vm $vm, mixed $t): JSPrimitiveWrapper
    {
        if ($t instanceof JSPrimitiveWrapper && $t->className === 'Date') {
            return $t;
        }
        $vm->throwError('TypeError', 'this is not a Date object');
    }

    /** Keep integral results as PHP ints so ToString(number) prints "5", not "5.0". */
    private static function numberResult(float $v): int|float
    {
        if (is_nan($v) || is_infinite($v)) {
            return $v;
        }
        return ($v == floor($v) && abs($v) <= Conversions::MAX_EXACT_INT) ? (int)$v : $v;
    }

    public static function getField(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $d = self::thisDate($vm, $t);
        $time = (float)$d->primitiveValue;
        if (is_nan($time)) {
            return NAN;
        }
        $op = self::GETTERS[$fn?->name ?? 'getTime'] ?? 'time';
        if ($op === 'time') {
            return self::numberResult($time);
        }
        return self::numberResult((float)DateOps::$op($time));
    }

    public static function getTimezoneOffset(Vm $vm, mixed $t, array $args): mixed
    {
        $d = self::thisDate($vm, $t);
        return is_nan((float)$d->primitiveValue) ? NAN : 0;
    }

    public static function setTime(Vm $vm, mixed $t, array $args): mixed
    {
        $d = self::thisDate($vm, $t);
        $v = DateOps::timeClip((float)Conversions::toNumber(
            $vm,
            \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined
        ));
        $d->primitiveValue = $v;
        return self::numberResult($v);
    }

    /**
     * All setXxx methods: replace a contiguous run of the seven fields
     * [year, month, date, hour, min, sec, ms] and rebuild the time value.
     */
    public static function setField(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $d = self::thisDate($vm, $t);
        [$first, $maxArgs] = self::SETTERS[$fn?->name ?? 'setMilliseconds'];
        $time = (float)$d->primitiveValue;
        // setFullYear on an invalid Date starts from the epoch; the others
        // stay NaN but must still consume (and coerce) their arguments.
        $base = is_nan($time) ? ($first === 0 ? 0.0 : NAN) : $time;

        $fields = is_nan($base)
            ? [NAN, NAN, NAN, NAN, NAN, NAN, NAN]
            : [
                (float)DateOps::yearFromTime($base),
                (float)DateOps::monthFromTime($base),
                (float)DateOps::dateFromTime($base),
                (float)DateOps::hourFromTime($base),
                (float)DateOps::minFromTime($base),
                (float)DateOps::secFromTime($base),
                (float)DateOps::msFromTime($base),
            ];
        for ($i = 0; $i < $maxArgs; $i++) {
            if (!\array_key_exists($i, $args)) {
                break;
            }
            $fields[$first + $i] = (float)Conversions::toNumber($vm, $args[$i]);
        }
        if (is_nan($base)) {
            $d->primitiveValue = NAN;
            return NAN;
        }
        $v = DateOps::timeClip(DateOps::makeDate(
            DateOps::makeDay($fields[0], $fields[1], $fields[2]),
            DateOps::makeTime($fields[3], $fields[4], $fields[5], $fields[6])
        ));
        $d->primitiveValue = $v;
        return self::numberResult($v);
    }

    public static function getYear(Vm $vm, mixed $t, array $args): mixed
    {
        $d = self::thisDate($vm, $t);
        $time = (float)$d->primitiveValue;
        return is_nan($time) ? NAN : self::numberResult(DateOps::yearFromTime($time) - 1900);
    }

    public static function setYear(Vm $vm, mixed $t, array $args): mixed
    {
        $d = self::thisDate($vm, $t);
        $time = (float)$d->primitiveValue;
        $base = is_nan($time) ? 0.0 : $time;
        $y = (float)Conversions::toNumber($vm, \array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (is_nan($y)) {
            $d->primitiveValue = NAN;
            return NAN;
        }
        $yi = $y < 0 ? -floor(-$y) : floor($y);
        $year = ($yi >= 0 && $yi <= 99) ? $yi + 1900 : $y;
        $v = DateOps::timeClip(DateOps::makeDate(
            DateOps::makeDay($year, (float)DateOps::monthFromTime($base), (float)DateOps::dateFromTime($base)),
            DateOps::timeWithinDay($base)
        ));
        $d->primitiveValue = $v;
        return self::numberResult($v);
    }

    // ---- stringification ---------------------------------------------------

    private static function formatFull(float $time): string
    {
        return DateOps::toDateString($time) . ' ' . DateOps::toTimeString($time);
    }

    public static function stringify(Vm $vm, mixed $t, array $args, ?JSNativeFunction $fn = null): mixed
    {
        $name = $fn?->name ?? 'toString';
        if ($name === 'toJSON') {
            // toJSON is generic: it works on any object with a numeric
            // valueOf, and returns null for a non-finite time (15.9.5.44).
            $o = Conversions::toObject($vm, $t);
            $prim = Conversions::toPrimitive($vm, $o, 'number');
            if ((is_int($prim) || is_float($prim)) && !is_finite((float)$prim)) {
                return null;
            }
            $toIso = $o->get('toISOString', $vm);
            if (!$toIso instanceof JSFunctionBase) {
                $vm->throwError('TypeError', 'toISOString is not callable');
            }
            return $vm->invoke($toIso, $o, []);
        }
        $d = self::thisDate($vm, $t);
        $time = (float)$d->primitiveValue;
        if ($name === 'toISOString') {
            if (!DateOps::isValid($time)) {
                $vm->throwError('RangeError', 'Invalid time value');
            }
            return DateOps::toIsoString($time);
        }
        if (is_nan($time)) {
            return 'Invalid Date';
        }
        return match ($name) {
            'toDateString' => DateOps::toDateString($time),
            'toTimeString' => DateOps::toTimeString($time),
            'toLocaleDateString' => DateOps::toDateString($time),
            'toLocaleTimeString' => DateOps::toTimeString($time),
            'toUTCString' => self::toUtcString($time),
            default => self::formatFull($time),
        };
    }

    private static function toUtcString(float $time): string
    {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return sprintf(
            '%s, %02d %s %04d %02d:%02d:%02d GMT',
            $days[(int)DateOps::weekDay($time)],
            DateOps::dateFromTime($time),
            $months[DateOps::monthFromTime($time)],
            DateOps::yearFromTime($time),
            DateOps::hourFromTime($time),
            DateOps::minFromTime($time),
            DateOps::secFromTime($time)
        );
    }
}

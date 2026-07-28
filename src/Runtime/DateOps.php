<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * The ES5.1 §15.9.1 time-value algorithms, in doubles.
 *
 * Deliberately not delegating to PHP's date functions: JS uses a proleptic
 * Gregorian calendar over a fixed ±8.64e15 ms range with no leap seconds, and
 * needs NaN propagation throughout. Implementing the spec's arithmetic
 * directly is both simpler and exact.
 *
 * Local time is fixed to UTC. Time zones and DST are host policy that a
 * shared-nothing request model has no good answer for; getTimezoneOffset()
 * reports 0 and the local-time getters mirror the UTC ones.
 */
final class DateOps
{
    public const MS_PER_SECOND = 1000;
    public const MS_PER_MINUTE = 60000;
    public const MS_PER_HOUR = 3600000;
    public const MS_PER_DAY = 86400000;
    /** 100,000,000 days either side of the epoch (15.9.1.1). */
    public const MAX_TIME = 8.64e15;

    private const MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    public static function isValid(float $t): bool
    {
        return !is_nan($t) && abs($t) <= self::MAX_TIME;
    }

    /** TimeClip (15.9.1.14). */
    public static function timeClip(float $t): float
    {
        if (is_nan($t) || is_infinite($t) || abs($t) > self::MAX_TIME) {
            return NAN;
        }
        // ToInteger, keeping -0 out of the result.
        $v = $t < 0 ? -floor(-$t) : floor($t);
        return $v == 0.0 ? 0.0 : $v;
    }

    /** Floor division that rounds toward negative infinity, as the spec's `floor` does. */
    private static function floorDiv(float $a, float $b): float
    {
        return floor($a / $b);
    }

    private static function modulo(float $a, float $b): float
    {
        $r = fmod($a, $b);
        return $r < 0 ? $r + $b : $r;
    }

    public static function day(float $t): float
    {
        return self::floorDiv($t, self::MS_PER_DAY);
    }

    public static function timeWithinDay(float $t): float
    {
        return self::modulo($t, self::MS_PER_DAY);
    }

    public static function daysInYear(float $y): int
    {
        if (fmod($y, 4) !== 0.0) {
            return 365;
        }
        if (fmod($y, 100) !== 0.0) {
            return 366;
        }
        return fmod($y, 400) === 0.0 ? 366 : 365;
    }

    /** DayFromYear (15.9.1.3). */
    public static function dayFromYear(float $y): float
    {
        return 365 * ($y - 1970)
            + floor(($y - 1969) / 4)
            - floor(($y - 1901) / 100)
            + floor(($y - 1601) / 400);
    }

    public static function timeFromYear(float $y): float
    {
        return self::MS_PER_DAY * self::dayFromYear($y);
    }

    public static function yearFromTime(float $t): float
    {
        // Estimate from the average year length, then correct.
        $y = floor($t / (self::MS_PER_DAY * 365.2425)) + 1970;
        while (self::timeFromYear($y) > $t) {
            $y--;
        }
        while (self::timeFromYear($y + 1) <= $t) {
            $y++;
        }
        return $y;
    }

    public static function inLeapYear(float $t): int
    {
        return self::daysInYear(self::yearFromTime($t)) === 366 ? 1 : 0;
    }

    public static function dayWithinYear(float $t): float
    {
        return self::day($t) - self::dayFromYear(self::yearFromTime($t));
    }

    public static function monthFromTime(float $t): int
    {
        $d = self::dayWithinYear($t);
        $leap = self::inLeapYear($t);
        $acc = 0;
        foreach (self::MONTH_DAYS as $m => $len) {
            $len += ($m === 1 ? $leap : 0);
            if ($d < $acc + $len) {
                return $m;
            }
            $acc += $len;
        }
        return 11;
    }

    public static function dateFromTime(float $t): float
    {
        $d = self::dayWithinYear($t);
        $leap = self::inLeapYear($t);
        $acc = 0;
        foreach (self::MONTH_DAYS as $m => $len) {
            $len += ($m === 1 ? $leap : 0);
            if ($d < $acc + $len) {
                return $d - $acc + 1;
            }
            $acc += $len;
        }
        return $d - $acc + 1;
    }

    public static function weekDay(float $t): float
    {
        return self::modulo(self::day($t) + 4, 7);
    }

    public static function hourFromTime(float $t): float
    {
        return self::modulo(self::floorDiv($t, self::MS_PER_HOUR), 24);
    }

    public static function minFromTime(float $t): float
    {
        return self::modulo(self::floorDiv($t, self::MS_PER_MINUTE), 60);
    }

    public static function secFromTime(float $t): float
    {
        return self::modulo(self::floorDiv($t, self::MS_PER_SECOND), 60);
    }

    public static function msFromTime(float $t): float
    {
        return self::modulo($t, self::MS_PER_SECOND);
    }

    /** MakeTime (15.9.1.11). */
    public static function makeTime(float $h, float $m, float $s, float $ms): float
    {
        if (!self::allFinite([$h, $m, $s, $ms])) {
            return NAN;
        }
        return self::toIntegerFloat($h) * self::MS_PER_HOUR
            + self::toIntegerFloat($m) * self::MS_PER_MINUTE
            + self::toIntegerFloat($s) * self::MS_PER_SECOND
            + self::toIntegerFloat($ms);
    }

    /** MakeDay (15.9.1.12). */
    public static function makeDay(float $year, float $month, float $date): float
    {
        if (!self::allFinite([$year, $month, $date])) {
            return NAN;
        }
        $y = self::toIntegerFloat($year);
        $m = self::toIntegerFloat($month);
        $dt = self::toIntegerFloat($date);
        $ym = $y + floor($m / 12);
        if (!is_finite($ym)) {
            return NAN;
        }
        $mn = self::modulo($m, 12);
        // Find the day number of the first of month $mn in year $ym.
        $day = self::dayFromYear($ym);
        $leap = self::daysInYear($ym) === 366 ? 1 : 0;
        for ($i = 0; $i < $mn; $i++) {
            $day += self::MONTH_DAYS[$i] + ($i === 1 ? $leap : 0);
        }
        return $day + $dt - 1;
    }

    /** MakeDate (15.9.1.13). */
    public static function makeDate(float $day, float $time): float
    {
        if (!is_finite($day) || !is_finite($time)) {
            return NAN;
        }
        return $day * self::MS_PER_DAY + $time;
    }

    /** @param list<float> $values */
    private static function allFinite(array $values): bool
    {
        foreach ($values as $v) {
            if (!is_finite($v)) {
                return false;
            }
        }
        return true;
    }

    private static function toIntegerFloat(float $v): float
    {
        return $v < 0 ? -floor(-$v) : floor($v);
    }

    /**
     * Parse the Date Time String Format (15.9.1.15) plus the legacy
     * `Day Mon DD YYYY HH:MM:SS GMT+0000` form that toString() emits.
     */
    public static function parse(string $s): float
    {
        $s = trim($s);
        if ($s === '') {
            return NAN;
        }
        if (preg_match(
            '/^([+-]\d{6}|\d{4})(?:-(\d{2})(?:-(\d{2}))?)?'
            . '(?:[T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,}))?)?'
            . '(Z|[+-]\d{2}:\d{2})?)?$/',
            $s,
            $m
        )) {
            $year = (float)$m[1];
            $month = isset($m[2]) && $m[2] !== '' ? (float)$m[2] - 1 : 0.0;
            $date = isset($m[3]) && $m[3] !== '' ? (float)$m[3] : 1.0;
            $hour = isset($m[4]) && $m[4] !== '' ? (float)$m[4] : 0.0;
            $min = isset($m[5]) && $m[5] !== '' ? (float)$m[5] : 0.0;
            $sec = isset($m[6]) && $m[6] !== '' ? (float)$m[6] : 0.0;
            $ms = isset($m[7]) && $m[7] !== '' ? (float)substr(str_pad($m[7], 3, '0'), 0, 3) : 0.0;
            if ($month > 11 || $date > 31 || $hour > 24 || $min > 59 || $sec > 59) {
                return NAN;
            }
            $offset = 0.0;
            if (isset($m[8]) && $m[8] !== '' && $m[8] !== 'Z') {
                $sign = $m[8][0] === '-' ? -1 : 1;
                $offset = $sign * ((float)substr($m[8], 1, 2) * self::MS_PER_HOUR
                    + (float)substr($m[8], 4, 2) * self::MS_PER_MINUTE);
            }
            $t = self::makeDate(
                self::makeDay($year, $month, $date),
                self::makeTime($hour, $min, $sec, $ms)
            );
            return self::timeClip($t - $offset);
        }
        // The toUTCString() form: "Tue, 14 Nov 2023 22:13:20 GMT".
        if (preg_match(
            '/^[A-Z][a-z]{2}, (\d{2}) ([A-Z][a-z]{2}) (-?\d+) (\d{2}):(\d{2}):(\d{2}) GMT$/',
            $s,
            $m
        )) {
            $month = self::monthIndex($m[2]);
            if ($month === null) {
                return NAN;
            }
            return self::timeClip(self::makeDate(
                self::makeDay((float)$m[3], (float)$month, (float)$m[1]),
                self::makeTime((float)$m[4], (float)$m[5], (float)$m[6], 0)
            ));
        }
        if (preg_match(
            '/^[A-Z][a-z]{2} ([A-Z][a-z]{2}) (\d{2}) (-?\d+) (\d{2}):(\d{2}):(\d{2}) GMT([+-]\d{4})(?: \(.*\))?$/',
            $s,
            $m
        )) {
            $month = self::monthIndex($m[1]);
            if ($month === null) {
                return NAN;
            }
            $offset = ((float)substr($m[7], 1, 2) * self::MS_PER_HOUR
                + (float)substr($m[7], 3, 2) * self::MS_PER_MINUTE)
                * ($m[7][0] === '-' ? -1 : 1);
            $t = self::makeDate(
                self::makeDay((float)$m[3], (float)$month, (float)$m[2]),
                self::makeTime((float)$m[4], (float)$m[5], (float)$m[6], 0)
            );
            return self::timeClip($t - $offset);
        }
        return NAN;
    }

    private static function monthIndex(string $abbrev): ?int
    {
        $i = array_search($abbrev, ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], true);
        return $i === false ? null : $i;
    }

    public static function toIsoString(float $t): string
    {
        $year = self::yearFromTime($t);
        $yearStr = ($year >= 0 && $year <= 9999)
            ? sprintf('%04d', $year)
            : sprintf('%+07d', $year);
        return sprintf(
            '%s-%02d-%02dT%02d:%02d:%02d.%03dZ',
            $yearStr,
            self::monthFromTime($t) + 1,
            self::dateFromTime($t),
            self::hourFromTime($t),
            self::minFromTime($t),
            self::secFromTime($t),
            self::msFromTime($t)
        );
    }

    public static function toDateString(float $t): string
    {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return sprintf(
            '%s %s %02d %04d',
            $days[(int)self::weekDay($t)],
            $months[self::monthFromTime($t)],
            self::dateFromTime($t),
            self::yearFromTime($t)
        );
    }

    public static function toTimeString(float $t): string
    {
        return sprintf(
            '%02d:%02d:%02d GMT+0000 (Coordinated Universal Time)',
            self::hourFromTime($t),
            self::minFromTime($t),
            self::secFromTime($t)
        );
    }
}

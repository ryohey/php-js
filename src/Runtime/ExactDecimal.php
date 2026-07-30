<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

/**
 * The exact decimal expansion of a double, and decimal rounding on top of it.
 *
 * Every finite double is a dyadic rational m / 2^k, so its decimal expansion is
 * finite: m · 5^k / 10^k. That expansion can run to 767 significant digits, and
 * both facts matter for `Number.prototype.toFixed`, which PHP's own `sprintf`
 * cannot implement:
 *
 * - **Ties.** ES5.1 15.7.4.5 picks *the larger* n when two are equally close,
 *   after taking the absolute value — ties away from zero. PHP's `%F` rounds
 *   half to even, so `(0.625).toFixed(2)` came out `"0.62"` where every other
 *   engine says `"0.63"`.
 * - **Precision.** `%F` caps at 53 fraction digits and pads the rest with
 *   zeros, so `Math.pow(2, -70).toFixed(100)` lost the real digits.
 *
 * Doing the arithmetic in base 10^7 limbs makes both exact. None of this is on
 * a hot path: it runs once per `toFixed` call.
 */
final class ExactDecimal
{
    /** Digits per limb. 10^7 keeps every intermediate product inside int64. */
    private const LIMB_DIGITS = 7;
    private const LIMB_BASE = 10_000_000;

    /**
     * Round |$x| to $digits fraction digits, ties away from zero.
     *
     * The caller owns the sign and the NaN / Infinity / >=1e21 cases; this takes
     * a finite value and returns unsigned digits.
     */
    public static function toFixed(float $x, int $digits): string
    {
        [$int, $frac] = self::expand(abs($x));

        if (strlen($frac) <= $digits) {
            // The expansion terminates at or before the requested digit, so
            // there is nothing to round -- only zeros to add.
            return $digits === 0 ? $int : $int . '.' . str_pad($frac, $digits, '0');
        }

        $keep = substr($frac, 0, $digits);
        // "Ties away from zero" is exactly "round up when the first dropped
        // digit is 5 or more": a dropped 5 followed by anything is >= one half,
        // and a dropped 4 followed by anything is below it.
        if ($frac[$digits] >= '5') {
            [$int, $keep] = self::increment($int, $keep);
        }
        return $digits === 0 ? $int : $int . '.' . $keep;
    }

    /**
     * Split a non-negative finite double into its exact decimal digits.
     *
     * @return array{0: string, 1: string} [integer digits, fraction digits]
     */
    public static function expand(float $x): array
    {
        [$mantissa, $exponent] = self::decompose($x);
        if ($mantissa === 0) {
            return ['0', ''];
        }
        if ($exponent >= 0) {
            // An integer: m · 2^e.
            return [self::toDigits(self::mulPow(self::limbs($mantissa), 2, $exponent)), ''];
        }
        // m / 2^k, written over a power of ten: m · 5^k / 10^k. The last k
        // digits are the fraction and there are never more than k of them.
        $k = -$exponent;
        $digits = self::toDigits(self::mulPow(self::limbs($mantissa), 5, $k));
        $digits = str_pad($digits, $k + 1, '0', STR_PAD_LEFT);
        return [substr($digits, 0, -$k), substr($digits, -$k)];
    }

    /**
     * IEEE 754 decomposition: $x === $mantissa * 2 ** $exponent, exactly, with
     * $mantissa an integer.
     *
     * @return array{0: int, 1: int}
     */
    private static function decompose(float $x): array
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', pack('E', $x));
        $bits = $unpacked[1];
        $biased = ($bits >> 52) & 0x7FF;
        $fraction = $bits & 0xF_FFFF_FFFF_FFFF;
        return $biased === 0
            // Subnormal (and zero): no implicit leading bit, fixed exponent.
            ? [$fraction, -1074]
            : [$fraction | (1 << 52), $biased - 1075];
    }

    /**
     * Add one to the last digit of $int . $frac, carrying into $int.
     *
     * @return array{0: string, 1: string}
     */
    private static function increment(string $int, string $frac): array
    {
        $combined = $int . $frac;
        for ($i = strlen($combined) - 1; $i >= 0; $i--) {
            if ($combined[$i] !== '9') {
                $combined[$i] = (string)((int)$combined[$i] + 1);
                break;
            }
            $combined[$i] = '0';
            if ($i === 0) {
                // All nines: the number grew a digit, e.g. 9.99 -> 10.0.
                $combined = '1' . $combined;
                return [substr($combined, 0, strlen($int) + 1), substr($combined, strlen($int) + 1)];
            }
        }
        return [substr($combined, 0, strlen($int)), substr($combined, strlen($int))];
    }

    /**
     * $limbs · $base ** $power, in place.
     *
     * Applied in chunks so each limb-by-multiplier product stays inside int64:
     * the multiplier is kept below 10^11, which bounds a product at
     * (10^7 - 1) · 10^11 plus carry.
     *
     * @param  list<int> $limbs little-endian base-10^7 limbs
     * @return list<int>
     */
    private static function mulPow(array $limbs, int $base, int $power): array
    {
        $perChunk = $base === 2 ? 30 : 10;   // 2^30 and 5^10 are both under 10^10
        while ($power > 0) {
            $take = min($power, $perChunk);
            $limbs = self::mulSmall($limbs, $base ** $take);
            $power -= $take;
        }
        return $limbs;
    }

    /**
     * @param  list<int> $limbs
     * @return list<int>
     */
    private static function mulSmall(array $limbs, int $multiplier): array
    {
        $carry = 0;
        foreach ($limbs as $i => $limb) {
            $product = $limb * $multiplier + $carry;
            $limbs[$i] = $product % self::LIMB_BASE;
            $carry = intdiv($product, self::LIMB_BASE);
        }
        while ($carry > 0) {
            $limbs[] = $carry % self::LIMB_BASE;
            $carry = intdiv($carry, self::LIMB_BASE);
        }
        return $limbs;
    }

    /** @return list<int> little-endian base-10^7 limbs of a non-negative int */
    private static function limbs(int $value): array
    {
        $limbs = [];
        do {
            $limbs[] = $value % self::LIMB_BASE;
            $value = intdiv($value, self::LIMB_BASE);
        } while ($value > 0);
        return $limbs;
    }

    /** @param list<int> $limbs */
    private static function toDigits(array $limbs): string
    {
        $out = (string)$limbs[count($limbs) - 1];
        for ($i = count($limbs) - 2; $i >= 0; $i--) {
            $out .= str_pad((string)$limbs[$i], self::LIMB_DIGITS, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}

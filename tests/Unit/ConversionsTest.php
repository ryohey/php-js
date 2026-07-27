<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Runtime\Conversions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConversionsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: int|float}> */
    public static function numberToStringCases(): iterable
    {
        yield 'int' => ['42', 42];
        yield 'negative int' => ['-7', -7];
        yield 'zero' => ['0', 0];
        yield 'negative zero' => ['0', -0.0];
        yield 'simple float' => ['0.1', 0.1];
        yield 'half' => ['2.5', 2.5];
        yield 'integral float' => ['100', 100.0];
        yield 'nan' => ['NaN', NAN];
        yield 'inf' => ['Infinity', INF];
        yield 'neg inf' => ['-Infinity', -INF];
        yield '1e15 plain' => ['1000000000000000', 1e15];
        yield '1e20 plain' => ['100000000000000000000', 1e20];
        yield '1e21 exponent' => ['1e+21', 1e21];
        yield '1e-6 plain' => ['0.000001', 1e-6];
        yield '1e-7 exponent' => ['1e-7', 1e-7];
        yield '1.5e-7' => ['1.5e-7', 1.5e-7];
        yield 'third' => ['0.3333333333333333', 1 / 3];
        yield 'max double' => ['1.7976931348623157e+308', 1.7976931348623157e308];
        yield 'min denormal' => ['5e-324', 5e-324];
        yield 'big shortest' => ['1.2345678901234569e+23', 123456789012345678901234.0];
    }

    #[DataProvider('numberToStringCases')]
    public function testNumberToString(string $expected, int|float $input): void
    {
        $this->assertSame($expected, Conversions::numberToString($input));
    }

    /** @return iterable<array{0: int|float, 1: string}> */
    public static function stringToNumberCases(): iterable
    {
        yield [0, ''];
        yield [0, '   '];
        yield [42, '42'];
        yield [42, '  42  '];
        yield [-3.5, '-3.5'];
        yield [255, '0xFF'];
        yield [INF, 'Infinity'];
        yield [-INF, '-Infinity'];
        yield [NAN, '12abc'];
        yield [NAN, '0x'];
        yield [1500.0, '1.5e3'];
        yield [0.5, '.5'];
    }

    #[DataProvider('stringToNumberCases')]
    public function testStringToNumber(int|float $expected, string $input): void
    {
        $actual = Conversions::stringToNumber($input);
        if (is_float($expected) && is_nan($expected)) {
            $this->assertTrue(is_float($actual) && is_nan($actual), "expected NaN for '$input'");
            return;
        }
        $this->assertTrue($actual == $expected, "expected $expected for '$input', got " . var_export($actual, true));
    }

    public function testToBoolean(): void
    {
        $this->assertFalse(Conversions::toBoolean(0));
        $this->assertFalse(Conversions::toBoolean(-0.0));
        $this->assertFalse(Conversions::toBoolean(NAN));
        $this->assertFalse(Conversions::toBoolean(''));
        $this->assertFalse(Conversions::toBoolean(null));
        $this->assertTrue(Conversions::toBoolean('0'));
        $this->assertTrue(Conversions::toBoolean(0.5));
    }
}

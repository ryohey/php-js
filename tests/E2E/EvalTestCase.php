<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PhpJs\Engine;
use PhpJs\Runtime\JSUndefined;
use PHPUnit\Framework\TestCase;

abstract class EvalTestCase extends TestCase
{
    protected function evalJs(string $source): mixed
    {
        $engine = new Engine();
        return $engine->evaluate($source);
    }

    /**
     * Assert the completion value of a script. Expected uses PHP values;
     * pass ['undefined'] semantics via assertUndefined instead.
     */
    protected function assertJs(mixed $expected, string $source, string $message = ''): void
    {
        $actual = $this->evalJs($source);
        if (is_float($expected) && is_nan($expected)) {
            $this->assertTrue(is_float($actual) && is_nan($actual), $message !== '' ? $message : "expected NaN from: $source");
            return;
        }
        if (is_float($expected) || is_int($expected)) {
            $this->assertTrue(
                (is_int($actual) || is_float($actual)) && $actual == $expected,
                ($message !== '' ? $message : "from: $source") . ' — got ' . var_export($actual, true)
            );
            return;
        }
        $this->assertSame($expected, $actual, $message !== '' ? $message : "from: $source");
    }

    protected function assertJsUndefined(string $source): void
    {
        $this->assertInstanceOf(JSUndefined::class, $this->evalJs($source), "expected undefined from: $source");
    }
}

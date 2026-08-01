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
        $this->assertJsValue($expected, $this->evalJs($source), $message !== '' ? $message : "from: $source");
    }

    private function assertJsValue(mixed $expected, mixed $actual, string $message): void
    {
        if (is_float($expected) && is_nan($expected)) {
            $this->assertTrue(is_float($actual) && is_nan($actual), "expected NaN $message");
            return;
        }
        if (is_float($expected) || is_int($expected)) {
            $this->assertTrue(
                (is_int($actual) || is_float($actual)) && $actual == $expected,
                "$message — got " . var_export($actual, true)
            );
            return;
        }
        $this->assertSame($expected, $actual, $message);
    }

    protected function assertJsUndefined(string $source): void
    {
        $this->assertInstanceOf(JSUndefined::class, $this->evalJs($source), "expected undefined from: $source");
    }

    /**
     * A source's own completion value is captured before the microtask queue
     * drains (Engine::evaluate), so it can never observe an `await`'s result
     * directly. This runs the source through `eval` inside an `async` IIFE
     * of its own -- awaiting whatever it completes with, promise or plain
     * value either way -- and reads the settled result back with a second
     * `evaluate()` call, by which point the first call's own drain has
     * already run.
     */
    protected function evalJsAsync(string $source): mixed
    {
        $engine = new Engine();
        $engine->evaluate(
            'var __asyncResult; (async () => { __asyncResult = await eval(' . json_encode($source) . '); })();'
        );
        return $engine->evaluate('__asyncResult');
    }

    protected function assertJsAsync(mixed $expected, string $source, string $message = ''): void
    {
        $this->assertJsValue($expected, $this->evalJsAsync($source), $message !== '' ? $message : "from: $source");
    }
}

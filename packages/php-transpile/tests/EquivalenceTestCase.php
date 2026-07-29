<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PhpJs\Engine;
use PhpJs\Transpile\Artifact;
use PHPUnit\Framework\TestCase;

/**
 * Runs a program twice — once entirely on bytecode, once with every function
 * the emitter accepted replaced by generated PHP — and requires the two to
 * agree.
 *
 * This is the only verification that matters for a transpiler: it does not
 * check that the output "looks right", it checks that ahead-of-time compiling
 * a function changed nothing observable.
 */
abstract class EquivalenceTestCase extends TestCase
{
    private static int $seq = 0;

    /**
     * @return array{0: mixed, 1: mixed, 2: Artifact} [interpreted, transpiled, artifact]
     */
    protected function bothWays(string $source): array
    {
        $interpreted = (new Engine())->evaluate($source);

        // Native IDs are process-wide, so each build needs its own namespace
        // or a second registration of the same source would collide.
        $artifact = Artifact::build($source, 'test' . (++self::$seq));
        $artifact->registerDirect();
        $transpiled = (new Engine())->runTemplate($artifact->template);

        return [$interpreted, $transpiled, $artifact];
    }

    /** Assert both runs agree and that the emitter actually converted something. */
    protected function assertSameBothWays(mixed $expected, string $source, int $minConverted = 1): void
    {
        [$interpreted, $transpiled, $artifact] = $this->bothWays($source);

        $this->assertGreaterThanOrEqual(
            $minConverted,
            $artifact->converted,
            "nothing was transpiled, so this proves nothing. Refusals: "
                . json_encode($artifact->refused, JSON_PRETTY_PRINT)
        );
        $this->assertSame($expected, $this->normalize($interpreted), 'interpreted run');
        $this->assertSame($expected, $this->normalize($transpiled), 'transpiled run: ' . $artifact->php);
    }

    private function normalize(mixed $v): mixed
    {
        if (is_float($v) && !is_nan($v) && !is_infinite($v) && $v == (int)$v && abs($v) < 1e15) {
            return (int)$v;
        }
        return $v;
    }
}

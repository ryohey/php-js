<?php

declare(strict_types=1);

namespace PhpJs\StripTypes\Tests;

use PhpJs\Engine;
use PhpJs\StripTypes\Stripper;
use PHPUnit\Framework\TestCase;

/**
 * The only claim worth checking for a type stripper: erasure, not just
 * "produces some output" — the stripped code has to actually *run*, in
 * php-js itself, and behave the way the typed source promised.
 */
final class StripperTest extends TestCase
{
    public function testStripsTypeAnnotationsAndRunsInPhpJs(): void
    {
        $ts = <<<'TS'
        function add(a: number, b: number): number {
          return a + b;
        }
        add(2, 3);
        TS;
        $js = Stripper::strip($ts, 'add.ts');
        $this->assertStringNotContainsString(': number', $js);

        $engine = new Engine();
        $this->assertSame(5, $engine->evaluate($js));
    }

    public function testStripsJsxToClassicCreateElementCalls(): void
    {
        $tsx = 'const el = <div className="x">{1 + 1}</div>;';
        $js = Stripper::strip($tsx, 'component.tsx');
        $this->assertStringContainsString('React.createElement', $js);
        $this->assertStringNotContainsString('<div', $js);
    }

    public function testLeavesModernSyntaxAlone(): void
    {
        // disableESTransforms: sucrase must not downlevel what php-js already
        // parses natively.
        $ts = 'const f = (a?: number) => a ?? 0;';
        $js = Stripper::strip($ts, 'modern.ts');
        $this->assertStringContainsString('??', $js);
    }

    public function testPassesThroughPlainJavaScriptUnharmed(): void
    {
        $js = 'function square(x) { return x * x; }';
        $stripped = Stripper::strip($js, 'plain.js');
        $engine = new Engine();
        $this->assertSame(9, $engine->evaluate($stripped . "\nsquare(3);"));
    }

    public function testDownlevelsRelativeImportsToRequire(): void
    {
        $ts = "import { helper } from './helper'; helper();";
        $js = Stripper::strip($ts, 'entry.ts');
        $this->assertStringContainsString("require(", $js);
        $this->assertStringContainsString('./helper', $js);
    }
}

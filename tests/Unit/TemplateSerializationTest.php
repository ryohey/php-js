<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Compiler\Compiler;
use PhpJs\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Guards the load-bearing shared-nothing constraint (DESIGN.md §11.1):
 * function templates must be plain arrays that survive var_export/require,
 * or the opcache strategy silently dies.
 */
final class TemplateSerializationTest extends TestCase
{
    private const SOURCE = '
        "use strict";
        var cache = {};
        function memoFib(n) {
            if (n < 2) return n;
            if (cache[n]) return cache[n];
            return cache[n] = memoFib(n - 1) + memoFib(n - 2);
        }
        try { memoFib("x" / 2); } catch (e) {}
        /a+/g.test("aaa");
        memoFib(25);
    ';

    public function testTemplateContainsOnlyPlainData(): void
    {
        $tpl = Compiler::compile(self::SOURCE);
        $this->assertPlainData($tpl, 'template');
    }

    public function testTemplateSurvivesVarExportRoundtrip(): void
    {
        $tpl = Compiler::compile(self::SOURCE);
        $file = tempnam(sys_get_temp_dir(), 'phpjs') . '.php';
        try {
            file_put_contents($file, "<?php\nreturn " . var_export($tpl, true) . ";\n");
            $loaded = require $file;
            $this->assertSame($tpl, $loaded);

            $engine = new Engine();
            $result = $engine->runTemplate($loaded);
            $this->assertSame(75025, $result);
        } finally {
            @unlink($file);
        }
    }

    private function assertPlainData(mixed $v, string $path): void
    {
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $this->assertPlainData($item, "$path.$k");
            }
            return;
        }
        $this->assertTrue(
            $v === null || is_int($v) || is_float($v) || is_string($v) || is_bool($v),
            "non-plain value at $path: " . get_debug_type($v)
        );
    }
}

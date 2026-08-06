<?php

declare(strict_types=1);

namespace PhpJs\Aot\Tests;

use PhpJs\Aot\LibraryCompiler;
use PhpJs\Node\NodeHost;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of this package, checked end to end: compiling a fixture
 * "library" writes an AOT cache that a completely separate, later `NodeHost`
 * — one that never heard of `LibraryCompiler`, `NodeIntegration` or `Trust` —
 * picks up on a plain `require()`, with no wiring of its own.
 */
final class LibraryCompilerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpjs-aot-lib-' . getmypid() . '-' . uniqid();
        $libDir = $this->root . '/node_modules/fixture-lib';
        mkdir($libDir, 0o777, true);
        file_put_contents($libDir . '/package.json', json_encode(['name' => 'fixture-lib', 'main' => 'index.js']));
        file_put_contents($libDir . '/index.js', <<<'JS'
        function cube(x) { return x * x * x; }
        module.exports = { cube: cube };
        JS);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCompilingWritesACacheThatAPlainRequireLaterPicksUpTransparently(): void
    {
        $cacheDir = $this->root . '/node_modules/.phpjs-aot';
        $result = (new LibraryCompiler())->compile($this->root, ['fixture-lib'], $cacheDir);

        $this->assertGreaterThan(0, $result['converted'], 'nothing was compiled to PHP');
        $this->assertSame(1, $result['files'], 'expected exactly one module file (fixture-lib/index.js)');
        $this->assertCount(1, glob($cacheDir . '/*.php') ?: []);

        // A fresh host, with no explicit AOT wiring at all -- the cache
        // directory is auto-discovered because it exists at the conventional
        // path under node_modules.
        $host = new NodeHost($this->root);
        $lib = $host->requireModule('fixture-lib');
        $cube = $lib->get('cube', $host->vm());
        $this->assertSame(27, $host->call($cube, null, [3]));
    }

    public function testTheResultIsInertUntilANodeHostActuallyRequiresTheModule(): void
    {
        $cacheDir = $this->root . '/node_modules/.phpjs-aot';
        (new LibraryCompiler())->compile($this->root, ['fixture-lib'], $cacheDir);

        // Loading the file directly (not through a NodeHost) must not error
        // or need anything beyond core PHP -- it is a plain array literal.
        $files = glob($cacheDir . '/*.php') ?: [];
        $this->assertNotEmpty($files);
        $entries = require $files[0];
        $this->assertIsArray($entries);
        foreach (array_keys($entries) as $id) {
            $this->assertStringStartsWith('aot:', $id);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

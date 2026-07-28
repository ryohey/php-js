<?php

declare(strict_types=1);

namespace PhpJs\Node\Tests;

use PhpJs\JSException;
use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PHPUnit\Framework\TestCase;

final class ModuleLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpjs-node-' . uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function host(): NodeHost
    {
        return new NodeHost($this->root, captureOutput: true);
    }

    private function str(NodeHost $host, mixed $value): string
    {
        return Conversions::toString($host->vm(), $value);
    }

    public function testRelativeRequire(): void
    {
        $this->write('lib/greet.js', 'module.exports = function (name) { return "hi " + name; };');
        $this->write('index.js', 'var greet = require("./lib/greet"); exports.result = greet("world");');
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('hi world', $this->str($host, $exports->get('result', $host->vm())));
    }

    public function testModuleWrapperProvidesFilenameAndDirname(): void
    {
        $this->write('a/b/mod.js', 'exports.dir = __dirname; exports.file = __filename;');
        $host = $this->host();
        $exports = $host->requireModule('./a/b/mod.js');
        $this->assertStringEndsWith('/a/b', $this->str($host, $exports->get('dir', $host->vm())));
        $this->assertStringEndsWith('/a/b/mod.js', $this->str($host, $exports->get('file', $host->vm())));
    }

    public function testModulesAreCachedAndEvaluatedOnce(): void
    {
        $this->write('counter.js', 'exports.n = (globalThis.__count = (globalThis.__count || 0) + 1);');
        $this->write('index.js', 'require("./counter"); require("./counter"); exports.n = require("./counter").n;');
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('1', $this->str($host, $exports->get('n', $host->vm())));
    }

    public function testNodeModulesResolution(): void
    {
        $this->write('node_modules/pkg/package.json', '{"name":"pkg","main":"lib/main.js"}');
        $this->write('node_modules/pkg/lib/main.js', 'module.exports = { tag: "from-pkg" };');
        $this->write('index.js', 'exports.tag = require("pkg").tag;');
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('from-pkg', $this->str($host, $exports->get('tag', $host->vm())));
    }

    public function testNodeModulesIndexFallback(): void
    {
        $this->write('node_modules/plain/index.js', 'module.exports = "indexed";');
        $this->write('index.js', 'exports.v = require("plain");');
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('indexed', $this->str($host, $exports->get('v', $host->vm())));
    }

    public function testJsonModule(): void
    {
        $this->write('data.json', '{"answer": 42}');
        $this->write('index.js', 'exports.answer = require("./data.json").answer;');
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('42', $this->str($host, $exports->get('answer', $host->vm())));
    }

    public function testCyclicRequireSeesPartialExports(): void
    {
        $this->write('a.js', 'exports.name = "a"; var b = require("./b"); exports.sawB = b.name;');
        $this->write('b.js', 'exports.name = "b"; var a = require("./a"); exports.sawA = a.name;');
        $host = $this->host();
        $exports = $host->requireModule('./a.js');
        $this->assertSame('b', $this->str($host, $exports->get('sawB', $host->vm())));
    }

    public function testMissingModuleThrowsInJs(): void
    {
        $this->write('index.js', 'require("./nope");');
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('Cannot find module');
        $this->host()->requireModule('./index.js');
    }

    public function testResolutionCannotEscapeTheRoot(): void
    {
        $this->write('index.js', 'require("../../../etc/passwd");');
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('Cannot find module');
        $this->host()->requireModule('./index.js');
    }

    public function testCoreModuleStubs(): void
    {
        $this->write('index.js', <<<'JS'
        var stream = require('stream');
        var util = require('util');
        function Sub() { stream.Readable.call(this); }
        util.inherits(Sub, stream.Readable);
        exports.isReadable = (new Sub()) instanceof stream.Readable;
        exports.formatted = util.format('%s=%d', 'n', 7);
        JS);
        $host = $this->host();
        $exports = $host->requireModule('./index.js');
        $this->assertSame('true', $this->str($host, $exports->get('isReadable', $host->vm())));
        $this->assertSame('n=7', $this->str($host, $exports->get('formatted', $host->vm())));
    }
}

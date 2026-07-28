<?php

declare(strict_types=1);

namespace PhpJs\Node\Tests;

use PhpJs\JSException;
use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PHPUnit\Framework\TestCase;

final class HostSurfaceTest extends TestCase
{
    private function host(array $env = []): NodeHost
    {
        $host = new NodeHost(__DIR__ . '/fixtures', captureOutput: true);
        if ($env !== []) {
            $host->setEnv($env);
        }
        return $host;
    }

    private function eval(NodeHost $host, string $source): string
    {
        return Conversions::toString($host->vm(), $host->engine->evaluate($source));
    }

    public function testProcessEnvIsVisible(): void
    {
        $host = new NodeHost(__DIR__ . '/fixtures', captureOutput: true);
        $this->assertSame('production', $this->eval($host, 'process.env.NODE_ENV'));
        $this->assertSame('linux', $this->eval($host, 'process.platform'));
        $this->assertSame('18.0.0', $this->eval($host, 'process.versions.node'));
    }

    public function testProcessStdoutIsCaptured(): void
    {
        $host = $this->host();
        $host->engine->evaluate('process.stdout.write("from guest")');
        $this->assertSame('from guest', $host->takeOutput());
    }

    public function testNextTickRunsOnDrain(): void
    {
        $host = $this->host();
        $host->engine->evaluate('var order = []; process.nextTick(function () { order.push("tick"); }); order.push("sync");');
        $host->drain();
        $this->assertSame('sync,tick', $this->eval($host, 'order.join(",")'));
    }

    public function testTimersRunInDueOrderOnVirtualTime(): void
    {
        $host = $this->host();
        $started = microtime(true);
        $host->engine->evaluate(<<<'JS'
        var order = [];
        setTimeout(function () { order.push('late'); }, 5000);
        setTimeout(function () { order.push('early'); }, 10);
        setTimeout(function () { order.push('immediate'); }, 0);
        JS);
        $host->drain();
        $this->assertSame('immediate,early,late', $this->eval($host, 'order.join(",")'));
        // 5 seconds of guest delay must not cost 5 seconds of wall clock.
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function testClearTimeoutCancels(): void
    {
        $host = $this->host();
        $host->engine->evaluate('var ran = false; var id = setTimeout(function () { ran = true; }, 1); clearTimeout(id);');
        $host->drain();
        $this->assertSame('false', $this->eval($host, 'String(ran)'));
    }

    public function testIntervalThatNeverClearsIsReportedNotHung(): void
    {
        $host = $this->host();
        $host->timers->maxIterations = 50;
        $host->engine->evaluate('setInterval(function () {}, 1);');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not settle');
        $host->drain();
    }

    public function testFsIsConfinedToTheRoot(): void
    {
        $host = $this->host();
        $this->expectException(JSException::class);
        $host->engine->evaluate('require("fs")');
    }

    public function testPolyfillsAreInstalled(): void
    {
        $host = $this->host();
        $this->assertSame('function', $this->eval($host, 'typeof Object.assign'));
        $this->assertSame('@@react.element', $this->eval($host, 'Symbol.for("react.element")'));
        $this->assertSame('2', $this->eval($host, 'String(new Map([["a", 2]]).get("a"))'));
        $this->assertSame('true', $this->eval($host, 'String(new Set([1, 1, 2]).size === 2)'));
        $this->assertSame('3', $this->eval($host, 'String(new Uint16Array([1, 2, 3]).length)'));
        $this->assertSame('65535', $this->eval($host, 'String(new Uint16Array([-1])[0])'));
        $this->assertSame('abab', $this->eval($host, '"ab".repeat(2)'));
    }

    public function testMapDistinguishesObjectKeys(): void
    {
        $host = $this->host();
        $this->assertSame('a,b', $this->eval($host, <<<'JS'
        var m = new Map();
        var k1 = {}, k2 = {};
        m.set(k1, 'a');
        m.set(k2, 'b');
        [m.get(k1), m.get(k2)].join(',');
        JS));
    }

    public function testGlobalAliases(): void
    {
        $host = $this->host();
        $this->assertSame('true', $this->eval($host, 'String(global === globalThis)'));
    }
}

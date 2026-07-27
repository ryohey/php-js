<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PhpJs\Engine;

final class PromiseTest extends EvalTestCase
{
    /** Run source, then read the value of global `result` after the drain. */
    private function evalAfterDrain(string $source): mixed
    {
        $engine = new Engine();
        $engine->evaluate($source);
        return $engine->evaluate('result');
    }

    public function testThenChain(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            Promise.resolve(1)
                .then(function (v) { result.push("a" + v); return v + 1; })
                .then(function (v) { result.push("b" + v); });
        ');
        $this->assertSame('a1,b2', $this->joinArray($r));
    }

    public function testCatchAndRecovery(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            new Promise(function (resolve, reject) { reject(new Error("no")); })
                .catch(function (e) { result.push("caught:" + e.message); return "ok"; })
                .then(function (v) { result.push(v); });
        ');
        $this->assertSame('caught:no,ok', $this->joinArray($r));
    }

    public function testExecutorThrowRejects(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            new Promise(function () { throw new Error("thrown"); })
                .catch(function (e) { result.push(e.message); });
        ');
        $this->assertSame('thrown', $this->joinArray($r));
    }

    public function testAll(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            Promise.all([1, Promise.resolve(2), 3]).then(function (vs) { result.push(vs.join("+")); });
        ');
        $this->assertSame('1+2+3', $this->joinArray($r));
    }

    public function testAllRejectsOnFirstFailure(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            Promise.all([1, Promise.reject("bad")]).then(
                function () { result.push("nope"); },
                function (e) { result.push("rejected:" + e); }
            );
        ');
        $this->assertSame('rejected:bad', $this->joinArray($r));
    }

    public function testThenableAssimilation(): void
    {
        $r = $this->evalAfterDrain('
            var result = [];
            var thenable = { then: function (resolve) { resolve(99); } };
            Promise.resolve().then(function () { return thenable; }).then(function (v) { result.push(v); });
        ');
        $this->assertSame('99', $this->joinArray($r));
    }

    public function testMicrotaskOrderingBeforeHostContinues(): void
    {
        $engine = new Engine();
        $engine->evaluate('var order = []; Promise.resolve().then(function(){ order.push("micro"); }); order.push("sync");');
        $order = $engine->evaluate('order.join(",")');
        $this->assertSame('sync,micro', $order);
    }

    public function testUnhandledRejectionIsReported(): void
    {
        $engine = new Engine();
        $engine->evaluate('Promise.reject(new Error("lost"));');
        $this->assertCount(1, $engine->unhandledRejections());
    }

    public function testHandledRejectionIsNotReported(): void
    {
        $engine = new Engine();
        $engine->evaluate('Promise.reject(new Error("found")).catch(function () {});');
        $this->assertCount(0, $engine->unhandledRejections());
    }

    private function joinArray(mixed $r): string
    {
        $engine = new Engine();
        $this->assertInstanceOf(\PhpJs\Runtime\JSArray::class, $r);
        $parts = [];
        foreach ($r->toList() as $v) {
            $parts[] = is_string($v) ? $v : $engine->stringify($v);
        }
        return implode(',', $parts);
    }
}

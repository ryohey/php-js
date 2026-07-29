<?php

declare(strict_types=1);

namespace PhpJs\Node\Tests;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The post-ES5 library surface that is implemented natively rather than in
 * `js/polyfills.js`. Expected values are what Node produces.
 *
 * These are drop-in replacements for shims that library code already relies
 * on, so the risk is not "does it work" but "does it differ from the JS
 * version in some corner" — hence the emphasis on -0, NaN, int/float identity
 * and key ordering.
 */
final class NativeLibraryTest extends TestCase
{
    private function evalJs(string $source): mixed
    {
        $host = new NodeHost(__DIR__ . '/fixtures', captureOutput: true);
        return $host->engine->evaluate($source);
    }

    private function evalString(string $source): string
    {
        $host = new NodeHost(__DIR__ . '/fixtures', captureOutput: true);
        return Conversions::toString($host->vm(), $host->engine->evaluate($source));
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function mathCases(): iterable
    {
        yield 'clz32 of zero' => [32, 'Math.clz32(0)'];
        yield 'clz32 of one' => [31, 'Math.clz32(1)'];
        yield 'clz32 of 255' => [24, 'Math.clz32(255)'];
        yield 'clz32 of the sign bit' => [0, 'Math.clz32(2147483648)'];
        yield 'clz32 of -1 wraps to all ones' => [0, 'Math.clz32(-1)'];
        yield 'clz32 coerces NaN to zero' => [32, 'Math.clz32(NaN)'];
        yield 'clz32 truncates a float' => [31, 'Math.clz32(1.9)'];
        yield 'clz32 of a string' => [24, 'Math.clz32("255")'];
        yield 'clz32 wraps past 32 bits' => [31, 'Math.clz32(4294967297)'];

        yield 'imul small' => [12, 'Math.imul(3, 4)'];
        yield 'imul negative' => [-60, 'Math.imul(-5, 12)'];
        yield 'imul wraps' => [-5, 'Math.imul(0xffffffff, 5)'];
        yield 'imul overflows to zero' => [0, 'Math.imul(1073741824, 4)'];
        yield 'imul of NaN' => [0, 'Math.imul(NaN, 3)'];

        // JS null converts to 0, undefined to NaN. Reading arguments with `??`
        // conflates them, because JS null is PHP null.
        yield 'sign of null is zero, not NaN' => [0, 'Math.sign(null)'];
        yield 'sign of undefined is NaN' => [NAN, 'Math.sign(undefined)'];
        yield 'clz32 of null' => [32, 'Math.clz32(null)'];
        yield 'hypot with null' => [3, 'Math.hypot(3, null)'];

        yield 'trunc positive' => [4, 'Math.trunc(4.7)'];
        yield 'trunc negative' => [-4, 'Math.trunc(-4.7)'];
        yield 'trunc keeps Infinity' => [INF, 'Math.trunc(Infinity)'];
        yield 'trunc of a negative fraction keeps -0' => ['-Infinity', 'String(1 / Math.trunc(-0.5))'];

        yield 'sign negative' => [-1, 'Math.sign(-3)'];
        yield 'sign positive' => [1, 'Math.sign(3)'];
        yield 'sign keeps -0' => ['-Infinity', 'String(1 / Math.sign(-0))'];
        yield 'sign of NaN' => [NAN, 'Math.sign(NaN)'];

        yield 'log2 exact' => [3, 'Math.log2(8)'];
        yield 'log10 exact' => [3, 'Math.log10(1000)'];
        yield 'cbrt negative' => [-3, 'Math.cbrt(-27)'];
        yield 'cbrt of zero' => [0, 'Math.cbrt(0)'];
        yield 'hypot' => [5, 'Math.hypot(3, 4)'];
        yield 'hypot with no arguments' => [0, 'Math.hypot()'];
        yield 'hypot Infinity beats NaN' => [INF, 'Math.hypot(Infinity, NaN)'];
        yield 'fround exact in float32' => [5.5, 'Math.fround(5.5)'];
        yield 'fround rounds' => [1.100000023841858, 'Math.fround(1.1)'];

        // Integral results must come back as ints, not integral floats
        // (DESIGN.md §3.1) — otherwise they diverge from the rest of the VM.
        yield 'clz32 result is exact' => [true, 'Math.clz32(1) === 31'];
        yield 'log2 result is exact' => [true, 'Math.log2(8) === 3'];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function collectionCases(): iterable
    {
        yield 'map round trip' => ['b', 'var m = new Map(); m.set("a", "b"); m.get("a")'];
        yield 'map size' => [3, 'var m = new Map(); m.set(1, 1).set(2, 2).set(3, 3); m.size'];
        yield 'map set is chainable' => [true, 'var m = new Map(); m.set(1, 1) === m'];
        yield 'map missing key is undefined' => [true, 'new Map().get("nope") === undefined'];
        yield 'map keys objects by identity' => [
            'a|b',
            'var m = new Map(), k1 = {}, k2 = {}; m.set(k1, "a"); m.set(k2, "b"); m.get(k1) + "|" + m.get(k2)',
        ];
        yield 'map treats int and float alike' => ['one', 'var m = new Map(); m.set(1, "one"); m.get(1.0)'];
        yield 'map finds NaN' => ['nan', 'var m = new Map(); m.set(NaN, "nan"); m.get(NaN)'];
        yield 'map treats -0 as 0' => ['zero', 'var m = new Map(); m.set(0, "zero"); m.get(-0)'];
        yield 'map distinguishes close doubles' => [
            'a|b',
            'var m = new Map(); m.set(0.1, "a"); m.set(0.1 + 1e-17, "b"); m.get(0.1) + "|" + m.get(0.1 + 1e-17)',
        ];
        yield 'map distinguishes types' => [
            '3',
            'var m = new Map(); m.set(1, 1); m.set("1", 1); m.set(true, 1); m.size + ""',
        ];
        yield 'map holds null and undefined keys' => [
            'n|u',
            'var m = new Map(); m.set(null, "n"); m.set(undefined, "u"); m.get(null) + "|" + m.get(undefined)',
        ];
        yield 'map delete' => [
            'true|false|0',
            'var m = new Map(); m.set("a", 1); var d = m["delete"]("a"); d + "|" + m.has("a") + "|" + m.size',
        ];
        yield 'deleting a missing key returns false' => [false, 'new Map()["delete"]("x")'];
        yield 'null and undefined are distinct keys' => [
            '2',
            'var m = new Map(); m.set(null, "n"); m.set(undefined, "u"); m.size + ""',
        ];
        yield 'a null value round trips' => [
            'true',
            'var m = new Map(); m.set("k", null); (m.get("k") === null) + ""',
        ];
        yield 'new Map(null) is empty' => [0, 'new Map(null).size'];
        yield 'map clear' => [0, 'var m = new Map(); m.set(1, 1).set(2, 2); m.clear(); m.size'];
        yield 'map iterates in insertion order' => [
            'c,a,b',
            'var m = new Map(); m.set("z", "c"); m.set("y", "a"); m.set("x", "b");'
            . 'var o = []; m.forEach(function (v) { o.push(v); }); o.join(",")',
        ];
        yield 'map forEach passes value, key, map' => [
            'v|k|true',
            'var m = new Map(); m.set("k", "v"); var r; m.forEach(function (a, b, c) { r = a + "|" + b + "|" + (c === m); }); r',
        ];
        yield 'map forEach honours thisArg' => [
            'ctx',
            'var m = new Map(); m.set(1, 1); var r; m.forEach(function () { r = this.tag; }, { tag: "ctx" }); r',
        ];
        yield 'map skips deleted entries when iterating' => [
            'a,c',
            'var m = new Map(); m.set(1, "a"); m.set(2, "b"); m.set(3, "c"); m.set(4, "d"); m["delete"](2);'
            . 'var o = []; m.forEach(function (v) { o.push(v); }); o.slice(0, 2).join(",")',
        ];
        yield 'map from an entry array' => [2, 'new Map([["a", 1], ["b", 2]]).get("b")'];
        yield 'map size is not an own key' => [0, 'Object.keys(new Map()).length'];
        yield 'map instanceof' => [true, 'new Map() instanceof Map'];
        yield 'map constructor requires new' => [
            'TypeError',
            'var r = "no throw"; try { Map(); } catch (e) { r = e.name; } r',
        ];

        yield 'set dedupes' => [3, 'new Set([1, 2, 2, 3]).size'];
        yield 'set add is chainable' => [true, 'var s = new Set(); s.add(1) === s'];
        yield 'set has' => [true, 'new Set([1, 2]).has(2)'];
        yield 'set delete' => ['true|1', 'var s = new Set([1, 2]); var d = s["delete"](1); d + "|" + s.size'];
        yield 'set forEach passes value twice' => [
            '7|7|true',
            'var s = new Set([7]); var r; s.forEach(function (a, b, c) { r = a + "|" + b + "|" + (c === s); }); r',
        ];
        yield 'set dedupes NaN' => [1, 'new Set([NaN, NaN]).size'];
        yield 'set dedupes objects by identity' => ['2', 'var k = {}; new Set([k, k, {}]).size + ""'];
        yield 'WeakMap aliases Map' => [true, 'new WeakMap() instanceof Map'];
        yield 'WeakSet aliases Set' => [true, 'new WeakSet() instanceof Set'];
    }

    #[DataProvider('mathCases')]
    #[DataProvider('collectionCases')]
    public function testCase(mixed $expected, string $source): void
    {
        $actual = $this->evalJs($source);
        if (is_float($expected) && is_nan($expected)) {
            $this->assertTrue(is_float($actual) && is_nan($actual), "expected NaN from: $source");
            return;
        }
        if (is_string($expected) && !is_string($actual)) {
            $actual = Conversions::toString(
                (new NodeHost(__DIR__ . '/fixtures', captureOutput: true))->vm(),
                $actual
            );
        }
        $this->assertSame($expected, $actual, $source);
    }

    public function testNativesWinOverThePolyfills(): void
    {
        // The polyfill file defines the same names with a define-if-absent
        // helper, so this asserts the ordering in NodeHost::installGlobals.
        // If it ever inverts, everything still works and 30% of a React 19
        // render quietly comes back.
        $this->assertSame('true', $this->evalString('typeof Map.prototype.get === "function" && !("_k" in new Map())'));
        $this->assertSame('true', $this->evalString(
            'Object.getOwnPropertyDescriptor(Map.prototype, "size").get !== undefined'
        ));
    }

    public function testCryptoStubProvidesRandomUuid(): void
    {
        $uuid = $this->evalString('require("crypto").randomUUID()');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-a[0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    }

    public function testCryptoStubRefusesRatherThanWeakens(): void
    {
        $this->assertSame('Error', $this->evalString(
            'var e; try { require("crypto").createHash("sha1"); } catch (err) { e = err.name; } e'
        ));
    }
}

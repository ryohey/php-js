<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every case runs the same program on bytecode and on generated PHP and
 * requires identical results. Cases are written so the interesting work
 * happens inside a transpiled function, not at program level (the module
 * wrapper is never converted).
 */
final class EmitterTest extends EquivalenceTestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function cases(): iterable
    {
        yield 'arithmetic' => [7, 'function f(a, b) { return a * b + 1; } f(2, 3)'];
        yield 'string concatenation' => ['ab', 'function f(a, b) { return a + b; } f("a", "b")'];
        yield 'mixed addition coerces' => ['1a', 'function f(a, b) { return a + b; } f(1, "a")'];
        yield 'division by zero is Infinity' => [INF, 'function f(a, b) { return a / b; } f(1, 0)'];
        yield 'modulo keeps the sign of the dividend' => [-1, 'function f() { return -7 % 3; } f()'];
        yield 'unary minus on zero gives -0' => ['-Infinity', 'function f() { return String(1 / -0); } f()'];
        yield 'bitwise wraps to int32' => [-1, 'function f() { return 0xffffffff | 0; } f()'];
        yield 'unsigned shift' => [2147483647, 'function f() { return -2 >>> 1; } f()'];
        yield 'bitwise not' => [-6, 'function f(x) { return ~x; } f(5)'];

        yield 'strict equality is not loose' => [false, 'function f() { return 1 === "1"; } f()'];
        // `===` against a string, boolean, null or undefined literal compiles
        // to PHP's own `===`. Against a *number* it must not: JS says
        // `1 === 1.0` and PHP does not, and this runtime really can hold both.
        yield 'int and float are the same number' => [true, 'function f(x) { return x === 1; } f(1.0)'];
        yield 'float literal against an int' => [true, 'function f(x) { return x === 1.0; } f(1)'];
        yield 'string identity' => [true, 'function f(x) { return x === "a"; } f("a")'];
        yield 'string against a number' => [false, 'function f(x) { return x === "1"; } f(1)'];
        yield 'undefined identity' => [true, 'function f(x) { return x === undefined; } f()'];
        yield 'null is not undefined' => [false, 'function f(x) { return x === undefined; } f(null)'];
        yield 'undefined is not null' => [false, 'function f(x) { return x === null; } f()'];
        yield 'boolean identity' => [false, 'function f(x) { return x === true; } f(1)'];
        yield 'object is never identical to a string' => [false, 'function f(x) { return x === "[object Object]"; } f({})'];
        yield 'loose equality coerces' => [true, 'function f() { return 1 == "1"; } f()'];
        yield 'NaN is not itself' => [false, 'function f(x) { return x === x; } f(NaN)'];
        yield 'comparison on strings' => [true, 'function f() { return "a" < "b"; } f()'];
        yield 'comparison with NaN is false both ways' => [
            'false,false',
            'function f(x) { return (x < 1) + "," + (x >= 1); } f(NaN)',
        ];

        yield 'missing argument is undefined' => ['undefined', 'function f(a) { return typeof a; } f()'];
        // The one name read that is not a ReferenceError.
        yield 'typeof an undeclared global' => ['undefined', 'function f() { return typeof nowhere; } f()'];
        yield 'typeof a declared global' => ['function', 'function f() { return typeof Array; } f()'];
        yield 'typeof a global assigned later' => [
            'undefined,number',
            'function f() { return typeof later; } var s = f(); later = 1; s + "," + f()',
        ];
        yield 'typeof a catch parameter' => [
            'object',
            'function f() { try { throw {}; } catch (e) { return typeof e; } } f()',
        ];
        yield 'typeof undefined' => ['undefined', 'function f() { return typeof undefined; } f()'];
        yield 'an explicit null argument stays null' => [true, 'function f(a) { return a === null; } f(null)'];
        yield 'extra arguments are ignored' => [1, 'function f(a) { return a; } f(1, 2, 3)'];

        yield 'if / else' => ['neg', 'function f(x) { if (x > 0) { return "pos"; } else { return "neg"; } } f(-1)'];
        yield 'while loop' => [10, 'function f(n) { var t = 0; while (n > 0) { t = t + n; n = n - 4; } return t; } f(7)'];
        yield 'do-while runs once' => [1, 'function f() { var n = 0; do { n = n + 1; } while (false); return n; } f()'];
        yield 'for loop' => [45, 'function f(n) { var t = 0; for (var i = 0; i < n; i++) { t = t + i; } return t; } f(10)'];
        yield 'break' => [3, 'function f() { var i; for (i = 0; i < 10; i = i + 1) { if (i === 3) { break; } } return i; } f()'];
        yield 'continue in a while loop' => [
            '1,3,5,7,9',
            'function f() { var out = [], i = 0; while (i < 10) { i = i + 1; if (i % 2 === 0) { continue; } out.push(i); } return out.join(","); } f()',
        ];
        yield 'nested loops' => [
            '00,01,10,11',
            'function f() { var out = []; for (var i = 0; i < 2; i++) { for (var j = 0; j < 2; j++) { out.push("" + i + j); } } return out.join(","); } f()',
        ];
        // `continue` in a do-while jumps to the *test*, not past it. The
        // generated loop puts the test at the end of the body, so a PHP
        // `continue` would skip it and the loop would never end.
        yield 'continue in a do-while still tests' => [
            '1,3,5,7,9',
            'function f() { var out = [], i = 0; do { i = i + 1; if (i % 2 === 0) { continue; } out.push(i); } while (i < 10); return out.join(","); } f()',
        ];

        yield 'labelled break leaves the outer loop' => [
            '00,01,10',
            'function f() { var out = []; outer: for (var i = 0; i < 3; i++) { for (var j = 0; j < 2; j++) { if (i === 1 && j === 1) { break outer; } out.push("" + i + j); } } return out.join(","); } f()',
        ];
        yield 'labelled continue skips the rest of the outer body' => [
            '00,10,20',
            'function f() { var out = []; outer: for (var i = 0; i < 3; i++) { for (var j = 0; j < 2; j++) { out.push("" + i + j); continue outer; } out.push("never"); } return out.join(","); } f()',
        ];
        // The outer `for` has an update, so its body is wrapped and `continue
        // outer` has to break that wrapper rather than the inner loop.
        yield 'labelled continue still runs the update' => [
            3,
            'function f() { outer: for (var i = 0; i < 3; i++) { while (true) { continue outer; } } return i; } f()',
        ];
        yield 'labelled continue on a while loop' => [
            '1,2,3',
            'function f() { var out = [], i = 0; outer: while (i < 3) { i = i + 1; for (var j = 0; j < 1; j++) { out.push(i); continue outer; } } return out.join(","); } f()',
        ];
        yield 'labelled continue on a do-while re-tests' => [
            '1,2,3',
            'function f() { var out = [], i = 0; outer: do { i = i + 1; for (var j = 0; j < 1; j++) { out.push(i); continue outer; } } while (i < 3); return out.join(","); } f()',
        ];
        yield 'labelled break out of a labelled block' => [
            'a',
            'function f(x) { var s = ""; done: { s = s + "a"; if (x) { break done; } s = s + "b"; } return s; } f(1)',
        ];
        yield 'a labelled block that is not broken out of runs on' => [
            'ab',
            'function f(x) { var s = ""; done: { s = s + "a"; if (x) { break done; } s = s + "b"; } return s; } f(0)',
        ];
        yield 'labelled break out of a switch' => [
            'x',
            'function f(v) { var s = ""; sw: switch (v) { case 1: s = "x"; break sw; case 2: s = "y"; } return s; } f(1)',
        ];
        yield 'labelled continue from inside a switch' => [
            '0,2',
            'function f() { var out = []; outer: for (var i = 0; i < 3; i++) { switch (i) { case 1: continue outer; default: out.push(i); } } return out.join(","); } f()',
        ];
        yield 'labelled break from inside a for-in' => [
            'a',
            'function f(o) { var s = ""; outer: for (var k in o) { for (var j = 0; j < 1; j++) { s = s + k; break outer; } } return s; } f({ a: 1, b: 2 })',
        ];
        yield 'labelled continue on a for-in' => [
            'ab',
            'function f(o) { var s = ""; outer: for (var k in o) { s = s + k; for (var j = 0; j < 1; j++) { continue outer; } s = s + "!"; } return s; } f({ a: 1, b: 2 })',
        ];
        yield 'inner label shadows an outer one of the same name' => [
            '0,1',
            'function f() { var out = []; L: for (var i = 0; i < 2; i++) { L: for (var j = 0; j < 3; j++) { if (j === 1) { break L; } } out.push(i); } return out.join(","); } f()',
        ];

        yield 'for-in over own properties' => [
            'a=1;b=2;',
            'function f(o) { var s = ""; for (var k in o) { s = s + k + "=" + o[k] + ";"; } return s; } f({ a: 1, b: 2 })',
        ];
        yield 'for-in walks the prototype chain' => [
            'own,inherited',
            'function A() {} A.prototype.inherited = 1;'
            . 'function f(o) { var ks = []; for (var k in o) { ks.push(k); } return ks.join(","); }'
            . 'var o = new A(); o.own = 1; f(o)',
        ];
        yield 'for-in skips a property deleted during iteration' => [
            'a',
            'function f(o) { var ks = []; for (var k in o) { ks.push(k); delete o.b; } return ks.join(","); } f({ a: 1, b: 2 })',
        ];
        yield 'for-in over a string wrapper' => [
            '0,1',
            'function f(s) { var ks = []; for (var k in s) { ks.push(k); } return ks.join(","); } f("ab")',
        ];

        yield 'object literal' => [3, 'function f(x) { return { a: x, b: x + 1 }.b; } f(2)'];
        yield 'object literal preserves key order' => [
            'z,a,m',
            'function f() { var o = { z: 1, a: 2, m: 3 }; var ks = []; for (var k in o) { ks.push(k); } return ks.join(","); } f()',
        ];
        yield 'array literal' => ['1,2,3', 'function f() { return [1, 2, 3].join(","); } f()'];
        yield 'array literal holes' => [3, 'function f() { return [1, , 3].length; } f()'];
        yield 'nested literals' => [2, 'function f() { return { a: [1, 2] }.a[1]; } f()'];

        yield 'property read' => [1, 'function f(o) { return o.a; } f({ a: 1 })'];
        yield 'computed property read' => [1, 'function f(o, k) { return o[k]; } f({ a: 1 }, "a")'];
        yield 'property write' => [5, 'function f(o) { o.a = 5; return o.a; } f({})'];
        yield 'property write hits a prototype setter' => [
            'set:9',
            'function A() {} Object.defineProperty(A.prototype, "v", { set: function (x) { this._v = "set:" + x; } });'
            . 'function f(o) { o.v = 9; return o._v; } f(new A())',
        ];
        yield 'property read hits a prototype getter' => [
            42,
            'function A() {} Object.defineProperty(A.prototype, "v", { get: function () { return 42; } });'
            . 'function f(o) { return o.v; } f(new A())',
        ];
        yield 'reading a property of null throws' => [
            'TypeError',
            'function f(o) { return o.x; } var r; try { f(null); } catch (e) { r = e.name; } r',
        ];
        yield 'delete' => [false, 'function f(o) { delete o.a; return "a" in o; } f({ a: 1 })'];
        yield 'in operator' => [true, 'function f(o) { return "a" in o; } f({ a: 1 })'];
        yield 'instanceof' => [true, 'function A() {} function f(o) { return o instanceof A; } f(new A())'];

        yield 'method call keeps the receiver' => [
            'ok',
            'function f(o) { return o.m(); } f({ tag: "ok", m: function () { return this.tag; } })',
        ];
        yield 'plain call gets undefined this in strict mode' => [
            'undefined',
            '"use strict"; function g() { return typeof this; } function f() { return g(); } f()',
        ];
        yield 'call with arguments' => [6, 'function g(a, b) { return a + b; } function f() { return g(2, 4); } f()'];
        yield 'new expression' => [7, 'function A(x) { this.x = x; } function f() { return new A(7).x; } f()'];
        yield 'calling a non-function throws' => [
            'TypeError',
            'function f(g) { return g(); } var r; try { f(1); } catch (e) { r = e.name; } r',
        ];

        yield 'arguments.length' => [3, 'function f() { return arguments.length; } f(1, 2, 3)'];
        yield 'indexed arguments' => [2, 'function f() { return arguments[1]; } f(1, 2, 3)'];
        yield 'arguments past the end is undefined' => [
            'undefined',
            'function f() { return typeof arguments[5]; } f(1)',
        ];

        yield 'logical and short-circuits' => [
            'no call',
            'function f(o) { return o && o.boom(); } var r = f(null); r === null ? "no call" : "called"',
        ];
        yield 'logical or short-circuits' => [1, 'function f(a, b) { return a || b; } f(1, 2)'];
        yield 'logical or falls through' => [2, 'function f(a, b) { return a || b; } f(0, 2)'];
        yield 'ternary' => ['y', 'function f(c) { return c ? "y" : "n"; } f(1)'];
        yield 'ternary runs only the taken branch' => [
            2,
            'function f(c, o) { return c ? o.a++ : o.a--; } var o = { a: 1 }; f(1, o); o.a',
        ];
        yield 'increment on a property' => [
            '1,2',
            'function f(o) { var was = o.a++; return was + "," + o.a; } f({ a: 1 })',
        ];
        yield 'not' => [true, 'function f(x) { return !x; } f(0)'];
        yield 'typeof' => ['object', 'function f(x) { return typeof x; } f(null)'];
        yield 'void' => ['undefined', 'function f(x) { return typeof void x; } f(1)'];
        yield 'sequence expression' => [3, 'function f() { var a = 0; var b = (a = 1, a + 2); return b; } f()'];

        yield 'compound assignment' => [5, 'function f(x) { x += 2; return x; } f(3)'];
        yield 'compound assignment on a property' => [5, 'function f(o) { o.a += 2; return o.a; } f({ a: 3 })'];
        yield 'postfix increment returns the old value' => [
            '3,4',
            'function f(x) { var was = x++; return was + "," + x; } f(3)',
        ];
        yield 'prefix increment returns the new value' => [
            '4,4',
            'function f(x) { var now = ++x; return now + "," + x; } f(3)',
        ];
        yield 'increment coerces a string' => [4, 'function f(x) { x++; return x; } f("3")'];
        yield 'increment past the safe integer range' => [
            9007199254740992,
            'function f(x) { x++; return x; } f(9007199254740991)',
        ];

        yield 'throw' => [
            'boom',
            'function f() { throw new Error("boom"); } var r; try { f(); } catch (e) { r = e.message; } r',
        ];
        // switch
        yield 'switch matches strictly' => ['num', 'function f(x) { switch (x) { case "1": return "str"; case 1: return "num"; } return "?"; } f(1)'];
        yield 'switch falls through' => ['12', 'function f(x) { var o = ""; switch (x) { case 1: o += "1"; case 2: o += "2"; break; case 3: o += "3"; } return o; } f(1)'];
        yield 'switch default in the middle' => ['d2', 'function f(x) { var o = ""; switch (x) { case 1: o += "1"; break; default: o += "d"; case 2: o += "2"; } return o; } f(9)'];
        yield 'switch with no match and no default' => ['none', 'function f(x) { var o = "none"; switch (x) { case 1: o = "1"; } return o; } f(5)'];
        // A matching case must stop the evaluation of later case expressions.
        yield 'switch stops evaluating tests once one matches' => [
            'a,1',
            'function f() { var log = []; function t(v) { log.push(v); return v; }'
            . ' switch ("a") { case t("a"): break; case t("b"): break; } return log.join(",") + "," + log.length; } f()',
        ];
        yield 'switch evaluates every test when none matches' => [
            'a,b,2',
            'function f() { var log = []; function t(v) { log.push(v); return v; }'
            . ' switch (1) { case t("a"): break; case t("b"): break; } return log.join(",") + "," + log.length; } f()',
        ];
        yield 'continue inside a switch inside a for' => [
            'd.2.d.',
            'function f(n) { var o = ""; for (var i = 0; i < n; i++) { switch (i) { case 1: continue; case 2: o += "2"; break; default: o += "d"; } o += "."; } return o; } f(4)',
        ];
        yield 'break inside a loop inside a switch' => [
            '0',
            'function f(x) { var o = ""; switch (x) { case 1: for (var i = 0; i < 3; i++) { if (i === 1) { break; } o += i; } break; } return o; } f(1)',
        ];
        yield 'switch on a string' => ['b', 'function f(x) { switch (x) { case "a": return "a"; case "b": return "b"; } } f("b")'];

        // try / catch / finally
        yield 'catch receives the thrown value' => [
            'boom',
            'function f() { try { throw new Error("boom"); } catch (e) { return e.message; } } f()',
        ];
        yield 'finally runs after catch' => [
            'tcf',
            'function f() { var o = ""; try { o += "t"; throw 1; } catch (e) { o += "c"; } finally { o += "f"; } return o; } f()',
        ];
        yield 'finally runs without a catch' => [
            'tf',
            'function f() { var o = ""; try { o += "t"; } finally { o += "f"; } return o; } f()',
        ];
        yield 'finally runs before a return leaves' => [
            'r:f',
            'function f(log) { try { return "r"; } finally { log.push("f"); } } var l = []; f(l) + ":" + l.join("")',
        ];
        yield 'a rethrow reaches the outer catch' => [
            'ab',
            'function f() { try { try { throw "a"; } catch (e) { throw e + "b"; } } catch (e2) { return e2; } } f()',
        ];
        yield 'the catch parameter shadows an outer name' => [
            'inner,outer',
            'var e = "outer"; function f() { var got; try { throw "inner"; } catch (e) { got = e; } return got + "," + e; } f()',
        ];
        yield 'a caught native error is a JS error' => [
            'TypeError',
            'function f() { try { return null.x; } catch (e) { return e.name; } } f()',
        ];
        yield 'try with no throw skips catch' => [
            'ok',
            'function f() { try { return "ok"; } catch (e) { return "caught"; } } f()',
        ];

        yield 'closure over a module binding' => [
            10,
            'var base = 7; function f(x) { return base + x; } f(3)',
        ];
        yield 'writing a module binding from inside' => [
            9,
            'var acc = 0; function f(x) { acc = acc + x; return acc; } f(4); f(5)',
        ];
    }

    #[DataProvider('cases')]
    public function testCase(mixed $expected, string $source): void
    {
        $this->assertSameBothWays($expected, $source);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusalCases(): iterable
    {
        yield 'nested function' => ['nested function', 'function f() { function g() { return 1; } return g(); }'];
        yield 'closure over own local' => ['environment record', 'function f() { var a = 1; return function () { return a; }; }'];
        yield 'regexp literal' => ['RegExpLiteral', 'function f(s) { return /x/.test(s); }'];
        yield 'getter in an object literal' => ['accessor property', 'function f() { return { get a() { return 1; } }; }'];
        yield 'with-style dynamic scope' => ['statement not supported', 'function f(o) { debugger; return o; }'];
    }

    /** The emitter must refuse cleanly, leaving the function on bytecode. */
    #[DataProvider('refusalCases')]
    public function testRefusesCleanly(string $expectedReason, string $source): void
    {
        $artifact = \PhpJs\Transpile\Artifact::build($source, 'refuse' . hash('xxh128', $source));
        $this->assertGreaterThan(0, $artifact->seen, 'no function was even considered');
        $reasons = implode(' | ', array_column($artifact->refused, 'reason'));
        $this->assertStringContainsString($expectedReason, $reasons);
    }
}

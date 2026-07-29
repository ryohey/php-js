<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Semantics of the fused opcodes and of the prototype-walk fast path.
 *
 * Both optimizations work by recognising a common shape and skipping the
 * general code, so what matters is the cases that look common but are not:
 * a property that holds null, an exotic object whose own properties are not
 * in $props, a comparison against NaN.
 */
final class SuperinstructionTest extends EvalTestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function fusedCases(): iterable
    {
        // STORE_LOCAL (SET_LOCAL + POP)
        yield 'assignment statement stores' => [7, 'function f() { var x; x = 7; return x; } f()'];
        yield 'assignment expression still yields a value' => [
            '5 5',
            'function f() { var x, y; y = (x = 5); return x + " " + y; } f()',
        ];
        yield 'chained assignment' => [3, 'function f() { var a, b, c; a = b = c = 3; return a + b + c - 6; } f()'];
        yield 'assignment in a loop body' => [45, 'function f() { var t = 0, i; for (i = 0; i < 10; i++) { t = t + i; } return t; } f()'];

        // GET_LOCAL_PROP (GET_LOCAL + GET_PROP)
        yield 'own property through a local' => [1, 'function f() { var o = { x: 1 }; return o.x; } f()'];
        yield 'inherited property through a local' => [
            9,
            'function A() {} A.prototype.x = 9; function f() { var o = new A(); return o.x; } f()',
        ];
        yield 'missing property through a local' => [true, 'function f() { var o = {}; return o.x === undefined; } f()'];
        yield 'array length through a local' => [3, 'function f() { var a = [1, 2, 3]; return a.length; } f()'];
        yield 'string length through a local' => [3, 'function f() { var s = "abc"; return s.length; } f()'];
        yield 'getter through a local' => [
            42,
            'function f() { var o = {}; Object.defineProperty(o, "v", { get: function () { return 42; } }); return o.v; } f()',
        ];
        yield 'property of null throws' => [
            'TypeError',
            'function f() { var o = null; try { return o.x; } catch (e) { return e.name; } } f()',
        ];

        // TYPEOF_LOCAL (GET_LOCAL + TYPEOF)
        yield 'typeof an undefined local' => ['undefined', 'function f() { var x; return typeof x; } f()'];
        yield 'typeof a function local' => ['function', 'function f() { var x = f; return typeof x; } f()'];
        yield 'typeof a null local' => ['object', 'function f() { var x = null; return typeof x; } f()'];

        // JSEQ / JSNEQ (SEQ + JT/JF)
        yield 'strict compare branches true' => ['yes', 'function f(a) { if (a === 1) { return "yes"; } return "no"; } f(1)'];
        yield 'strict compare rejects a coercible value' => ['no', 'function f(a) { if (a === 1) { return "yes"; } return "no"; } f("1")'];
        yield 'NaN is never strictly equal' => ['no', 'function f(a) { if (a === a) { return "yes"; } return "no"; } f(NaN)'];
        yield 'negative zero equals zero' => ['yes', 'function f(a) { if (a === 0) { return "yes"; } return "no"; } f(-0)'];
        yield 'undefined is not null' => ['no', 'function f(a) { if (a === null) { return "yes"; } return "no"; } f(undefined)'];
        yield 'int and float compare by value' => ['yes', 'function f(a) { if (a === 1) { return "yes"; } return "no"; } f(1.0)'];
        yield 'strict compare in a while condition' => [
            4,
            'function f() { var i = 0; while (i !== 4) { i++; } return i; } f()',
        ];
        yield 'strict compare result kept as a value' => [
            'true false',
            'function f() { return (1 === 1) + " " + (1 === 2); } f()',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function prototypeWalkCases(): iterable
    {
        yield 'a null-valued prototype property is found' => [
            true,
            'function A() {} A.prototype.x = null; var o = new A(); o.x === null',
        ];
        yield 'a null-valued own property is found' => [true, 'var o = { x: null }; o.x === null'];
        yield 'own property shadows the prototype' => [
            'own',
            'function A() {} A.prototype.x = "proto"; var o = new A(); o.x = "own"; o.x',
        ];
        yield 'accessor on a prototype still runs' => [
            5,
            'function A() {} Object.defineProperty(A.prototype, "v", { get: function () { return 5; } });'
            . 'var o = new A(); o.v',
        ];
        yield 'accessor shadowing a data property up the chain' => [
            'getter',
            'function A() {} A.prototype.v = "data"; function B() {} B.prototype = Object.create(A.prototype);'
            . 'Object.defineProperty(B.prototype, "v", { get: function () { return "getter"; } });'
            . 'var o = new B(); o.v',
        ];
        yield 'three levels deep' => [
            'deep',
            'function A() {} A.prototype.x = "deep"; function B() {} B.prototype = new A();'
            . 'function C() {} C.prototype = new B(); (new C()).x',
        ];
        yield 'lazy function prototype is materialized' => [true, 'function A() {} typeof A.prototype === "object"'];
        yield 'lazy function length is materialized' => [2, 'function A(a, b) {} A.length'];

        // Exotic own properties: these must never take the plain-$props path.
        yield 'array index inherited through the chain' => [20, 'var o = Object.create([10, 20]); o[1]'];
        yield 'array length inherited through the chain' => [2, 'var o = Object.create([10, 20]); o.length'];
        yield 'string wrapper index' => ['b', 'var s = new String("abc"); s[1]'];
        yield 'string wrapper length' => [3, 'var s = new String("abc"); s.length'];
        yield 'string wrapper index through the chain' => ['c', 'var o = Object.create(new String("abc")); o[2]'];
        yield 'mapped arguments follow a parameter assignment' => [
            2,
            'function f(a) { a = 2; return arguments[0]; } f(1)',
        ];
        yield 'a parameter follows a mapped arguments assignment' => [
            3,
            'function f(a) { arguments[0] = 3; return a; } f(1)',
        ];
        yield 'strict arguments are unmapped' => [
            1,
            '"use strict"; function f(a) { a = 2; return arguments[0]; } f(1)',
        ];
    }

    #[DataProvider('fusedCases')]
    #[DataProvider('prototypeWalkCases')]
    public function testCase(mixed $expected, string $source): void
    {
        $this->assertJs($expected, $source);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unoptimizedComparisonCases(): iterable
    {
        // Stack traces: the pass renumbers the line table, and a wrong entry
        // misreports where an error came from without failing anything else.
        yield 'stack trace through several assignments' => [
            "function a() {\n    var x;\n    x = 1;\n    x = 2;\n    null.boom;\n}\n"
            . "var seen;\ntry { a(); } catch (e) { seen = e.stack; }\nseen;\n",
        ];
        yield 'stack trace from a nested call' => [
            "function inner() {\n    var q = 1;\n    q.z.y;\n}\nfunction outer() {\n    var p;\n    p = inner();\n    return p;\n}\n"
            . "var seen;\ntry { outer(); } catch (e) { seen = e.stack; }\nseen;\n",
        ];
        yield 'stack trace out of a loop body' => [
            "function a(n) {\n    var t = 0;\n    for (var i = 0; i < n; i++) {\n        t = t + i;\n        if (i === 2) { undefined.x; }\n    }\n    return t;\n}\n"
            . "var seen;\ntry { a(5); } catch (e) { seen = e.stack; }\nseen;\n",
        ];
        yield 'labelled break out of a try' => [
            'function f(x) { a: try { if (x) { break a; } return "no-break"; } finally { } return "after"; } '
            . 'f(1) + "/" + f(0)',
        ];
        yield 'switch on strict equality' => [
            'function f(v) { switch (v) { case 1: return "one"; case "1": return "str"; default: return "other"; } } '
            . 'f(1) + "/" + f("1") + "/" + f(2)',
        ];
        yield 'nested try/catch/finally with assignments' => [
            'function f() { var log = ""; try { log = log + "t"; throw 1; } catch (e) { log = log + "c"; } '
            . 'finally { log = log + "f"; } return log; } f()',
        ];
        yield 'continue inside a labelled loop' => [
            'function f() { var out = ""; outer: for (var i = 0; i < 3; i++) { for (var j = 0; j < 3; j++) { '
            . 'if (j === 1) { continue outer; } out = out + i + j; } } return out; } f()',
        ];
    }

    /**
     * The pass is supposed to be unobservable, so the strongest check is to
     * run the same program with it off and compare.
     */
    #[DataProvider('unoptimizedComparisonCases')]
    public function testMatchesUnoptimizedBytecode(string $source): void
    {
        $optimized = $this->evalJs($source);
        \PhpJs\Compiler\Peephole::$enabled = false;
        try {
            $plain = $this->evalJs($source);
        } finally {
            \PhpJs\Compiler\Peephole::$enabled = true;
        }
        $this->assertSame($plain, $optimized);
        $this->assertNotSame('', (string)$optimized, 'the case produced no output to compare');
    }
}

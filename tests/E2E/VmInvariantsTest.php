<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Guards two dispatch-loop optimizations.
 *
 * The loop keeps the stack pointer in a local and publishes it to the VM only
 * from opcodes that can re-enter (a getter, a valueOf, a native call). If a
 * re-entry path is ever missed, the new frame overlaps live stack slots and
 * values are silently corrupted — so these cases force re-entry from an
 * operator slow path with operands still live around it.
 *
 * The fused INC_LOCAL/DEC_LOCAL replaces the generic ++/-- sequence wherever
 * the result is discarded, which must not change what the counter holds.
 */
final class VmInvariantsTest extends EvalTestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function reentryCases(): iterable
    {
        yield 'valueOf during addition keeps surrounding operands' => [
            '1|2|52|4|5',
            'var o = { valueOf: function () { return g(); } };'
            . 'function g() { var t = 0; for (var i = 0; i < 5; i++) { t += i; } return 42 + t; }'
            . 'function f() { var a = 1, b = 2, d = 4, e = 5;'
            . '  return a + "|" + b + "|" + (0 + o) + "|" + d + "|" + e; }'
            . 'f()',
        ];
        yield 'getter re-entry inside an expression' => [
            30,
            'var o = {}; Object.defineProperty(o, "v", { get: function () { return sum(4); } });'
            . 'function sum(n) { var t = 0; for (var i = 0; i <= n; i++) { t += i; } return t; }'
            . 'function outer() { var a = 1, b = 2, c = 3; return a + b + c + o.v + o.v + 4; }'
            . 'outer()',
        ];
        yield 'toString re-entry during string concatenation' => [
            'a-inner-b',
            'var o = { toString: function () { return build(); } };'
            . 'function build() { var parts = []; for (var i = 0; i < 1; i++) { parts.push("inner"); } return parts.join(""); }'
            . 'function outer() { var left = "a-", right = "-b"; return left + o + right; }'
            . 'outer()',
        ];
        yield 'valueOf re-entry inside a comparison' => [
            true,
            'var o = { valueOf: function () { return deep(3); } };'
            . 'function deep(n) { return n === 0 ? 5 : deep(n - 1); }'
            . 'function outer() { var lo = 1, hi = 9; return lo < o && o < hi; }'
            . 'outer()',
        ];
        yield 'valueOf re-entry inside a bitwise operator' => [
            6,
            'var o = { valueOf: function () { return countTo(2); } };'
            . 'function countTo(n) { var t = 0; for (var i = 0; i < n; i++) { t += 1; } return t; }'
            . 'function outer() { var mask = 4; return (mask | o) + 0; }'
            . 'outer()',
        ];
        yield 'native call re-entry with a deep expression stack' => [
            '1|2|3|9',
            'function outer() {'
            . ' var a = 1, b = 2, c = 3;'
            . ' return [a, b, c, [4, 5].reduce(function (x, y) { return x + y; })].join("|"); }'
            . 'outer()',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function updateCases(): iterable
    {
        yield 'for-update on a local' => [10, 'function f() { var n = 0; for (var i = 0; i < 10; i++) { n++; } return n; } f()'];
        yield 'decrement on a local' => [-5, 'function f() { var n = 0; for (var i = 0; i < 5; i++) { n--; } return n; } f()'];
        yield 'update on a string local coerces' => [6, 'function f() { var s = "5"; s++; return s; } f()'];
        yield 'update on undefined is NaN' => [NAN, 'function f() { var u; u++; return u; } f()'];
        yield 'postfix value is still usable when kept' => ['5,6', 'function f() { var n = 5; var was = n++; return was + "," + n; } f()'];
        yield 'prefix value is still usable when kept' => ['6,6', 'function f() { var n = 5; var now = ++n; return now + "," + n; } f()'];
        yield 'update on a captured local' => [3, 'function f() { var n = 0; function bump() { n++; } bump(); bump(); bump(); return n; } f()'];
        yield 'update past the int range promotes' => [9007199254740992.0, 'function f() { var n = 9007199254740991; n++; return n; } f()'];
        yield 'update on a program-level var' => [4, 'var g = 0; function f() { g++; } f(); f(); f(); f(); g'];
    }

    #[DataProvider('reentryCases')]
    #[DataProvider('updateCases')]
    public function testCase(mixed $expected, string $source): void
    {
        $this->assertJs($expected, $source);
    }

    public function testDeepRecursionKeepsFramesDisjoint(): void
    {
        // Every frame holds live locals while the next one runs; an sp that
        // lags would let the callee scribble over the caller's slots.
        $this->assertJs(
            4950,
            'function walk(n) { var mine = n; if (n === 0) { return 0; } var rest = walk(n - 1); return mine + rest; }'
            . 'walk(99)'
        );
    }
}

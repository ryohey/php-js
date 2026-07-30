<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PhpJs\Compiler\CompileError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec-semantics regressions found by running test262. Each case here maps to
 * a defect that a naive implementation gets wrong.
 */
final class SemanticsTest extends EvalTestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function propertyDescriptorCases(): iterable
    {
        yield 'partial descriptor defaults to false on create' => [
            'false,false,false',
            'var o={}; Object.defineProperty(o,"x",{value:1});'
            . ' var d=Object.getOwnPropertyDescriptor(o,"x"); [d.writable,d.enumerable,d.configurable].join()',
        ];
        yield 'partial descriptor keeps attributes on update' => [
            '2,true,true',
            'var o={x:1}; Object.defineProperty(o,"x",{value:2});'
            . ' var d=Object.getOwnPropertyDescriptor(o,"x"); [d.value,d.writable,d.enumerable].join()',
        ];
        yield 'redefining non-configurable throws' => [
            'TypeError',
            'var o={}; Object.defineProperty(o,"x",{value:1});'
            . ' try { Object.defineProperty(o,"x",{value:2}); "none" } catch(e) { e.constructor.name }',
        ];
        yield 'redefining non-configurable with same value is allowed' => [
            'ok',
            'var o={}; Object.defineProperty(o,"x",{value:1}); Object.defineProperty(o,"x",{value:1}); "ok"',
        ];
        yield 'accessor and value together throw' => [
            'TypeError',
            'try { Object.defineProperty({},"x",{get:function(){},value:1}); "none" } catch(e) { e.constructor.name }',
        ];
        yield 'array index has a descriptor' => [
            '1,true,true,true',
            'var d=Object.getOwnPropertyDescriptor([1,2],"0"); [d.value,d.writable,d.enumerable,d.configurable].join()',
        ];
        yield 'array length descriptor' => [
            '2,true,false,false',
            'var d=Object.getOwnPropertyDescriptor([1,2],"length");'
            . ' [d.value,d.writable,d.enumerable,d.configurable].join()',
        ];
        yield 'freeze locks an array' => [
            '1,2|true',
            'var a=[1,2]; Object.freeze(a); a[0]=9; a.length=0; a.join(",")+"|"+Object.isFrozen(a)',
        ];
        yield 'non-writable length rejects push' => [
            'TypeError',
            'var a=[1]; Object.defineProperty(a,"length",{writable:false});'
            . ' try { a.push(2); "none" } catch(e) { e.constructor.name }',
        ];
        yield 'truncation stops at non-configurable element' => [
            2,
            'var a=[1,2,3]; Object.defineProperty(a,"1",{configurable:false}); a.length=0; a.length',
        ];
        yield 'index accessor works' => [
            '42,1',
            'var a=[]; Object.defineProperty(a,"0",{get:function(){return 42;},enumerable:true,configurable:true});'
            . ' a[0]+","+a.length',
        ];
        yield 'deleted builtin global stays deleted' => [
            'undefined',
            'delete this.Boolean; typeof Boolean',
        ];
        yield 'prototype constructor exists without touching the global' => [
            true,
            'var a=[]; a.constructor === Array',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function enumerationOrderCases(): iterable
    {
        yield 'indices first, ascending' => [
            '0,2,z,a',
            'var o={}; o.z=1; o[2]=2; o.a=3; o[0]=4; Object.keys(o).join(",")',
        ];
        yield 'accessors keep creation position' => [
            'b,a,c',
            'var o={}; o.b=1; Object.defineProperty(o,"a",{get:function(){return 2;},enumerable:true});'
            . ' o.c=3; Object.keys(o).join(",")',
        ];
        yield 'for-in follows the same order' => [
            'ba',
            'var o={}; o.b=1; Object.defineProperty(o,"a",{get:function(){return 2;},enumerable:true});'
            . ' var s=""; for (var k in o) s+=k; s',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function numberCases(): iterable
    {
        yield 'integer literals past 2^53 keep the double they denote' => [
            '1000000000000000100',
            '(1000000000000000128).toString()',
        ];
        yield 'ToNumber(String) past 2^53 too' => [
            '1000000000000000100',
            'Number("1000000000000000128").toString()',
        ];
        yield 'JSON numbers past 2^53 too' => [
            '1000000000000000100',
            'JSON.parse("1000000000000000128").toString()',
        ];
        // Symbol is a primitive type, which no polyfill can be: `typeof` is the
        // whole point. React's renderer tests `typeof type === "string"` before
        // it identity-compares against its element brands, so a symbol that is
        // really a string makes `<></>` fail with "Invalid tag".
        yield 'typeof a symbol' => ['symbol', 'typeof Symbol("x")'];
        yield 'symbols are unique' => [false, 'Symbol("a") === Symbol("a")'];
        yield 'the registry returns one symbol' => [true, 'Symbol.for("a") === Symbol.for("a")'];
        yield 'a registry symbol is not a fresh one' => [false, 'Symbol.for("a") === Symbol("a")'];
        yield 'keyFor on a registered symbol' => ['a', 'Symbol.keyFor(Symbol.for("a"))'];
        yield 'keyFor on an unregistered symbol' => ['undefined', 'String(Symbol.keyFor(Symbol("a")))'];
        yield 'a symbol is truthy' => [true, 'Symbol("") ? true : false'];
        yield 'Symbol is not a constructor' => [
            'TypeError',
            'try { new Symbol("x"); "none" } catch (e) { e.constructor.name }',
        ];
        // Implicit ToString is a TypeError on purpose; String() is the way out.
        yield 'a symbol will not concatenate' => [
            'TypeError',
            'try { "" + Symbol("x"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'String() describes a symbol' => ['Symbol(hi)', 'String(Symbol("hi"))'];
        yield 'toString describes a symbol' => ['Symbol(hi)', 'Symbol("hi").toString()'];
        yield 'description' => ['hi', 'Symbol("hi").description'];
        yield 'a symbol will not become a number' => [
            'TypeError',
            'try { +Symbol("x"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'class of a symbol' => ['[object Symbol]', 'Object.prototype.toString.call(Symbol("x"))'];
        // Symbol-keyed properties live in the same string-keyed table under a
        // private key, and every enumeration filters them out (see JSSymbol).
        yield 'a symbol key round-trips' => [
            2,
            'var s = Symbol("k"), o = {}; o[s] = 2; o[s]',
        ];
        yield 'two symbols with one description are two keys' => [
            '1,2',
            'var a = Symbol("k"), b = Symbol("k"), o = {}; o[a] = 1; o[b] = 2; o[a] + "," + o[b]',
        ];
        yield 'a symbol key is invisible to Object.keys' => [
            '["a"]',
            'var s = Symbol("k"), o = {a: 1}; o[s] = 2; JSON.stringify(Object.keys(o))',
        ];
        yield 'a symbol key is invisible to getOwnPropertyNames' => [
            '["a"]',
            'var s = Symbol("k"), o = {a: 1}; o[s] = 2; JSON.stringify(Object.getOwnPropertyNames(o))',
        ];
        yield 'a symbol key is invisible to for-in' => [
            'a',
            'var s = Symbol("k"), o = {a: 1}; o[s] = 2; var out = []; for (var k in o) out.push(k); out.join(",")',
        ];
        yield 'a symbol-keyed value is invisible to JSON' => [
            '{"a":1}',
            'var s = Symbol("k"), o = {a: 1}; o[s] = 2; JSON.stringify(o)',
        ];
        yield 'getOwnPropertySymbols finds it' => [
            true,
            'var s = Symbol("k"), o = {}; o[s] = 1; Object.getOwnPropertySymbols(o)[0] === s',
        ];
        yield 'in works with a symbol' => [
            true,
            'var s = Symbol("k"), o = {}; o[s] = 1; s in o',
        ];
        yield 'hasOwnProperty works with a symbol' => [
            true,
            'var s = Symbol("k"), o = {}; o[s] = 1; o.hasOwnProperty(s)',
        ];
        yield 'delete works with a symbol' => [
            0,
            'var s = Symbol("k"), o = {}; o[s] = 1; delete o[s]; Object.getOwnPropertySymbols(o).length',
        ];
        yield 'defineProperty works with a symbol' => [
            '7,0',
            'var s = Symbol("k"), o = {};'
                . ' Object.defineProperty(o, s, {value: 7});'
                . ' o[s] + "," + Object.keys(o).length',
        ];
        yield 'a well-known symbol is a symbol' => ['symbol', 'typeof Symbol.iterator'];
        // It exists so feature detection works; this is an ES5.1 realm and there
        // is no iteration protocol behind it, same as @@species (DESIGN.md §15).
        yield 'a well-known symbol is stable' => [true, 'Symbol.iterator === Symbol.iterator'];
        yield 'arrays have no iterator' => ['undefined', 'typeof [][Symbol.iterator]'];

        yield 'toFixed stays exact' => ['1000000000000000128', '(1000000000000000128).toFixed(0)'];
        // 15.7.4.5 takes |x| first, then picks the *larger* n on a tie: ties go
        // away from zero, not to even. PHP's own sprintf('%F') rounds to even,
        // and every one of these came out a digit low while it was used.
        yield 'toFixed rounds an exact tie away from zero' => ['0.63', '(0.625).toFixed(2)'];
        yield 'toFixed ties on a negative use the magnitude' => ['-0.63', '(-0.625).toFixed(2)'];
        yield 'toFixed does not round to even' => ['3', '(2.5).toFixed(0)'];
        yield 'toFixed rounds 0.5 up' => ['1', '(0.5).toFixed(0)'];
        yield 'toFixed carries through nines' => ['10.00', '(9.999).toFixed(2)'];
        yield 'toFixed carries into a new digit' => ['1.00', '(0.999).toFixed(2)'];
        // 9.995 is *below* the tie -- the nearest double is 9.99499999999999921...
        yield 'toFixed does not round up a near-tie' => ['9.99', '(9.995).toFixed(2)'];
        // 1.005 is not a tie: the nearest double is 1.00499999999999989...,
        // so rounding the *exact* value is what makes this "1.00".
        yield 'toFixed rounds the double, not the literal' => ['1.00', '(1.005).toFixed(2)'];
        // Past 53 fraction digits sprintf() padded with zeros; a double's
        // expansion is exact and finite, so these digits are real.
        yield 'toFixed keeps digits past sprintf precision' => [
            '0.0000000000000000000008470329472543003390683225006796419620513916015625',
            'Math.pow(2, -70).toFixed(70)',
        ];
        yield 'toFixed of the smallest subnormal' => [
            '0.0000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000',
            'Math.pow(2, -1074).toFixed(100)',
        ];
        yield 'toFixed of negative zero has no sign' => ['0.00', '(-0).toFixed(2)'];
        yield 'toFixed of a small negative keeps the sign' => ['-0.00', '(-0.0001).toFixed(2)'];
        yield 'Number("-0") is -0' => [-INF, '1/Number("-0")'];
        yield 'toExponential without argument' => ['1.23456e+2', '(123.456).toExponential()'];
        yield 'toExponential rounds ties away from zero' => ['3e+1', '(25).toExponential(0)'];
        yield 'toExponential of zero' => ['0.00e+0', '(0).toExponential(2)'];
        yield 'Infinity beats the range check' => ['Infinity', 'Infinity.toExponential(500)'];
        yield 'toFixed range-checks before casting' => [
            'RangeError',
            'try { (1).toFixed(Infinity); "none" } catch(e) { e.constructor.name }',
        ];
        yield 'toPrecision fixed notation' => ['123.5', '(123.456).toPrecision(4)'];
        yield 'toPrecision exponential notation' => ['1.2e+2', '(123).toPrecision(2)'];
        yield 'Number.prototype rejects a foreign wrapper' => [
            'TypeError',
            'var d=new Date(0); d.toString = Number.prototype.toString;'
            . ' try { d.toString(); "none" } catch(e) { e.constructor.name }',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function jsonCases(): iterable
    {
        yield 'reviver rewrites values' => [
            '10|20,30',
            'var r=JSON.parse(\'{"a":1,"b":[2,3]}\', function(k,v){ return typeof v === "number" ? v*10 : v; });'
            . ' r.a + "|" + r.b.join(",")',
        ];
        yield 'reviver dropping a value deletes it' => [
            false,
            'var r=JSON.parse(\'{"a":1,"b":2}\', function(k,v){ return k === "b" ? undefined : v; }); "b" in r',
        ];
        yield 'array replacer sets key order' => [
            '{"a":2,"b":1}',
            'JSON.stringify({b:1, a:2}, ["a","b"])',
        ];
        yield 'Number object as space' => [
            "{\n   \"a\": 1\n}",
            'JSON.stringify({a:1}, null, new Number(3))',
        ];
        yield 'replacer sees the top-level wrapper' => [
            'object',
            'var t; JSON.stringify({a:1}, function(k,v){ if (k === "") t = typeof this; return v; }); t',
        ];
        yield 'lone surrogates are escaped' => [
            '"\\ud834"',
            'JSON.stringify(String.fromCharCode(0xD834))',
        ];
        yield 'circular structures throw' => [
            'TypeError',
            'var o={}; o.self=o; try { JSON.stringify(o); "none" } catch(e) { e.constructor.name }',
        ];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function completionValueCases(): iterable
    {
        yield 'zero-iteration loop yields undefined' => [true, 'eval("var a; 1; for (a in {}) {}") === undefined'];
        yield 'untaken if yields undefined' => [true, 'eval("2; if (false) {}") === undefined'];
        yield 'loop body value wins' => [5, 'eval("1; for (var i=0;i<2;i++) { 5; }")'];
        yield 'expression statement value' => [3, 'eval("1; 3;")'];
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function genericArrayCases(): iterable
    {
        yield 'reverse on an array-like' => [
            'cba',
            'var o={0:"a",1:"b",2:"c",length:3}; Array.prototype.reverse.call(o); [o[0],o[1],o[2]].join("")',
        ];
        yield 'splice on an array-like' => [
            '4:aXYc|b',
            'var o={0:"a",1:"b",2:"c",length:3}; var r=Array.prototype.splice.call(o,1,1,"X","Y");'
            . ' o.length+":"+[o[0],o[1],o[2],o[3]].join("")+"|"+r.join("")',
        ];
        yield 'sort on an array-like' => [
            '123',
            'var o={0:3,1:1,2:2,length:3}; Array.prototype.sort.call(o); [o[0],o[1],o[2]].join("")',
        ];
        yield 'sort orders values, then undefined, then holes' => [
            '1,2,3,,',
            '[3,undefined,1,,2].sort().join(",")',
        ];
        yield 'sort rejects a non-callable comparator' => [
            'TypeError',
            'try { [1,2].sort("x"); "none" } catch(e) { e.constructor.name }',
        ];
    }

    #[DataProvider('propertyDescriptorCases')]
    #[DataProvider('enumerationOrderCases')]
    #[DataProvider('numberCases')]
    #[DataProvider('jsonCases')]
    #[DataProvider('completionValueCases')]
    #[DataProvider('genericArrayCases')]
    public function testCase(mixed $expected, string $source): void
    {
        $this->assertJs($expected, $source);
    }

    /** @return iterable<string, array{0: string}> */
    public static function strictEarlyErrorCases(): iterable
    {
        yield 'var eval' => ['"use strict"; var eval = 1;'];
        yield 'var arguments' => ['"use strict"; var arguments = 1;'];
        yield 'function named eval' => ['"use strict"; function eval() {}'];
        yield 'parameter named arguments' => ['"use strict"; function f(arguments) {}'];
        yield 'duplicate parameters' => ['"use strict"; function f(a, a) {}'];
        yield 'assignment to eval' => ['"use strict"; eval = 1;'];
        yield 'increment of arguments' => ['"use strict"; arguments++;'];
        yield 'catch parameter named eval' => ['"use strict"; try {} catch (eval) {}'];
        yield 'function declaration as a loop body' => ['while (false) label: function f() {}'];
        yield 'function declaration as an if body' => ['if (true) function f() {}'];
    }

    #[DataProvider('strictEarlyErrorCases')]
    public function testStrictEarlyError(string $source): void
    {
        $this->expectException(CompileError::class);
        $this->evalJs($source);
    }

    public function testDuplicateParametersAllowedOutsideStrictMode(): void
    {
        $this->assertJs(2, 'function f(a, a) { return a; } f(1, 2)');
    }

    public function testTimeLimitAbortsRunawayLoop(): void
    {
        $engine = new \PhpJs\Engine();
        $engine->vm->setTimeLimit(0.5);
        $started = microtime(true);
        try {
            $engine->evaluate('while (true) {}');
            $this->fail('expected the time limit to abort execution');
        } catch (\PhpJs\JSException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertLessThan(5.0, microtime(true) - $started);
    }
}

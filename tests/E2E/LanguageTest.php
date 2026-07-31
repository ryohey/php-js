<?php

declare(strict_types=1);

namespace PhpJs\Tests\E2E;

use PhpJs\Compiler\CompileError;
use PhpJs\JSException;
use PHPUnit\Framework\Attributes\DataProvider;

final class LanguageTest extends EvalTestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function cases(): iterable
    {
        // --- arithmetic & numbers ---
        yield 'add ints' => [3, '1+2'];
        yield 'int overflow promotes' => [1.8446744073709552e19, '9223372036854775807 + 9223372036854775807'];
        yield 'division is exact' => [2.5, '5/2'];
        yield 'division by zero' => [INF, '1/0'];
        yield 'modulo negative dividend' => [-1, '-5 % 2'];
        yield 'fmod' => [0.5, '5.5 % 2.5'];
        yield 'unary minus zero is -0' => [-INF, '1/(-0)'];
        yield 'string minus number' => [7, '"10" - 3'];
        yield 'plus concatenates' => ['103', '"10" + 3'];
        yield 'NaN not equal to itself' => [false, 'NaN === NaN'];
        yield 'shift' => [20, '5 << 2'];
        yield 'ushr negative' => [4294967295, '-1 >>> 0'];
        yield 'sar' => [-1, '-1 >> 1'];
        yield 'bitand tonum' => [4, '"12" & 6'];
        yield 'exponent str' => ['100000000000000000000', '(1e20).toString()'];
        yield 'exponent str 21' => ['1e+21', '(1e21).toString()'];
        yield 'small float str' => ['0.000001', '(1e-6).toString()'];
        yield 'smaller float str' => ['1e-7', '(1e-7).toString()'];

        // --- equality & comparison ---
        yield 'loose eq null undefined' => [true, 'null == undefined'];
        yield 'strict neq null undefined' => [false, 'null === undefined'];
        yield 'loose eq num str' => [true, '1 == "1"'];
        yield 'strict eq int float' => [true, '1 === 1.0'];
        yield 'string compare' => [true, '"apple" < "banana"'];
        yield 'mixed compare' => [true, '"2" < 10'];
        yield 'NaN compare' => [false, 'NaN < 1 || NaN >= 1'];

        // --- variables, scoping, closures ---
        yield 'var hoisting' => [true, 'typeof x === "undefined"; var x = 1; x === 1'];
        yield 'closure counter' => [3, 'function c(){var n=0;return function(){return ++n;};} var f=c(); f(); f(); f()'];
        yield 'closures share env' => [7, 'function m(){var n=0; return {inc:function(){n+=7;},get:function(){return n;}};} var o=m(); o.inc(); o.get()'];
        yield 'deep capture' => [1, 'function a(){var x=1;return function(){return function(){return x;};};} a()()()'];
        yield 'named fnexpr self-ref' => [120, 'var f=function fact(n){return n<2?1:n*fact(n-1);}; f(5)'];
        yield 'global var from function' => [5, 'var g=1; function f(){g=5;} f(); g'];
        yield 'param shadowing' => [2, 'var x=1; function f(x){return x;} f(2)'];

        // --- control flow ---
        yield 'for loop' => [45, 'var s=0; for(var i=0;i<10;i++) s+=i; s'];
        yield 'while' => [10, 'var i=0; while(i<10) i++; i'];
        yield 'do-while runs once' => [1, 'var i=0; do { i++; } while(false); i'];
        yield 'break label' => ['20', 'outer: for(var i=0;i<3;i++){for(var j=0;j<3;j++){if(j==1)continue outer;if(i==2)break outer;}} ""+i+j'];
        yield 'switch fallthrough' => ['abd', 'var s=""; switch(1){case 1: s+="a"; case 2: s+="b"; break; case 3: s+="c"; default: s+="x";} s+"d"'];
        yield 'switch default middle' => ['d', 'function f(x){switch(x){case 1: return "a"; default: return "d"; case 2: return "b";}} f(99)'];
        yield 'ternary' => ['y', '1<2 ? "y" : "n"'];
        yield 'logical short circuit' => [2, 'var n=0; false && n++; true || n++; n+2'];
        yield 'logical value' => ['a', '"" || "a"'];
        yield 'comma' => [3, '(1, 2, 3)'];

        // --- objects & prototypes ---
        yield 'object literal' => [3, 'var o={a:1,b:{c:2}}; o.a + o.b.c'];
        yield 'string and numeric keys' => [5, 'var o={"a b":2, 3:3}; o["a b"] + o[3]'];
        yield 'prototype method' => [42, 'function A(n){this.n=n;} A.prototype.get=function(){return this.n;}; new A(42).get()'];
        yield 'prototype chain' => [true, 'function A(){} var a=new A(); a instanceof A && a instanceof Object'];
        yield 'ctor returns object' => ['o', 'function A(){return {tag:"o"};} new A().tag'];
        yield 'ctor returns primitive ignored' => ['A', 'function A(){this.tag="A"; return 5;} new A().tag'];
        yield 'in operator' => [true, 'var o={a:1}; "a" in o && !("b" in o)'];
        yield 'delete' => [true, 'var o={a:1}; delete o.a; !("a" in o)'];
        yield 'getter setter' => [20, 'var o={_x:0, get x(){return this._x*2;}, set x(v){this._x=v;}}; o.x=10; o.x'];
        yield 'hasOwnProperty vs chain' => [true, 'var o={a:1}; o.hasOwnProperty("a") && !o.hasOwnProperty("toString") && ("toString" in o)'];
        yield 'this on method extraction' => [true, 'var o={f:function(){return this;}}; var g=o.f; g() !== o'];
        yield 'shadow proto with own' => [2, 'function A(){} A.prototype.x=1; var a=new A(); a.x=2; a.x'];

        // --- arrays ---
        yield 'array literal + length' => [3, '[1,2,3].length'];
        yield 'array holes length' => [3, '[1,,3].length'];
        yield 'hole reads undefined' => [true, '[1,,3][1] === undefined'];
        yield 'push pop' => [4, 'var a=[1]; a.push(2,3); a.pop(); a.length + a[1]'];
        yield 'length truncates' => ['1', 'var a=[1,2,3]; a.length=1; a.join(",")'];
        yield 'sparse assign extends' => [12, 'var a=[]; a[10]=1; a.length + a[10]'];
        yield 'map filter' => ['4,16', '[1,2,3,4].map(function(x){return x*x;}).filter(function(x){return x%2===0;}).join(",")'];
        yield 'reduce' => [10, '[1,2,3,4].reduce(function(a,b){return a+b;})'];
        yield 'reduce with init' => [110, '[1,2,3,4].reduce(function(a,b){return a+b;}, 100)'];
        yield 'slice negative' => ['3,4', '[1,2,3,4].slice(-2).join(",")'];
        yield 'splice' => ['1,9,4|2,3', 'var a=[1,2,3,4]; var r=a.splice(1,2,9); a.join(",")+"|"+r.join(",")'];
        yield 'concat' => ['1,2,3,4', '[1,2].concat([3,4]).join(",")'];
        yield 'reverse' => ['3,2,1', '[1,2,3].reverse().join(",")'];
        yield 'indexOf strict' => [-1, '[1,2,3].indexOf("2")'];
        yield 'isArray' => [true, 'Array.isArray([]) && !Array.isArray({length:0})'];
        yield 'array ctor length' => [5, 'new Array(5).length'];
        yield 'for-in array' => ['0x1y', 'var a=["x","y"],s=""; for(var i in a) s+=i+a[i]; s'];

        // --- functions ---
        yield 'arguments' => ['3:8', 'function f(){return arguments.length + ":" + arguments[1];} f(9,8,7)'];
        yield 'missing args undefined' => [true, 'function f(a,b){return b===undefined;} f(1)'];
        yield 'call' => [6, 'function f(a,b){return this.x+a+b;} f.call({x:1},2,3)'];
        yield 'apply' => [7, 'Math.max.apply(null, [3,7,2])'];
        yield 'bind' => [6, 'function f(a,b){return this.x+a+b;} f.bind({x:1},2)(3)'];
        yield 'bind with new' => [true, 'function A(v){this.v=v;} var B=A.bind(null, 9); new B() instanceof A && new B().v === 9'];
        yield 'recursion fib' => [6765, 'function fib(n){return n<2?n:fib(n-1)+fib(n-2);} fib(20)'];
        yield 'fn length' => [2, '(function(a,b){}).length'];
        yield 'hoisted before def' => [7, 'var r=f(); function f(){return 7;} r'];

        // --- exceptions ---
        yield 'try catch' => ['boom', 'try { throw new Error("boom"); } catch(e) { e.message }'];
        yield 'catch type' => [true, 'try { null.x } catch(e) { e instanceof TypeError }'];
        yield 'finally runs on return' => [3, 'var g=0; function f(){ try { return 1; } finally { g=2; } } f()+g'];
        yield 'finally runs on throw' => ['fx', 'var s=""; try { try { throw new Error("x"); } finally { s+="f"; } } catch(e){ s+=e.message; } s'];
        yield 'return in finally wins' => [2, 'function f(){ try { return 1; } finally { return 2; } } f()'];
        yield 'nested catch rethrow' => ['ab', 'var s=""; try { try { throw new Error("a"); } catch(e) { s+=e.message; throw new Error("b"); } } catch(e){ s+=e.message; } s'];
        yield 'exception across frames' => ['deep', 'function a(){throw new Error("deep");} function b(){a();} try { b(); } catch(e) { e.message }'];
        yield 'catch binding scoped' => [true, 'var e = 1; try { throw 2; } catch(e) {} e === 1'];
        yield 'break through finally' => ['ff1', 'var s=""; for(var i=0;i<9;i++){ try { if(i===1) break; } finally { s+="f"; } } s+i'];
        yield 'throw non-error' => [42, 'try { throw 42; } catch(e) { e }'];

        // --- typeof / void / misc operators ---
        yield 'typeof undeclared' => ['undefined', 'typeof nope'];
        yield 'typeof function' => ['function', 'typeof function(){}'];
        yield 'typeof null' => ['object', 'typeof null'];
        yield 'void' => [true, 'void 0 === undefined'];
        yield 'prefix vs postfix' => ['6,5,7', 'var x=5; var a=++x; x=5; var b=x++; ""+a+","+b+","+(x+1)'];
        yield 'update member' => [12, 'var o={n:10}; o.n++; ++o.n; o.n'];
        yield 'update element' => [3, 'var a=[1,2]; a[0]++; ++a[1]; a[0]+a[1] - 2'];
        yield 'compound member assign' => [15, 'var o={n:10}; o.n += 5; o.n'];
        yield 'compound elem assign' => [6, 'var a=[2]; a[0] *= 3; a[0]'];

        // --- strings ---
        yield 'length utf16' => [11, '"héllo wörld".length'];
        yield 'surrogate length' => [2, '"😀".length'];
        yield 'charCodeAt' => [233, '"é".charCodeAt(0)'];
        yield 'fromCharCode' => ['A é', 'String.fromCharCode(65, 32, 233)'];
        yield 'index chars' => ['e', '"hello"[1]'];
        yield 'slice negative str' => ['lo', '"hello".slice(-2)'];
        yield 'substring swaps' => ['ell', '"hello".substring(4,1)'];
        yield 'split join' => ['a-b-c', '"a,b,c".split(",").join("-")'];
        yield 'split empty' => ['h.i', '"hi".split("").join(".")'];
        yield 'trim' => ['x', '"  x\t\n ".trim()'];
        yield 'replace dollar amp' => ['[ab]c', '"abc".replace("ab", "[$&]")'];
        yield 'replace callback' => ['aXc', '"abc".replace("b", function(m){return "X";})'];
        yield 'string methods on primitives' => ['HELLO', '"hello".toUpperCase()'];
        yield 'concat numbers' => ['12', '1 + "" + 2'];
        yield 'str idx oob' => [true, '"a"[5] === undefined'];

        // --- global functions ---
        yield 'parseInt radix' => [255, 'parseInt("ff", 16)'];
        yield 'parseInt trailing' => [42, 'parseInt("42px")'];
        yield 'parseInt hex auto' => [255, 'parseInt("0xFF")'];
        yield 'parseFloat' => [3.14, 'parseFloat("3.14abc")'];
        yield 'isNaN coerces' => [true, 'isNaN("abc")'];
        yield 'isFinite' => [false, 'isFinite(Infinity)'];
        yield 'encodeURIComponent' => ['a%20b%2Fc', 'encodeURIComponent("a b/c")'];
        yield 'decodeURIComponent' => ['a b/c', 'decodeURIComponent("a%20b%2Fc")'];
        yield 'eval indirect' => [5, 'eval("2+3")'];
        yield 'Function ctor' => [5, 'new Function("a","b","return a+b")(2,3)'];

        // --- Object builtins ---
        yield 'Object.keys' => ['a,b', 'Object.keys({a:1,b:2}).join(",")'];
        yield 'Object.keys skips proto' => ['own', 'function A(){this.own=1;} A.prototype.inherited=2; Object.keys(new A()).join(",")'];
        yield 'Object.create' => [true, 'var p={x:1}; var o=Object.create(p); o.x===1 && !o.hasOwnProperty("x")'];
        yield 'Object.create null' => [true, 'var o=Object.create(null); typeof o.toString === "undefined"'];
        yield 'defineProperty non-enum' => ['a', 'var o={a:1}; Object.defineProperty(o,"b",{value:2}); Object.keys(o).join(",")'];
        yield 'defineProperty accessor' => [7, 'var o={}; Object.defineProperty(o,"x",{get:function(){return 7;}}); o.x'];
        yield 'freeze' => [1, 'var o={a:1}; Object.freeze(o); o.a=9; o.a'];
        yield 'getPrototypeOf' => [true, 'Object.getPrototypeOf({}) === Object.prototype'];
        yield 'obj toString tag' => ['[object Array]', 'Object.prototype.toString.call([])'];

        // --- Math / Number / JSON ---
        yield 'math round half up' => [4, 'Math.round(3.5)'];
        yield 'math round negative half' => [-3, 'Math.round(-3.5)'];
        yield 'math max nan' => [NAN, 'Math.max(1, NaN)'];
        yield 'math pow' => [8, 'Math.pow(2,3)'];
        // ---- ES2015 syntax (DESIGN.md §2.5) --------------------------------
        yield 'template literal, no substitution' => ['plain', '`plain`'];
        yield 'template literal substitution' => ['a2b', 'var x = 2; `a${x}b`'];
        yield 'template literal is a string' => ['string', 'var x = 2; typeof `${x}`'];
        yield 'template literal, adjacent substitutions' => ['22', 'var x = 2; `${x}${x}`'];
        yield 'empty template' => ['', '``'];
        yield 'template with an expression' => ['a2bc', '`a${1 + 1}b${"c"}`'];
        yield 'nested template' => ['outer inner 2', 'var x = 2; `outer ${`inner ${x}`}`'];
        yield 'template escapes' => ["a\nb\t`\${", '`a\nb\t\`\${`'];
        // A template converts with ToString; `+` converts with ToPrimitive under
        // the default hint. An object with both methods tells them apart, which
        // is why this cannot be a rewrite to `+`.
        yield 'template uses ToString, not ToPrimitive' => [
            'S|1',
            'var o = { valueOf: function () { return 1; }, toString: function () { return "S"; } };'
                . ' `${o}` + "|" + ("" + o)',
        ];
        yield 'template stringifies null and undefined' => [
            'null-undefined',
            'var n = null, u; `${n}-${u}`',
        ];

        yield 'arrow, expression body' => [3, 'var f = (a, b) => a + b; f(1, 2)'];
        yield 'arrow, block body' => [42, 'var f = (x) => { return x * 2; }; f(21)'];
        yield 'arrow, no parameters' => [7, '(() => 7)()'];
        yield 'arrow returning an object literal' => [1, '(() => ({ a: 1 }))().a'];
        yield 'curried arrows' => [3, '((a) => (b) => a + b)(1)(2)'];
        yield 'arrow as a callback' => ['1,4,9', '[1, 2, 3].map(x => x * x).join(",")'];
        // The whole point of the form: `this` is the enclosing function's, and
        // no receiver the caller supplies can change it.
        yield 'arrow takes this from its enclosing function' => [
            42,
            'var o = { v: 42, get: function () { return (() => this.v)(); } }; o.get()',
        ];
        yield 'nested arrows chain this' => [
            9,
            'var f = function () { return () => () => this.v; }; f.call({ v: 9 })()()',
        ];
        yield 'arrow this survives a callback' => [
            3,
            'var o = { n: 0, inc: function () { [1,2,3].forEach(() => { this.n++; }); return this.n; } }; o.inc()',
        ];
        yield 'arrow ignores an explicit receiver' => [
            42,
            'var o = { v: 42, get: function () { return (() => this.v).call({ v: 0 }); } }; o.get()',
        ];
        // No prototype, so `new` on one is a TypeError rather than a call.
        yield 'arrow has no prototype' => [true, '(() => 1).prototype === undefined'];
        yield 'arrow is not a constructor' => [
            'TypeError',
            'try { new (() => 1); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'arrow length and name' => ['2|', '((a, b) => a + b).length + "|" + ((a, b) => a + b).name'];
        yield 'arrow is a function' => ['function', 'typeof (() => 1)'];

        // A default applies to `undefined` only -- not to every falsy value, and
        // not to an explicitly passed `null`.
        yield 'default parameter fills a missing argument' => [
            '1|2|3',
            'function d(a, b = 2, c = a + b) { return a + "|" + b + "|" + c; } d(1)',
        ];
        yield 'default yields to an argument that was passed' => [
            '1|9|10',
            'function d(a, b = 2, c = a + b) { return a + "|" + b + "|" + c; } d(1, 9)',
        ];
        yield 'explicit undefined still takes the default' => [
            '1|2|7',
            'function d(a, b = 2, c = a + b) { return a + "|" + b + "|" + c; } d(1, undefined, 7)',
        ];
        yield 'null does not take the default' => [
            '1|null|1',
            'function d(a, b = 2, c = a + b) { return a + "|" + b + "|" + c; } d(1, null)',
        ];
        yield 'a default may reference an earlier parameter' => [
            5,
            'function f(a, b = a) { return b; } f(5)',
        ];
        // Parameters are in a dead zone while the list initializes, so this is a
        // ReferenceError in the spec. Nothing implements that zone yet, and
        // answering undefined would be silently wrong, so it is refused.
        yield 'a default may not reference a later parameter' => [
            'SyntaxError',
            'try { eval("(function (b = a, a = 1) { return b; })()"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];
        yield 'rest parameter collects the remainder' => [
            '1|2|2,3',
            'function r(a, ...rest) { return a + "|" + rest.length + "|" + rest.join(","); } r(1, 2, 3)',
        ];
        yield 'rest parameter is empty when nothing is left' => [
            '1|0|',
            'function r(a, ...rest) { return a + "|" + rest.length + "|" + rest.join(","); } r(1)',
        ];
        yield 'rest parameter is a real array' => [
            true,
            'Array.isArray((function (...x) { return x; })())',
        ];
        yield 'defaults and rest together' => [
            '1|2|3,4',
            'function m(a, b = 1, ...rest) { return a + "|" + b + "|" + rest.join(","); } m(1, 2, 3, 4)',
        ];
        yield 'a default is captured by a closure' => [
            1,
            'function capt(a = 1) { return function () { return a; }; } capt()()',
        ];
        yield 'a rest parameter is captured by a closure' => [
            '123',
            'function c(...xs) { return function () { return xs.join(""); }; } c(1, 2, 3)()',
        ];
        // `length` counts parameters before the first default or rest, which is
        // why it is a separate template field from the slot count.
        yield 'length stops at the first default' => [
            '1|1|1',
            'function d(a, b = 2, c = 3) {} function r(a, ...x) {} function m(a, b = 1, ...x) {}'
                . ' d.length + "|" + r.length + "|" + m.length',
        ];
        // A body directive may not follow a non-simple parameter list: it would
        // change how the list is read after it has been read.
        yield 'use strict is refused after a default parameter' => [
            'SyntaxError',
            'try { eval("(function (a = 1) { \'use strict\'; return a; })"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];
        yield 'use strict stays legal after a simple list' => [
            1,
            'eval("(function (a) { \'use strict\'; return a; })")(1)',
        ];
        yield 'arrow with a default' => [5, '((x = 5) => x)()'];
        yield 'arrow with a rest parameter' => [3, '((...xs) => xs.length)(1, 2, 3)'];
        // A non-simple parameter list is never mapped onto `arguments`.
        yield 'arguments still counts what was passed' => [
            3,
            '(function (a, ...rest) { return arguments.length; })(1, 2, 3)',
        ];

        // --- let and const ---
        // A block is a scope of its own, which is the whole difference from
        // `var`: the outer binding is shadowed, not overwritten.
        yield 'let is scoped to its block' => [1, 'var x = 1; { let x = 2; } x'];
        yield 'let shadows an outer binding' => [
            '2|1',
            'var x = 1; var seen; { let x = 2; seen = x; } seen + "|" + x',
        ];
        yield 'a let block does not leak' => ['undefined', '{ let inner = 1; } typeof inner'];
        yield 'let without an initializer is undefined' => ['undefined', '{ let a; typeof a }'];
        yield 'nested blocks each get their own binding' => [
            '1,2,1',
            'var out = []; { let n = 1; out.push(n); { let n = 2; out.push(n); } out.push(n); }'
                . ' out.join(",")',
        ];
        yield 'let at function-body level stays in the function' => [
            '1|undefined',
            'function f() { let g = 1; return g; } f() + "|" + typeof g',
        ];
        yield 'a for head binding does not leak' => [
            'undefined',
            'for (let i = 0; i < 3; i++) {} typeof i',
        ];
        yield 'let in a loop body is fine when nothing captures it' => [
            6,
            'var t = 0; for (var i = 0; i < 3; i++) { let j = i * 2; t += j; } t',
        ];
        yield 'a closure over a block-scoped let keeps it' => [
            3,
            'var f; { let v = 3; f = function () { return v; }; } f()',
        ];
        // Lexical bindings enter their dead zone before the body's functions are
        // instantiated, so a hoisted function may close over one.
        yield 'a hoisted function sees a let in the same body' => [
            7,
            'function outer() { let v = 7; function inner() { return v; } return inner(); } outer()',
        ];

        // Every statement list that can hold a `let` is a scope, not just a
        // plain block.
        yield 'a try block scopes its let' => ['undefined', 'try { let q = 1; } catch (e) {} typeof q'];
        yield 'a catch body scopes its let' => [
            'undefined',
            'try { throw 1 } catch (e) { let q = 1; } typeof q',
        ];
        yield 'a finally block scopes its let' => ['undefined', 'try {} finally { let q = 1; } typeof q'];
        yield 'a labelled block scopes its let' => ['undefined', 'lbl: { let z = 1; } typeof z'];
        // A switch's cases share one scope, so a `let` in one case is visible to
        // the next -- and may not be declared twice across them.
        yield 'switch cases share one block scope' => [
            'one',
            'var r; switch (1) { case 1: let s = "one"; r = s; break; } r',
        ];

        yield 'const holds its value' => [5, 'const c = 5; c'];
        yield 'assigning to a const is a TypeError' => [
            'TypeError',
            'const c = 1; try { c = 2; "none" } catch (e) { e.constructor.name }',
        ];
        yield 'const objects are still mutable' => [2, 'const o = { a: 1 }; o.a = 2; o.a'];
        yield 'a const declaration needs an initializer' => [
            'SyntaxError',
            'try { eval("const c;"); "none" } catch (e) { e.constructor.name }',
        ];

        // The dead zone is what makes an early read observable rather than
        // `undefined`, which is the reason `let` is not `var`.
        yield 'reading a let before its declaration throws' => [
            'ReferenceError',
            '{ try { x; "none" } catch (e) { e.constructor.name } let x = 1; }',
        ];
        yield 'the dead zone ends at the declaration' => [1, '{ let x = 1; x }'];

        // A lexical name may not collide with anything else that reaches the
        // same scope. `var` hoists through blocks, so it reaches further than
        // it looks.
        yield 'let may not be declared twice in a block' => [
            'SyntaxError',
            'try { eval("{ let e = 1; let e = 2; }"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a let may not share a name with a var' => [
            'SyntaxError',
            'try { eval("let d = 1; var d = 2;"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a var in a nested block still collides' => [
            'SyntaxError',
            'try { eval("let h = 1; { var h = 2; }"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a let may not share a name with a parameter' => [
            'SyntaxError',
            'try { eval("(function (p) { let p = 1; })"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a nested block may reuse a parameter name' => [
            9,
            '(function (p) { { let p = 9; return p; } })(1)',
        ];
        yield 'a let may not share a name with the catch parameter' => [
            'SyntaxError',
            'try { eval("try {} catch (e) { let e = 1; }"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a for head binding may not share a name with a body var' => [
            'SyntaxError',
            'try { eval("for (let i = 0; i < 1; i++) { var i = 2; }"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];
        yield 'a switch may not declare the same let twice' => [
            'SyntaxError',
            'try { eval("switch(1){case 1: let s=1; case 2: let s=2;}"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];
        // `let` is not a reserved word, so `var let` stays legal -- but a
        // lexical declaration cannot bind it, because `let let` has no reading.
        yield 'a lexical declaration may not be named let' => [
            'SyntaxError',
            'try { eval("let let = 1;"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'var let is still allowed' => [1, 'var let = 1; let'];

        // --- object destructuring ---
        yield 'basic object pattern' => [3, 'var {a, b} = {a: 1, b: 2}; a + b'];
        yield 'a property may be renamed' => [5, 'var {a: x} = {a: 5}; x'];
        yield 'a missing property is undefined' => ['undefined', 'var {q} = {}; typeof q'];
        yield 'nested patterns' => [7, 'var {a: {b}} = {a: {b: 7}}; b'];
        yield 'const declares through a pattern' => [1, 'const {a} = {a: 1}; a'];
        yield 'let declares through a pattern' => [2, '{ let {a} = {a: 2}; a }'];
        yield 'a pattern let does not leak' => ['undefined', '{ let {a} = {a: 1}; } typeof a'];
        yield 'assigning a const from a pattern is a TypeError' => [
            'TypeError',
            'const {a} = {a: 1}; try { a = 2; "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a var pattern hoists' => [
            'undefined',
            'function f() { return typeof a; var {a} = {a: 1}; } f()',
        ];

        // A default applies to `undefined` only, exactly as a parameter's does.
        yield 'a default fills a missing property' => [3, 'var {a = 3} = {}; a'];
        yield 'a default yields to a present property' => [1, 'var {a = 3} = {a: 1}; a'];
        yield 'explicit undefined takes the default' => [3, 'var {a = 3} = {a: undefined}; a'];
        yield 'null does not take a pattern default' => ['null', 'var {a = 3} = {a: null}; String(a)'];
        yield 'a default may reference an earlier name' => [2, 'var {a, b = a + 1} = {a: 1}; b'];
        yield 'nested with a default' => [2, 'var {a: {b = 2} = {}} = {}; b'];

        yield 'a computed key' => [9, 'var k = "z"; var {[k]: v} = {z: 9}; v'];
        // The key is converted once and reused, so a user `toString` runs once.
        yield 'a computed key converts once' => [
            1,
            'var n = 0; var k = { toString: function () { n++; return "z"; } };'
                . ' var {[k]: v} = {z: 1}; n',
        ];

        yield 'object rest' => [
            '1:{"b":2,"c":3}',
            'var {a, ...r} = {a: 1, b: 2, c: 3}; a + ":" + JSON.stringify(r)',
        ];
        yield 'object rest is a plain object' => [
            true,
            'var {...r} = {a: 1}; Object.getPrototypeOf(r) === Object.prototype',
        ];
        yield 'object rest excludes a computed key' => [
            '2:{"a":1}',
            'var k = "b"; var {[k]: v, ...r} = {a: 1, b: 2}; v + ":" + JSON.stringify(r)',
        ];
        yield 'object rest skips non-enumerable' => [
            '{"a":1}',
            'var o = {a: 1}; Object.defineProperty(o, "h", {value: 2, enumerable: false});'
                . ' var {...r} = o; JSON.stringify(r)',
        ];
        yield 'object rest does not copy the prototype chain' => [
            '{}',
            'function P() {} P.prototype.x = 1; var {...r} = new P(); JSON.stringify(r)',
        ];

        // RequireObjectCoercible runs before any property is read, and still
        // runs when the pattern has no property to read.
        yield 'destructuring null throws' => [
            'TypeError',
            'try { var {a} = null; "none" } catch (e) { e.constructor.name }',
        ];
        yield 'destructuring undefined throws' => [
            'TypeError',
            'try { var {a} = undefined; "none" } catch (e) { e.constructor.name }',
        ];
        yield 'an empty pattern still checks null' => [
            'TypeError',
            'try { var {} = null; "none" } catch (e) { e.constructor.name }',
        ];
        yield 'an empty pattern accepts an object' => ['ok', 'var {} = {}; "ok"'];
        yield 'a string source reads its properties' => [3, 'var {length} = "abc"; length'];
        yield 'a number source is coercible' => [true, 'var {constructor} = 5; constructor === Number'];

        yield 'a parameter pattern' => [3, 'function f({a, b}) { return a + b; } f({a: 1, b: 2})'];
        yield 'a parameter pattern with a default' => [4, 'function f({a} = {a: 4}) { return a; } f()'];
        yield 'a default inside a parameter pattern' => [9, 'function f({a = 9}) { return a; } f({})'];
        yield 'a parameter pattern beside a plain one' => [
            3,
            'function f({a}, b) { return a + b; } f({a: 1}, 2)',
        ];
        yield 'an arrow parameter pattern' => [3, '(({a}) => a)({a: 3})'];
        yield 'a parameter pattern is captured by a closure' => [
            6,
            'function f({a}) { return function () { return a; }; } f({a: 6})()',
        ];
        // A pattern makes the list non-simple, but it does not stop `length`:
        // only a default or a rest element does.
        yield 'length counts a pattern before any default' => [2, '(function ({a}, b) {}).length'];
        yield 'length stops at a pattern default' => [0, '(function ({a} = {}, b) {}).length'];
        yield 'a pattern parameter list is never mapped' => [
            7,
            '(function ({a}) { arguments[0] = 1; return a; })({a: 7})',
        ];

        yield 'destructuring assignment' => [8, 'var a; ({a} = {a: 8}); a'];
        // The expression's value is the source, not the pattern.
        yield 'a destructuring assignment evaluates to its source' => [
            '{"a":1}',
            'var a; JSON.stringify(({a} = {a: 1}))',
        ];
        yield 'assignment into a member expression' => [6, 'var o = {}; ({a: o.x} = {a: 6}); o.x'];
        yield 'assignment with a default' => [5, 'var a; ({a = 5} = {}); a'];
        yield 'shorthand with a default in an assignment' => [2, 'var x; ({x = 2} = {}); x'];
        yield 'assignment to an existing binding in a block' => [
            3,
            'var a = 0; { ({a} = {a: 3}); } a',
        ];
        yield 'nested assignment' => [4, 'var b; ({a: {b}} = {a: {b: 4}}); b'];

        yield 'a getter on the source runs once' => [
            1,
            'var n = 0; var o = { get a() { n++; return 1; } }; var {a} = o; n',
        ];
        // Properties are read in the pattern's order, not the source's.
        yield 'properties are read in order' => [
            'b,a',
            'var log = []; var o = { get a() { log.push("a"); return 1; },'
                . ' get b() { log.push("b"); return 2; } }; var {b, a} = o; log.join(",")',
        ];
        yield 'a pattern in a for body' => [
            1,
            'var t = 0; for (var i = 0; i < 2; i++) { var {a} = {a: i}; t += a; } t',
        ];

        yield 'repeated names across patterns are rejected' => [
            'SyntaxError',
            'try { eval("(function ({a}, {a}) {})"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a pattern may not collide with a body let' => [
            'SyntaxError',
            'try { eval("(function ({a}) { let a = 1; })"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'use strict is refused after a pattern' => [
            'SyntaxError',
            'try { eval("(function ({a}) { \'use strict\'; return a; })"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];

        // An escaped keyword is still a keyword, and the escape is the only way
        // one reaches the compiler as an identifier at all.
        yield 'an escaped keyword may not be bound' => [
            'SyntaxError',
            'try { eval("var br\\\\u0065ak = 1;"); "none" } catch (e) { e.constructor.name }',
        ];
        yield 'an escaped keyword may not be a shorthand target' => [
            'SyntaxError',
            'try { eval("var x = { bre\\\\u0061k } = { break: 42 };"); "none" }'
                . ' catch (e) { e.constructor.name }',
        ];
        yield 'a strict-only reserved word is bindable in sloppy code' => [
            1,
            'var package = 1; package',
        ];
        // Writing to a `let` before its declaration is a ReferenceError just as
        // reading one is.
        yield 'assigning to a let in its dead zone throws' => [
            'ReferenceError',
            '{ try { x = 1; "none" } catch (e) { e.constructor.name } let x; }',
        ];

        // --- for...of and the iteration protocol ---
        yield 'for-of iterates an array' => [6, 'var t = 0; for (var x of [1,2,3]) t += x; t'];
        yield 'a const for-of head' => [6, 'var t = 0; for (const x of [1,2,3]) t += x; t'];
        yield 'the for-of head does not leak' => ['undefined', 'for (const x of [1]) {} typeof x'];
        yield 'a for-of body may declare a let' => [
            '24',
            'var s = ""; for (const x of [1,2]) { let y = x * 2; s += y; } s',
        ];
        // A string iterates by code point, which is the whole difference from
        // indexing it: a surrogate pair is one step, of length 2.
        yield 'a string iterates by code point' => [
            '121',
            'var s = ""; for (const c of "a😀b") s += c.length; s',
        ];
        yield 'for-of over arguments' => [
            6,
            '(function () { var t = 0; for (const a of arguments) t += a; return t; })(1,2,3)',
        ];
        yield 'entries yields index and value' => [
            '0a1b',
            'var s = ""; for (const e of ["a","b"].entries()) s += e[0] + e[1]; s',
        ];
        yield 'keys yields indices' => ['01', 'var s = ""; for (const k of ["a","b"].keys()) s += k; s'];
        yield 'an iterator is itself iterable' => [
            '12',
            'var s = ""; for (const v of [1,2].values()) s += v; s',
        ];
        yield 'destructuring in a for-of head' => [
            3,
            'var t = 0; for (const {v} of [{v:1},{v:2}]) t += v; t',
        ];
        yield 'an existing binding is a valid for-of target' => [
            '3:2',
            'var y, t = 0; for (y of [1,2]) t += y; t + ":" + y',
        ];
        yield 'a member expression is a valid for-of target' => [
            2,
            'var o = {}; for (o.k of [1,2]) {} o.k',
        ];

        yield 'break stops a for-of' => [
            1,
            'var t = 0; for (const x of [1,2,3]) { if (x === 2) break; t += x; } t',
        ];
        yield 'continue skips one iteration' => [
            4,
            'var t = 0; for (const x of [1,2,3]) { if (x === 2) continue; t += x; } t',
        ];
        yield 'return leaves a for-of' => [
            2,
            'function f() { for (const x of [1,2,3]) { if (x === 2) return x; } return 0; } f()',
        ];
        yield 'a labelled continue crosses a for-of' => [
            3,
            'var t = 0; outer: for (const a of [1,2]) { for (const b of [1,2]) {'
                . ' if (b === 2) continue outer; t += a * b; } } t',
        ];
        yield 'try/finally inside a for-of' => [
            '1f2f',
            'var s = ""; for (const x of [1,2]) { try { s += x; } finally { s += "f"; } } s',
        ];
        yield 'break out of a try inside a for-of' => [
            '1ff',
            'var s = ""; for (const x of [1,2,3]) { try { if (x === 2) break; s += x; }'
                . ' finally { s += "f"; } } s',
        ];

        yield 'a non-iterable throws' => [
            'TypeError',
            'try { for (const x of 5) {} "none" } catch (e) { e.constructor.name }',
        ];
        yield 'an object without Symbol.iterator throws' => [
            'TypeError',
            'try { for (const x of {a:1}) {} "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a bad iterator result throws' => [
            'TypeError',
            'var o = {}; o[Symbol.iterator] = function () { return { next: function () { return 1; } }; };'
                . ' try { for (const v of o) {} "none" } catch (e) { e.constructor.name }',
        ];
        yield 'a custom iterable' => [
            15,
            'var o = { a: [7,8] };'
                . ' o[Symbol.iterator] = function () { var i = 0, a = this.a; return { next: function () {'
                . ' return i < a.length ? {value: a[i++], done: false} : {value: undefined, done: true}; } }; };'
                . ' var t = 0; for (const v of o) t += v; t',
        ];
        // `next` is captured with the iterator, not re-read each step, which a
        // getter makes observable.
        yield 'next is read once' => [
            1,
            'var reads = 0; var o = {}; o[Symbol.iterator] = function () { var n = 0; var it = {};'
                . ' Object.defineProperty(it, "next", { get: function () { reads++; return function () {'
                . ' return n++ < 3 ? {value: 1, done: false} : {done: true}; }; } }); return it; };'
                . ' for (const v of o) {} reads',
        ];

        // IteratorClose: every abrupt exit tells the iterator, exhaustion does
        // not, and `continue` is not an exit.
        yield 'break closes the iterator' => [
            true,
            'var closed = false; var o = {}; o[Symbol.iterator] = function () { return {'
                . ' next: function () { return {value: 1, done: false}; },'
                . ' return: function () { closed = true; return {}; } }; };'
                . ' for (const v of o) break; closed',
        ];
        yield 'return closes the iterator' => [
            true,
            'var closed = false; var o = {}; o[Symbol.iterator] = function () { return {'
                . ' next: function () { return {value: 1, done: false}; },'
                . ' return: function () { closed = true; return {}; } }; };'
                . ' (function () { for (const v of o) return; })(); closed',
        ];
        yield 'a throw closes the iterator' => [
            true,
            'var closed = false; var o = {}; o[Symbol.iterator] = function () { return {'
                . ' next: function () { return {value: 1, done: false}; },'
                . ' return: function () { closed = true; return {}; } }; };'
                . ' try { for (const v of o) throw new Error("x"); } catch (e) {} closed',
        ];
        yield 'exhaustion does not close the iterator' => [
            false,
            'var closed = false; var o = {}; o[Symbol.iterator] = function () { var n = 0; return {'
                . ' next: function () { return n++ < 1 ? {value: 1, done: false} : {value: undefined, done: true}; },'
                . ' return: function () { closed = true; return {}; } }; };'
                . ' for (const v of o) {} closed',
        ];
        yield 'continue does not close the iterator' => [
            false,
            'var closed = false; var o = {}; o[Symbol.iterator] = function () { var n = 0; return {'
                . ' next: function () { return n++ < 2 ? {value: 1, done: false} : {value: undefined, done: true}; },'
                . ' return: function () { closed = true; return {}; } }; };'
                . ' for (const v of o) { continue; } closed',
        ];
        // While unwinding a throw, an error from `return` loses to the one
        // already in flight.
        yield 'a throw from return is swallowed' => [
            'outer',
            'var o = {}; o[Symbol.iterator] = function () { return {'
                . ' next: function () { return {value: 1, done: false}; },'
                . ' return: function () { throw new Error("inner"); } }; };'
                . ' try { for (const v of o) throw new Error("outer"); } catch (e) { e.message }',
        ];

        // The fused INC_LOCAL form is a bare slot bump, so a lexical binding
        // must not take it: it would skip both of these.
        yield 'incrementing a const is a TypeError' => [
            'TypeError',
            '(function () { const c = 1; try { c++; return "none" } catch (e) { return e.constructor.name } })()',
        ];
        yield 'incrementing a for-of const is a TypeError' => [
            'TypeError',
            'try { for (const x of [1,2,3]) { x++ } "none" } catch (e) { e.constructor.name }',
        ];
        yield 'incrementing a let in its dead zone throws' => [
            'ReferenceError',
            '{ try { x++; "none" } catch (e) { e.constructor.name } let x = 1; }',
        ];

        // --- array destructuring, over the iteration protocol ---
        yield 'array pattern' => [3, 'var [a, b] = [1, 2]; a + b'];
        yield 'an elision skips an element' => [2, 'var [, b] = [1, 2]; b'];
        yield 'an elision still steps the iterator' => ['1:0,1', 'var log = []; var o = {}; o[Symbol.iterator] = function () { var i = 0; return { next: function () { log.push(i); return {value: i++, done: i > 3}; } }; }; var [, b] = o; b + ":" + log.join(",")'];
        yield 'an array rest element' => ['1:2,3', 'var [a, ...r] = [1,2,3]; a + ":" + r.join(",")'];
        yield 'an array rest is a real array' => [true, 'var [...r] = [1]; Array.isArray(r)'];
        yield 'an array rest of nothing is empty' => [0, 'var [a, ...r] = [1]; r.length'];
        yield 'a default in an array pattern' => [5, 'var [a = 5] = []; a'];
        yield 'a default yields to a value' => [1, 'var [a = 5] = [1]; a'];
        yield 'a default applies to undefined only' => ['null', 'var [a = 5] = [null]; String(a)'];
        yield 'nested array patterns' => [3, 'var [[a], [b]] = [[1],[2]]; a + b'];
        yield 'an object pattern inside an array pattern' => [7, 'var [{x}] = [{x: 7}]; x'];
        yield 'an array pattern inside an object pattern' => [3, 'var {a: [x, y]} = {a: [1,2]}; x + y'];
        yield 'a short source gives undefined' => ['1:undefined', 'var [a, b] = [1]; a + ":" + typeof b'];
        yield 'a string destructures by code point' => ['a:2', 'var [a, b] = "a\\ud83d\\ude00"; a + ":" + b.length'];
        yield 'a Set destructures' => [9, 'var s = {}; s[Symbol.iterator] = function () { var i = 0, a = [4,5]; return { next: function () { return i < 2 ? {value: a[i++], done: false} : {done: true}; } }; }; var [x, y] = s; x + y'];
        yield 'const through an array pattern' => [9, 'const [a] = [9]; a'];
        yield 'let through an array pattern' => [3, '{ let [a] = [3]; a }'];
        yield 'an array pattern let does not leak' => ['undefined', '{ let [a] = [1]; } typeof a'];
        yield 'destructuring a non-iterable throws' => ['TypeError', 'try { var [a] = 5; "none" } catch (e) { e.constructor.name }'];
        yield 'an array pattern on null throws' => ['TypeError', 'try { var [a] = null; "none" } catch (e) { e.constructor.name }'];
        yield 'array destructuring assignment' => ['1:2', 'var a, b; [a, b] = [1, 2]; a + ":" + b'];
        yield 'a swap' => ['2:1', 'var a = 1, b = 2; [a, b] = [b, a]; a + ":" + b'];
        yield 'an array pattern assigns into a member expression' => [4, 'var o = {}; [o.x] = [4]; o.x'];
        yield 'the assignment evaluates to its source' => ['[1,2]', 'var a; JSON.stringify([a] = [1, 2])'];
        yield 'an array pattern parameter' => [3, 'function f([a, b]) { return a + b; } f([1,2])'];
        yield 'an array pattern parameter with a default' => [3, 'function f([a] = [3]) { return a; } f()'];
        yield 'an arrow with an array pattern' => [3, '(([a, b]) => a + b)([1,2])'];
        yield 'an array pattern in a for-of head' => ['1a2b', 'var s = ""; for (const [k, v] of [[1,"a"],[2,"b"]]) s += k + v; s'];
        yield 'a partly consumed iterator is closed' => [true, 'var closed = false; var o = {}; o[Symbol.iterator] = function () { return { next: function () { return {value: 1, done: false}; }, return: function () { closed = true; return {}; } }; }; var [a] = o; closed'];
        yield 'a rest element leaves nothing to close' => ['false:2', 'var closed = false; var o = {}; o[Symbol.iterator] = function () { var n = 0; return { next: function () { return n++ < 2 ? {value: 1, done: false} : {done: true}; }, return: function () { closed = true; return {}; } }; }; var [...r] = o; closed + ":" + r.length'];
        yield 'a throw while binding closes the iterator' => [true, 'var closed = false; var o = {}; o[Symbol.iterator] = function () { return { next: function () { return {value: 1, done: false}; }, return: function () { closed = true; return {}; } }; }; try { var [a = (function(){ throw new Error("x"); })()] = o; } catch (e) {} closed'];

        // --- spread ---
        yield 'array spread' => ['1,2,3', '[...[1,2], 3].join(",")'];
        yield 'spread in the middle' => ['0,1,2,3', '[0, ...[1,2], 3].join(",")'];
        yield 'spread a string' => ['a-b-c', '[..."abc"].join("-")'];
        yield 'spread keeps a hole as undefined' => ['[1,null,2]', 'JSON.stringify([...[1,,2]])'];
        yield 'spread an empty array' => [0, '[...[]].length'];
        yield 'two spreads' => ['1,2,3', '[...[1], ...[2,3]].join(",")'];
        yield 'spread a custom iterable' => ['1,2', 'var o = {}; o[Symbol.iterator] = function () { var i = 0; return { next: function () { return i < 2 ? {value: ++i, done: false} : {done: true}; } }; }; [...o].join(",")'];
        yield 'spread a non-iterable throws' => ['TypeError', 'try { [...5]; "none" } catch (e) { e.constructor.name }'];
        yield 'call spread' => [6, 'function f(a,b,c){return a+b+c;} f(...[1,2,3])'];
        yield 'call spread after fixed arguments' => ['1:2:3', 'function f(a,b,c){return a+":"+b+":"+c;} f(1, ...[2,3])'];
        yield 'call spread before fixed arguments' => ['1:2:3', 'function f(a,b,c){return a+":"+b+":"+c;} f(...[1,2], 3)'];
        yield 'call spread keeps the receiver' => [15, 'var o = {n: 10, f: function(a){return this.n + a;}}; o.f(...[5])'];
        yield 'call spread reaches a builtin' => [7, 'Math.max(...[3,7,2])'];
        yield 'call spread sets arguments.length' => [3, '(function () { return arguments.length; })(...[1,2,3])'];
        yield 'new with spread' => [3, 'function P(a,b){this.v=a+b;} new P(...[1,2]).v'];
        yield 'object spread' => ['{"a":1,"b":2}', 'JSON.stringify({...{a:1}, b:2})'];
        yield 'a later property wins over a spread' => ['{"a":2,"b":3}', 'JSON.stringify({a:1, ...{a:2, b:3}})'];
        yield 'a later spread wins over a property' => ['{"a":9}', 'JSON.stringify({...{a:1}, a:9})'];
        yield 'object spread of a string' => ['{"0":"a","1":"b"}', 'JSON.stringify({..."ab"})'];
        yield 'object spread of null is empty' => ['{}', 'JSON.stringify({...null})'];
        yield 'object spread skips the prototype' => ['{}', 'function P(){} P.prototype.x = 1; JSON.stringify({...new P()})'];
        yield 'a computed key in an object literal' => ['{"z":1}', 'var k = "z"; JSON.stringify({[k]: 1})'];
        yield 'a computed accessor key' => ['{"z":1}', 'var k = "z"; JSON.stringify({get [k]() { return 1; }})'];
        yield 'a computed symbol key' => [5, 'var s = Symbol("s"); var o = {[s]: 5}; o[s]'];
        yield 'a computed key converts once in a literal' => [1, 'var n = 0; var k = { toString: function () { n++; return "a"; } }; var o = {[k]: 1}; n'];

        // --- for...in heads, which share the for...of machinery ---
        yield 'a const for-in head' => ['ab', 'var s = ""; for (const k in {a:1,b:2}) s += k; s'];
        yield 'a let for-in head' => ['a', 'var s = ""; for (let k in {a:1}) s += k; s'];
        yield 'a lexical for-in head does not leak' => [
            'undefined',
            'for (const k in {a:1}) {} typeof k',
        ];
        yield 'a pattern in a for-in head' => [
            'ab',
            'var s = ""; for (var [a, b] in {ab:1}) s += a + b; s',
        ];
        yield 'a member expression for-in target' => [
            'a',
            'var o = {}; var s = ""; for (o.k in {a:1}) s += o.k; s',
        ];

        // A native drain runs outside the dispatch loop, so it has to enforce
        // the wall-clock limit itself or an endless iterable escapes it.
        yield 'a broken iterator is not closed' => [
            0,
            'var c = 0; var o = {}; o[Symbol.iterator] = function () { return {'
                . ' next: function () { throw new Error("n"); },'
                . ' return: function () { c++; return {}; } }; };'
                . ' try { var [a] = o; } catch (e) {} c',
        ];
        // Promise combinators take an iterable, not an array-like.
        yield 'Promise.all iterates its argument' => [
            true,
            'var s = {}; s[Symbol.iterator] = function () { var i = 0; return { next: function () {'
                . ' return i < 2 ? {value: ++i, done: false} : {done: true}; } }; };'
                . ' var p = Promise.all(s); p instanceof Promise',
        ];

        yield 'number toFixed' => ['3.14', '(3.14159).toFixed(2)'];
        yield 'number toString radix' => ['ff', '(255).toString(16)'];
        yield 'json roundtrip' => ['{"a":[1,2],"b":"x"}', 'JSON.stringify(JSON.parse("{\"a\":[1,2],\"b\":\"x\"}"))'];
        yield 'json undefined dropped' => ['{"b":1}', 'JSON.stringify({a:undefined, f:function(){}, b:1})'];
        yield 'json indent' => ["{\n  \"a\": 1\n}", 'JSON.stringify({a:1}, null, 2)'];
        yield 'json string escapes' => ['"a\\"b\\n"', 'JSON.stringify("a\"b\n")'];

        // --- RegExp ---
        yield 'regex test' => [true, '/^a+b$/.test("aaab")'];
        yield 'regex exec groups' => ['aaa', '/^h(a+)!/.exec("haaa! yes")[1]'];
        yield 'regex exec index' => [4, '/b+/.exec("aaaabb").index'];
        yield 'regex replace global' => ['a#b#c#', '"a1b22c333".replace(/\d+/g, "#")'];
        yield 'regex replace groups' => ['22-11', '"11-22".replace(/(\d+)-(\d+)/, "$2-$1")'];
        yield 'regex ignorecase' => [true, '/HELLO/i.test("hello")'];
        yield 'regex lastIndex global' => ['0,3,0', 'var re=/a/g; var a=re.lastIndex; re.exec("xxa"); var b=re.lastIndex; re.exec("xxa"); ""+a+","+b+","+re.lastIndex'];
        yield 'regex split' => ['a|b|c', '"a1b22c".split(/\d+/).join("|")'];
        yield 'regex match global' => ['1,22,333', '"a1b22c333".match(/\d+/g).join(",")'];
        yield 'regex empty class' => [false, '/[]/.test("anything")'];
        yield 'regex source' => ['a+', '/a+/g.source'];
        yield 'regex sticky' => [false, 'var re=/b/y; re.test("ab")'];
        yield 'regex unicode escape' => [true, '/é/.test("é")'];
        yield 'string search' => [4, '"aaaabb".search(/b/)'];
    }

    #[DataProvider('cases')]
    public function testCase(mixed $expected, string $source): void
    {
        $this->assertJs($expected, $source);
    }

    public function testUncaughtBecomesHostException(): void
    {
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('Uncaught ReferenceError');
        $this->evalJs('missingIdentifier + 1');
    }

    public function testUnsupportedSyntaxFailsCompilation(): void
    {
        $this->expectException(CompileError::class);
        $this->evalJs('class A {}');
    }

    /**
     * A `let` captured by a closure inside a loop needs a fresh binding each
     * iteration. One slot is reused instead, which would hand every closure the
     * last value, so the shape is refused rather than answered wrongly.
     */
    public function testLetCapturedInALoopIsRejected(): void
    {
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('fresh binding per iteration');
        $this->evalJs('var f = []; for (var i = 0; i < 3; i++) { let j = i; f.push(function () { return j; }); }');
    }

    /** A `for` head's binding is per-iteration too, so the same refusal applies. */
    public function testLetInAForHeadCapturedByAClosureIsRejected(): void
    {
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('fresh binding per iteration');
        $this->evalJs('var f = []; for (let i = 0; i < 3; i++) { f.push(function () { return i; }); }');
    }

    /** A for-in head binds per iteration too, so a capture is refused. */
    public function testLetInAForInHeadCapturedByAClosureIsRejected(): void
    {
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('fresh binding per iteration');
        $this->evalJs('var f = []; for (const k in {a:1}) { f.push(function () { return k; }); }');
    }

    /**
     * Peast returns the wrong target for `({x = f = 1} = v)` -- the shorthand's
     * key is lost -- so the shape is refused rather than compiled from a tree
     * that does not match the source.
     */
    public function testShorthandWithAnAssignmentDefaultIsRejected(): void
    {
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('the parser loses the target');
        $this->evalJs('var f, x; ({x = f = 1} = {});');
    }

    /**
     * Draining an iterator happens in PHP, outside the dispatch loop that
     * normally checks the clock, so an endless iterable would otherwise spin
     * past the limit the host set.
     */
    public function testAnEndlessIterableStillHitsTheTimeLimit(): void
    {
        $engine = new \PhpJs\Engine();
        $engine->vm->setTimeLimit(0.5);
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('timed out');
        $engine->evaluate(
            'var o = {}; o[Symbol.iterator] = function () {'
            . ' return { next: function () { return {value: 1, done: false}; } }; };'
            . ' var a = [...o];'
        );
    }

    public function testStackOverflowIsRangeError(): void
    {
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('Maximum call stack size exceeded');
        $this->evalJs('function f(){ return f(); } f()');
    }
}

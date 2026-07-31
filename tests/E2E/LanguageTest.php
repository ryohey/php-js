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

    public function testLetInAForInHeadIsRejected(): void
    {
        $this->expectException(CompileError::class);
        $this->evalJs('for (let k in {a: 1}) {}');
    }

    public function testStackOverflowIsRangeError(): void
    {
        $this->expectException(JSException::class);
        $this->expectExceptionMessage('Maximum call stack size exceeded');
        $this->evalJs('function f(){ return f(); } f()');
    }
}

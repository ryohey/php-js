# php-js-transpile

Compiles JavaScript functions to PHP ahead of time, for the
[php-js](../..) runtime. Build-time only — nothing here runs on a request.

The idea and the measurements behind it are in [docs/aot-php.md](../../docs/aot-php.md);
this README is the operating manual.

## What it does

Runs the ordinary bytecode compiler with a per-function hook attached, and for
each function tries to emit an equivalent PHP closure. Functions it accepts get
a `nativeId` stamped on their bytecode template; at run time a `JSFunction`
whose `nativeId` is registered calls that PHP instead of interpreting.

Three properties follow from that design and are worth stating plainly:

- **The bytecode is still there.** Generated PHP is optional at run time; with
  none registered the program runs exactly as before. That is what makes it
  safe to ship separately, or not at all.
- **The function is still a `JSFunction`.** `.prototype`, `[[Construct]]`,
  `.length`, `.name` and `instanceof` are unchanged, so an ahead-of-time
  compiled function is not distinguishable from the one it replaced.
- **There is no marshaling.** Generated code shares the heap and the value
  representation with the interpreter, so calls cross the boundary in either
  direction for the price of a PHP function call.

## Using it

```console
$ composer install
$ bin/phpjs-transpile -v path/to/module.js -o build/
module.js                    146 / 201  functions -> build/module-js.4f3a….php
```

From PHP, over a `node-compat` host:

```php
use PhpJs\Transpile\NodeIntegration;

$aot = new NodeIntegration(fn (string $path) => str_contains($path, '/node_modules/react'));
$aot->attach($host);          // before the first require
$app = $host->requireModule('./js/app.js');

printf("%d / %d functions\n", $aot->totalConverted(), $aot->totalSeen());
```

`NodeIntegration` compiles and registers in-process, which is what the tests
and benchmarks use. A production build should write the PHP to disk with
`Artifact::writePhp()` and load it through `opcache.preload` — `eval`'d code is
never cached by opcache, which is the entire deployment argument.

## Coverage

Against React 19's two production sources, 202 of 268 functions compile. What
it refuses, and why, is the emitter's real specification:

| Refusal | Count |
|---|---|
| `switch` statement | 28 |
| the function's own locals are captured | 23 |
| `try` statement | 15 |
| nested function expression | 3 |
| `typeof` on a possibly-undeclared global | 2 |
| labelled statement | 1 |

A refusal is a normal outcome — that function keeps running as bytecode.

## Correctness

The only verification that means anything for a transpiler is that compiling a
function changed nothing observable, so that is what the tests check:

- `EmitterTest` runs ~70 programs twice, once on bytecode and once with every
  accepted function replaced, and requires identical results. Cases lean on the
  corners: `-0`, NaN comparisons, short-circuit side effects, for-in during
  deletion, prototype getters and setters, `arguments` past the end.
- `ReactAotTest` renders the real React 19 app both ways and requires the HTML
  to match byte for byte, with 200+ functions converted.

## What it does not do

Conservative by construction: property writes take the full `[[Set]]` path,
arithmetic goes through `Ops`, calls go through `Vm::invoke`. No type
speculation, no escape analysis, no assumption that a write target is a fresh
object, no assumption that `hasOwnProperty` is still the builtin.

That costs 3.5-8x against hand-written PHP for the same function
(docs/aot-php.md §9), and it is the right default: every specialization beyond
this needs its own justification, and for a build-time renderer correctness is
worth more than the last 2x.

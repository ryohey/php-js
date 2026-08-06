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

That is build mode: it compiles and registers in-process via `eval`, which is
convenient for tests but costs 280-450 ms of boot per process and is never
cached by opcache. The deployable shape is a build step and a run step:

```php
// build.php -- once, at deploy time
$aot = NodeIntegration::forBuild($accept);
$aot->attach($host);
$host->requireModule('./js/app.js');
$aot->writePhp('build/react.aot.php');

// runtime
Artifact::register('build/react.aot.php');   // opcache holds this
$aot = NodeIntegration::forRun($accept);     // stamps IDs, compiles nothing
$aot->attach($host);
```

Native IDs are derived from each module's contents, so the two agree, and an
upgraded dependency stops matching its stale natives rather than binding them
to the wrong functions.

That `Artifact::register()` / `NodeIntegration::forRun()` pair is explicit
wiring a project opts into for one combined file. There is now a path that
needs none of it: `writeArtifacts()` writes one file per module instead —
holding both that module's compiled template and whatever functions
converted to native PHP — under exactly the content hash the engine's own
`PhpJs\Cache\ArtifactCache` looks a source up by, so an ordinary `require()`
finds it, skipping compilation of that module entirely rather than just
interpreting less of it. A project
that just wants React (or anything else in `node_modules`) to go faster can
run [`packages/aot`](../aot)'s CLI once and never call anything in this
package directly at all. Reach for the pair above only when a deployment
shape genuinely wants one combined file (embedding it in `opcache.preload`,
for instance) rather than a directory `require()` already knows to check.

## What the input has to be

**Trusted, and pinned.** This is a code generator: it reads JavaScript and
writes PHP that gets `require`d. Two guarantees the interpreter makes do not
survive the translation, and both are verified rather than assumed:

- **`Vm::setTimeLimit()` is not enforced.** The deadline is checked by the
  dispatch loop, and generated PHP has no dispatch loop. A `while (true) {}` the
  interpreter aborts after 0.5 s runs forever once it is compiled.
- **JS recursion becomes PHP recursion.** Interpreted JS frames live on the VM's
  own stack, so runaway recursion raises a catchable `RangeError`. Compiled
  frames are PHP frames, and the same program exhausts memory with a fatal error.

Neither is a defect to be fixed later: they are what "no dispatch loop" and "a
real PHP function" mean, and they are most of why compiling is faster. There is
no injection surface — emission goes AST-to-AST through nikic/php-parser and
never concatenates strings — but an injection surface was never the concern. The
concern is that the interpreter is the part with the safety rails, and untrusted
code is exactly the code that needs them.

So the rule is: compile dependencies a lockfile pins, and let everything else
interpret. `NodeIntegration::pinnedDependencies()` is that filter:

```php
$aot = NodeIntegration::forBuild(NodeIntegration::pinnedDependencies());
```

Precompiled *bytecode* is a different matter and carries none of this: a
template file is `<?php return [...];` of plain arrays, so loading it defines
nothing and calls nothing, and the VM interprets it afterwards with every rail
in place. Precompiling any JavaScript is as safe as running it. It is only the
generated PHP that needs a trusted input.

## Closed-build assumptions

By default the emitter assumes nothing, and a literal translation is worth
about 17% on a render — but only 4% once PHP's tracing JIT is on, because the
JIT speeds up the interpreter's dispatch loop by roughly as much.

`Assumptions::closedBuild()` changes that. It permits two specializations that
are sound when the library is pinned and compiled at deploy time:

```php
$aot = NodeIntegration::forBuild($accept, Assumptions::closedBuild());
// ...and the run side must pass the same value:
$aot = NodeIntegration::forRun($accept, Assumptions::closedBuild());
```

- `hasOwnProperty.call(o, k)` becomes a direct own-property test, when the
  module proves the binding was assigned `Object.prototype.hasOwnProperty`
  exactly once and never reassigned.
- A `for-in` loop guarded by that call asks for own enumerable keys directly
  and drops the emitter's deleted-during-iteration check — the guard was going
  to discard the inherited keys anyway, and it already covers deletion.
- A write to a local proven to hold a fresh object literal, before it escapes,
  becomes a store instead of a `[[Set]]` walk.

One more specialization needs no assumption and is always on: `===` against a
string, boolean, `null` or `undefined` literal compiles to PHP's `===`.
Numeric literals are excluded — JS says `1 === 1.0` and PHP does not.

Neither is a name match or a hardcoded pattern for one library: both are proofs
over the module being compiled, and both refuse when the proof fails. With
them the numbers become **65% without the JIT and 58% with it** — the JIT and
this package stop being substitutes, because what these delete is work the
program does rather than overhead in how it is run. Once coverage is this
complete the JIT is worth about 1% on top, having no interpreter loop left to
accelerate; it earns its keep on code that was *not* compiled ahead of time.

Assumptions are hashed into the native IDs, so a run configured differently
from its build matches nothing and falls back to bytecode rather than running
code whose premises do not hold.

See [docs/aot-php.md §10-§11](../../docs/aot-php.md).

## Coverage

Against React 19, 262 of 291 functions compile. What it refuses, and why, is
the emitter's real specification:

| Refusal | Count |
|---|---|
| the function's own locals are captured | 23 |
| nested function expression | 5 |
| regexp literal | 1 |

The first row is the structural one: such a function has to allocate a `JSEnv`
and have its nested functions close over it, which means emitting nested
functions too.

A refusal is a normal outcome — that function keeps running as bytecode. None
of the three left is on the render's hot path: the interpreter is entered twice
per render for React, both times during setup.

## Correctness

The only verification that means anything for a transpiler is that compiling a
function changed nothing observable, so that is what the tests check:

- `EmitterTest` runs ~70 programs twice, once on bytecode and once with every
  accepted function replaced, and requires identical results. Cases lean on the
  corners: `-0`, NaN comparisons, short-circuit side effects, for-in during
  deletion, prototype getters and setters, `arguments` past the end.
- `ReactAotTest` renders the real React 19 app both ways and requires the HTML
  to match byte for byte, with 200+ functions converted.

The corners are where the bugs were. Both of the emitter's two real defects so
far were a `continue` landing in the wrong place — once inside a `switch`
inside a `for`, once inside a `do`-`while` — and both were found by an
equivalence case, not by reading the generated PHP.

## What it does not do

Conservative by construction: property writes take the full `[[Set]]` path,
arithmetic goes through `Ops`, calls go through `Vm::invoke`. No type
speculation, no escape analysis, no assumption that a write target is a fresh
object, no assumption that `hasOwnProperty` is still the builtin.

That costs 3.5-8x against hand-written PHP for the same function
(docs/aot-php.md §9); the closed-build assumptions above bring it to 2.3x. It
is the right default: every specialization beyond this needs its own
justification, and correctness is worth more than the last 2x.

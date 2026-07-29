# Ahead-of-time PHP for the hot path

Status: **phases 0, 0.5 and 1 done.** This adapts an
externally drafted "hot-path transpilation" strategy to what this runtime
actually is and what it actually measures. Read DESIGN.md first; §2
(compilation pipeline), §5 (object model) and §11 (shared-nothing / opcache)
constrain most of what follows.

Where it stands: native library substitution took React 19 down 30% (§6 phase
0). The transpiler now exists (`packages/php-transpile`), compiles **219 of
291** React functions to PHP with byte-identical output, and takes another
**16.6%** off a render. It does *not* hit the speed the hand-written pricing
experiment predicted — §9 has the honest accounting and what it means.

The goal is a Next.js-SSG-equivalent: render React trees to static HTML at build
time, in PHP. Build time only, closed input, no untrusted JS.

## 1. Corrections to the incoming proposal

The draft was written without access to this codebase. Five of its premises do
not survive contact with it, and two of its designs already exist here.

**The bottleneck is not dispatch.** The draft opens with "Bottleneck: dispatch
overhead, not GC/alloc". That was this project's own claim until it was
measured, and it is wrong. The superinstruction pass removed 13% of the
instructions executed in a render and bought about 4% of the wall time, because
the instructions it removes are the cheapest ones. A per-opcode time profile:

| Opcode | Share of instructions | Share of time |
|---|---|---|
| `CALL` | 3.9% | 15.4% |
| `GET_METHOD` | 2.2% | 7.3% |
| `GET_PROP` + `GET_LOCAL_PROP` | 7.6% | 10.8% |
| `RETURN` | 2.1% | 3.9% |

A third of a render is calls and property lookups at roughly ten times the cost
of a cheap opcode. This matters for the plan because "fewer instructions" is not
the objective function — "less work per operation" is. It also means the
expected win from AOT PHP is *larger* than a dispatch-cost model predicts, since
compiled PHP removes the expensive opcodes too, not just the dispatch around
them.

**The dispatch mechanism the draft designs already exists.** The draft proposes
a build-time `functionId → {kind, entry}` table consulted by `CALL`, explicitly
avoiding a new opcode. That is precisely this runtime's `BuiltinRegistry`:
native functions are referenced from the heap by **string ID**, `CALL` already
branches once per call between the bytecode path and the native path, and
`BuiltinRegistry::registerHost()` already exists as the extension point for
out-of-core packages. No new opcode, no new table, no new calling convention.
What is missing is only the build-time *generation* of entries. This is not a
coincidence — DESIGN.md §11.3 forbids PHP closures on the JS heap, which forces
the ID-indirection design the draft arrived at independently.

**"Treat JS numbers as `float` uniformly in emitted PHP" must be rejected.**
DESIGN.md §3.1 fixes the representation as PHP `int|float`, int only where a
double is exact (`Conversions::MAX_EXACT_INT`). Emitting uniform floats would
make transpiled and interpreted code disagree at every boundary crossing:
`is_int` fast paths in the VM, `===`, property keys (PHP truncates float array
keys), and number→string formatting. Transpiled code uses `Conversions` and the
same representation as everything else. The draft's JIT-friendliness argument is
real but is not worth a two-representation runtime.

**"`createElement` → PHP array literal" must be rejected for the same reason.**
React elements cross the transpiled/interpreted boundary constantly, because
user components stay on the VM and return elements to a transpiled walker.
Transpiled code constructs the same `JSObject`s the VM does. The win here is
removing the interpreter, not changing the heap — and keeping one heap
representation is what makes the boundary free (§3 below).

**The scope decision is right, and now measured.** "Transpile library internals,
not user components" holds: in a 20-row render, user component code is under 5%
of self time on React 17 and about 5% on React 19 (mostly one `.map` callback
building rows). Everything else is React or this project's own shims.

**React 19 already works, today, unmodified.** The draft treats React 19 support
as part of the project. It is not:

- React 19.2.8's production CJS builds compile clean under the ES5.1 compiler —
  React ships ES5-compatible output, so the "downlevel with SWC first"
  precondition does not apply to React itself.
- `renderToStaticMarkup` renders **byte-identically to Node** already.
- The synchronous renderer this plan targets —
  `react-dom/cjs/react-dom-server-legacy.node.production.js`, which is exactly
  what `react-dom/server` re-exports `renderToStaticMarkup` from — needs
  nothing beyond `crypto` and `async_hooks` stubs and a `queueMicrotask`
  global, all now in `node-compat`. Going through the `react-dom/server` entry
  additionally drags in the streaming renderer and with it `MessageChannel` and
  `AbortController`, so the fixture requires the sync build directly.

## 2. Measured starting point

`renderToStaticMarkup`, 20 rows, same machine and sitting:

| | php-js | php-js + JIT | Node 22 | ratio |
|---|---|---|---|---|
| React 17.0.2 | 88.2 ms | 70.8 ms | 1.34 ms | 53x |
| React 19.2.8 | 128 ms | — | 2.09 ms | 62x |

React 19 is slower here than React 17 because its SSR path does more work, not
because of anything the runtime does differently.

A render executes a **small, fixed set of JS functions** — 36 distinct ones on
React 17, 73 on React 19 — and the time is concentrated:

| React 17 | self time | | React 19 | self time |
|---|---|---|---|---|
| `ReactDOMServerRenderer.render` (tree walk) | 23.8% | | `Math.clz32` (**our JS polyfill**) | 20.5% |
| `renderDOM` | 14.4% | | `createElement` | 8.4% |
| `createElement` | 13.3% | | `renderElement` | 7.9% |
| `escapeTextForBrowser` | 9.5% | | `pushStartGenericElement` | 6.7% |
| `createMarkupForProperty` | 6.7% | | `flushSubtree` | 5.6% |
| top 10 combined | 81% | | top 10 combined | 71% |

That `Math.clz32` line is the finding that reorders the whole plan. React 19
uses `clz32` for tree-context bit manipulation and calls it 290 times per
render; `node-compat` ships it as a **JS polyfill that shifts one bit at a
time**. Replacing `clz32` and `imul` with two native PHP functions — about
twenty lines, using the extension point that already exists — takes React 19
from 128 ms to 102 ms with identical output.

**A 20% win, from two functions, with no new machinery.** That is more than the
superinstruction pass and the prototype-walk fix combined. It is the thing to do
before writing a transpiler, and it sets the bar the transpiler has to clear.

And it is not the only one. Attributing every entry in the React 19 profile:

| Origin | Share of render |
|---|---|
| `node-compat` JS polyfills | **~27%** |
| React internals | ~68% |
| User component code | ~5% |

The polyfill share is `clz32` at 20.5% plus the `Map`/`Set` implementation at
about 6% (`keyOf` 4.2% and its `_k`/`_entries` accessors 2.0%) — React 19 uses
`Map` heavily inside the renderer. So **roughly a quarter of a React 19 render
is this project's own JS shim code**, and phase 0 addresses all of it.

One caveat on a native `Map`/`Set`: §11.3 forbids foreign PHP objects on the JS
heap, so `SplObjectStorage` is not available. A native implementation is a
`JSMap extends JSObject` holding plain PHP arrays keyed by an object-id stamp —
structurally what the polyfill already does, but without the interpreter. The
win should be large but it is not the near-total elimination `clz32` got.

*Profiler calibration.* These shares come from an interval profiler that adds an
`hrtime` pair per instruction, which inflates functions made of many cheap
instructions. The `clz32` ablation is the check: the profile predicted ~26 ms
once deflated for total instrumentation overhead, and removing it saved 26 ms.
Attribution is therefore trustworthy to within a few points — but only the
`clz32` line has been confirmed by ablation, and the ranking below it is a
hypothesis until each one is likewise replaced and measured.

## 3. How AOT PHP plugs into this runtime

### The boundary is free, so build for many small islands

Because transpiled PHP and the VM share one value representation and one heap,
a transpiled function is an ordinary native:

```php
fn(Vm $vm, mixed $thisVal, array $args, JSNativeFunction $self): mixed
```

It receives and returns runtime values (`JSObject`, `int|float`, `string`,
`bool`, `null`, `JSUndefined`). It calls back into interpreted code with
`$vm->invoke($fn, $this, $args)` and raises JS exceptions with
`$vm->throwError()` — the same path every existing builtin uses. There is no
marshaling step in either direction.

This invalidates the draft's central cost model. It argues at length for
maximal-munch island detection because "VM-boundary crossing count is the actual
cost driver". Here a crossing costs one PHP function call. The draft's
post-order tiling algorithm is the right algorithm for a runtime with a
marshaling boundary; this runtime does not have one, so **function-level
granularity is not a coarse approximation to be refined — it is very likely the
right granularity, permanently.** Subtree-level islands get built only if a
specific hot function is provably unconvertible as a whole and profiling says it
matters.

### How a function gets substituted

At build time the transpiler tags the child function template with a native ID.
Templates are plain `var_export`-able arrays (§11.1), and an ID is a string, so
this rides opcache unchanged:

```php
['name' => 'escapeTextForBrowser', ..., 'nativeId' => 'aot.react19.escapeTextForBrowser']
```

`NEW_FUNC` constructs a `JSTranspiledFunction` instead of a `JSFunction` when
the template carries the tag. `JSTranspiledFunction extends JSNativeFunction`
and adds one field:

```php
final class JSTranspiledFunction extends JSNativeFunction
{
    public ?JSEnv $env;   // the defining environment, exactly as JSFunction holds it
}
```

`CALL` needs **no changes at all** — the existing `instanceof JSNativeFunction`
branch already handles it. `JSEnv` on the heap is explicitly allowed by §11.3
(`JSArgumentsObject` already relies on this).

The `$env` field is not optional: React's internals are module-level functions
that read module-level bindings, which this compiler places in environment
slots. Generated PHP reads them as `$self->env->slots[N]`. For the slot numbers
to be right, **the transpiler must reuse `Compiler`'s scope-analysis pass rather
than running its own** — same AST, same `Ctx`, same `Binding` assignments. This
is the single most important structural requirement on the implementation.

### What the transpiler may not do

- Not invent a number representation (§1).
- Not invent an object representation (§1).
- Not build PHP source by string concatenation. Agreed with the draft: emit
  nikic/php-parser AST nodes and let the pretty-printer escape. Content values
  become `Scalar\String_` nodes, never code fragments.
- Not put a PHP closure, resource, or foreign object where the JS heap can reach
  it (§11.3). The string-ID indirection is not negotiable.
- Not generate code at runtime. Build writes `.php` files; runtime `require`s or
  preloads them. `eval()` is not opcache-cached and is out.

## 4. Package layout

Core stays clean; this follows the existing split.

- **`packages/php-transpile`** (new) — build-time only. Depends on
  `nikic/php-parser`. Reads JS with the core `Compiler`'s analysis pass, emits
  PHP files plus a registry manifest. Never loaded at render time.
- **`packages/node-compat`** — gains the native ES6-library functions that are
  currently JS polyfills, and a `crypto` stub. This is phase 0 and needs no new
  package.
- **core** — gains at most `JSTranspiledFunction`, a `nativeId` template field,
  and three lines in `NEW_FUNC`. Nothing else.

Generated PHP is written one function per file, named by content hash
(`hash('xxh128', $jsSource)`), and loaded through `opcache.preload` at server
start. Content-hash naming also gives correct invalidation when React is
upgraded, which matters more here than the draft's path-traversal argument —
build input is trusted, but a stale artifact silently rendering wrong HTML is
the realistic failure.

## 5. Verification

The draft has no verification story. This project already has the right one, and
every phase is gated on both:

1. **Byte-identical to Node.** `packages/react-ssr-bench` already asserts this
   for `renderToStaticMarkup` and `renderToString`. Extend it to React 19. No
   substitution or transpilation lands without it passing.
2. **test262 pass rate does not drop.** Anything touching core (the `nativeId`
   field, `NEW_FUNC`) must hold 96.49% with an identical failure set.
3. **A substituted function must be equivalence-testable in isolation** — the
   native and the original JS both callable, compared over a case list. The
   `clz32` probe already did this ad hoc; it should be a test helper.

Benchmark methodology, learned the hard way: compare versions with
**interleaved paired runs**, never a batch of "before" followed by a batch of
"after". This machine drifts 20% between sittings, which is larger than every
effect measured so far.

## 6. Build order

**Phase 0 — native substitution. No transpiler. → DONE.**
Native `Math.clz32/imul/trunc/sign/log2/log10/cbrt/hypot/fround` and native
`Map`/`Set`/`WeakMap`/`WeakSet` in `node-compat`, plus `crypto` and
`async_hooks` stubs and a `queueMicrotask` global (React 19's server entry
needs all three). The React 19 fixture is now part of the benchmark.

*Result: **−29.9%** on a React 19 render (130 → 91 ms), 7/7 paired runs, output
hash unchanged and still byte-identical to Node on both render methods.*

Two things worth carrying forward from doing it:

- The natives install *before* `js/polyfills.js` runs, and that file defines
  only what is missing, so the JS versions became fallbacks without being
  deleted. A test asserts the ordering, because inverting it would silently
  give the 30% back.
- Reading arguments as `$args[0] ?? JSUndefined` is wrong and it bit twice: JS
  `null` arrives as PHP `null`, so `??` turns it into `undefined`. That made
  `Math.sign(null)` return NaN instead of 0, and made `null` and `undefined`
  the same `Map` key. Use `array_key_exists`. DESIGN.md §5.1 flags the same
  trap for property reads; it applies to every native's argument list.

**Phase 0.5 — pricing. → DONE, and the answer is go.**
Hand-wrote React 19's `createElement` as a PHP native to measure N before
building anything. **N ≈ 18x** with conservative `[[Set]]` semantics, ~40x if
the emitter proves the write target is a fresh object (§8). The end-to-end
render moved 12.8%, matching the 12.6% the per-call numbers predict.

**Phase 1 — the emitter, end to end. → DONE, with one criterion missed.**
`packages/php-transpile`: Peast AST → the bytecode compiler's own scope
analysis → php-parser AST → PHP → `BuiltinRegistry`. It turned out to be no
harder to compile *every* function a module has than to select one, so it
does that and refuses what it cannot handle.

*Achieved:* runs unattended over React 19 (219/291 functions, 75% by the CLI's
count over the two source files), renders byte-identically to the interpreted
run for both render methods, and takes **16.6% off a render** (7/7 paired).
test262 unchanged, all suites green.

*Missed:* generated PHP is **3.5-8x the cost of the hand-written native**, not
the ~2x the exit criterion asked for. §9 explains why, and it is not a
fixable oversight — a substantial part is work the hand-written version was
entitled to skip and the emitter is not.

Two design decisions worth carrying forward:

- A transpiled function stays a `JSFunction` and gains a `nativeId`; it is not
  swapped for a `JSNativeFunction`. The first attempt did swap it, and lost
  `.prototype`, `[[Construct]]` and `instanceof` — `function A() {}` stopped
  being usable as a constructor. Keeping the JSFunction means an ahead-of-time
  compiled function is not distinguishable from the one it replaced, which is
  the whole contract.
- The template still carries its bytecode, and `JSFunction` only takes the
  native path when that ID is actually registered. So the generated PHP is
  optional at run time: ship it, or don't, and the program behaves the same.

**Phase 2 — close the refusals, then the fixed overhead.**
The emitter refuses 72 of React's 291 functions, and the reasons are now
measured rather than guessed:

| Refusal | Count |
|---|---|
| `switch` statement | 28 |
| function's own locals are captured (needs an environment record) | 23 |
| `try` statement | 15 |
| nested function expression | 3 |
| `typeof` on a possibly-undeclared global | 2 |
| labelled statement | 1 |

`switch` and `try` are ordinary emitter work. The environment-record case is
the structural one: a function whose locals are captured must allocate a
`JSEnv` and have its nested functions close over it, which means emitting
nested functions too — that is the same problem as phase 4's islands, arriving
from a different direction.
*Exit:* refusals below ~10% of functions, with the remainder characterised.

Then the fixed overhead from §9: every operator is a static call today. Fusing
common shapes (a comparison feeding an `if`, a property read feeding a call)
is the same idea as the bytecode peephole pass and should transfer.

**Phase 3 — preload wiring.**
`opcache.preload` for the generated set, `opcache_compile_file()` warmup as a
fallback. Measure with and without; the draft's per-file `stat()` concern is
real only when preload is not used.

**Phase 4 — subtree islands, only if earned.**
Implement post-order convertibility tagging only if phase 2 produced a hot
function rejected as a whole for a small, isolable reason. Otherwise skip it.

**Not in scope:** runtime type feedback and deopt guards (the draft correctly
rejects these for a closed build-time input), streaming SSR and Suspense, and
hand-porting the hooks dispatcher. That last one is where I part company with
the draft: hand-written PHP React internals are a fork that drifts on every
React release, with no mechanical way to tell it has drifted. Transpiled output
is regenerable from the upstream source and is checked by byte-identity. Prefer
transpiling even where hand-porting looks easier.

## 7. Where phase 0 left the profile

Re-profiling React 19 after phase 0, the polyfills are gone from the render
entirely — 73 distinct JS functions down to 60 — and what remains is flat:

| Function | self time |
|---|---|
| `createElement` | 11.3% |
| `renderElement` | 10.2% |
| `pushStartGenericElement` | 8.9% |
| `flushSubtree` | 8.1% |
| `pushStartInstance` | 7.3% |
| `retryNode` | 7.2% |
| the app's own `.map` callback | 5.8% |
| `renderNodeDestructive` | 4.5% |
| `renderNode` | 3.8% |
| `escapeTextForBrowser` | 3.7% |
| top 10 combined | 71% |

**This makes the transpiler case harder, not easier.** Phase 0 took the one
concentrated target; there is no 20% item left. Each remaining function is
worth 4-11% of the render *before* multiplying by however much faster compiled
PHP is than the interpreter for that function — and these are the object-heavy,
call-heavy ones, not arithmetic loops, so the multiplier will be nothing like
`clz32`'s.

Converting the entire top 10 at a hypothetical 5x on that slice would yield
about 1.8x overall. That is the number the transpiler has to be worth building
for.

*Reproducing this:* the per-function profile comes from an ad-hoc patch that
accumulates `hrtime` deltas per template at the top of the dispatch loop. It is
not committed — a per-instruction hook costs a few percent and does not belong
in the loop. See §2 for the calibration check that makes its output usable.

## 8. Pricing the transpiler: 18x hand-written (but see §9)

§7 left one number unmeasured, and it is the number the whole decision turns
on: how much faster is hand-written PHP than the interpreter for an
*object-heavy* React internal — not an arithmetic loop like `clz32`, but
something that enumerates properties, copies them, reads through prototype
chains and allocates objects.

Measured by reimplementing React 19's `createElement` (11.3% of a render) as a
PHP native and swapping it in. Two variants, because the answer depends on how
much the emitter is willing to prove:

| Call shape | JS | native, direct writes | native, full `[[Set]]` |
|---|---|---|---|
| 1 prop, 1 child | 40.3 µs | 0.98 µs | 2.24 µs |
| 2 props, 1 child | 49.1 µs | 1.20 µs | 2.53 µs |
| 1 prop, 2 children | 60.1 µs | 1.61 µs | 3.09 µs |
| no props, 1 child | 19.9 µs | 0.14 µs | 1.07 µs |
| **N** | — | **37-146x** | **18-19x** |

The right-hand column is the conservative one: every property write goes
through the full `[[Set]]` path with its prototype-chain setter checks, which
is what an emitter produces when it will *not* assume the target is a fresh
plain object. It is remarkably stable at ~18x across shapes. The extra ~2x in
the middle column needs an escape analysis proving `props` is a fresh
`{}` — standard, but it is the difference between two tiers of emitter, not
free.

All three produce identical elements, and the swapped-in native renders
byte-identically to Node.

**The model checks out end to end.** 230 `createElement` calls per render
(counted, not estimated — the profiler's 460 "entries" double-counts re-entry
after a nested call) × 40 µs = 9.2 ms of a 73 ms render = 12.6% predicted.
Measured saving from the swap: **12.8%, 7/7 paired runs.** The per-function
profile, the per-call microbenchmark and the end-to-end render all agree.

So the answer to §7's question is **build it**. 18x conservative is far above
the 8x that would justify the work and nowhere near the 2x that would kill it.

*(Read §9 next. The emitter, once built, reached 3-6x rather than 18x, and the
reason is visible only in hindsight: this hand-written implementation skipped
work a faithful emitter has to keep. The decision still stands — 3-6x is worth
having — but the number in this section is not the one to plan with.)*

Projecting the top 10 functions (71% of the render) at a conservative N=18
gives `1 / (0.29 + 0.71/18)` ≈ **3.0x** on top of what phase 0 already did.
§9 revises that to ~2.1x with the emitter's real multiplier. The residual is
then almost entirely the fraction left interpreted, which is where the argument
for subtree islands (phase 4) would finally come from.

One aside worth keeping: materializing `arguments` costs about **2.6 µs per
call** on its own, and React's `createElement` touches it on every call. The
native avoids it by reading the argument list directly, which is exactly what a
transpiler does with `arguments.length` — the compiler already tracks
`usesArguments` per function (§2.2), so the information is there. But 2.6 µs is
only ~6% of `createElement`'s 40 µs; the bulk is the `for-in` over the config
(which walks the prototype chain to build its key list), the
`hasOwnProperty.call` native round-trip per property, and the element literal.

## 9. What the emitter actually costs, and why the pricing was optimistic

Phase 1's exit criterion was "generated PHP within ~2x of the hand-written
native". It came out at **3.5-8x**. Per call, on React 19's `createElement`:

| Call shape | bytecode | generated | hand-written | gen. vs bytecode | gen. vs hand |
|---|---|---|---|---|---|
| 1 prop, 1 child | 44.6 µs | 14.5 µs | 2.62 µs | 3.1x | 5.5x |
| 2 props, 1 child | 55.6 µs | 18.2 µs | 2.32 µs | 3.0x | 7.8x |
| 1 prop, 2 children | 65.0 µs | 24.9 µs | 4.55 µs | 2.6x | 5.5x |
| no props, 1 child | 23.9 µs | 4.09 µs | 1.14 µs | 5.8x | 3.6x |

Splitting that by where the cost lands is the useful part. With no properties
to copy, generated is ~4x the hand-written cost — that is the emitter's fixed
overhead. The marginal cost *per copied property* is about 7.0 µs generated
against 0.6 µs hand-written, roughly 12x.

That per-property gap is not sloppiness. React's copy loop is

```js
for (propName in config)
  hasOwnProperty.call(config, propName) && ... && (props[propName] = config[propName]);
```

The hand-written native iterates `ownEnumerableKeys()` and simply drops the
`hasOwnProperty.call`, because a human knows own-enumerable-keys already
satisfies it. The emitter cannot: it would have to prove that `hasOwnProperty`
is still `Object.prototype.hasOwnProperty` and that `.call` is still
`Function.prototype.call`. So it emits both invokes, per property, faithfully.

**So the §8 pricing of N ≈ 18x was optimistic, and now we know the mechanism:
a hand-written implementation is allowed to elide work that a faithful emitter
must keep.** The honest multiplier for generated code is **3-6x**, and the
gap between 3-6x and 18x is the value of the assumptions a human makes without
noticing.

Two consequences for what comes next:

- The remaining fixed overhead is worth attacking (the emitter still routes
  every operator through a static call), but the per-property gap needs
  *identity assumptions about builtins* — "this really is
  `Object.prototype.hasOwnProperty`" — which is a different and much larger
  design question than anything in §3. It is speculation with a guard, which is
  exactly what §1 rejected for this use case. Whether an SSG build's closed
  input makes it provable rather than speculative is the interesting question,
  and it is not answered here.
- Revised projection: at N=4 across converted code the whole-render ceiling is
  `1 / (0.29 + 0.71/4)` ≈ **2.1x**, not 3x. The measured 16.6% on one render is
  consistent with that, since only part of the render is converted.

Two cheap emitter improvements were tried and are in: results already known to
be PHP bools skip `Conversions::toBoolean` (including across the temporaries
that `&&` chains create), and property reads go through an `Ops::getProp` fast
path. Together they were worth almost nothing on `createElement` — which is how
the property-copy loop was identified as the real cost.

## 10. What this can and cannot achieve

The transpiler's real multiplier is 3-6x on converted code (§9), not the 18x
the hand-written pricing suggested, which puts a fully converted render at
about **2x** on top of phase 0 rather than 3x. Measured so far: 16.6% from
converting React alone. Counting phase 0, that is roughly **1.8x end to end**
from where this began, with maybe 2.5-3x reachable if phase 2 clears the
switch/try refusals.

Node is 2.09 ms and React 19 on this runtime is now ~80 ms. Nothing in this
plan closes a 38x gap; it makes a build-time renderer meaningfully faster, and
that is the honest scope.

The binding constraint at that point is no longer the converted code but the
**29% left interpreted** — which is what would finally justify subtree islands
(phase 4), and why that phase stays last rather than being designed up front.

One thing still worth weighing:

- **For SSG specifically, memoization may dominate all of this.** Build-time
  rendering with fixed input means identical subtrees render repeatedly across
  pages. Caching rendered output keyed by element identity is a different lever
  entirely, is far cheaper to build, and composes with everything above. It is
  out of scope here, but it should be measured before the transpiler is, not
  after.

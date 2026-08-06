# Ahead-of-time PHP for the hot path

Status: **phases 0, 0.5, 1, 1.5, 2, 2.5 and 3 done — and phase 3's own file
path generalized past React specifically, §4.** This adapts an
externally drafted "hot-path transpilation" strategy to what this runtime
actually is and what it actually measures. Read DESIGN.md first; its §2
(compilation pipeline), §5 (object model) and §11 (shared-nothing / opcache)
constrain most of what follows. Section numbers below are this document's.

Where it stands: native library substitution took React 19 down 30% (§6 phase
0). The transpiler (`packages/php-transpile`) compiles **262 of 291** React
functions to PHP with byte-identical output and takes a further **65%** off a
render, or **58%** on top of PHP's tracing JIT.

Getting there needed three things that are easy to state and were not obvious:
the build has to be allowed to prove things about a pinned library (§10); the
functions worth converting are the ones an emitter finds hardest, not the ones
it finds easiest (§6 phase 2); and refusals have to be ranked by how much of
the render they are, not by how many functions they block — the two orders were
almost unrelated (§6 phase 2.5). A literal translation that assumes nothing,
converting only the easy functions, was worth 16.7% and 4.2%.

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

### The input has to be trusted, and that is structural

The draft assumed a closed build-time input and was right to, but did not say
what goes wrong otherwise. Two guarantees the interpreter makes do not survive
compilation. Both are measured, not reasoned about:

| | interpreted | compiled |
|---|---|---|
| `while (true) {}` under `setTimeLimit(0.5)` | `RangeError` after 0.69 s | never returns |
| unbounded recursion | catchable `RangeError` | fatal: memory exhausted |

The deadline is checked by the dispatch loop (§4 of DESIGN.md, every
`DEADLINE_CHECK_INTERVAL` instructions) and generated PHP has no dispatch loop.
JS frames live on the VM's own stack and PHP's stack is untouched — that is
DESIGN.md §4's first promise — but a compiled call is a real PHP call, so
compiled recursion is PHP recursion. `Vm::invoke` even reaches the `nativeId`
branch before the re-entry counter, so `MAX_REENTRY` does not apply either.

None of this is a defect queued for repair. It is what "no dispatch loop" and "a
real PHP function" *mean*, and it is most of where the 65% comes from. Adding a
deadline tick to every generated loop would put back the per-iteration overhead
the whole exercise removes, and capping compiled recursion at `MAX_REENTRY` (400)
would refuse trees the interpreter renders fine (`MAX_FRAMES` is 10000) — a
capability regression traded for a limit the trusted case does not want.

So the boundary is a build-time policy, not a runtime check:

- **Compile what a lockfile pins.** `NodeIntegration::pinnedDependencies()` is
  that filter, and `packages/ssg-demo` uses it with a test that asserts no
  template outside `node_modules` carries a native ID.
- **Interpret everything else** — application code, anything authored per
  deployment, anything that arrives at run time. It costs about 3% on this
  workload (§6 phase 2.5 measured extending the filter to the app's own
  components), which is the right price for keeping the rails on.
- **Precompiled bytecode is not in scope for any of this.** A template file is
  `<?php return [...];` of plain arrays: loading it defines nothing and calls
  nothing, and the VM interprets it afterwards with every guarantee intact.
  Precompiling any JavaScript is exactly as safe as running it. Only the
  *generated PHP* needs a trusted input.

There is no injection surface either way — emission is AST-to-AST and never
concatenates strings — but that was never the risk worth naming.

## 4. Package layout

Core stays clean; this follows the existing split.

- **`packages/php-transpile`** — build-time only. Depends on `nikic/php-parser`.
  Reads JS with the core `Compiler`'s analysis pass, emits PHP. Never loaded
  at render time. `NodeIntegration` is the piece that hooks module compilation
  and does the emitting; it has no opinion about *which* modules to compile
  (that is `Trust`'s call, wherever it is invoked from) or where the result
  ends up (`writePhp()`/`php()` for one combined file, `writePerModule()` for
  an AOT cache directory — see below).
- **`packages/aot`** — build-time only, and the only *generic* piece: a CLI
  (`phpjs-aot build`) plus a `LibraryCompiler` class that reads a small JSON
  manifest naming `node_modules` specifiers, runs `php-transpile` over them,
  and writes the result into an AOT cache directory. It has no idea React (or
  any other specific library) exists — a project names what it wants compiled,
  the same way `packages/ssg-demo` does for React.
- **`packages/node-compat`** — gained the native ES6-library functions that
  were JS polyfills (phase 0), and `ModuleLoader` gained the *consuming* half
  of the AOT cache: on every `require()`, if no build hook is explicitly
  attached, it checks the cache directory (by the module's own content hash)
  and stamps whatever native IDs it finds — transparently, with no dependency
  on `php-transpile` at all (registering an already-compiled `.php` file needs
  none of the emitter). This is what makes AOT invisible to a host: a
  `NodeHost` constructed against a project root where the conventional cache
  directory happens to exist just goes faster, and one where it does not
  behaves exactly as if AOT never existed.
- **core** — gained nothing beyond what already existed. `nativeId` on a
  function template and `BuiltinRegistry` (§11.3) were already the whole
  mechanism generated PHP substitutes into; the AOT cache is entirely a
  node-compat/php-transpile/aot-package concern layered on top.

Generated PHP is written **one file per module** (not per function — a
module's several functions share one array, since they are always compiled
and looked up together), named by that module's own content hash
(`hash('xxh128', $moduleSource)`), and `ModuleLoader::aotLookupHook()` loads
one lazily, exactly when a `require()` for that exact module happens — not
through `opcache.preload`, which this arrived at a simpler alternative to:
opcache already caches the file the first ordinary `require` pulls in, with
no separate preload step or server-start hook to keep in sync with what a
build actually produced. Content-hash naming still gives correct invalidation
when a library is upgraded, for the same reason as before — build input is
trusted, but a stale artifact silently rendering wrong HTML is the realistic
failure — except now that safety is a directory-listing property (an upgraded
module's hash no longer matches any file there) rather than something a
combined manifest has to get right.

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
run for both render methods, and takes **16.7% off a render** (9/9 paired)
without the JIT — but only **4.2%** with it, which §11 argues is the finding
that matters most. test262 unchanged, all suites green.

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

**Phase 1.5 — specialize what a closed build can prove. → DONE.**
See §10. Two specializations, both gated behind an explicit `Assumptions` flag
that is off by default. Worth −3.6% on top of the literal build without the
JIT, and much more with it (§11).

**Phase 2 — `switch` and `try`. → DONE, and it was the whole game.**
The decision to do these first came from one measurement: with the 219
functions of phase 1 converted, **essentially all the remaining render time was
still in interpreted code**. The converted functions had become cheap enough to
vanish from the profile; the refused ones were the render. And every one of the
eleven hottest still-interpreted functions was refused for exactly one of two
reasons — `switch` or `try`. Not one for the closure case.

Both are emitter work. `switch` cannot use PHP's own, which compares loosely,
so it lowers to a `do { } while (false)` — which gives `break` its meaning for
free — with the matching clause index found first and `if ($m <= $i)` per
clause reproducing fallthrough. `try` maps straight onto PHP's, catching the
`JSThrowSignal` that a JS exception already is at a native boundary.

219 → **252 of 291** functions, and the render:

| | bytecode | AOT, closed build | |
|---|---|---|---|
| opcache, no JIT | 60.9 ms | 31.0 ms | **−47.9%** (9/9) |
| opcache + tracing JIT | 48.3 ms | 28.5 ms | **−38.7%** (9/9) |

Two bugs worth recording, both found by the tests rather than by reading:

- A `continue` inside a `switch` inside a `for` spun forever. The update is
  emitted at the end of the loop body (the test may need statements, so it
  cannot live in a PHP `for` header), and PHP `continue` skipped it. The guard
  that was supposed to refuse this searched the AST through a whitelist of
  getters, which does not include a switch's cases. The fix is better than the
  guard: when the body has a `continue`, it is wrapped in `do { } while (false)`
  and `continue` becomes a `break` of that wrapper — landing exactly on the
  update. The refusal is gone.
- The equivalence suite rejected one of its own new expectations. `switch (1)
  { case t("a"): ... case t("b"): ... }` evaluates *both* tests, because
  neither matches. Written as an assertion about "stops at the first match" it
  was simply wrong, and running the same program interpreted said so.

**What is left refused**, and it is now a different shape entirely:

| Refusal | Count |
|---|---|
| function's own locals are captured (needs an environment record) | 23 |
| labelled statement | 6 |
| nested function expression | 5 |
| `typeof` on a possibly-undeclared global | 4 |
| regexp literal | 1 |

The environment-record case is the structural one: such a function must
allocate a `JSEnv` and have its nested functions close over it, which means
emitting nested functions too — the same problem as phase 4's islands, reached
from another direction. It is also, now, most of what is left.

*Exit criterion met:* refusals are 13% of functions and the remainder is
characterised.

**Phase 2.5 — labels, and `typeof` on a global. → DONE.**
Counting refusals ranks them by how many *functions* they block, which is the
wrong axis. Counting interpreted frame entries in one render ranks them by how
much of the render they are, and the two orders had almost nothing to do with
each other. After phase 2 the render entered the interpreter 502 times, and
**376 of those were a single function** — `retryNode`, refused for a labelled
statement, the second-rarest refusal in the table above. The 23 environment-record
refusals, the largest row by function count, accounted for **two**.

So: labels, then the `typeof` that `retryNode` turned out to need next.

- Every entry on the emitter's breakable stack is exactly one PHP breakable
  construct, so a target's PHP level is its distance from the top of that stack
  — `break L` and `continue L` are the same walk as the unlabelled ones with a
  different stop condition. A loop consumes its own label so `continue L` can
  reach it; anything else labelled gets a `do { } while (false)` around it,
  which is all `break L` needs.
- `typeof x` on a global is the one name read that is not a ReferenceError, so
  it cannot use the ordinary global load. `Ops::typeofGlobal()` mirrors the
  VM's `TYPEOF_GLOBAL` opcode.

Implementing labels turned up a bug in code that had been shipped since phase 1:
`continue` inside a `do`-`while` skipped the test. The loop is emitted as
`while (true) { <body>; if (!t) break; }` — the test sits after the body, the
way JS runs it — so a PHP `continue` jumped over it. The `for` loop already had
the wrapper that fixes this; the `do`-`while` should have had it too, and now
does. Nothing in React hit it, and no test covered it until this one.

252 → **262 of 291** functions, and the render again:

| | bytecode | AOT, closed build | |
|---|---|---|---|
| opcache, no JIT | 59.6 ms | 20.9 ms | **−65.0%** (9/9) |
| opcache + tracing JIT | 48.9 ms | 20.7 ms | **−57.9%** (9/9) |

The interpreter is now essentially absent from the render. Interpreted frame
entries fell 502 → 123, and **121 of those 123 are the benchmark app's own
components**, which the build filter never accepted — it only accepts
`node_modules/react`. Two entries per render are all that is left of React
itself, both one-shot setup.

That also explains the second column. Turning the JIT on is worth 18% against
bytecode and **1% against this**: there is no longer an interpreter loop for it
to speed up, and the remaining time is in generated PHP and native builtins.
The JIT has become what the premise wanted it to be — insurance for code that
was not compiled ahead of time, which here means the application's own.

**What is left refused:**

| Refusal | Count |
|---|---|
| function's own locals are captured (needs an environment record) | 23 |
| nested function expression | 5 |
| regexp literal | 1 |

Nothing in this table is on the render's hot path any more. Phase 4 should be
judged on the application's components, not on React's remainder.

**Phase 3 — opcache wiring. → DONE, and no longer opt-in per project.**
`NodeIntegration::forBuild()` emits, `writePerModule()` (or `writePhp()`, for
one combined file) writes, and on the *reading* side a plain `require()` — no
`Artifact::register()`, no `NodeIntegration::forRun()`, no per-project wiring
at all — picks a matching artifact up on its own
(`ModuleLoader::aotLookupHook()`, packages/node-compat, §4). Native IDs are
still derived from the module's *contents*, so a build and a later run agree,
and an upgraded dependency stops matching its stale natives instead of
binding them to the wrong functions; that property is what made this
generalizable to any `require()`, not only ones a specific project's own
build script chose to instrument. Confirmed with `opcache_get_status()`:
966 KB of generated code resident in shared memory.

Two results, one expected and one not — see §11.

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

**So the §8 pricing of N ≈ 18x was optimistic, and the mechanism is now clear:
a hand-written implementation is allowed to elide work that a literal emitter
must keep.** For a literal translation the honest multiplier is **3-6x**, and
the gap up to 18x is the value of the assumptions a human makes without
noticing.

The useful follow-up is that those assumptions are not all unprovable —
§10 recovers a good part of that gap by proving them instead. With them,
`createElement` drops from 14.1 µs to **8.6 µs**, or from 5.5x to 3.5x the
hand-written cost.

Two cheap emitter improvements were tried and are in: results already known to
be PHP bools skip `Conversions::toBoolean` (including across the temporaries
that `&&` chains create), and property reads go through an `Ops::getProp` fast
path. Together they were worth almost nothing on `createElement` — which is how
the property-copy loop was identified as the real cost.

## 10. What a closed build is allowed to assume

§9 identified the emitter's biggest cost as work a human would skip and a
literal translation may not. The premise that makes it skippable is that **the
library is fixed at build time**: a pinned React version, compiled at deploy,
in a realm with no untrusted code. Under that premise some of those "may not"s
become provable.

Three are implemented, all behind `Assumptions` and all **off by default**,
because the emitter's contract is that compiling changes nothing observable and
each of these spends a little of that. A fourth needs no assumption at all and
is unconditional.

**`hasOwnProperty.call(o, k)` → a direct own-property test.** React writes
`var hasOwnProperty = Object.prototype.hasOwnProperty` once at module scope and
calls it 26 times. `ModuleFacts` scans the module and accepts the binding only
if it is assigned that expression **exactly once and never assigned again
anywhere in the module** — a mechanical proof over the module text, not a name
match. The emitter additionally requires the call site to resolve to *that*
module binding, so a local of the same name is not affected. What remains
assumed is only that `Object.prototype.hasOwnProperty` was still itself when
the module loaded, which is what the closed build buys.

This deletes two native invokes per property (through
`Function.prototype.call`, then through the builtin) — the single largest item
in §9's accounting.

**Writes to a proven-fresh object → a store instead of a `[[Set]]` walk.** A
local whose every assignment is an object literal, written before it first
escapes, cannot have an accessor in the way. "Escapes" is deliberately blunt —
any mention other than reading or writing through it counts — and the check
compares against the *first* escape offset, which is what makes it sound inside
loops: a loop body that both escapes and writes has the escape at the lower
offset, so the specialization simply does not apply there.

**A `hasOwnProperty` guard rewrites the whole loop.** `for (k in o) { if
(hasOwnProperty.call(o, k)) ... }` is *the* way to skip inherited keys, and
recognising it removes two things. The emitter was following the guard with its
own deleted-during-iteration check — the guard already covers that, since a
property deleted mid-iteration fails `hasOwnProperty` too. And the key list no
longer needs to be a full for-in list: the guard was going to discard the
inherited keys anyway, so the loop asks for own enumerable keys directly.

That second half was the largest single item left. Building a for-in list for a
plain object costs 648 ns against 185 ns for own keys alone, because it has to
enumerate `Object.prototype` to discover that none of it is enumerable — per
loop, every call. Recognised only when the guard is the body's first condition,
on the same object and the same loop variable.

**And one that assumes nothing: `===` against a non-numeric literal.**
`x === "key"`, `x === null`, `x === undefined` compile to PHP's own `===`,
because for a string, boolean, null or `undefined` literal, PHP identity and JS
strict equality agree on every possible value of the other side. Numbers are
excluded, and that exclusion is the point: JS says `1 === 1.0`, PHP does not,
and this runtime stores an exact integer as an `int` (DESIGN.md §3.1), so the
two really can meet. React's minified output is full of these guards, and each
one was a static call plus a `ToBoolean` that the result-is-a-bool tracking can
now also drop.

Measured on `createElement`, calling the generated closure directly so no
interpreter is in the loop:

| | per call | vs hand-written |
|---|---|---|
| generated, closed build (first cut) | 5.49 µs | 2.60x |
| + own-keys under a guard | **4.79 µs** | **2.28x** |
| hand-written PHP | 2.10 µs | 1x |

The property-copy loop that §9 blamed now emits a `foreach` over own keys, one
own-property test, three PHP string comparisons and a store — which is close to
what the hand-written version does by hand.

**Three other things were tried and did not work**, which is worth recording
because they all looked obvious:

- *Inlining the property-read fast path* (the single most common thing the
  emitter generates, 818 of React's 924 reads have a literal key). Measured
  3% **slower**, consistently. `Ops::getProp` costs 64 ns against 32 ns for the
  same test inline — but only when it hits, and React reads methods off
  prototypes often enough that the misses, which now pay the inline test *and*
  the call, more than cancel the hits.
- *Collapsing `&&` chains of booleans into one PHP expression* instead of a
  temporary and an `if` per link. No measurable change.
- *Reusing the previous link's temporary* in those chains rather than copying.
  Perhaps 3%, at the edge of noise; kept because it is strictly less code.

The pattern across all four is the same: **removing work wins, shaving
per-operation overhead does not.** The two changes that paid — dropping the
`hasOwnProperty` round-trip and dropping the prototype walk — each deleted
something the program was doing. The three that did not were all attempts to
make the same work cheaper.

The tests carry more negative cases than positive ones on purpose. A
specialization that fails to fire is a performance bug; one that fires when its
proof does not hold is a correctness bug. So `AssumptionsTest` checks that
nothing is specialized when the binding is reassigned later in the module, when
it never was the builtin, when a local shadows it, when the object escapes
before the write, when the local is not always an object literal, and when the
escape is inside the same loop as the write.

**Assumptions are part of the artifact's identity.** They are hashed into the
native IDs, so a run configured differently from its build matches nothing and
falls back to bytecode rather than running code whose premises do not hold.

## 11. opcache, and what the JIT does to the whole argument

The phase 1 measurement was taken with `eval`'d code, which opcache never
caches. Replacing that with a built file changes two things and, more
importantly, exposes one:

**Boot.** `eval` costs 280-450 ms more than `require` for the same 310 KB of
generated PHP, because every process re-compiles it. The file path is the only
deployable one; `eval` stays as a convenience for tests.

**Render — unchanged.** 74.5 ms (eval) vs 74.8 ms (file). Once compiled,
opcodes are opcodes.

**And then the JIT nearly ate the win — until the build was allowed to
assume things.** Paired runs, React 19 / 20 rows:

| | AOT, literal | AOT, closed build |
|---|---|---|
| opcache, no JIT | −16.7% | **−18%** |
| opcache + tracing JIT | −4.2% | **−12 to −16%** |

(Paired runs, 7-9 pairs, every set 9/9 or 7/7 for the closed build. The JIT row
is a range because the machine's speed moved by a third between measurement
sessions and the two configurations do not scale with it identically; the
per-function numbers above are the cleaner evidence for any single change.)

The middle column was the alarming one. The dispatch loop is a hot, type-stable
`while`/`switch` — precisely what a tracing JIT is good at — so JIT-ing the
interpreter recovered almost everything a *literal* translation was providing.
On that evidence the two looked like substitutes rather than complements.

The right-hand column is what changes the conclusion. With the closed-build
specializations of §10 the two stack: JIT alone is −14%, closed AOT alone is
−18%, and together they are **−27%** off the un-JIT-ed baseline. Compiling
ahead of time is worth **12-16% on top of** a JIT-ed interpreter, where a
literal translation was worth 4%.

Why they stack now is the interesting part. The JIT speeds up the interpreter's
own PHP, which is where a literal translation's advantage lived. It cannot
speed up a *native invoke* through `Function.prototype.call`, or a `[[Set]]`
walk up a prototype chain — those are work the program does, not overhead in
how the program is run. The specializations delete that work outright, so the
JIT has nothing to claw back.

## 12. What this can and cannot achieve

Measured end to end, React 19 at 20 rows, best configuration at each step:

| | render | vs start |
|---|---|---|
| where this began | 130 ms | 1.0x |
| + native library substitution (phase 0) | 88 ms | 1.5x |
| + ahead-of-time PHP, closed build (phases 1, 1.5) | 69 ms | 1.9x |
| + PHP's tracing JIT | **63 ms** | **2.1x** |

(Absolute figures from one machine state; this host's speed has moved by a
third between sessions. The ratios are the durable part.)

Node is 2.09 ms. Nothing here closes a 31x gap; it makes a build-time renderer
twice as fast, and that is the honest scope. Phase 2 (the switch/try refusals)
is the next increment, and §10 suggests the specializations have more left in
them than the coverage does.

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

# Ahead-of-time PHP for the hot path

Status: **plan, nothing built yet.** This adapts an externally drafted
"hot-path transpilation" strategy to what this runtime actually is and what it
actually measures. Read DESIGN.md first; §2 (compilation pipeline), §5 (object
model) and §11 (shared-nothing / opcache) constrain most of what follows.

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
- One gap: `react-dom/server.node` pulls in `crypto` (for the streaming path).
  `react-dom/cjs/react-dom-server-legacy.node.production.js` — the sync renderer
  this plan targets — needs nothing. A `crypto` stub in `node-compat` closes it.

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

**Phase 1 — one transpiled function, end to end.**
Pick the smallest hot pure leaf function (`escapeTextForBrowser`: a char loop
with a switch, no captures, 9.5% of React 17). Build the minimum pipeline —
Peast AST → `Compiler` analysis → php-parser AST → file → `BuiltinRegistry`
entry → `nativeId` on the template → `JSTranspiledFunction`. Nothing general:
one function, whatever it takes.
*Exit:* the pipeline runs unattended, output is byte-identical, and the
per-function profiler shows the function gone from the JS profile. This phase
is about proving the plumbing, not about speed.

**Phase 2 — the ranked list.**
Work down the profile: `createElement`, `pushStartGenericElement`,
`createMarkupForProperty`, `renderElement`. Extend the emitter only as far as
each target requires, and re-measure after every one. Expect targets to be
rejected as unconvertible; record which construct rejected them, because that
list is the real specification for the emitter.
*Exit:* top-10 functions converted or explicitly rejected, with a measured
number for each.

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

## 8. What this can and cannot achieve

If React internals are ~95% of a render and AOT PHP is *N* times faster on that
slice, the overall speedup is `1 / (0.05 + 0.95/N)`: 4.0x at N=5, 6.9x at N=10.
So a realistic landing zone is **5-7x**, putting React 19 around 20-25 ms
against Node's 2.09 ms — roughly 10x Node rather than 62x. Worth doing for a
build-time renderer; not parity, and nobody should plan as if it were.

Two things to weigh before committing to phase 1:

- **Phase 0 was a large fraction of the available win for a fraction of the
  effort** — it returned 30% in a day, and §7 shows it also flattened what is
  left. The next step is not phase 1 as written but the cheapest experiment
  that prices the transpiler: hand-write **one** object-heavy React internal
  (`createElement`, say) as a PHP native and measure it. That yields the real
  multiplier for the realistic case, without building any transpiler at all. If
  it comes back at 2x, the transpiler is not worth building; if it comes back
  at 8x, it is.
- **For SSG specifically, memoization may dominate all of this.** Build-time
  rendering with fixed input means identical subtrees render repeatedly across
  pages. Caching rendered output keyed by element identity is a different lever
  entirely, is far cheaper to build, and composes with everything above. It is
  out of scope here, but it should be measured before the transpiler is, not
  after.

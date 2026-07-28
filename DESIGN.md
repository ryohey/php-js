# php-js Detailed Design

Design document for a JS runtime that executes JavaScript directly on PHP. Building on the established design policy (concept and core decisions), this document concretizes each component to the level where implementation can begin.

Settled premises:

- The "PHP → WASM → QuickJS" approach was measured and discarded. There is exactly one interpretation layer.
- Target spec is **ES5.1 + Promise/microtask queue**. Class syntax, destructuring, generators, template literals, and the iterator protocol are not implemented. Input code is assumed to be downleveled to ES5 with SWC or similar beforehand.
- Progress is measured by the pass rate of test262 (ES5.1 subset).

How the reference implementations are used: goja = blueprint for the overall architecture / QuickJS = vocabulary for the instruction set / engine262 = source of truth for spec semantics (abstract operations) / Peast = adopted as the parser. Neither the regexp engine nor the GC is ported from any of them.

---

## 1. Overall Architecture

```
        (build time or first request)                (every request)
┌───────────┐   ┌───────────┐   ┌────────────────┐   ┌─────────────────────┐
│ JS source  │ → │ Peast AST  │ → │ Compiler        │ → │ Bytecode file        │
└───────────┘   └───────────┘   │ (AST→bytecode)  │   │ <?php return […];    │
                                 └────────────────┘   └─────────┬───────────┘
                                                                │ require
                                                                │ (resident in opcache shared memory)
                                                                ▼
                                                    ┌───────────────────────────┐
                                                    │ VM (single while/switch)   │
                                                    │ + Realm (lazy init)        │
                                                    │ + Builtins (native PHP)    │
                                                    └───────────────────────────┘
```

Compilation and execution are fully decoupled. The typical flow in a shared-nothing environment is: compile JS to bytecode files at deploy time → each request just `require`s and executes. Since the parsed bytecode lives in opcache shared memory, the per-request parse cost is effectively zero. During development, a cache layer compiles on demand based on source mtime.

## 2. Compilation Pipeline

### 2.1 Parser

Peast (ESTree-compliant) is adopted. We do not write our own.

- Parse in ES5 mode; the compiler rejects out-of-scope syntax (classes, generators, etc.) by AST node kind with an immediate error (never silently miscompile).
- Peast is also a runtime dependency for the `Function` constructor / indirect `eval` (in production runs that execute only precompiled code it sits on a never-autoloaded path, so the cost is effectively zero).

### 2.2 Compiler structure

Pass 1: scope analysis. For each function, collect `var` declarations / function declarations / formal parameters, and classify every variable:

| Class | Condition | Access path |
|---|---|---|
| Local slot | Referenced only within the function | Numeric index into the frame |
| Environment slot | Referenced from an inner function (closure capture) | Via environment record (depth, index) |
| Dynamic | Under the influence of `with` / direct `eval`, or global | Dictionary lookup by name |

Pass 2: translate the AST directly to bytecode (no intermediate IR — within ES5 scope, pipeline simplicity wins over optimization-pass headroom). Each function compiles to an independent "function template"; nested functions are held as children of the template.

### 2.3 Function template (the unit of bytecode)

Composed exclusively of `var_export`-able plain arrays (**a precondition for the opcache strategy below; it must never contain PHP objects, closures, or resources**).

```php
[
  'name'     => 'funcName',        // '' if anonymous
  'strict'   => true,
  'nparams'  => 2,
  'nlocals'  => 5,                 // local slot count, including formal parameters
  'nenv'     => 1,                 // slot count of the environment record this function creates
  'code'     => [/* flat int array: opcodes and immediate operands, contiguous */],
  'consts'   => [/* constant pool: strings, floats, regexp sources, etc. */],
  'children' => [/* nested function templates */],
  'trys'     => [/* exception table of [start, end, catch_pc, finally_pc] */],
  'lines'    => [/* delta-compressed pc→line table (for stack traces) */],
  'flags'    => 0,                 // bit flags: uses_arguments | has_eval | has_with, etc.
]
```

`code` is a flat array of ints for both opcodes and immediate operands. Strings and floats are referenced by index into `consts`. Operand counts are fixed per opcode; a disassembler/verifier exists from day one.

## 3. Value Representation

No boxing. Mapping between JS and PHP values:

| JS | PHP | Notes |
|---|---|---|
| number | `int` \| `float` | See 3.1 |
| string | `string` | Kept as UTF-8. See §6 |
| boolean | `bool` | As-is |
| null | `null` | As-is |
| undefined | `JSUndefined::$instance` | The one singleton. Tested with `=== JSUndefined::$instance` |
| object / function | `JSObject` and its subclasses | See §5 |

Type dispatch uses combinations of `is_int` / `is_float` / `is_string` / `is_bool` / `=== null` / `instanceof`, centralized in `TypeOps`. C-style optimizations such as NaN boxing are not ported (they are harmful here).

### 3.1 Number semantics

JS numbers are always doubles, but internally we use "int while representable as int, promoted to float when needed". PHP arithmetic auto-promotes to float on int overflow, so native operators work directly for add/sub/mul. Spots that need individual handling:

- **Division**: PHP's `/` returns int for evenly divisible ints, but both are just "number" to JS, so that is fine. Only division by zero needs explicit `INF/-INF/NAN` results (PHP throws `DivisionByZeroError`).
- **Modulo**: PHP's `%` is int-only. Branch to `fmod()` when either operand is float.
- **Unary minus**: only float can represent `-0`. `NEG` on int `0` returns `-0.0` (float).
- **Bitwise ops** (`& | ^ << >> >>>` `~`): go through `ToInt32` / `ToUint32` per spec. Implemented on 64-bit PHP ints with masks of the form `((x & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000`. `>>>` is naturally unsigned via `($x & 0xFFFFFFFF) >> $n`.
- **Strict equality**: PHP's `===` rejects int-vs-float on type, so it cannot be used. Numbers compare with `==` (PHP numeric comparison is IEEE-conformant; `NAN == NAN` is false, as expected); everything else goes through type-tag + value comparison in `TypeOps::strictEquals`. `+0 === -0` is true under PHP's `==`, matching the spec.
- **ToString(number)**: JS number-to-string (shortest round-trip representation) does not match PHP's stringification, so it gets a dedicated implementation. Exponent-notation boundaries around `1e21`, `-0` → `"0"`, and integral floats printing as `"1"` follow the spec (Number::toString) exactly. This is one of the most divergence-prone areas in test262, so it has dedicated tests from the start.
- **The int/double boundary is 2^53, not 2^63.** A PHP int may only stand in for a JS number when a double represents it exactly (`Conversions::MAX_EXACT_INT`). Every place that *creates* a number from text — numeric literals in the compiler, `ToNumber(String)`, the JSON parser — narrows to int only below that bound, so `(1000000000000000128).toString()` correctly yields `"1000000000000000100"`.
  - **Accepted deviation**: arithmetic results are *not* re-normalized. Between 2^53 and 2^63 PHP integer arithmetic stays exact where JS would have rounded, so `9007199254740992 + 1` gives `9007199254740993` instead of `9007199254740992`. Normalizing would put a range check on every `ADD`/`SUB`/`MUL` in the dispatch loop, which is exactly the per-instruction cost §4.1 exists to avoid. Revisit only if a real workload depends on it.

Spec abstract operations (`ToNumber` `ToPrimitive` `ToInt32` `ToString` `SameValue` …) live in a `Conversions` class, one function per abstract operation, so they can be checked side-by-side against engine262.

## 4. Execution Model (VM)

### 4.1 Dispatch loop

The VM proper is confined to a single method containing `while (true) { switch ($op) }`. **JS function calls do not use the PHP call stack.** Frames are managed on our own stack (a PHP array); `CALL` is a frame push plus `$pc` swap, `RETURN` is a frame pop.

Rationale (restating and concretizing the policy):

- PHP function-call cost dominates; mapping JS-level calls onto PHP calls cannot win.
- With frames as our own data, generators/async/deep recursion can be retrofitted as "frame suspend/restore" (that door closes the moment frames live on the PHP call stack).

Hot-path discipline:

- Inside the loop, avoid property accesses and method calls; keep the frame contents (`$stack`, `$sp`, `$pc`, `$code`, `$consts`, `$locals`) unpacked into local variables, saving/restoring only on frame switches.
- Leave room for superinstructions for frequent patterns (`GET_LOCAL n` + `PUSH_CONST k` + `ADD` → `ADD_LOCAL_CONST n k`, etc.), but do not build them initially — introduce them after benchmarking.

### 4.2 Frames and the stack

```php
// Conceptual structure (the implementation uses parallel arrays or a list of arrays
// to avoid property accesses)
$frame = [
  $template,   // function template (shared, read-only)
  $locals,     // local slot array
  $env,        // head of the environment-record chain (or null)
  $thisVal,
  $retPc,      // caller's resume pc
  $retSp,      // caller's stack pointer
];
```

The operand stack is a single array shared by all frames (`$stack` + `$sp`). Each function template records its maximum stack depth at compile time, so `CALL` can check headroom in one step.

### 4.3 Calling convention

- `CALL argc`: executes with `[func, this, arg1..argN]` on the stack. If the callee is
  - a **JS function (bytecode)** → push a frame and continue (no PHP function call);
  - a **native function** → invoke the PHP callable from the registry directly and push the result.
- `NEW argc`: fetch `prototype` → create `JSObject` → pass as `this`, then proceed as `CALL`. If the return value is not an object, the created object is the result.
- **Native→JS callback re-entry** (e.g. the callback of `Array.prototype.map`): the VM exposes a re-entry point `runUntil(int $frameDepth)` that runs until execution returns to the given frame depth. Native implementations push a frame for the callback JS function and call `runUntil`. Re-entry consumes one level of the PHP call stack, but the design caps that at one level per `map` call (not per element).

### 4.4 Exception handling

- JS `throw` is handled as in-VM control flow. Look up the function template's exception table (`trys`) by `pc`; if a handler exists, swap `pc` to the catch/finally, otherwise pop the frame and propagate. **PHP exceptions are never used for JS control flow** (PHP exceptions are far too expensive for code that enters/leaves try/catch frequently).
- Only the native boundary uses a PHP exception, `JSThrowSignal` (carrying the JS exception value). Native code that needs to throw a JS exception — or that receives one propagating out of a callback — throws it, and the VM catches it at the invocation site and merges into normal propagation.
- `finally` is handled through the exception table like catch; the "continuation after finally" (rethrow / continue returning / fall through) is represented by a completion record pushed on the stack (no QuickJS-style `OP_gosub`; we push a completion kind instead).

### 4.5 Scopes and closures

- Uncaptured variables live in the frame's `$locals` slots (numeric indices).
- Captured variables live in environment records `JSEnv { ?JSEnv $parent; array $slots; }`, accessed with `GET_ENV depth,index` walking the chain. Environment records are created on function entry only for functions that need them.
- Scopes containing `with` or direct `eval` fall back to dynamic resolution (marked per-function via `flags`). Only then does the compiler emit name-based lookup instructions (`GET_VAR_DYN`, etc.). Direct `eval` is required for test262, so the feature itself is not dropped.
- `arguments` is materialized only in functions where its use is detected (the exotic mapped behavior applies only in non-strict mode — per spec, with the cost confined to functions that use it).

## 5. Object Model

### 5.1 JSObject

No hand-rolled hidden classes or inline caches. Property storage is delegated to PHP arrays, leaving hash lookup to the PHP engine (zend hash). The only optimization target is **reducing the number of PHP-level operations**.

```php
class JSObject {
    public array $props = [];        // name => raw value (fast path for data properties)
    public ?array $descs = null;     // name => [getter, setter, flags] (created only when needed)
    public ?JSObject $proto;
    public bool $extensible = true;
    public ?string $nativeId = null; // provenance ID for realm snapshots (§11)
}
```

- Ordinary data properties (writable/enumerable/configurable all true) are stored as raw values in `$props`. Only when `defineProperty` specifies other attributes or accessors does an entry appear in `$descs`. **Gets take a fast path that skips descriptor checks entirely when `$descs === null`.**
- Fast-path get: `$obj->props[$k] ?? <miss handling>`. Because `??` misfires only when the stored value is JS `null` (= PHP null), the miss route starts with an `array_key_exists` correction (we accept that only null-storing cases are slower).
- The prototype chain is walked through plain PHP object references.
- Property keys are strings only (ES5, no Symbol). PHP arrays canonicalize numeric-string keys to ints; this is harmless because JS also identifies `"0"` with `0`, but key enumeration inserts a `(string)` normalization.

### 5.2 Exotic objects

Expressed via class inheritance (cheaper to dispatch than tag + if):

- `JSArray`: elements live in `$elements` (a PHP array), separate from `$props`, plus `$length`. While indices stay dense, PHP keeps it a packed array. Length-assignment truncation and automatic `length` updates are implemented here. If it becomes sparse, the same `$elements` simply hashes (PHP does this automatically).
- `JSFunction`: `$template` (function template reference) + `$env` (defining environment) + `$realm`.
- `JSNativeFunction`: `$fnId` (**string ID into the native function registry**; never holds a PHP Closure directly — see the serialization constraints in §11) + `$arity` + `$name`.
- `JSBoundFunction`, `JSRegExpObject`, `JSDateObject`, primitive wrappers (`JSStringObject`, etc.).
- The global object is a `JSGlobalObject` with a property-miss hook that consults the builtin table (the linchpin of lazy initialization, §11).

### 5.3 Builtin library

- Everything is implemented in native PHP (not self-hosted JS — self-hosting loses on both VM re-entry cost and initialization cost).
- Hot higher-order functions (`Array.prototype.map/filter/forEach/reduce`, the callback form of `String.prototype.replace`) run as PHP loops, returning to the VM only for callback invocations via `runUntil`.
- All native functions register in `BuiltinRegistry` as `'Array.prototype.map' => callable`; the heap references them by ID. The signature is uniformly `fn(VM $vm, mixed $thisVal, array $args): mixed`.

## 6. Strings

The internal representation stays UTF-8, with an ASCII flag providing the fast path.

- PHP strings are used directly (no wrapper object), so metadata like the ASCII flag cannot live on the string itself. **Metadata lives in a VM-side memoization table** (a small LRU holding `[len16, isAscii, offset conversion table]` for recently accessed strings). One analysis pass absorbs patterns like hitting `length` or `charCodeAt` on the same string inside a loop.
- ASCII detection is equivalent to `!preg_match('/[\x80-\xFF]/', $s)` (implemented with `strspn`). For ASCII, `.length` = `strlen` and `charCodeAt` = `ord` — done.
- Only non-ASCII strings take the slow UTF-16-semantics path: computing the UTF-16 code-unit count and building a code-unit ⇔ byte-offset conversion table (surrogate-pair aware).
- Because `fromCharCode` / `charCodeAt` can produce lone surrogates, the internal representation is strictly **WTF-8** (a UTF-8 extension tolerating lone surrogates). Validity is dealt with only at the regexp and host-output boundaries.
- String concatenation maps straight to PHP's `.` (next to refcount GC and opcache, this is where delegating to PHP wins the most).

## 7. Bytecode Instruction Set

Using QuickJS's opcode granularity as a vocabulary, the set is trimmed to the ~90 instructions a stack machine needs. Categories and representative examples:

| Category | Examples |
|---|---|
| Constants | `PUSH_CONST k` `PUSH_INT i` (small-int immediate) `PUSH_TRUE/FALSE/NULL/UNDEF` |
| Stack | `DUP` `DUP2` `POP` `SWAP` |
| Variables | `GET_LOCAL n` `SET_LOCAL n` `GET_ENV d,i` `SET_ENV d,i` `GET_GLOBAL k` `SET_GLOBAL k` `GET_VAR_DYN k` `TYPEOF_VAR k` |
| Properties | `GET_PROP k` (static key) `SET_PROP k` `GET_ELEM` (dynamic key) `SET_ELEM` `DEL_PROP` `DEFINE_DATA k` (for literals; never triggers setters) `DEFINE_GETTER/SETTER k` |
| Operators | `ADD SUB MUL DIV MOD NEG INC DEC` `BAND BOR BXOR BNOT SHL SHR USHR` `NOT` `EQ NEQ SEQ SNEQ LT LE GT GE` `TYPEOF` `IN` `INSTANCEOF` |
| Control | `JMP a` `JT a` `JF a` `JT_KEEP a` `JF_KEEP a` (for `&&`/`||`) `SWITCH_SEQ` (=== comparison for cases) |
| Calls | `CALL argc` `CALL_METHOD argc` `NEW argc` `RETURN` `RETURN_UNDEF` |
| Creation | `NEW_OBJECT` `NEW_ARRAY n` `NEW_FUNC idx` `NEW_REGEXP k` `PUSH_THIS` `ARGUMENTS` |
| Exceptions | `THROW` `TRY_ENTER idx` `TRY_LEAVE` `FINALLY_END` |
| Enumeration | `FOR_IN_INIT` `FOR_IN_NEXT a` (pushes a snapshotted key list as an internal iterator) |
| Misc | `WITH_ENTER` `WITH_LEAVE` `DEBUGGER` (no-op) |

Design rules:

- `ADD` carries a number+number fast path inline (mapping straight to PHP's `+`), branching to a slow path for string concatenation / ToPrimitive.
- Comparison and equality follow the same two-tier scheme: "both operands numeric/string → direct native op; anything else → `Conversions` call".
- `GET_PROP` inlines the prototype-chain walk inside the instruction (not a method call). Exotics (numeric indices on `JSArray`, etc.) branch off with a single `instanceof`.

## 8. Regular Expressions: JS → PCRE2 Translation Layer

libregexp is not ported. A `RegExpTranslator` converts JS regexp literal/constructor patterns into PCRE2 syntax. Translation runs once, at compile time (literals) or at RegExp object creation (dynamic), and the resulting PCRE pattern is cached on the object.

| JS | PCRE2 treatment |
|---|---|
| `g` flag | Not reflected in the pattern; the caller (exec/replace implementation) drives the `lastIndex` loop |
| `y` flag | `preg_match` `$offset` + the `A` (anchored) modifier |
| `i` `m` | `i` `m` as-is |
| `u` flag | The `u` (UTF) modifier + PCRE2's UCP escapes absorb most of it. `\u{XXXX}` → `\x{XXXX}` |
| `\uXXXX` `\xXX` | Rewritten to `\x{XXXX}` |
| `[]` (empty class) | In JS it "matches nothing" → replaced with `(?!)`. `[^]` → `[\s\S]` |
| `$` `^` | Same meaning (also under `m`) |
| Octal escapes / Annex B forms | Handled explicitly by the translator (rewriting anything whose interpretation diverges from PCRE) |
| Named groups and other ES2018+ | Compile error as out of scope (SWC does not downlevel regexps, so this is **documented as an input-side constraint**) |

The offset problem: PCRE offsets are in bytes; JS `lastIndex` is in UTF-16 code units. For ASCII strings the mapping is the identity; only non-ASCII strings use the conversion table from §6 to translate bytes ⇔ UTF-16.

Known accepted compromise (documented): the JS semantics of non-`u` regexps operating on surrogate pairs per code unit cannot be fully reproduced by PCRE over UTF-8. Affected test262 cases are managed via the skip list.

## 9. Promise / Microtasks

- The job queue is a plain FIFO array inside the `Realm`. `Promise` itself is implemented in native PHP (then-chain resolution runs without VM re-entry; only user callbacks enter the VM).
- Execution model: the host API `$runtime->run($bytecode)` performs "synchronous execution → drain microtasks until the queue is empty" in one call. Task queues (macrotasks) like `setTimeout` are not part of the core; they belong to the host integration layer (`flushUntilIdle()` for SSR use cases).
- Unhandled rejections are detected when draining completes and reported to a host hook.

## 10. Errors and Stack Traces

- When an `Error`-family object is created, the runtime walks its own frame stack and builds the `stack` string from `template name + lines table`. Since frames are our own data, PHP's `debug_backtrace` is not involved (it would show nothing useful anyway).
- VM-internal bugs and JS exceptions are strictly separated: JS exceptions propagate as values; a PHP exception leaking to the VM boundary is by definition a runtime bug.

## 11. Fitting the PHP Execution Environment (shared-nothing)

**The constraints in this section cannot be retrofitted, so verifying that every other section complies with them is a mandatory implementation-review item.**

### 11.1 Bytecode = opcache-resident

- Compilation output is a file of the form `<?php return [ /* function template tree */ ];`.
- opcache caches this file as an immutable array in shared memory, so the second and subsequent `require`s incur neither deserialization nor copying (the immutable-array optimization). **This is why function templates must contain nothing but plain arrays.**
- The cache key is source hash + compiler version. The bytecode format embeds a version number; incompatibility triggers recompilation.

### 11.2 Lazy realm initialization (a phase-1 hard requirement)

- A `Realm` is created per request, but builtins are not constructed until touched.
  - On a global-variable miss, `JSGlobalObject` consults the `BuiltinRegistry::GLOBALS` table (name → initializer ID) and materializes the entry.
  - `Object.prototype` / `Array.prototype` etc. are obtained only through memoized `Realm` accessors (`$realm->arrayPrototype()`), constructed on first access.
  - Acceptance criterion: "a request that touches only `console.log` and `JSON` creates not a single object for any other builtin."

### 11.3 Realm snapshots (phase 2 — but the structural constraints apply from day one)

The idea: export an initialized realm (builtins + heap after the user's initialization code has run) to a file, and restore it at request start. To make this possible, **what may live on the heap is restricted from the very beginning**:

1. Reachable values from `JSObject` fields are limited to: JS values (the table in §3) + `JSEnv` + function-template references.
2. **No PHP Closures, resources, or foreign PHP objects directly on the heap.** Native functions are referenced indirectly through the registry by `$fnId` (string) (§5.2). Host-integration objects likewise use ID references.
3. Because the heap contains cycles, `var_export` cannot be applied directly. Snapshots are exported as a flat table with an ID assigned to every object (references are IDs), reconstructed in two passes at restore time. The table itself is a plain array, so it rides opcache.
4. Whether restore cost (O(heap size) object recreation) beats lazy initialization is **decided by measurement**. If it loses, phase 2 is discarded and lazy init alone remains in production (the structural constraints stay regardless — they remain useful for debug dumps and testing).

## 12. test262 Operation

- A runner lives in `tests/test262/`: front-matter parsing (negative / includes / flags), harness injection (`sta.js`, `assert.js`, …), and execution in both strict and non-strict modes.
- Target filter: an include list selects the ES5.1-equivalent portions of `language/` and `built-ins/`; out-of-scope features (class, generator, Symbol, Proxy, …) are excluded by feature tag + path.
- The skip list (known compromises such as the regexp surrogate cases in §8) is a single file where a reason comment is mandatory for every entry.
- CI emits the pass rate as a number, and **the pass-rate trend is the sole progress metric** ("does framework X run" is not a metric). Regressions are detected mechanically as a drop in the pass count.

## 13. Directory Layout

```
src/
  Compiler/      # Peast AST → function templates (ScopeAnalyzer, Emitter, ConstPool)
  Vm/            # dispatch loop, frame management, runUntil re-entry point
  Runtime/       # Realm, JSObject family, JSEnv, Conversions, TypeOps, StringOps
  Builtins/      # BuiltinRegistry and each builtin (Global, ObjectB, ArrayB, StringB, JSONB, MathB, DateB, ErrorB, PromiseB, RegExpB)
  RegExp/        # RegExpTranslator, JSRegExp (PCRE cache, lastIndex control)
  Cache/         # bytecode file emit/require, version management
  Host/          # host integration (task queue, console, timers — non-core features)
bin/
  phpjs          # CLI: compile / run / disasm
tests/
  unit/          # PHPUnit tests for Conversions, Translator, individual instructions
  test262/       # runner, include list, skip list
```

Namespace is `PhpJs\`. Requires PHP 8.2+ (readonly, enums, and the immutable-array optimization of recent opcache are assumed).

## 14. Milestones

| # | Content | Done criteria |
|---|---|---|
| M0 | Scaffolding: composer, Peast, CLI skeleton, test262 runner | The runner can report 0% |
| M1 | Expression/statement compilation and VM (no functions): arithmetic, variables, control flow, `Conversions` | The in-scope parts of test262 `language/expressions` and `language/statements` mostly pass |
| M2 | Functions, closures, exceptions, `arguments`, `this` | In-scope parts of `language/` |
| M3 | Object model and core builtins (Object/Array/String/Number/Boolean/Math/JSON/Error) | Pass rate on in-scope `built-ins/` becomes the primary metric |
| M4 | Full UTF-16 string semantics + RegExp translation layer | In-scope `built-ins/RegExp` and `built-ins/String` |
| M5 | Promise + microtasks + Date | Target suites pass |
| M6 | Bytecode file output + opcache verification + measuring lazy realm init | Benchmark: measured report of per-request initialization cost |
| M7 | Real-world validation: run SSR of an SWC-downleveled React app, compare against a plain PHP renderer | Performance report (go/no-go material) |

From M1 onward, the test262 pass rate is measured continuously in CI.

### 12.1 Measured baseline

Against a current tc39/test262 checkout, the ES5.1 subset stands at **96.5%**
(12590 / 13048 run; `language/` 94.2%, `built-ins/` 97.7%). The whole suite runs
in about two minutes.

Roughly 22000 further tests are skipped. Three groups, in order of size:

1. Tests whose **own source** uses ES6 syntax (arrow functions, `const`,
   template literals) even where the semantics under test are ES5. An ES5.1
   engine cannot run these by construction, so the runner reports them as skips:
   `CompileError::$unsupportedSyntax` separates "post-ES5 construct" from a
   genuine early error, and only the latter counts as a failure.
2. ES6+ features excluded by front-matter tag (`excluded-features.txt`).
3. Post-ES5 builtins excluded by path (`skip.txt`), which is how methods that
   carry no feature tag — `Math.trunc`, `Object.assign`, `Array.prototype.find`
   — are kept out of the denominator.

Two lessons from the first real run are worth recording, because both were
invisible from unit tests:

- **PHP's own error surface leaks.** `1/0.0` throws `DivisionByZeroError` in
  PHP 8, so the `-0` sign checks crashed the host on 21 tests. Anything the
  engine computes with must be checked against PHP 8 semantics, not PHP 5/7
  habits.
- **Spec-accurate scans are not runnable.** Once generic array-likes correctly
  used `ToLength` (up to 2^53-1) instead of `ToUint32`, the spec's `0..len`
  element walk hung for minutes. Hole-skipping operations now traverse the
  indices the receiver actually has; with no Proxy in an ES5 realm the skipped
  indices are unobservable. Expect the same tension anywhere the spec assumes an
  O(length) loop is free.

## 15. Risks and Open Questions

- **Dispatch-cost floor**: measured. The loop runs about **3M instructions/second**, which puts React server-side rendering at **56-75x Node 22** for identical output (see `packages/react-ssr-bench`). Native builtins are not the problem — a full render makes only ~4000 native calls — so the cost is the dispatch loop itself, and the lever is *fewer instructions per operation*, not a faster instruction. Two cheap wins are already in: the stack pointer and the deadline counter left the per-instruction path (~13% together), and statement-position `++`/`--` on a local became one `INC_LOCAL` instead of eight instructions. The next candidates are a fused compare-and-branch for loop conditions and `GET_LOCAL`+`ADD` style pairs; both are additive and can be measured one at a time.
- **Direct `eval`**: the dynamic-scoping fallout is wide. `eval` now inherits the caller's strict mode, which is what most strict-mode early-error tests observe, but it still compiles and runs in the global scope and cannot inject a binding into the calling function — the remaining `compound-assignment` and `eval-code` failures. Implementing it means marking the containing function as dynamically scoped and emitting name-based lookups there (§4.5). Inheriting strictness is also technically wrong for *indirect* eval, which the spec keeps sloppy; that trade is deliberate until direct eval is distinguished at the call site.
- **`with`**: unimplemented; the compiler rejects it. Same machinery as direct `eval`, so both should land together.
- **`@@species`**: `ArraySpeciesCreate` and the Promise combinators honour the constructor lookup and its observable errors, but always produce a base Array/Promise — an ES5 realm has no Symbols to key the species hook on.
- **RegExp pattern validation**: PCRE does the parsing, so patterns the spec rejects as early errors are accepted (most of the remaining `language/literals/regexp` failures). Fixing this needs a JS pattern validator in front of the translator.
- **Local time is UTC.** `getTimezoneOffset()` reports 0 and every local getter mirrors its UTC counterpart. Time zone and DST policy has no good answer in a shared-nothing request model; revisit only with a host-supplied zone.
- **`Function` constructor**: requires shipping Peast + the compiler in the runtime. Accepted as dynamic compilation that bypasses opcache (assumed rare).
- **Regexp semantic gaps** (§8): managed via the skip list; fix individually as real applications hit them.
- **Holes in SWC downleveling**: SWC does not transform regexp syntax, and some builtins (`Object.assign`, etc.) are left to polyfills. The input-code preconditions are documented separately as `docs/input-requirements.md`.
- **Realm-snapshot economics** (§11.3): decided by measurement. The design keeps it discardable if it loses.

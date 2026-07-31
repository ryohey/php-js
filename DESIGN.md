# php-js Detailed Design

Design document for a JS runtime that executes JavaScript directly on PHP. Building on the established design policy (concept and core decisions), this document concretizes each component to the level where implementation can begin.

Settled premises:

- The "PHP → WASM → QuickJS" approach was measured and discarded. There is exactly one interpretation layer.
- The language target is **growing from ES5.1 + Promise towards the ES2015+ syntax real code is written in**. See §2.5 for why, what has landed, and what the order is. Symbol is already a primitive type (§3.4).
- Progress is measured two ways, because they answer different questions: the test262 pass rate for **conformance** on what is implemented, and `tests/acceptance/run.php` for **reach** — the share of published npm files that compile at all (§12).

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

### 2.5 Growing the syntax surface

The compiler used to refuse ES6+ syntax outright, on the grounds that
downlevelling belongs in the toolchain. That reasoning holds for code you author
and fails for code you install. Measured against a real `node_modules`, sampling
400 published `.js`/`.cjs` files, the ES5.1 compiler accepted **46.8%**. The
rejections were not exotic:

| tripped on | share of all files |
|---|---|
| `const` | 35.0% |
| arrow functions | 8.2% |
| `let` | 2.2% |
| `export` / `import` / `from` | 3.0% |
| everything else | ~5% |

Three features account for the bulk. Half of npm was unreadable for want of
them, and no amount of build-time cleverness fixes a library you did not write.
So the target grows.

**What this does not change.** The bytecode stays plain `var_export`-able arrays,
the JS heap stays free of foreign PHP objects, and every feature must arrive with
its semantics intact or not at all — a silently wrong answer is worse than a
refusal, and refusal is already how this compiler declines what it cannot do.
Downlevelling in the toolchain remains supported and is still the right choice
for code you control; it is no longer the *only* way in.

**Landed:**

- **Template literals.** Not a rewrite to `+`: a substitution converts with
  ToString (13.2.8.5) while `+` converts with ToPrimitive under the default hint,
  and an object carrying both `valueOf` and `toString` tells them apart. Hence
  the `TOSTR` opcode.
- **Arrow functions.** `this` travels on the function object as `lexicalThis`,
  captured by `NEW_FUNC` from the creating frame, rather than being threaded
  through the scope analyser as a synthetic binding. Nested arrows chain for
  free: the inner one closes over the outer one's frame, whose `this` is already
  the captured value. An arrow has no `prototype` and is not constructible.
  `arguments` inside an arrow is refused — it means the *enclosing* function's,
  and handing back the arrow's own would be silently wrong.

- **Default and rest parameters.** A default applies to `undefined` only, and
  is filled by a prologue that runs before captured parameters are copied into
  the environment record — so the environment sees finished values rather than
  raw arguments. `length` becomes a template field of its own, separate from
  `nparams`: the first counts parameters before the first default or rest, the
  second counts slots that receive positional arguments, and a rest element has
  no slot at all. Its array is built by `REST_ARGS` from the argument list,
  which is retained for exactly that reason. A parameter list carrying either is
  never mapped onto `arguments` (9.2.12), and may not repeat a name.

- **`let` and `const`.** Block scopes are frames on the existing `lexStack`, and
  the bindings go into `Ctx::$extraBindings`, so slot assignment is untouched and
  a block-scoped name is an ordinary slot or environment index. Both passes must
  agree exactly on what is in scope where, so `enterBlock` creates the bindings
  once during analysis, keyed by the statement list that owns them, and pushes the
  remembered ones again during codegen. **Every statement list that can hold a
  `let` goes through it** — a plain block, a labelled block, a function body, a
  program, a `try`/`catch`/`finally` block, a `for` head, and a switch's cases
  (which share one scope, so the case lists are merged into one). A list reached
  by any other route silently loses its scope, which is why codegen funnels them
  all through a single `genScopedList`.

  TDZ is a sentinel written by `PUSH_TDZ` and read by `TDZ_CHECK`; `THROW_CONST`
  is the assignment guard. A lexical name may not collide with anything else that
  reaches the same scope, and `var` hoists straight through blocks, so the check
  looks at the whole subtree (`varNamesIn`) plus the names that occupy the scope
  without appearing in it: parameters for a function body, the catch parameter for
  a catch body, the body's `var`s for a `for` head.

  **Refused rather than approximated:** a `let` or `const` captured by a closure
  inside a loop — including one declared in the `for` head — needs a fresh binding
  per iteration, and one slot is reused instead. Answering would hand every
  closure the last value. `for (let x in …)` is refused for the same reason.

- **Object destructuring**, in declarations, assignments and parameters, with
  nesting, defaults, computed keys and a rest element. The source arrives at
  `genPattern` as a *thunk* rather than as a value already on the stack, because
  the spec fixes an order the stack cannot express: in `{a: obj.x} = src` the
  reference `obj.x` is evaluated before `src.a` is read, and a default is applied
  after the reference and before the store. Handing each leaf a thunk lets it
  pull the value at its own moment, and folding a default *into* the thunk keeps
  it in the right place with no special case at the leaf.

  Three opcodes: `REQ_COERCIBLE` (an opcode of its own because `const {} = null`
  throws with no property to read, and reading one anyway would be observable
  through a getter), `COPY_REST` for `CopyDataProperties`, and `TO_KEY` so a
  computed key that also feeds a rest element's exclusion list converts once.

  A pattern parameter takes its position under a name no source can reach and is
  unpacked by the prologue after the defaults have run. It makes the list
  non-simple — so no mapped `arguments`, no `'use strict'` after it — but it does
  not stop `length`, which only a default or a rest element does.

  **Array destructuring is refused.** It is defined over the iterator protocol
  (`GetIterator` / `IteratorStep` / `IteratorClose`), which the engine does not
  have; reading by index instead would answer `undefined` for a Set, a Map or a
  generator rather than iterating it. That protocol is the next increment, and it
  carries `for…of` and spread with it.

  One shape is refused for a parser defect rather than a design one: Peast
  returns the wrong target for a shorthand whose default is itself an assignment
  (`({x = f = 1} = v)` comes back bound to `f`, having lost `x`). Declarations and
  parameters parse correctly. The compiler asserts the invariant — a shorthand's
  target is its key — and refuses when the tree does not match the source.

**Known gaps in what has landed**, all visible as test262 failures rather than
as silence: `var f = () => {}` does not take the name `f` (NamedEvaluation is
not implemented for any form, and it is now the single largest failure group at
48 of them, since a destructuring default names a function too), and two
malformed-unicode-escape cases in templates are still accepted where they should
be early errors.

One refusal is deliberately wider than the spec: a parameter default that
reaches a parameter declared after it is rejected at compile time, where the
spec throws a ReferenceError at run time. Parameters occupy a temporal dead zone
while the list initializes, nothing implements that zone yet, and answering
`undefined` would be silently wrong. It also refuses the case where the later
parameter is only named inside a nested function, which the spec would allow.
Both go away when TDZ lands with `let`.

Defaults and rest also brought in tests for something not implemented: with a
non-simple parameter list the parameters and the body's `var`s occupy *separate*
scopes, so `function f(a = 1) { var a; }` has two `a`s. Seven test262 cases cover
it and fail. `let` gave the compiler block scopes but not a second *var* scope,
so this is still open.

Widening the denominator also *exposed* four pre-existing builtin bugs —
`Array.prototype` `pop`/`push`/`unshift` with a string receiver, and `shift`
against a non-writable `length`. Those tests had been skipped because their own
source is written in ES6, not because the engine passed them. That is the second
argument for growing the surface: the skips were hiding real defects.

**Next: the iterator protocol.** `for…of` (9.2% of all rejections), spread
(4.8%) and array destructuring (1.2%) are one feature wearing three hats — they
all reduce to `GetIterator` / `IteratorStep` / `IteratorValue` / `IteratorClose`
plus `Symbol.iterator` on Array, String and `arguments`. Doing them together is
the difference between implementing the protocol once and approximating it three
times, and the shares understate it: most of the files that need array patterns
trip on `for…of` first.

Queued behind that: classes (3.0%), per-iteration loop bindings (which retires
the refusal above), the separate parameter scope, and NamedEvaluation.

**Deliberately out of scope for now:** ES modules (node-compat resolves
CommonJS, and published packages overwhelmingly ship it), Proxy and Reflect
(an object-model change), and private fields. Generators and `async`/`await` are
*not* dismissed: because JS frames are the VM's own data rather than PHP's call
stack (§4), suspending one is copying a frame plus its operand-stack slice and
resuming is restoring it at a new base with `base`, `retsp` and the handler
stack shifted by the delta. That is mechanical here in a way it is not in an
engine built on the host stack, and `async` then falls out of generators plus the
Promise machinery that already exists.

### 2.4 Peephole pass (superinstructions)

Codegen emits one instruction per operation, and a final pass (`Compiler\Peephole`) fuses adjacent pairs into single opcodes. This is the only optimization pass in the pipeline, and it exists because dispatch is a fixed per-opcode cost — a switch jump, an operand fetch, a loop-back — that is worth paying once instead of twice.

The pattern table is short on purpose and is chosen from measurement, not intuition: a dynamic opcode-pair histogram over a React server-side render (`packages/react-ssr-bench`, the largest real workload the runtime has). Pairs currently fused, with each pair's share of all instructions executed in one render:

| Pair | Fused | Share | Why it is common |
|---|---|---|---|
| `SET_LOCAL n` + `POP` | `STORE_LOCAL n` | 5.9% | `SET_LOCAL` leaves its value on the stack for the expression case; the statement case pops it straight back |
| `GET_LOCAL n` + `GET_PROP k` | `GET_LOCAL_PROP n k` | 4.3% | `x.y` |
| `SEQ` + `JT`/`JF` | `JSEQ`/`JSNEQ` | 3.1% | `if (a === b)`, `switch`, and the `x === undefined` guards a minified bundle is made of |
| `GET_LOCAL n` + `TYPEOF` | `TYPEOF_LOCAL n` | 1.3% | `typeof x` |

Together these remove about 13% of the instructions executed in a render.

Two rules keep the pass honest:

- **Fusion must be unobservable.** A fused opcode does exactly what the two it replaced did, in the same order. `Peephole::$enabled` turns the pass off, and the test suite runs the same programs both ways and compares results, including stack traces.
- **Nothing may jump between the halves.** A pair is rejected when the second instruction is a jump target. Surviving targets — and the `lines` table, which lookup resolves last-match-wins — are rewritten to their new addresses. If the code stream cannot be decoded, the pass returns it untouched rather than guessing.

`GET_LOCAL` + `CALL` is another 2.2% and was tried, but the only cheap way to express it is a `case` that falls through into `CALL`, which measured slower under PHP's tracing JIT than not fusing at all. Duplicating the whole `CALL` body to avoid the fall-through is not worth 2.2%.

## 3. Value Representation

No boxing. Mapping between JS and PHP values:

| JS | PHP | Notes |
|---|---|---|
| number | `int` \| `float` | See 3.1 |
| string | `string` | Kept as UTF-8. See §6 |
| boolean | `bool` | As-is |
| null | `null` | As-is |
| undefined | `JSUndefined::$instance` | The one singleton. Tested with `=== JSUndefined::$instance` |
| symbol | `JSSymbol` | Identity is PHP object identity. See §3.4 |
| object / function | `JSObject` and its subclasses | See §5 |

Type dispatch uses combinations of `is_int` / `is_float` / `is_string` / `is_bool` / `=== null` / `instanceof`, centralized in `TypeOps`. C-style optimizations such as NaN boxing are not ported (they are harmful here).

### 3.4 Symbol, the one post-ES5 primitive

Symbol is in the engine and not in node-compat's polyfills, which is a deliberate
exception to "ES5.1 plus Promise" and worth justifying, because the boundary it
crosses is *type* and not *syntax*.

No library polyfill can make `typeof x` answer `"symbol"`. That is not a
cosmetic gap. React brands element types with `Symbol.for("react.fragment")` and
its renderer tests `typeof type === "string"` **before** identity-comparing
against those brands, so a symbol that is really a string takes the
host-element path: `<></>` fails with `Invalid tag: @@react.fragment`. A string
polyfill also collapses distinct symbols into one property key. The type has to
be real or the feature is not there.

What that costs, kept small on purpose:

- `JSSymbol` is a primitive, not a `JSObject`: no own properties, wrapped on
  demand by `Conversions::toObject()` like a string or a number.
  `TypeOps::strictEquals` already falls through to identity, so equality needed
  no change.
- Implicit `ToString` and `ToNumber` throw, per spec. `String(sym)` and
  `sym.toString()` are the sanctioned descriptions, and `Display` prints one
  rather than throwing inside `console.log`.
- Symbol keys share the string-keyed property table; see §5's note on
  `Vm::propertyKey()` and `orderKeys()`.
- The registry (`Symbol.for` / `keyFor`) and the well-known symbols live on the
  realm, not in a static, because a symbol is heap state and two realms in one
  process must not share one (§11).

What it deliberately does not do is give the well-known symbols meaning. They
exist as values and work as keys, so feature detection sees what it expects, but
this is still an ES5.1 realm: there is no iteration protocol behind
`Symbol.iterator` and nothing consults `Symbol.toPrimitive`. That is the same
position as `@@species` in §15 — present, inert, and honest about it.

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
- The prototype chain is walked through plain PHP object references. `JSObject::get` takes the same `$props[$k] ?? …` shortcut at *every* level rather than calling `getOwn` per level, because a method lookup walks two or three prototypes before it hits anything and the virtual call dominates otherwise. Objects whose own properties are not fully described by `$props` — array indices, string wrapper code units, mapped `arguments` — clear `ownPropsArePlain`, which forces the general path for them. Lazily materialized properties do not need the flag: an unmaterialized key is simply absent from `$props`, so the shortcut misses and falls through to `ensureOwn` on its own.
- Property tables are keyed by string. PHP arrays canonicalize numeric-string keys to ints; this is harmless because JS also identifies `"0"` with `0`, but key enumeration inserts a `(string)` normalization.
- Symbol keys ride in the same table. A `JSSymbol` (§3.4) owns one private, NUL-prefixed key string; `Vm::propertyKey()` is the only place the substitution happens, and `JSObject::orderKeys()` filters those keys out of every enumeration. That is what keeps `Object.keys`, `for-in`, `JSON.stringify` and `Object.getOwnPropertyNames` symbol-blind without any of them knowing symbols exist, and `Object.getOwnPropertySymbols` is the only way back. The alternative — a string-or-symbol key type through every property operation — buys spec purity at a cost the whole object model would pay.

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

## 12. Measuring progress: conformance and reach

Two numbers, because they answer different questions and neither substitutes for
the other.

**Conformance — test262.** A runner lives in `tests/test262/`: front-matter
parsing (negative / includes / flags), harness injection (`sta.js`, `assert.js`,
…), and execution in both strict and non-strict modes. An include list selects
the portions of `language/` and `built-ins/` covering what is implemented;
features that are not are excluded by tag and path. The skip list (known
compromises such as the regexp surrogate cases in §8) is a single file where a
reason comment is mandatory for every entry. Regressions are detected
mechanically as a drop in the pass count.

As §2.5 lands syntax, the include list grows with it, and the headline
percentage will move for two reasons at once — real progress and a widening
denominator. When it does, the honest comparison is the **failure set**, not the
percentage: a feature is done when the tests it brought in pass and nothing that
passed before stopped.

**Reach — `tests/acceptance/run.php`.** Point it at a `node_modules` and it
reports the share of published files that compile, plus a histogram of what the
rest tripped on. Sampling is seeded, so two runs are comparable. This is the
number that says whether the syntax work is aimed at anything, and the histogram
is the work queue in priority order.

Neither is "does framework X run", which remains not a metric. What React does
is measured separately and byte-for-byte against Node (`packages/react-ssr-bench`,
`packages/ssg-demo`).

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

- **Interpretation-cost floor**: measured, and the earlier reading of it was wrong. React server-side rendering runs at **50-70x Node 22** for identical output (see `packages/react-ssr-bench`). What is *not* true is that raw dispatch dominates. The superinstruction pass (§2.4) removed 13% of the instructions executed in a render and bought only about 4% of the wall time, because the instructions it removes (`POP`, `GET_LOCAL`, `TYPEOF`, a taken branch) are the cheapest ones. A per-opcode time profile puts the cost elsewhere:

  | Opcode | Share of instructions | Share of time |
  |---|---|---|
  | `CALL` | 3.9% | 15.4% |
  | `GET_METHOD` | 2.2% | 7.3% |
  | `GET_PROP` + `GET_LOCAL_PROP` | 7.6% | 10.8% |
  | `RETURN` | 2.1% | 3.9% |
  | everything else | 84.2% | 62.6% |

  So roughly a third of a render is spent in calls and property lookups, at around ten times the cost of a cheap opcode. Trimming the prototype walk (§5.1) is the one that has paid so far. `CALL` is the largest remaining item and the least explored: a JS call costs about 1.4µs above the dispatch floor, spent on the frame array, the environment record, and the locals-init loop. Note that the obvious-looking cleanups are not free wins — replacing the current frame's PHP reference with indexed writes measured *slower*, as did fusing `GET_LOCAL`+`CALL`. Measure each one; several plausible ones lose.

- **Enable PHP's tracing JIT.** `opcache.jit=tracing` is worth about 20% on a render and costs nothing but configuration — a bigger lever than anything the interpreter has given up so far. It is off by default in PHP, so hosts have to opt in; the benchmark reports both.

- **Library gaps beat interpreter tuning.** A render runs a small, fixed set of JS functions — 36 distinct ones on React 17, 73 on React 19 — and any one of them implemented in JS rather than native PHP shows up immediately. `Math.clz32`, shipped by `node-compat` as a bit-at-a-time JS polyfill, is **20% of a React 19 render** on its own. Profile by JS function before optimizing the VM; see `docs/aot-php.md` §2.

- **Ahead-of-time PHP for the hot path**: `docs/aot-php.md`. It fits without a new dispatch mechanism, because `BuiltinRegistry`'s string-ID indirection (forced by §11.3) is already the function table such a scheme needs. It has now been priced rather than guessed: hand-writing React 19's `createElement` in PHP is **18x** faster than interpreting it with conservative `[[Set]]` semantics, ~40x if the emitter proves the write target is fresh. Projected at about 3x on a whole render. The remaining open question is not whether it wins but how much of that survives a general emitter.
- **Direct `eval`**: the dynamic-scoping fallout is wide. `eval` now inherits the caller's strict mode, which is what most strict-mode early-error tests observe, but it still compiles and runs in the global scope and cannot inject a binding into the calling function — the remaining `compound-assignment` and `eval-code` failures. Implementing it means marking the containing function as dynamically scoped and emitting name-based lookups there (§4.5). Inheriting strictness is also technically wrong for *indirect* eval, which the spec keeps sloppy; that trade is deliberate until direct eval is distinguished at the call site.
- **`with`**: unimplemented; the compiler rejects it. Same machinery as direct `eval`, so both should land together.
- **`@@species`**: `ArraySpeciesCreate` and the Promise combinators honour the constructor lookup and its observable errors, but always produce a base Array/Promise. `Symbol.species` now exists as a value (§3.4) — what is missing is any code that consults it, and in an ES5 realm there is nothing for it to select.
- **RegExp pattern validation**: PCRE does the parsing, so patterns the spec rejects as early errors are accepted (most of the remaining `language/literals/regexp` failures). Fixing this needs a JS pattern validator in front of the translator.
- **Local time is UTC.** `getTimezoneOffset()` reports 0 and every local getter mirrors its UTC counterpart. Time zone and DST policy has no good answer in a shared-nothing request model; revisit only with a host-supplied zone.
- **`Function` constructor**: requires shipping Peast + the compiler in the runtime. Accepted as dynamic compilation that bypasses opcache (assumed rare).
- **Regexp semantic gaps** (§8): managed via the skip list; fix individually as real applications hit them.
- **Holes in SWC downleveling**: SWC does not transform regexp syntax, and some builtins (`Object.assign`, etc.) are left to polyfills. The input-code preconditions are documented separately as `docs/input-requirements.md`.
- **Realm-snapshot economics** (§11.3): decided by measurement. The design keeps it discardable if it loses.

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

- Parse in ES5 mode; the compiler rejects out-of-scope syntax (`async`/`await`, private fields, etc.) by AST node kind with an immediate error (never silently miscompile). See §2.5 for what has since grown beyond ES5.
- Peast is also a runtime dependency for the `Function` constructor / indirect `eval` (in production runs that execute only precompiled code it sits on a never-autoloaded path, so the cost is effectively zero).
- A parser bug is fixed in the parser: `composer.json` points at
  [ryohey/peast](https://github.com/ryohey/peast) rather than working around a
  wrong AST here. A wrong tree is wrong for every consumer of it — this project,
  `docs/aot-php.md`'s ahead-of-time compiler, anything else that ever parses
  with it — and a compiler-side check papering over one shape leaves every
  other shape the bug can take unguarded. The fork tracks upstream `master` and
  exists to carry small patches like this until they land there.

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

  A `let`/`const`/`class` captured by a closure inside a loop needs a fresh
  binding per iteration, or every closure would see the last value; landed
  later, once the VM had a way to give a loop its own environment layer
  separate from its function's — see "Per-iteration loop bindings" below.

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

  One shape used to be refused for a parser defect rather than a design one:
  Peast returned the wrong target for a shorthand whose default is itself an
  assignment (`({x = f = 1} = v)` came back bound to `f`, having lost `x`).
  Fixed upstream in [ryohey/peast](https://github.com/ryohey/peast) rather than
  worked around here — a compiler-side check on the tree shape would have had
  to be re-derived by every consumer of the parser, where the parser itself has
  exactly one place that builds an `AssignmentProperty` from a shorthand
  (`expressionToPattern`). `composer.json` pins the fork's branch until the fix
  lands upstream.

- **The iteration protocol, and `for…of`.** `%IteratorPrototype%`, the array and
  string iterators, `Array.prototype.values`/`keys`/`entries`, and
  `@@iterator` on Array, String and `arguments`. A string iterator steps by code
  point, which is the whole difference from indexing it.

  `for…of` reads `next` **once**, with the iterator, because the spec captures it
  there and a getter makes re-reading observable. `ITER_GET` does that and leaves
  `[iterator, nextMethod]` on the stack in call order, so each step is
  `GET_LOCAL next; GET_LOCAL iter; CALL 0` — the ordinary call path, no VM
  re-entry per iteration. `ITER_NEXT` validates the result object and branches on
  `done`.

  `IteratorClose` is the part with real content. The loop sits inside a protected
  region, so a throw from the body closes the iterator and rethrows; `break`
  closes at its break target; `return` and a labelled break crossing the loop
  close through `emitExitCleanup`, which grew a case for it beside the `finally`
  one. `continue` deliberately does not close, and neither does running to
  exhaustion — the iterator already finished and the spec does not ask twice.
  While unwinding a throw, an error from `return` is discarded in favour of the
  exception in flight (7.4.9).

- **Spread and array destructuring**, the protocol's other two consumers.

  An array literal or argument list carrying a spread does not know its length
  until it runs, so it is grown one element at a time (`ARR_APPEND`,
  `ARR_SPREAD`) and a call passes the result as one array (`CALL_SPREAD`,
  `NEW_SPREAD`). `{...src}` is `CopyDataProperties`, the same operation an object
  pattern's rest element performs, and they share one implementation.

  An array pattern holds an iterator *record* — `[iterator, next, done]` in a
  frame slot, the way `FORIN_INIT` holds its key list — because a pattern longer
  than its source keeps taking `undefined` without asking again, and because
  whether to close on the way out depends on that flag. A rest element drains the
  iterator, so nothing is left to close; an element list that stopped early
  leaves it open, and the spec closes it. A step that *throws* leaves the
  iterator broken, and a broken iterator is not closed — the record is marked
  done before the throw escapes.

  Two early errors used to be missing for a destructuring *assignment*, where
  the pattern is reinterpreted from an array or object literal: a rest element
  must end its pattern, and it takes no default (`[...x, y] = []` and
  `[...x = 1] = []` both parsed). The binding forms already enforced both from
  their own grammar, so this was a gap in `expressionToPattern`'s reuse of
  `SpreadElement`, not a new rule — fixed in the same fork and commit as the
  shorthand bug above. One of them, `[...x,]`, survives only in the source
  text: the array literal it is reinterpreted from allows the trailing comma,
  and the AST is identical to `[...x]` either way, so the parser now records
  it on the node as it parses rather than trying to recover it later from
  offsets.

  `for…in` heads went through the same machinery, which both fixed patterns
  there and retired the refusal of `for (const k in o)`.

  Two pre-existing bugs surfaced. `{[k]: v}` in an object *literal* silently
  produced the key `"k"`; computed keys now evaluate, in source order, converted
  once (`DEFINE_DATA_ELEM` and the accessor forms). And `Promise.all`/`race` read
  their argument as an array-like, so `Promise.all(new Set([p]))` resolved with
  nothing; they take an iterable now.

**Known gaps in what has landed**, all visible as test262 failures rather than
as silence: two malformed-unicode-escape cases in templates are still accepted
where they should be early errors.

One refusal is deliberately wider than the spec: a parameter default that
reaches a parameter declared after it is rejected at compile time, where the
spec throws a ReferenceError at run time. Parameters occupy a temporal dead zone
while the list initializes, nothing implements that zone yet, and answering
`undefined` would be silently wrong. It also refuses the case where the later
parameter is only named inside a nested function, which the spec would allow.
Both go away when TDZ lands with `let`.

Defaults and rest also brought in tests for something not implemented at the
time: with a non-simple parameter list the parameters and the body's `var`s
occupy *separate* scopes, so `function f(a = 1) { var a; }` has two `a`s. Landed
later in this section, once `let` had given the compiler block scopes to build
the second one on top of.

Widening the denominator also *exposed* four pre-existing builtin bugs —
`Array.prototype` `pop`/`push`/`unshift` with a string receiver, and `shift`
against a non-writable `length`. Those tests had been skipped because their own
source is written in ES6, not because the engine passed them. That is the second
argument for growing the surface: the skips were hiding real defects.

- **Tagged templates**, plus `String.raw`.

  A plain template rejects a malformed `\x`/`\u`/octal escape at compile time;
  a tagged one does not (12.9.6) -- the tag still gets the raw text, so a
  template built for another syntax entirely (a regex DSL, SQL) can use
  escapes that mean nothing to JS. Peast itself refuses a malformed `\u` at
  parse time, but only outside a tag; inside one it hands back the raw text
  unexamined, so `cookTemplate` now runs the same `\x`/`\u`/octal scan on the
  tagged path that it always ran for `\0`/`\8`/`\9`, and returns `null` -- the
  cooked value the array construction step turns into `undefined` -- instead
  of failing compilation.

  GetTemplateObject (13.2.8.3) is the part with real content: the same call
  site has to hand the tag the identical `[cooked...]` array (with `raw`
  attached, both frozen) on every evaluation, not a fresh one each time --
  test262's `tagged-template/cache-*` tests check this by pushing a loop's
  results into an array and comparing identity. `NEW_TAG_TEMPLATE` resolves
  the object from `Realm::$templateObjectCache`, keyed by a string the
  compiler assigns once per call site (`"$compilationId:$index"`). The index
  alone is not enough: two `eval` calls of the same source text must each get
  their own object (`cache-identical-source-eval.js`), so the compiler also
  carries a process-wide counter bumped once per `Compiler::compile()` call --
  every site inside one compilation shares an ID, and no two compilations
  share one to collide their indices under. The cache itself lives on the
  `Realm`, per DESIGN.md §11: keyed strings and heap arrays, nothing that
  cannot ride a snapshot, and two realms in one process never see each other's
  cache.

  Widening the denominator here found two more pre-existing bugs, both in
  `decodeStringLiteral` (shared by every string and template): U+2028 LINE
  SEPARATOR and U+2029 PARAGRAPH SEPARATOR were never recognised as
  `\`-continuations, only `\n` and `\r` were. Tests exercising it had been
  skipped as tagged-template syntax; landing tagged templates reached them,
  and fixing the shared function incidentally fixed the same bug in ordinary
  string literals too.

- **Classes** — declarations and expressions, `extends`, `super`, getters,
  setters, static members and computed keys — compiled onto the same
  machinery a function already has, not a parallel object model.

  A class becomes a constructor function plus a `prototype` object, built by
  `NEW_CLASS` and populated member-by-member: `DEFINE_METHOD`/`_ELEM` and the
  getter/setter equivalents install each entry non-enumerable (`W|C`, no
  `E`), which is the one property-attribute difference from an object
  literal's methods. `Ctx::$isClassConstructor` marks the function so `CALL`
  and `Vm::invoke()` refuse `[[Call]]` — a class must be `new`ed — and so its
  `prototype` is installed non-writable/non-configurable rather than the
  ordinary writable slot.

  `super.prop` / `super.method()` resolve through `[[HomeObject]]`
  (`JSFunction::$homeObject`), a slot `SET_HOME_OBJECT` fills in when each
  method is created, pointing at the `prototype` (or, for a static member,
  the constructor itself) it was defined on — not at the instance, which is
  why an overridden method can still reach the version one level up
  regardless of how deep `this`'s own class actually is. `checkSuperAllowed`
  refuses both forms outside a class method and, since an arrow has no
  `[[HomeObject]]` of its own and inheriting one the way it inherits `this`
  is unimplemented, inside an arrow too.

  `super(...)` is a deliberate simplification rather than the full spec
  algorithm: it runs the parent constructor's body against the *existing*
  `this` (`Vm::invoke($parentCtor, $thisVal, $args, $thisVal)`) instead of
  threading a dynamic `new.target` through the construction protocol.
  `Reflect.construct` is the only thing that would observe the difference,
  and `Reflect` is already out of scope (below), so the gap costs nothing
  reachable. The same simplification is why extending a native constructor
  (`class E extends Error {}`) is refused with a `TypeError`: `Error`'s own
  `[[Construct]]` builds and returns a fresh object rather than initializing
  the one it's handed, which the simplified model has no way to honor. That
  check, like extending a non-constructor or an arrow function, happens at
  `NEW_CLASS` — the superclass position is a general expression, not a
  syntactic fact, so it can only be caught once evaluated.

  A class's own binding is lexical but not constant: `class A {}; A = 1;`
  succeeds in every current engine, confirmed against Node before writing
  the compiler's TDZ check, so it goes through the same re-checked-at-write
  path as `let` rather than `const`'s.

  A class with no explicit `constructor` gets a synthesized one — source
  text (`(function(){})`, or `(function(...args){super(...args);})` for a
  derived class) parsed once per class and run through the same
  `analyzeFunction`/`compileChild` pipeline as a written one, rather than a
  hand-built template, so it can never drift from what a real constructor
  compiles to.

  Peast's own grammar had a bug here: `MethodDefinition.kind` collapsed to
  `"constructor"` whenever the key was named `constructor`, regardless of
  whether the method was actually a getter, setter, generator or async
  method — silently discarding the information needed to refuse `get
  constructor(){}` / `set constructor(){}`, both of which are early errors
  (a *static* accessor named `constructor` is unaffected — spec-legal, and
  the parser's own kind never applied to it since it only matters for
  `IsStatic false`). Fixed upstream rather than special-cased here, same
  policy as the destructuring bugs above: `parseMethodDefinition` now only
  folds the kind to `KIND_CONSTRUCTOR` for a plain, non-generator,
  non-async method. `static prototype(){}` on a class is refused for the
  more ordinary reason that the compiler now checks for it directly.

  **Explicitly refused, not silently ignored:** private fields and methods
  (`#x`), public instance and static fields (`x = 1;` outside a method), and
  static initialization blocks — all out of scope for the same reason as
  Proxy/Reflect below, an object-model change rather than a syntax one.

- **Generators** — `function*`, `yield`, `yield*`, and the same on object-literal
  and class methods — the VM's frame-as-our-own-data design (§4) existing
  specifically to make this cheap, not bolted on afterward.

  A generator function's `[[Call]]` never runs its body directly. Compiled
  bytecode gets one addition: right after the parameter/hoisting prologue and
  before the first real statement, every generator body has a `YIELD`
  inserted as a barrier the compiler, not the source, put there.
  `Vm::createGenerator()` pushes a frame and runs it exactly like an ordinary
  call, which means parameter defaults and destructuring run and can throw
  synchronously *as part of calling the function* (a bad destructuring
  parameter is a `TypeError` from `f(...)`, not from the first `.next()`,
  matching FunctionDeclarationInstantiation's place in
  EvaluateGeneratorBody) — and then immediately hits that barrier and
  suspends, before ever reaching a statement the source actually wrote. The
  frame that suspend captures — the live locals-plus-operand-stack region
  (relative to the frame's own base, so it replays at whatever base a later
  resume lands on), `pc`, environment, and exception-handler table (also
  rebased) — becomes the whole content of the returned Generator object.
  Nothing about this is generator-specific machinery bolted beside the
  dispatch loop; `YIELD`'s suspend path pops one frame and returns a small
  internal sentinel (`Vm\FrameSuspend`) from `execute()` exactly the way
  `RETURN` returns a value, and resuming pushes a frame and calls `execute()`
  exactly the way any native re-entry into JS already does (the same pattern
  `Array.prototype.map`'s callback invocation uses) — so the reentry guard,
  frame-count limit and wall-clock deadline all apply for free.

  `next`/`throw`/`return` collapse into one entry point,
  `Vm::resumeGenerator()`, selected by a mode tag. A suspended `YIELD` always
  resumes the same way regardless of which of the three woke it: the resumer
  never pushes onto the operand stack (an already-inflight expression like
  `1 + (yield x)` must find `1` exactly where it left it), it writes the sent
  value and the mode into two ordinary local slots the compiler allocated for
  that `YIELD` site, and lets bytecode immediately after it branch three ways
  — `Compiler::genYieldSuspend()`, reused verbatim for the start barrier
  above and for `yield*`'s delegation loop below. A next-mode resume just
  reads the sent value as the yield expression's result. A throw-mode resume
  emits an ordinary `THROW` of the sent value right there, which the frame's
  own already-restored exception-handler table catches exactly as it would a
  real `throw` at that point — no separate mechanism at all, and why a
  `.throw()` before any `.next()` correctly does nothing but rethrow (no
  handler has been registered yet). A return-mode resume runs
  `emitExitCleanup(0)` — the identical call a written `return` statement at
  that exact point would make — so a `.return()` reaching into a `try/finally`
  around a `yield` runs the `finally` and nothing peculiar to generators had
  to be taught to the exception/finally machinery to make that true.

  `yield* expr` is the same `YIELD` primitive driving a compiled loop instead
  of a single suspension. Each pass calls `next`/`throw`/`return` on the
  inner iterable — `next` through the method GetIterator captured once,
  `throw`/`return` looked up fresh every pass, per spec, since mutating them
  mid-delegation is observable — validates and unpacks the result
  (`Op::YIELD_DELEGATE_STEP`), and either yields the value out through a
  plain `YIELD` (feeding next pass's mode/value straight from what *that*
  suspension gets resumed with) or, once the inner iterable reports done,
  either resolves `yield*`'s own value or — only when the pass that finished
  it was itself return-mode — propagates an outward `return` through this
  `yield*`'s own enclosing finalizers. A missing `throw` method closes the
  inner iterable and raises the protocol-violation `TypeError` the spec
  asks for; a missing `return` method completes the delegation with the
  received value directly.

  Two upstream Peast bugs surfaced building this, both fixed in
  [ryohey/peast](https://github.com/ryohey/peast) rather than around it:
  `MethodDefinition.kind` collapsing to `"constructor"` for any method
  literally named `constructor` regardless of whether it was a
  getter/setter/generator/async method (found via `get constructor(){}`,
  fixed by only folding the kind for a plain method); and the parser's
  context-keyword exclusion — what makes bare `yield` illegal as an
  identifier inside a generator body — comparing a token's *raw* source text
  against the keyword table, so an escaped `yield` silently read as an
  ordinary identifier where the unescaped keyword was correctly rejected.

  **Explicitly out of scope:** async generators and `for await` (both need
  `async`/`await`, already out of scope below), and two narrower gaps
  inherited from elsewhere in the compiler rather than anything about
  generators specifically — a generator function declaration is not treated
  as a lexical (redeclaration-checked) name the way `let`/`class` are, and a
  generator's own `[[Prototype]]` stays `%Function.prototype%` rather than
  the spec's separate (and practically unobservable) `%GeneratorFunction.prototype%`.

- **NamedEvaluation** (8.6.2) — `var f = function(){}` naming `f`, and every
  other form the spec threads a name through: `let`/`const`, a plain
  assignment to a bare identifier, a parameter default (including inside a
  destructuring pattern, but only for a bare `SingleNameBinding`, never a
  nested pattern's own default), and a non-computed object-literal property.
  Was the single largest failure group in test262 since arrow functions
  landed; fixing it moved the suite from 93.9% to 96.1%.

  One check carries all of it: `Compiler::analyzeMaybeNamed`, called at each
  of those sites instead of the ordinary `analyzeNode`, unwraps parens (at
  any depth — `var f = ((function(){}))` still names it "f") and checks
  `IsAnonymousFunctionDefinition` before threading the name through the exact
  mechanism a class method's own key already used to name itself
  (`analyzeFunction`'s `inferredName` parameter, generalized from what used
  to be class-method-only `classMethodName`) — SetFunctionName never
  overrides a name an expression already carries (`function foo(){}` keeps
  "foo" no matter where it appears), so this has to be a compile-time
  decision, not a runtime overwrite. One asymmetry survives the paren-unwrap
  rule: `(fn) = function(){}` does *not* name it, because IsIdentifierRef of
  the assignment's left side is checked against the source as written, not
  unwrapped — parens are only ever transparent on the value side.

  A non-computed object-literal key reuses the same mechanism for its
  method/getter/setter forms too (`{ m(){} }`, `{ get g(){} }`), which turned
  out to be a gap of its own — `.name` on those was empty before this landed,
  not just on the NamedEvaluation-eligible `init` form.

  A **computed** key (object-literal or class) is the one case with no
  compile-time name at all: `{ [k]: function(){} }`'s name depends on
  evaluating `k`. `SET_FUNC_NAME`, a new opcode, does the naming after the
  fact, right after the value is created and before it is ever attached to
  the object — reusing the same key `TO_KEY` already converted rather than
  the pre-conversion value, with `Realm::symbolByKey()` reversing a symbol's
  conversion back to its `[[Description]]` for the spec's bracketed
  `"[desc]"` form (or `""`, for a description-less symbol — never a bracket
  pair around nothing). This also retired a gap classes landed with: a
  computed class method's name was simply left blank before, for the same
  reason (§2.5's class section originally called it out as "the same
  NamedEvaluation gap ordinary functions already have").

  Two more bugs surfaced chasing this down, neither about NamedEvaluation
  itself:

  - A named function expression's own binding (`function foo(){}`'s `foo`,
    visible for recursion inside its own body) was fully mutable. It is
    supposed to be immutable, but — unlike `const` — created with
    `CreateImmutableBinding`'s strict parameter `false` (13.2), so an
    assignment silently does nothing in sloppy code and only throws in
    strict mode; either way the assignment *expression* still evaluates to
    the right-hand value, since `PutValue` never actually runs.
  - `JSFunctionBase::ensureOwn()`, which lazily materializes `.name`/
    `.length` on first access, did not check whether a class had already
    installed its own static member under that key first (`class C { static
    get name(){...} }` is legal and must win — 15.7.14 explicitly overwrites
    the constructor's own `.name` for exactly this case). The lazy default
    was clobbering it the first time anything read `.name`.

  **Left as narrower, separate gaps:** eval's dynamic scope resolution does
  not see a named function expression's own binding (so reassigning it
  through `eval` in strict mode throws `ReferenceError` instead of
  `TypeError` — a sharper edge of the same eval-scoping limit as always), and
  own-property enumeration order for a function's lazily-materialized
  `.length`/`.name` does not match the spec's creation-time order when a
  class also defines its own static member under one of those keys.

- **Per-iteration loop bindings** (CreatePerIterationEnvironment, 14.7.4.3) —
  the refusal above, retired. A `for`/`for…in`/`for…of`/`while`/`do…while`
  whose head or body declares a `let`/`const`/`class` a closure captures now
  gets a real environment of its own, created fresh every pass, rather than
  sharing the one flat environment its enclosing function already has.

  Every environment this VM had ever created before this belonged to a
  function call (§4.5): one per invocation, sized once, indexed by depth
  counting *function* boundaries (`Compiler::envDepth`, walking `Ctx::$parent`).
  A loop is not a function call, so giving one its own environment meant a
  second kind of scope that owns one — `Compiler\LoopEnvScope`, existing only
  for a loop that actually turns out to need it (checked once, after
  analyzing its own head and body; the overwhelming majority of loops get an
  empty one and compile exactly as before) — chained into the *same*
  `$parent`-walk `Ctx` already used, so a closure declared inside a loop
  lists that loop's scope as its own parent (`Compiler::loopEnvParent`) and
  `envDepth` crosses however many loop layers and function layers actually
  separate two points, in whatever order they nest, never needing to know
  which kind of layer it is looking at except whether that particular one
  ended up owning an environment at all (a function's `nenv > 0`, a loop's
  `size > 0`).

  Three new opcodes do the runtime work. `CAPTURE_ENV` stashes the loop's
  outer environment once, before the first iteration; every later
  environment is created as that same environment's *sibling*, never a
  child of the previous iteration's — chaining them would grow the parent
  chain without bound over a long-running loop, and every iteration needs
  the same depth relative to the outside regardless of how many have run.
  `NEW_ITER_ENV` replaces the current environment with a fresh one. `RESTORE_ENV`
  puts the stashed outer one back. Only a classic `for`'s own head bindings
  carry a value forward from one pass to the next (14.7.4.3's actual
  environment-copying step: read the current value out of the environment
  about to be replaced, create the fresh one, write it back) — `for…in`/
  `for…of`'s head and any loop body's own block bindings are simply fresh
  and TDZ'd every pass, the same as re-entering an ordinary block already
  works, just now into a new environment instead of the same reused slots.

  Restoring the outer environment on the way out only needed solving once
  for the two ways out actually differ. A `break`/`continue`/`return`
  crossing the loop is compile-time-known control flow, so it is inlined
  the same way closing a `for…of` iterator already is (`emitExitCleanup`) —
  a bookkeeping entry, not a real `TRY_ENTER`, since nothing registered a
  handler for it. An exception unwinding through needed nothing added at
  the loop at all, once `TRY_ENTER`'s handler-table entry captured the
  environment that was live *at that point* rather than reading whatever
  the frame's environment happens to be when the handler runs: the nearest
  real handler that actually catches it — inside the loop, or an ordinary
  `try` outside it — already recorded the right one to restore, on its own,
  for a reason that has nothing to do with loops.

  Two bugs surfaced building this, both about state that has to reset at a
  function boundary and did not:

  - `Binding::$inLoop` and the new per-loop bookkeeping are compile-time
    *position*, not lexical nesting — a function's own body is a fresh call
    each time regardless of which iteration of an enclosing loop declared
    it, so neither may leak into that function's own analysis (a `let` in
    *that* function's own unrelated loop is not "captured inside a loop"
    just because the function itself happens to sit inside one). Both passes
    now save, reset, and restore this bookkeeping at every function boundary
    (`analyzeFunction` and `genFunction` alike) — missing the codegen half
    of this (the analysis half was there from the start) was the more subtle
    of the two, and only surfaced compiling a real npm package with three
    levels of nested closures reaching into an enclosing loop's own binding,
    which needs the second one to line up depths correctly at all.
  - A long-stale `skip.txt` entry excluded the entire `for…of` statement test
    directory as "ES6 iteration protocol, out of scope" — true when it was
    written, false since `for…of` itself landed (above), and worth removing
    now specifically because it was hiding whether *this* feature actually
    works against test262's own dedicated per-iteration-environment tests for
    it, not just the hand-written cases here. It passes 693 of 701 once
    unskipped; the eight remaining are a pre-existing, unrelated TDZ-timing
    gap (the head's own binding should already be in its dead zone while the
    iterable expression to its right is evaluated, which is not implemented)
    and an iterator-close gap, neither touched by this feature.

- **Parameter/body variable scope** (FunctionDeclarationInstantiation, 9.2.12) —
  the gap flagged all the way back at defaults and rest, retired: a parameter
  default carrying a value expression, or a destructuring pattern anywhere in
  the parameter list, now gives the function body its own variable
  environment, a child of the one the parameters live in. A bare rest
  parameter alone still does not — nothing then distinguishes the two
  environments, so the list stays "simple" for this purpose even though it
  is not for `length`'s. Every one of the body's own `var`/function
  declarations lives there, not just ones whose name collides with a
  parameter's: `function f(a = 1) { var b = 2; return () => b; }`'s inner
  closure sees `b` only because it is declared in the body's own environment,
  not the parameters'; a closure made *in* a parameter default has no way
  back into it even once it exists, since the default runs — and any closure
  it creates closes over the parameter environment — before the body
  environment is ever created, and the two are chained in one direction only,
  parameters outward from body, never the reverse. A name that does collide
  with a parameter starts as a copy of that parameter's *current* value
  (post-defaults, post-destructuring), taken once, at body start; every other
  name simply starts undefined, same as a parameter would.

  Reused the same `EnvScope` a loop's per-iteration environment already
  introduced above, renamed from `LoopEnvScope` to name what it now is:
  compile-time bookkeeping for a nested environment smaller than a whole
  function, exists only when analysis finds one is actually needed, and
  composes through the same `envDepth` walk either way, without the walk ever
  needing to know which of the two reasons created a given layer. Resolving a
  name against the body's own declarations first, and the parameters second,
  reuses `lexStack` shadowing exactly the way a nested block already shadows
  an outer one — a new innermost layer for the body's own bindings, popped
  before the parameter defaults/patterns are analyzed (which must resolve
  against the parameters alone; the body's names do not exist yet at that
  point in the spec's own instantiation order).

  One bug surfaced building this, unrelated to the feature itself but found
  while reading the code it touches: codegen popped the function body's own
  block-scope `lexStack` layer twice where analysis only ever popped once,
  silently discarding the *next* layer down — this function's own
  parameter/var binding table — whenever the body happened to open one (a
  top-level `let`/`const`/`class` of its own). Every var/function name
  resolved afterward then fell through to the global object instead of the
  enclosing function's own locals. It went unnoticed because the only
  observable effect was a leaked local becoming reachable as a global
  property — the value itself still read back correctly, since both the
  errant write and the later read degraded to the same global lookup. Fixed
  and landed on its own, ahead of and separate from this feature.

- **Nullish coalescing** (`??`) — chosen from a fresh look at what real
  `node_modules` code still rejects, per the note above: a 2000-file sample of
  a large real corpus put it at 4.6% of files (`ChainExpression`, optional
  chaining, was close behind at the same look but stayed out of scope; async
  functions matched it almost exactly and are next).

  One new opcode, `JNN_KEEP`: jumps past the right side keeping the left
  side's value when it is neither `null` nor `undefined`, otherwise pops it
  and falls through — the same jump-keeping-or-popping shape `JT_KEEP`/
  `JF_KEEP` already use for `||`/`&&`, just with "nullish" in place of
  "falsy" as the test. Peast already enforces the one early error specific to
  this operator (mixing it with `&&`/`||` without parentheses is a
  SyntaxError), so nothing extra was needed on that front.

  **Deliberately left refused:** the logical assignment operators (`&&=`,
  `||=`, `??=`), which need genuinely different codegen from the ordinary
  compound-assignment path (`+=` and friends evaluate both sides
  unconditionally; these three must not evaluate the right side, or
  re-evaluate a member expression's object, unless the short-circuit
  condition allows it) rather than falling out of this operator's own
  addition for free.

- **Catch clause patterns and the optional catch binding** — two related
  gaps in the same statement, both retired together since both touch
  `genTryCatchPart`: a catch parameter can now be a destructuring pattern
  (`catch ({message}) {}`), and a catch clause can omit its parameter
  entirely (`catch {}`, ES2019), discarding the thrown value with nothing
  bound at all.

  A pattern parameter binds every name `patternNames` finds in it, each its
  own `catch`-kind `Binding` — reusing the exact `genPattern` machinery a
  `var`/`let` declaration or a function parameter pattern already goes
  through, materializing the thrown value into a temp once (its leaves may
  read it more than once) the same way a destructuring assignment's
  right-hand side is. All of a pattern's names, like the single name the
  identifier case already had, sit in a mini-scope of their own outside the
  catch body's block scope, so a `let` in the body may not redeclare any of
  them — `Compiler::$catchBind` generalized from one `Binding` per
  `CatchClause` to one per bound name to carry this through. A name repeated
  within the same pattern (`catch ({a, a}) {}`) is a SyntaxError Peast itself
  does not catch, so the compiler checks for it directly.

  One bug surfaced building this: the pattern's own destructuring was wired
  up to run *before* its names were pushed onto `lexStack`, so every leaf's
  store found nothing to resolve against and fell through to the global
  object — the same failure mode, and same class of ordering mistake, as the
  `lexStack` double-pop fixed just above. Caught immediately by comparing
  against Node rather than shipping on the strength of "it compiled and
  didn't throw."

  **Left as a separate, pre-existing gap:** a catch clause's own parameter
  name (pattern or plain) may not be redeclared by a *nested function
  declaration* in its body either, per an early error test262 checks for
  directly — sloppy-mode Annex B function hoisting is not modeled as adding
  a lexically-declared name for the purpose of this specific check. Fails the
  same way, with or without this feature, on a plain identifier catch
  parameter too.

- **Async functions, arrows and methods** (`async`/`await`) — the candidate
  this section's own commentary had already singled out, on the reasoning
  that generators proved the suspended-frame mechanism and the Promise
  machinery it resumes through already existed. Both proved out exactly as
  expected: this landed by teaching two existing mechanisms to drive each
  other, with no third one built from scratch.

  `Vm\GeneratorSuspend` — `execute()`'s return value when a suspend point
  pops its own frame mid-body — is renamed `FrameSuspend`, since `YIELD` no
  longer has sole claim to producing one: `await` suspends exactly the same
  way, through a new `AWAIT` opcode sharing `YIELD`'s own case body outright
  (identical mechanics either way; only who drives the resume differs).
  `FrameSuspend` also grew three fields, `func`/`thisVal`/`args`, redundant
  for a generator's own resume (it already has them on its
  `JSGeneratorObject`) but load-bearing for async: nothing wraps an
  in-flight async call the way a generator wraps a suspended one, so the
  suspend state has to be self-contained.

  `Vm::createAsyncCall` is the async analogue of `createGenerator`: instead
  of creating a Generator object suspended before its first instruction, it
  runs FunctionDeclarationInstantiation and the body synchronously up to the
  first `await` (or to completion) -- there is no barrier suspending before
  the first statement the way a generator's `GeneratorStart` needs, since an
  async function's body always starts running immediately (27.7.5.1) -- and
  always returns a Promise, created up front, that this call is the only
  thing that will ever settle. A throw escaping *before* any `await` rejects
  that promise rather than propagating synchronously out of the call: per
  spec, calling an async function never throws directly, whatever runs
  synchronously inside it.

  Resuming is where the two mechanisms actually meet: `await`'s operand goes
  through `PromiseResolve` (adopting an existing promise or thenable rather
  than double-wrapping it) and a reaction registered on it via the ordinary
  `Promise.prototype.then` machinery -- a native job (`AsyncBuiltins.
  resumeJob`, never JS-visible, the same shape `Promise.reactionJob`/
  `Promise.thenableJob` already use) that calls `Vm::resumeAsync`, which
  restores the frame exactly like `resumeGenerator` does and runs it forward
  from the microtask queue automatically, with no external driver at all.
  Nothing here runs inline: even an already-resolved value's continuation
  waits for a microtask, which is what makes `await x` observably suspend at
  least once regardless of what `x` is.

  Everything downstream of "the frame suspends and resumes" needed no
  changes at all: `this`, closures, a per-iteration loop binding, and
  `try`/`catch`/`finally` (including the exception-handler-table env capture
  built for loops and reused by generators) all already survive a suspend
  crossing them, since none of that machinery knows or cares which of the
  two reasons caused it.

  `.prototype` and `[[Construct]]` follow the arrow's own precedent exactly:
  an async function has neither, the same reasoning as an arrow (never
  constructible) rather than a generator's own special case (constructible
  only via its own iterator protocol).

  **Explicitly refused, not silently ignored:** `async function*`
  (async generators) and `for await` -- both still need each other and
  neither is in scope yet, same as before this landed.

  **Left as narrower, separate gaps, all pre-existing and none unique to
  async:** the compiler's own wider-than-spec refusal of a parameter default
  referencing a later parameter (§2.5's opening) also blocks referencing an
  `await`ed later parameter, where the spec is more precise about it; a
  function/generator/class/async-function *declaration*'s completion value
  is not the spec's `empty` (the same gap `try`'s own completion-value tests
  already surface); and Peast does not yet track "inside an async function"
  precisely enough to refuse `await` as an ordinary expression inside a
  *parameter default* specifically (it correctly refuses `await` as a
  *binding name* there) -- the identical gap already exists for `yield` in a
  generator's own parameter defaults, unaffected by this feature landing.

  One genuine Peast bug did surface and was fixed upstream, not worked
  around: `parseFunctionOrGeneratorDeclaration`'s `$allowGenerator=false`
  callers (a labelled statement's body, and the Annex B.3.4 if-statement-body
  position) correctly blocked `function*` there but had no way to also block
  `async function`, since the async check ran unconditionally before that
  flag was ever consulted -- `label: async function f(){}` compiled when
  Node refuses it. The same flag now gates both, since every current caller
  already wanted them disabled together.

- **Optional chaining** (`?.`, `?.[]`, `?.()`) — the other candidate the
  fresh look turned up, landed right after async since nothing about it
  depended on that choice. `a?.b`, `a?.[k]` and `a?.()` all short-circuit the
  *whole rest of the chain* to `undefined` the moment any step in it meets a
  nullish value, not just that one step -- `a?.b.c.d` skips `.c` and `.d`
  too if `a` is nullish, even though neither is itself marked `?.`.

  Peast already gives this to the compiler as ESTree does: one `?.` step
  anywhere sets `optional: true` on that specific `MemberExpression`/
  `CallExpression` node, and the *whole* chain it belongs to is wrapped once
  in an outer `ChainExpression` (however many `?.` steps it has, one wrapper
  covers all of them). Compiling it is one new opcode, `JNULLISH` (pop and
  jump if nullish -- the mirror image of `??`'s own `JNN_KEEP`, which jumps
  keeping the value when it is *not* nullish), plus a compile-time-only
  `$chainStack`: entering a `ChainExpression` pushes a fresh list, every `?.`
  step found while compiling its wrapped expression (an ordinary recursive
  walk of nested `MemberExpression`/`CallExpression`s, unchanged from how
  they already compiled) appends its jump site to whichever list is
  currently open, and once the walk finishes every site gets patched to one
  shared landing pad placed after the chain's own normal-path result --
  `POP` the leftover nullish value every jump site leaves behind, `PUSH_UNDEF`.
  A non-optional step later in the same chain needs no special handling *at
  all* to end up skipped: it simply sits, in program order, between an
  earlier jump and where that jump lands.

  Parens close a chain (`(a?.b).c` does not propagate `a`'s short circuit to
  `.c` -- it throws instead, same as `(undefined).c` always would), which
  falls out for free: a `ParenthesizedExpression` is not a `MemberExpression`/
  `CallExpression`, so the recursive walk simply cannot see through one, the
  same reason it does not see through a `CallExpression`'s own arguments
  either (`f(a?.b)`'s argument is its own independent chain). One shape
  needs its own handling anyway: `(a.b)()`/`(a?.b)()` already had to preserve
  `this` through parens even without any chain involved (`(a.b)()` calls
  with `this === a`, not `undefined` -- parens do not strip a
  MemberExpression's Reference-ness for a call specifically), so a
  parenthesized chain used as a call's callee gets a chain scope of its own,
  opened and closed right there instead of relying on an enclosing one,
  since there is nothing enclosing it once the parens have closed it off.

  `delete a?.b` is the one place a chain's short circuit does not mean
  `undefined`: nothing to delete is trivially successful, `true`, and
  reaching the member for real means an actual deletion, not a read -- its
  own small variant of the chain-compiling machinery, sharing everything
  except the final opcode and the landing value.

  A genuinely tricky case surfaced building the call-target handling:
  `GET_METHOD`/`GET_METHOD_ELEM` fuse `[func, this]` into one opcode with
  `this` landing on top, which makes `func` awkward to nullish-check once it
  is buried underneath. A temp local isolates `this` off the operand stack
  for exactly as long as the check needs, so it still runs against a single
  value the same as every other step in a chain does.

  **Left as a pre-existing, unrelated gap:** `eval?.('x')` should be an
  *indirect* eval (direct eval requires the literal syntactic form
  `eval(...)`, which `?.` is never part of), but the compiler does not
  distinguish direct from indirect eval by the call's syntactic shape at
  all -- a limitation of eval's own existing implementation, not something
  optional chaining introduces or could fix on its own.

**Next:** none queued. Both candidates this section's fresh look turned up
have landed. Future syntax work keeps starting from a fresh look at what
real `node_modules` code still rejects (`tests/acceptance/run.php`'s own
rejection breakdown), not from a backlog written up front.

**Deliberately out of scope for now:** ES modules (node-compat resolves
CommonJS, and published packages overwhelmingly ship it), Proxy and Reflect
(an object-model change), private fields, and async generators/`for await`
(both need each other, neither built yet).

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
- With frames as our own data, generators (landed, §2.5) and `async`/deep recursion can be retrofitted as "frame suspend/restore" (that door closes the moment frames live on the PHP call stack).

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

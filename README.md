# php-js

Experimental JS runtime on pure PHP: JavaScript compiled to bytecode and executed
by a PHP VM. No WASM, no extensions — one interpretation layer, designed around
PHP's runtime characteristics (refcount GC, opcache, shared-nothing request
model).

It started as an ES5.1 engine on the assumption that anything newer would be
downlevelled by a toolchain first. That holds for code you author and not for
code you install: of 400 published files sampled from a real `node_modules`, the
ES5.1 compiler accepted 46.8%, and `const` alone accounted for 35% of the
refusals. So the language target is growing — see [DESIGN.md §2.5](./DESIGN.md)
for what has landed and in what order the rest is coming.

- Design document: [DESIGN.md](./DESIGN.md)

## Progress

Two numbers, because they answer different questions (DESIGN.md §12).

**Reach** — how much published JavaScript compiles at all. This is the one that
says whether the syntax work is aimed at anything:

```console
$ php tests/acceptance/run.php --dir path/to/node_modules
  accepted   339   84.8%
  rejected    61   15.2%

what the rejections tripped on:
  ClassDeclaration            15   3.8%
  Generators                  10   2.5%
  unexpected export            7   1.8%
  ...
```

Template literals, arrow functions, default/rest parameters, `let`/`const`,
destructuring, `for…of`, spread and tagged templates have landed since —
46.8% to 84.8%. Classes are next.

**Conformance** — the test262 pass rate over what is implemented. Against a
current tc39/test262 checkout:

| Area | Pass rate | Run |
|---|---|---|
| **overall** | **96.3%** | 15498 |
| `language/` | 95.5% | 6503 |
| `built-ins/` | 96.8% | 8995 |

A further ~21000 tests are skipped as out of scope: features the engine cannot
attempt at all by front-matter tag, out-of-scope builtins by path, and — the
largest group — tests whose *own source* uses syntax the compiler does not accept
yet, which it cannot run by construction. The runner reports those as skips
rather than failures so the rate reflects engine defects only.

Both numbers move as the syntax surface grows, and the percentage is the less
interesting one: each landed feature un-skips a batch of tests that were never
passing, only unreachable, so the denominator grows with the numerator. The
honest comparison is the **failure set** — a feature is done when the tests it
brought in pass and nothing that passed before stopped.

```console
$ git clone --depth 1 https://github.com/tc39/test262.git ../test262
$ php tests/test262/run.php --test262 ../test262
```

The whole suite takes about three minutes.

## Status

The core language works end to end:

- **Compiler**: Peast (ESTree) → scope analysis (closure capture, strict-mode
  early errors) → stack bytecode → a peephole pass that fuses the opcode pairs
  a real workload actually executes. ES5.1 plus template literals, arrow
  functions, default/rest parameters, `let`/`const` (block scopes, temporal dead
  zone, redeclaration errors), destructuring, `for…of`, spread and tagged
  templates so far; DESIGN.md §2.5 has the order for the rest
- **VM**: single `while/switch` dispatch loop, own frame stack (JS calls never
  consume the PHP call stack), in-VM exception handling, wall-clock execution
  limit
- **Values**: unboxed — JS number → PHP `int|float` (int only where a double is
  exact), string → PHP `string` (UTF-8 storage, UTF-16 semantics on demand),
  only `undefined` is a sentinel. `Symbol` is a real primitive (`typeof` answers
  `"symbol"`), which no polyfill can be and which React's fragments require
- **Objects**: PHP-array property storage, spec `[[DefineOwnProperty]]`,
  `[[OwnPropertyKeys]]` ordering, array exotic behaviour including sparse
  index attributes, and a mapped `arguments` object
- **Builtins** (native PHP): Object, Function (`call`/`apply`/`bind`, the
  `Function` constructor), Array (generic over array-likes, sparse-aware),
  String, Number, Boolean, Math, JSON (spec `Walk`/`Str` with reviver and
  replacer), Error family, console, RegExp (translated to PCRE2), Date
- **Iteration**: `%IteratorPrototype%`, the array and string iterators (the
  string one steps by code point), and `@@iterator` on Array, String and
  `arguments`. `for…of`, spread and array destructuring all go through it, and
  `IteratorClose` runs on every abrupt exit
- **Promise** + microtask queue (native state machine, VM re-entry only for
  user callbacks; combinators build their result through `NewPromiseCapability`)
- **Bytecode files**: emitted as `<?php return [...];` — plain arrays that
  opcache keeps in shared memory (`phpjs compile`)

Known gaps, all tracked in DESIGN.md §15: direct `eval` inherits the caller's
strict mode but cannot inject bindings into the enclosing scope, `with` is
unimplemented, the well-known symbols other than `@@iterator` exist but nothing
consults them (so `@@species` still selects nothing), and local time is
fixed to UTC. The regexp translator accepts some patterns the spec rejects,
since PCRE does the parsing. Syntax newer than what §2.5 lists is refused with a
message naming the construct — downlevelling first still works, and is still the
right choice for code you control.

## Usage

```console
$ composer install
$ bin/phpjs eval '[1,2,3].map(function(x){ return x * x; }).join(",")'
'1,4,9'
$ bin/phpjs run app.js
$ bin/phpjs compile app.js -o app.phpjs.php   # opcache-friendly bytecode file
$ bin/phpjs disasm app.js                     # inspect bytecode
```

From PHP:

```php
use PhpJs\Engine;

$engine = new Engine();
$result = $engine->evaluate('6 * 7');            // int(42)
$engine->vm->setTimeLimit(1.0);                  // guard against runaway guest code
$tpl = require 'app.phpjs.php';                  // precompiled, opcache-resident
$engine->runTemplate($tpl);
```

## Packages

Host concerns live outside the core, in separate composer packages that depend
on it:

- [`packages/node-compat`](packages/node-compat) — CommonJS module loading,
  a read-only filesystem confined to a root, `process`, virtual-clock timers,
  and the ES2015+ *library* surface the engine does not own (Map, Set, typed
  arrays, `Object.assign`); `Symbol` and collection iteration moved into the
  engine, because a primitive type cannot be polyfilled.
- [`packages/react-ssr-bench`](packages/react-ssr-bench) — renders a React app
  server-side from React's own published CommonJS build, asserts the HTML is
  byte-identical to Node, and reports boot and render separately.
- [`packages/php-transpile`](packages/php-transpile) — build-time compiler from
  JavaScript functions to PHP. Converts 262 of React's 291 functions with
  unchanged output; the bytecode stays as the fallback.
- [`packages/ssg-demo`](packages/ssg-demo) — a small site written in TypeScript
  and JSX, rendered to HTML by React inside PHP. Renders a page on first request
  and serves the file after; `phpjs-ssg package` assembles a 2.9 MB render-only
  tree you can ship inside a plugin.

## Performance

React SSR runs at roughly 40x Node 22 for byte-identical output. Three things
are worth knowing before measuring anything yourself:

- **Turn PHP's tracing JIT on** (`-d opcache.jit=tracing
  -d opcache.jit_buffer_size=64M`). It is worth about 20% and is off by
  default.
- **Precompile.** Boot is dominated by compiling JS, which `phpjs compile` plus
  a warm opcache removes entirely.
- **Compile the hot library ahead of time.** `php-transpile` turns almost all
  of React into PHP: ~65% off a render, and ~58% even with the JIT already on.

`packages/react-ssr-bench/README.md` has the numbers and a per-opcode
breakdown of where the remaining time goes; `docs/aot-php.md` covers the
ahead-of-time PHP work and what it is measured to be worth.

## Development

```console
$ composer install
$ vendor/bin/phpunit
```

CI runs the test suite on PHP 8.2–8.4 for every push and pull request; a
separate weekly workflow runs test262 and uploads the pass-rate report.

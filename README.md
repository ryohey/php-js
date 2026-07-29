# php-js

Experimental JS runtime on pure PHP: an ES5.1 subset compiled to bytecode and
executed by a PHP VM. No WASM, no extensions — one interpretation layer,
designed around PHP's runtime characteristics (refcount GC, opcache,
shared-nothing request model).

- Design document: [DESIGN.md](./DESIGN.md)

## test262

The project's progress metric is the test262 pass rate over the ES5.1 subset
(DESIGN.md §12). Against a current tc39/test262 checkout:

| Area | Pass rate | Run |
|---|---|---|
| **overall** | **96.5%** | 13048 |
| `language/` | 94.2% | 4398 |
| `built-ins/` | 97.7% | 8650 |

A further ~22000 tests are skipped as out of scope: ES6+ features by front-matter
tag, post-ES5 builtins by path, and — the largest group — tests whose *own source*
is written in ES6 (arrow functions, `const`, template literals), which an ES5.1
engine cannot run by construction. The runner reports those as skips rather than
failures so the rate reflects engine defects only.

```console
$ git clone --depth 1 https://github.com/tc39/test262.git ../test262
$ php tests/test262/run.php --test262 ../test262
```

The whole suite takes about two minutes.

## Status

The core language works end to end:

- **Compiler**: Peast (ESTree) → scope analysis (closure capture, strict-mode
  early errors) → stack bytecode → a peephole pass that fuses the opcode pairs
  a real workload actually executes
- **VM**: single `while/switch` dispatch loop, own frame stack (JS calls never
  consume the PHP call stack), in-VM exception handling, wall-clock execution
  limit
- **Values**: unboxed — JS number → PHP `int|float` (int only where a double is
  exact), string → PHP `string` (UTF-8 storage, UTF-16 semantics on demand),
  only `undefined` is a sentinel
- **Objects**: PHP-array property storage, spec `[[DefineOwnProperty]]`,
  `[[OwnPropertyKeys]]` ordering, array exotic behaviour including sparse
  index attributes, and a mapped `arguments` object
- **Builtins** (native PHP): Object, Function (`call`/`apply`/`bind`, the
  `Function` constructor), Array (generic over array-likes, sparse-aware),
  String, Number, Boolean, Math, JSON (spec `Walk`/`Str` with reviver and
  replacer), Error family, console, RegExp (translated to PCRE2), Date
- **Promise** + microtask queue (native state machine, VM re-entry only for
  user callbacks; combinators build their result through `NewPromiseCapability`)
- **Bytecode files**: emitted as `<?php return [...];` — plain arrays that
  opcache keeps in shared memory (`phpjs compile`)

Known gaps, all tracked in DESIGN.md §15: direct `eval` inherits the caller's
strict mode but cannot inject bindings into the enclosing scope, `with` is
unimplemented, `@@species` does not exist in an ES5 realm, and local time is
fixed to UTC. The regexp translator accepts some patterns the spec rejects,
since PCRE does the parsing. ES6+ syntax is intentionally out of scope —
downlevel with SWC first.

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
  and ES2015+ *library* polyfills (the syntax stays ES5).
- [`packages/react-ssr-bench`](packages/react-ssr-bench) — renders a React app
  server-side from React's own published CommonJS build, asserts the HTML is
  byte-identical to Node, and reports boot and render separately.
- [`packages/php-transpile`](packages/php-transpile) — build-time compiler from
  JavaScript functions to PHP. Converts most of React to PHP with unchanged
  output; the bytecode stays as the fallback.

## Performance

React SSR runs at roughly 40x Node 22 for byte-identical output. Three things
are worth knowing before measuring anything yourself:

- **Turn PHP's tracing JIT on** (`-d opcache.jit=tracing
  -d opcache.jit_buffer_size=64M`). It is worth about 20% and is off by
  default.
- **Precompile.** Boot is dominated by compiling JS, which `phpjs compile` plus
  a warm opcache removes entirely.
- **Compile the hot library ahead of time.** `php-transpile` turns most of
  React into PHP: ~18% on a render, and ~16% even with the JIT already on.

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

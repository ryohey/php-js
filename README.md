# php-js

Experimental JS runtime on pure PHP: an ES5.1 subset compiled to bytecode and
executed by a PHP VM. No WASM, no extensions — one interpretation layer,
designed around PHP's runtime characteristics (refcount GC, opcache,
shared-nothing request model).

- Design document: [DESIGN.md](./DESIGN.md)

## Status

Early but functional. The core language works end to end:

- Compiler: Peast (ESTree) → scope analysis (closure capture) → stack bytecode
- VM: single `while/switch` dispatch loop, own frame stack (JS calls never
  consume the PHP call stack), in-VM exception handling
- Values: unboxed — JS number → PHP `int|float`, string → PHP `string` (UTF-8,
  UTF-16 semantics on demand), only `undefined` is a sentinel
- Builtins (native PHP): Object, Function (`call`/`apply`/`bind`, `Function`
  constructor), Array, String, Number, Boolean, Math, JSON, Error family,
  console, RegExp (translated to PCRE2), minimal Date
- Promise + microtask queue (native state machine, VM re-entry only for
  user callbacks)
- Bytecode emits as `<?php return [...];` files — plain arrays that opcache
  keeps in shared memory (`phpjs compile`)

Not implemented yet: direct `eval` scope access, `with`, mapped `arguments`
aliasing, test262 runner (see DESIGN.md §14 milestones). ES6+ syntax is
intentionally out of scope — downlevel with SWC first.

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
$result = $engine->evaluate('6 * 7');           // int(42)
$tpl = require 'app.phpjs.php';                  // precompiled, opcache-resident
$engine->runTemplate($tpl);
```

## Development

```console
$ composer install
$ vendor/bin/phpunit
```

CI runs the test suite on PHP 8.2–8.4 for every push and pull request.

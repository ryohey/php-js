# php-js node-compat

Node, as an environment the [php-js](../..) runtime can be given: CommonJS
`require` — the shape npm packages are published in — plus `process`, `fs` and
timers.

This lives outside the core package on purpose. The engine implements
ECMAScript and its whole standard library, and has no way out of the process;
`require`, `process`, `fs` and timers are things *Node* claims exist, and a
program that wants none of them should not pay for them. It is supplied
through `PhpJs\Host\Environment` (DESIGN.md §13.1) — two methods, `install()`
and `loadModule()` — which is the same interface a `deno-compat` would
implement, needing nothing from this package to do it.

**What is deliberately not here:** `Map`, `Set`, `Object.assign`,
`Math.clz32`, `String.prototype.padStart`. Those are ECMAScript, not Node.
They are in core, and an `Engine` has them whether or not anyone installed an
environment. (They used to be here, under the name "polyfills" — which made
the misplacement sound principled, and would have forced any second
environment to depend on this one just to get `Map`.)

## What it provides

| Piece | Notes |
|---|---|
| `NodeHost` | The `Environment` implementation: wires the rest into an `Engine` and exposes `require()` |
| `ModuleLoader` | CommonJS resolution: relative paths, `node_modules` walk, `package.json` `main`, extension probing, cyclic-safe module cache; decides when to consult an ahead-of-time-PHP cache |
| `ProcessBuiltins` | `process.env` / `argv` / `platform` / `version` / `cwd()` / `nextTick()` / `stdout.write()` / `hrtime()` |
| `FileSystemBuiltins` | `fs.readFileSync` / `existsSync` / `statSync`, confined to a configured root |
| `TimerBuiltins` | `setTimeout` / `setInterval` / `clearTimeout` / `setImmediate` over a virtual clock the host drains |
| `js/stubs/*.js` | Node core modules bundles pull in at load time even when unused: `stream`, `util`, `events`, `crypto`, `async_hooks` |

Syntax is a separate question from library surface: input is still expected to
be within what the compiler parses (DESIGN.md §2.5). `packages/strip-types`
handles TypeScript and JSX, transparently, if it is installed.

## Usage

```php
use PhpJs\Node\NodeHost;

$host = new NodeHost(__DIR__ . '/app');   // module resolution root
$host->setEnv(['NODE_ENV' => 'production']);
$exports = $host->requireModule('./server.js');
$html = $host->call($exports->get('render', $host->vm()), null, []);
```

## Ahead-of-time PHP, transparently

If `<root>/node_modules/.phpjs-aot/` exists, `NodeHost` finds it on its own —
no flag, no explicit wiring — and every `require()` after that checks it: a
module whose content hash matches a file there is handed back already
compiled, running some of its functions as native PHP instead of interpreted
bytecode, and one that does not match (because nothing ever compiled it, or a
dependency was upgraded since) just runs as before. That directory is
[`packages/aot`](../aot)'s job to populate (`phpjs-aot build`), never this
package's.

The cache format and the lookup itself are the engine's
(`PhpJs\Cache\ArtifactCache`) — caching a compile is the compiler's business,
not a module system's. What belongs *here* is only the decision of when to
consult one, which is genuinely CommonJS-shaped: `node_modules/.phpjs-aot` is
a `require()`-rooted convention, in the same spirit as `node_modules/.bin`.
The point of that split is that nothing which merely `require()`s a module
needs to know an AOT cache exists, and nothing about the cache needs to know
what CommonJS is.

```php
// Explicit, if a project wants a directory other than the auto-detected one,
// or wants the lookup off even though the conventional directory is there:
$host = new NodeHost(__DIR__ . '/app', aotCacheDir: '/path/to/cache');
$host = new NodeHost(__DIR__ . '/app', aotCacheDir: false);
```

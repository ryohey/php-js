# php-js node-compat

Host surface for running CommonJS code — the shape npm packages are published
in — on the [php-js](../..) runtime.

This lives outside the core package on purpose. The engine implements ECMAScript
and nothing else: `require`, `process`, `fs` and timers are host policy, and a
host that wants none of them should not pay for them. Everything here is built
from the engine's public API (`Engine`, `Realm`, `BuiltinRegistry`) with no
changes to the core.

## What it provides

| Piece | Notes |
|---|---|
| `NodeHost` | Wires the rest into an `Engine`, installs the polyfills, exposes `require()` |
| `ModuleLoader` | CommonJS resolution: relative paths, `node_modules` walk, `package.json` `main`, extension probing, cyclic-safe module cache |
| `ProcessBuiltins` | `process.env` / `argv` / `platform` / `version` / `cwd()` / `nextTick()` / `stdout.write()` / `hrtime()` |
| `FileSystemBuiltins` | `fs.readFileSync` / `existsSync` / `statSync`, confined to a configured root |
| `TimerBuiltins` | `setTimeout` / `setInterval` / `clearTimeout` / `setImmediate` over a virtual clock the host drains |
| `js/polyfills.js` | ES2015+ library surface an ES5.1 engine lacks: `Symbol`, `Object.assign`, `Map`, `Set`, `Array.from`, `String.prototype.repeat`, … |

The polyfills are *library* shims only. Syntax stays ES5.1: input still has to be
downleveled, which is the runtime's documented input contract.

## Usage

```php
use PhpJs\Node\NodeHost;

$host = new NodeHost(__DIR__ . '/app');   // module resolution root
$host->setEnv(['NODE_ENV' => 'production']);
$exports = $host->requireModule('./server.js');
$html = $host->call($exports->get('render', $host->vm()), null, []);
```

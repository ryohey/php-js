# php-js-aot

A CLI that ahead-of-time compiles the node_modules libraries a manifest names
into a directory any [`node-compat`](../node-compat) `NodeHost` picks up
automatically. It exists so that *using* AOT is a build step you run once and
otherwise forget about — the runtime side never mentions it.

## What it does

```console
$ phpjs-aot build --root .
react, react-dom/cjs/react-dom-server-legacy.node.production.js (./phpjs-aot.json)
262 / 291 functions compiled to PHP, 5 module file(s) -> node_modules/.phpjs-aot, in 0.8s
```

`build` reads a manifest (below), requires each library it names through a
[`php-transpile`](../php-transpile) `NodeIntegration`, and writes one PHP file
per module actually reached into `node_modules/.phpjs-aot/` — named by that
module's own content hash, in the format the engine itself owns
(`PhpJs\Cache\ArtifactCache`) and consults on every ordinary `require()`.
Each file holds both that module's whole compiled template and whatever
functions converted to native PHP, so a `require()` that finds one skips
compiling the module at all, not just interpreting less of it. That check is the entire integration: a `NodeHost` constructed against
a project root where that directory happens to exist automatically finds a
matching artifact and uses it; a project (or a specific library) that has
never run this CLI behaves exactly as if AOT did not exist, because as far as
its own code is concerned, it does not.

## The manifest

Either a dedicated `phpjs-aot.json` next to `node_modules`:

```json
{
  "libraries": [
    "react",
    "react-dom/cjs/react-dom-server-legacy.node.production.js"
  ]
}
```

or, if you'd rather not add a file, a `"phpjsAot"` key in `package.json` with
the same shape. The dedicated file wins if both exist. A library is a module
specifier resolved exactly the way `require()` resolves one from the project
root — a bare package name, or a deep path into one, whichever you'd actually
write in the code that loads it.

Nothing here is React-specific. Any node_modules dependency your project
requires can go in the list; what makes it worth compiling is being a pinned,
trusted version that isn't going to change out from under the cache — see
[`docs/aot-php.md`](../../docs/aot-php.md) for why that trust boundary exists
and what it costs to cross.

## Options

```
phpjs-aot build [--root DIR] [--config FILE] [--cache-dir DIR]
```

| | |
|---|---|
| `--root` | Module resolution root (default: current directory). |
| `--config` | An explicit manifest path, skipping the `phpjs-aot.json` → `package.json` lookup. |
| `--cache-dir` | Where to write (default: `<root>/node_modules/.phpjs-aot`, `NodeHost::AOT_CACHE_SUBDIR`). |

## One fixed profile per cache directory

Every artifact `phpjs-aot` writes is compiled under
`Assumptions::closedBuild()` — not configurable, on purpose. A cache
directory is addressed purely by a module's own content hash, with no
assumptions fingerprint folded in (unlike using `NodeIntegration` directly for
a one-off, single-file build), so mixing profiles under one directory would
silently mismatch generated code against what the interpreter would have
produced. One CLI, one profile, one directory: the invariant holds by
construction instead of needing to be remembered.

## Re-running it

`build` always recompiles everything the manifest names — content-hash
naming means an unchanged module reproduces the same file, and an upgraded
one (a version bump, most likely) produces a new one under a new name,
leaving the stale file writable but never looked up again. There is
currently no separate `clean` step; removing `node_modules/.phpjs-aot`
by hand is equivalent to a project that never ran this CLI at all.

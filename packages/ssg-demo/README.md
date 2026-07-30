# php-js-ssg-demo

A small website, written in TypeScript and JSX, rendered to HTML by React 19
running inside [php-js](../..). Start it locally and click around: the strip
along the top of every page reports what the page cost to produce and lets you
re-render the same URL a different way.

It exists to answer a question benchmarks cannot: **what does this feel like in
a browser.**

## Run it

```console
$ npm install && npm run build      # TSX -> one ES5 CommonJS bundle
$ composer install
$ bin/phpjs-ssg build               # compile React and the bundle into build/
$ bin/phpjs-ssg export              # optional: static HTML into dist/
$ bin/phpjs-ssg serve               # http://127.0.0.1:8080/
```

`serve` starts PHP's built-in server with opcache and the tracing JIT on, which
are not the defaults and are worth having.

Things worth doing once it is up:

- Click **AOT / bytecode / static** in the toolbar. Same URL, same bytes out,
  three ways of producing them.
- Open `/inventory/?items=2000`. The render is the page, and you can feel it.
- Look at **boot** on any page. That number is a whole JavaScript runtime and
  React's server build coming up from nothing, per request.
- Check the browser's own network panel — the timings are in `Server-Timing`
  too, so they show up next to the transfer time rather than only in the page.

## What each command does

| | |
|---|---|
| `build` | Compiles the Vite bundle, React and the polyfills to PHP arrays in `build/`. Nothing is compiled again after this. |
| `export` | Renders every route into `dist/` — static site generation. |
| `serve` | Local server, rendering on every request. |
| `compare` | Renders every route under Node too and requires the HTML to match byte for byte. |
| `bench` | Times one route both ways, interleaved, and prints the ratio. |

## How the pieces fit

```
app/*.tsx ──vite+babel──► bundle/entry.cjs ──► bytecode ──┐
                                                          ├─► request ──► HTML
node_modules ──php-js-transpile─────────────► PHP ────────┘   (opcache)
```

**The sources are TypeScript and JSX** in `app/`, bundled by Vite into one
CommonJS file.
Two things in `vite.config.ts` are not defaults and both matter: esbuild handles
the TSX but cannot target ES5, so Babel downlevels the finished chunk; and JSX
compiles to classic `React.createElement` calls, so the bundle needs nothing
from `react/jsx-runtime`. React itself stays external — the runtime loads
React's own published CommonJS build, which is the thing worth measuring.

**Dependencies are compiled to PHP; the site's own code is not.** The build runs
[php-transpile](../php-transpile) over `node_modules`, turning 262 of React's 291
functions into PHP functions, and stops there — `app/` stays interpreted. That
split is a security boundary, not an oversight; `src/Trust.php` is where it is
written down and *Why the site's own code stays interpreted* below is why. The
bytecode remains the fallback everywhere, so none of this is a dependency.

**A request compiles no JavaScript.** PHP is shared-nothing, so anything the
build does not precompute is paid per request — and parsing React's server build
is a few hundred milliseconds. The build writes the bytecode as plain PHP arrays
that opcache keeps in shared memory, which takes boot from ~400 ms to a few
milliseconds. A test asserts that a request compiles zero modules, because that
regressing would be invisible except in the timings.

**The toolbar is the host's, not React's.** The layout renders an empty
`<div id="phpjs-metrics">` and the server substitutes into it, because only the
server knows how long the render took. An export leaves it empty, so `dist/` is
deployable as-is.

## Why the site's own code stays interpreted

Precompiling bytecode and compiling to PHP look like one optimization. They are
on opposite sides of a line:

- **Bytecode is data.** `build/templates.*.php` is `<?php return [...];` of plain
  arrays. Loading it defines nothing and calls nothing; the VM interprets it
  afterwards, inside its sandbox. Precompiling any JavaScript is as safe as
  running it.
- **Generated PHP is code**, and running it gives up two things the interpreter
  guarantees. Measured, not assumed:

  | | interpreted | compiled |
  |---|---|---|
  | `while (true) {}` under a 0.5 s limit | `RangeError` after 0.69 s | never returns |
  | unbounded recursion | catchable `RangeError` | fatal: memory exhausted |

  The wall-clock limit is checked by the dispatch loop and generated PHP has no
  dispatch loop. Interpreted JS frames live on the VM's own stack; compiled
  frames are PHP frames. Neither is a bug to be fixed — they are most of why
  compiling is 60% faster.

There is no injection surface (emission is AST-to-AST through nikic/php-parser,
never string concatenation), but that was never the risk worth naming. The risk
is that the interpreter is the part with the safety rails, and code you did not
pin is exactly the code that needs them.

So: compile `node_modules`, interpret everything else. Extending the filter to
`app/` was measured at about **3%** — a fair price. A test asserts that no
template outside `node_modules` carries a native ID, because the filter is one
`str_contains` away from silently accepting everything.

Two related choices in the same spirit: `serve` leaves
`opcache.validate_timestamps` **on**, so a rebuild is always picked up and a
cached copy never outlives the file it came from; and `build/` is written by the
build, not by a request — a web process that can write there can run whatever it
writes.

## Two ways of not being wrong

- `bin/phpjs-ssg compare` renders every route under Node as well and requires
  the two to be **byte-identical**. This found a real bug the first time it ran:
  `(0.625).toFixed(2)` returned `"0.62"` where the spec and every other engine
  say `"0.63"` — PHP's `sprintf('%F')` rounds half to even and JavaScript rounds
  ties away from zero. Fixed in the engine, with tests.
- `DemoSiteTest` requires ahead-of-time PHP and interpreted bytecode to produce
  the same bytes for every route, and the static export to match the live render
  byte for byte.

## What ES5 costs you, in practice

The engine targets ES5.1 (plus Promise) and its compiler will not grow ES6+
syntax support, so the bundle is downlevelled — that part is mechanical, and it
turned out to be the whole cost. Nothing in this demo is written around the
engine.

That was not true at first. `<></>` failed with `Invalid tag: @@react.fragment`,
because React brands element types with `Symbol.for("react.fragment")` and its
renderer tests `typeof type === "string"` *before* identity-comparing against
those brands — and `Symbol` was polyfilled as a branded string. No amount of
JavaScript fixes that: `typeof` is a type question. So the engine grew a real
Symbol primitive (DESIGN.md §3.4) and fragments work.

Worth stating because it is the general shape of the thing: the gaps that matter
are types and syntax, not library surface. Library surface a polyfill can cover.

## Numbers from this machine

Not a benchmark — `packages/react-ssr-bench` is the benchmark. These are the
numbers the demo shows, so you know roughly what to expect. Absolute values
drift with the machine by a fair margin; `bin/phpjs-ssg bench` interleaves the
two engines so the ratio survives that, and the ratio is the durable part.

| | bytecode | ahead-of-time PHP |
|---|---|---|
| `/inventory/?items=120` render | ~400-420 ms | ~145-150 ms (**−64%**) |
| `/` render | ~12 ms | ~6 ms |
| boot, warm opcache | ~4-10 ms | ~4-10 ms |
| boot without a build | ~400 ms | ~400 ms |

## Layout

```
app/                the site: TypeScript and JSX, built by Vite
  entry.tsx           SSR entry for php-js
  entry.node.tsx      same components, rendered by Node, for the diff
  router.tsx          the route table
  components/         Layout, Home, Docs, Inventory, About
  content.ts          the site's text and data
src/                the host: PHP, autoloaded as PhpJs\Ssg\
  Builder.php         the build step
  Renderer.php        renders a route from what the build produced
  Exporter.php        static generation
  Toolbar.php         the strip the server injects
  Trust.php           what may be compiled to PHP, and what may not
bundle/             Vite output (generated)
build/              precompiled PHP (generated)
dist/               static export (generated)
public/             front controller and the stylesheet
```

`app/` is the guest and `src/` is the host — one is JavaScript this runtime
executes, the other is the PHP doing the executing, and they are the two sides of
the demo rather than one codebase.

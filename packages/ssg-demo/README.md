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
src/*.tsx ──vite+babel──► bundle/entry.cjs ──┐
                                             ├──► build/*.php ──► request ──► HTML
node_modules/react ──php-js-transpile────────┘      (opcache)
```

**The sources are TypeScript and JSX**, bundled by Vite into one CommonJS file.
Two things in `vite.config.ts` are not defaults and both matter: esbuild handles
the TSX but cannot target ES5, so Babel downlevels the finished chunk; and JSX
compiles to classic `React.createElement` calls, so the bundle needs nothing
from `react/jsx-runtime`. React itself stays external — the runtime loads
React's own published CommonJS build, which is the thing worth measuring.

**React is compiled to PHP at build time.** `bin/phpjs-ssg build` runs
[php-transpile](../php-transpile) over `node_modules/react`, which turns 262 of
its 291 functions into PHP functions. The bytecode stays as the fallback, so
this is an optimization and not a dependency.

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
syntax support, so the bundle is downlevelled — that part is mechanical. One
thing is not:

**JSX fragments do not work.** React detects a fragment with
`typeof type === 'symbol'`, and ES5 has no symbol primitive; this host polyfills
`Symbol` as a branded string, so `<>…</>` reaches the renderer looking like a tag
name and throws `Invalid tag: @@react.fragment`. Group siblings with a real
element instead. Nothing else in this demo needed working around.

## Numbers from this machine

Not a benchmark — `packages/react-ssr-bench` is the benchmark. These are the
numbers the demo shows, so you know roughly what to expect.

| | bytecode | ahead-of-time PHP |
|---|---|---|
| `/inventory/?items=120` render | ~418 ms | ~168 ms (**−60%**) |
| `/` render | ~12 ms | ~6 ms |
| boot, warm opcache | ~4-10 ms | ~4-10 ms |
| boot without a build | ~400 ms | ~400 ms |

## Layout

```
src/                TypeScript and JSX sources
  entry.tsx           SSR entry for php-js
  entry.node.tsx      same components, rendered by Node, for the diff
  router.tsx          the route table
  components/         Layout, Home, Docs, Inventory, About
  content.ts          the site's text and data
  Builder.php         the build step
  Renderer.php        renders a route from what the build produced
  Exporter.php        static generation
  Toolbar.php         the strip the server injects
bundle/             Vite output (generated)
build/              precompiled PHP (generated)
dist/               static export (generated)
public/             front controller and the stylesheet
```

`src/` holds both the TSX and the PHP on purpose: the demo is the pair, and
splitting them across two trees would only hide that.

# php-js-ssg-demo

A small website, written in TypeScript and JSX, rendered to HTML by React 19
running inside [php-js](../..). Start it locally and click around: the strip
along the top of every page reports what the page cost to produce and lets you
re-render the same URL a different way.

It exists to answer a question benchmarks cannot: **what does this feel like in
a browser.**

## Run it

```console
$ npm install                       # fetches React and sucrase, nothing else
$ composer install
$ bin/phpjs-ssg build               # compile React into build/ -- app/ is untouched
$ bin/phpjs-ssg export              # optional: static HTML into dist/
$ bin/phpjs-ssg serve               # http://127.0.0.1:8080/
```

`build` never reads `app/` at all — it only compiles the library (React), which
is the expensive, React-sized part and the part that does not change while
you're editing the site. `app/` is type-stripped and compiled the first time
anything actually needs it — the first render after a build, whichever request
or command gets there first — and the result is cached to disk (`AppCompiler`),
so every render after that, in this process or the next one, just loads it.
Both steps run entirely inside php-js: no `node`, `vite`, `babel` or `tsc`
process is ever spawned to produce what ships. `npm install` still runs once,
to put React and sucrase's own files on disk where php-js can `require` them.

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
| `build` | Compiles React and the polyfills to PHP arrays in `build/`. Never touches `app/`. |
| `export` | Renders every route into `dist/` — static site generation, all at once. The first route compiles `app/` too (`AppCompiler`), same as any other first render. |
| `package` | Assembles a render-only tree you can ship inside a plugin (see below); also forces `app/` to compile, since a distribution has no request left to defer it to. |
| `serve` | Local server. Renders a page the first time it is asked for, serves the file after — the very first render across all routes is also where `app/` compiles, if `build` alone hasn't already forced it. |
| `cache:clear` | Drops every cached page. |
| `compare` | Renders every route under Node too and requires the HTML to match byte for byte. |
| `bench` | Times one route both ways, interleaved, and prints the ratio. |

## How the pieces fit

```
                    bin/phpjs-ssg build                  the first render after that
                            │                                       │
                            ▼                                       ▼
node_modules ──php-js-transpile──► PHP ────────┐    app/*.tsx ──sucrase (in php-js)──► build/app-cjs/*.js
                            │                   │                                              │
                            └──────► bytecode ──┤                                       bytecode (AppCompiler)
                                                 │                                              │
                                                 └──────────────► request ──► HTML ◄────────────┘
                                                                  (opcache)
```

**Two builds, two different moments.** `bin/phpjs-ssg build` (`Builder`) only
ever compiles the library — React — because that is the expensive,
React-sized part and the part that does not change while you are editing the
site. `app/`, the site's own TypeScript and JSX, is compiled by `AppCompiler`
instead, lazily: the first time any render actually needs it, whichever
request or CLI command gets there first, with the result written to
`build/app-cjs/` and `build/templates.app.php` so every render after that —
in this process or the next one, since PHP is shared-nothing and this is a
plain file on disk, not a cache in memory — just loads it. It is the same
shape `PageCache` already gives the *rendered HTML* one layer up (the first
request renders, the rest serve a file), applied one layer down to
compilation itself, and it borrows that class's own lock-file trick so a
burst of concurrent first requests compiles once rather than once each.

**The sources are TypeScript and JSX** in `app/`. `AppCompiler` runs
[sucrase](https://github.com/alangpierce/sucrase) — itself just JavaScript —
inside php-js to strip types and turn JSX into classic `React.createElement`
calls, one file at a time, into `build/app-cjs/`. No bundler: relative
`import`s become relative `require`s and php-js's own CommonJS resolver walks
the resulting tree, the same way it walks `node_modules`.
Sucrase runs with `disableESTransforms: true` — the engine now parses `??`,
`?.`, destructuring, classes, `async`/`await` and the rest of what DESIGN.md
§2.5 has landed, so there is nothing left to downlevel. React itself stays
external — the runtime loads React's own published CommonJS build, which is
the thing worth measuring.

**Dependencies are compiled to PHP; the site's own code is not.** `Builder`
runs [php-transpile](../php-transpile) over `node_modules`, turning 262 of
React's 291 functions into PHP functions, and stops there — `app/` stays
interpreted, always, everywhere `AppCompiler` compiles it. That split is a
security boundary, not an oversight; `src/Trust.php` is where it is written
down and *Why the site's own code stays interpreted* below is why. The
bytecode remains the fallback everywhere, so none of this is a dependency.

**A request compiles no JavaScript — after the very first one.** PHP is
shared-nothing, so anything neither build has precomputed yet is paid per
request, and parsing React's server build is a few hundred milliseconds
(compiling `app/` itself, on top of that, is a few seconds — see the numbers
below). Both builds write bytecode as plain PHP arrays that opcache keeps in
shared memory, which is what takes boot from ~400 ms down to single-digit
milliseconds once both layers are cached. A test asserts that a request
against an *already-warm* build compiles zero modules, because that
regressing would be invisible except in the timings.

**The toolbar is the host's, not React's.** The layout renders an empty
`<div id="phpjs-metrics">` and the server substitutes into it, because only the
server knows how long the render took. An export leaves it empty, so `dist/` is
deployable as-is.

## Rendering on demand, then serving a file

`serve` does what Next.js calls on-demand ISR, arranged for hosting that has PHP
and nothing else — no daemon, no worker, no shared state beyond opcache:

```console
$ curl -sI localhost:8080/inventory/ | grep X-PhpJs
X-PhpJs-Cache: MISS
X-PhpJs-Render: 213.78ms

$ curl -sI localhost:8080/inventory/ | grep X-PhpJs
X-PhpJs-Cache: HIT
X-PhpJs-Render: 0.04ms
```

Four properties are worth more here than any feature:

- **A hit need not reach PHP at all.** `package` writes an `htaccess.example`
  that hands a cached file straight to Apache, so after the first request the
  runtime is out of the loop entirely. That is also why the cache lives under the
  document root rather than somewhere tidier.
- **What is cached has no toolbar in it.** A page is stored exactly as `export`
  writes it, with the metrics element left empty, and the toolbar is substituted
  in on the way out. So a cached file is deployable as-is — and with the rewrite
  active, a hit shows no toolbar, which is the demonstration.
- **A stampede renders once.** Concurrent misses contend on a lock; the losers
  wait and serve the winner's output. A cold cache plus a burst of traffic is how
  a shared host hits its process limit.
- **A 404 is served but never stored**, and neither is a path with a character
  that has no business in one. Otherwise any request for a nonexistent path
  writes a file, and enough of those is a full disk.

`?engine=` and `?items=` change the bytes, so a request carrying either renders
and is not cached — which keeps the cache keyed by path alone, and that is what
makes the file layout, and therefore the Apache bypass, possible.

## Shipping it: `phpjs-ssg package`

```console
$ bin/phpjs-ssg package
templates      1.9 MB  13 modules, paths relativized
natives        760 KB  262 of 291 functions compiled to PHP
polyfill       157 KB
javascript     287 KB  13 files (of a 33.2 MB node_modules)

18 files, 3.1 MB total
```

`package` forces `app/` to compile if nothing has yet — a distribution has no
request left to defer that to — so `templates` here is React's own templates
and `app/`'s merged into one file, and `javascript` includes both the library
and the site's own type-stripped sources.

That directory is the deployable unit — drop it into a WordPress plugin, a theme,
or a zip:

```php
$renderer = Renderer::fromDistribution(__DIR__ . '/phpjs');
$html = $renderer->render('/docs/')->html;
```

Three things make it that small, all measured rather than assumed:

- **Rendering needs no parser.** With every template precompiled, neither Peast
  (compiles JavaScript) nor nikic/php-parser (emits PHP) is ever loaded — checked
  by deleting both and rendering.
- **Rendering needs almost no JavaScript.** The template keys are the exact list
  of files the program loads: 13 files, 287 KB, against 33 MB of `node_modules`.
- **The second template set is left out.** `templates.bytecode.php` exists only
  so this demo can switch engines.

Paths go in relative and come out absolute, because a build happens at one path
and a plugin installs at another. The native IDs need no such treatment — they
are hashes of module *contents*, so they survive relocation untouched. A test
moves a package to a different directory and requires every route to come out
byte-identical to the build it came from.

The ahead-of-time compilation stays on your machine, which is the right split for
the reason below: what lands on the server is data plus already-generated PHP, and
the server itself compiles nothing.

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

One related choice in the same spirit: `serve` leaves
`opcache.validate_timestamps` **on**, so a rebuild is always picked up and a
cached copy never outlives the file it came from. `build/app-cjs/` and
`build/templates.app.php` *are* written by a request now (`AppCompiler`,
the first time one needs them) — the same trust boundary still holds, because
what a request writes there is always the bytecode form of `app/`'s own
source, type-stripped and interpreted, never anything with a native ID; the
line Trust draws is which JavaScript may become PHP, not which process is
allowed to write a bytecode file.

## Two ways of not being wrong

- `bin/phpjs-ssg compare` renders every route under Node as well and requires
  the two to be **byte-identical**. This found a real bug the first time it ran:
  `(0.625).toFixed(2)` returned `"0.62"` where the spec and every other engine
  say `"0.63"` — PHP's `sprintf('%F')` rounds half to even and JavaScript rounds
  ties away from zero. Fixed in the engine, with tests.
- `DemoSiteTest` requires ahead-of-time PHP and interpreted bytecode to produce
  the same bytes for every route, and the static export to match the live render
  byte for byte.

## What TSX costs you, in practice

DESIGN.md §2.5 tracks how far past ES5.1 the compiler's syntax support goes,
and by the time this demo stopped needing Babel it covered enough of what `app/`
and sucrase's own output use — `let`/`const`, destructuring, arrow functions,
classes, template literals, `for`/`of`, spread, `async`/`await`, optional
chaining, nullish coalescing — that sucrase only had to strip types and JSX,
never downlevel syntax. Nothing in this demo is written around the engine.

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
| boot, warm opcache, `app/` cached | ~4-10 ms | ~4-10 ms |
| boot, warm opcache, `app/` *not yet* cached | ~3.5 s | ~3.5 s |
| boot without a build at all | ~400 ms | ~400 ms |

The middle row is `AppCompiler`'s one-time cost — type-stripping and compiling
`app/` — paid exactly once (per `build/`, across every process, since it is a
disk cache) by whichever request or command renders first. Everything after
that is the top row.

## Layout

```
app/                the site: TypeScript and JSX, type-stripped by sucrase
  entry.tsx           SSR entry for php-js
  entry.node.tsx      same components, rendered by Node, for the diff
  router.tsx          the route table
  components/         Layout, Home, Docs, Inventory, About
  content.ts          the site's text and data
src/                the host: PHP, autoloaded as PhpJs\Ssg\
  Builder.php         the library build step (React only, ahead of time)
  AppCompiler.php     compiles app/ on first render, caches it, locks against a stampede
  Sucrase.php         runs sucrase, inside php-js, over app/ (AppCompiler's own tool)
  Renderer.php        renders a route from what both of the above produced
  Exporter.php        static generation
  Toolbar.php         the strip the server injects
  Trust.php           what may be compiled to PHP, and what may not
build/              library output (Builder) plus app-cjs/ and templates.app.php (AppCompiler) -- all generated
dist/               static export (generated)
public/             front controller and the stylesheet
```

`app/` is the guest and `src/` is the host — one is JavaScript this runtime
executes, the other is the PHP doing the executing, and they are the two sides of
the demo rather than one codebase.

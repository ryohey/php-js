# phext-ssg-demo

A small website — TypeScript, JSX, React 19 — rendered to HTML by
[phext](../phext) running on [php-js](../..). No Node process is involved in
producing any of it.

It exists to answer a question benchmarks cannot: **what does this feel like
in a browser.**

## Run it

```console
$ npm install
$ composer install
$ vendor/bin/phext build      # compile React and the pages, ahead of any request
$ vendor/bin/phext start      # http://127.0.0.1:3000/
```

Or skip the server entirely:

```console
$ vendor/bin/phext export     # every page as static HTML, into out/
```

## What is actually here

```
app/
  layout.tsx              the document: <html>, nav, footer
  page.tsx                /
  about/page.tsx          /about/
  inventory/page.tsx      /inventory/   -- reads ?items=N
  docs/page.tsx           /docs/
  docs/[slug]/page.tsx    /docs/:slug/  -- with generateStaticParams()
  not-found.tsx           anything else
  _components/            not routes: shared components and the site's content
public/
  site.css                served as-is
package.json              react, react-dom, and a "phext" key
composer.json             one dependency: ryohey/phext-cli
```

**That is the whole project.** There is no PHP in it. Earlier versions of this
demo carried about 900 lines of it — a builder, a renderer, an app compiler, a
page cache, a packager — and all of that is now [`phext`](../phext) and
[`phext-cli`](../phext-cli), which is the point of those packages existing. A
site is `app/`, `public/`, and two manifests.

Things worth doing once it is up:

- Open `/inventory/?items=2000`. The render is the page, and you can feel it.
- Then reload it without the query string. A cached page is a file on disk;
  the second request does not start the runtime at all.
- Look at `Server-Timing` in the browser's network panel — the first request
  to a page and every one after it are different by two orders of magnitude.
- Read `app/layout.tsx`. It is the entire document, and it is React output all
  the way out to `<html>` — not a PHP template with React inside it.

## The things this demonstrates

**No build tool.** `.tsx` is stripped to JavaScript inside php-js itself
([`packages/strip-types`](../strip-types)), when the file is first required.
There is no `vite`, `babel`, `tsc` or `esbuild` process anywhere, and nothing
watches your files. `npm install` runs once, to put React on disk where php-js
can `require` it.

**No `import React`.** JSX compiles through the automatic runtime, as it does
in any current toolchain.

**Dependencies are compiled to PHP; your pages are not.** `phext build` turns
262 of React's 291 functions into native PHP, and leaves `app/` as interpreted
bytecode. That is a trust boundary rather than an optimization — generated PHP
leaves the VM's wall-clock limit and recursion guard behind
([docs/aot-php.md](../../docs/aot-php.md)), which is a fine trade for a
lockfile-pinned dependency and a bad one for code you are editing.

**A request compiles no JavaScript.** After `phext build`, everything the
runtime needs is a `<?php return [...];` file that opcache holds in shared
memory. Without it, every request would re-parse React — a few hundred
milliseconds, forever, because PHP is shared-nothing.

**Incremental static regeneration with no daemon.** Set `"ttl"` in
package.json's `phext` key and a page older than that is re-rendered by the
next request for it. Nothing runs in the background, because there is nothing
for it to run in.

## What it is not

There is no client-side JavaScript on any page, and no hydration — this is a
server renderer. The engine-comparison toolbar earlier versions of this demo
carried is gone with the rest of its bespoke PHP; for measurements,
[`packages/react-ssr-bench`](../react-ssr-bench) is the benchmark and
[docs/aot-php.md](../../docs/aot-php.md) has the numbers.

# phext

File-based routing, incremental static regeneration and server-side React
rendering — on [php-js](../..), so the server is PHP and there is no Node
process anywhere.

If you have written a Next.js App Router app, you already know the shape:

```
app/
  layout.tsx           encloses every route below it
  page.tsx             /
  about/page.tsx       /about/
  docs/
    layout.tsx         encloses /docs/ and everything under it
    page.tsx           /docs/
    [slug]/page.tsx    /docs/:slug/
  not-found.tsx        anything that matches nothing
public/                served as-is
```

```tsx
// app/docs/[slug]/page.tsx
export const metadata = { title: 'A document' };

export function generateStaticParams() {
  return [{ slug: 'intro' }, { slug: 'advanced' }];
}

export default function Doc({ params }: { params: { slug: string } }) {
  return <article>{params.slug}</article>;
}
```

No `import React` — JSX compiles through the automatic runtime. No build tool
in front of any of it: `.tsx` is stripped to JavaScript inside php-js itself
([`packages/strip-types`](../strip-types)) at the moment the file is first
required.

Most projects should use [`phext-cli`](../phext-cli) (`phext build`, `phext
start`) and never name this package directly, the same way a Next.js app uses
`next` rather than its internals. Reach for `App` when you are embedding a
site in something that already has a request lifecycle — a WordPress plugin, an
existing framework's controller:

```php
use PhpJs\Phext\App;

$app = new App(__DIR__, cacheDir: __DIR__ . '/public/cache', ttl: 3600);
$page = $app->render('/docs/intro/');

http_response_code($page->status);
echo $page->html;
```

## What it supports, and what it does not

| | |
|---|---|
| `page.tsx` / `layout.tsx` / `not-found.tsx` | yes, nested arbitrarily |
| Dynamic segments — `[slug]`, several per route | yes |
| `generateStaticParams()` | yes, drives what a build renders |
| `export const metadata = { title, description }` | yes, page overrides layout |
| `_private/` directories inside `app/` | yes, never routed |
| `public/` served as-is | yes |
| Route groups `(name)`, parallel `@slot`, intercepting routes | **no** |
| `loading.tsx` / `error.tsx`, streaming, Suspense boundaries | **no** |
| Client components, hydration, Server Actions | **no** |
| Middleware, route handlers (`route.ts`) | **no** |

The line is not arbitrary: everything in the second half needs either a client
runtime or a streaming renderer, and this renders a document, once, on the
server, synchronously. A feature arrives with its semantics intact or is
refused by name — a half-working `loading.tsx` that never shows a loading state
would be worse than not having one.

## Incremental static regeneration, without a daemon

`ttl` seconds after a page is cached, the next request for it re-renders and
re-caches. That request waits.

There is deliberately **no stale-while-revalidate**. Serving the old page while
something else refreshes it needs a background worker, and PHP is
shared-nothing — there is nothing to be in the background *of*. What that
costs is one slow request per TTL per page. What it buys is a cache with no
moving parts, which is the only kind that works on hosting where you cannot
run a daemon.

Three properties matter more than features, and all three are tested:

- **A hit need not reach PHP at all.** The cache is written under the document
  root in the same layout a static export uses, so an Apache rewrite
  (`PageCache::htaccess()`) can hand the file over without starting the
  runtime.
- **A 404 is served but never stored.** Otherwise any request for a
  nonexistent path writes a file, and enough of those is a full disk.
- **A stampede renders once.** Concurrent misses contend on a lock; the losers
  wait and serve the winner's output.

## How a page becomes HTML

`Renderer` requires the page and its layouts through php-js's own module
loader, composes `createElement(Layout, { children: createElement(Page, props) })`
in PHP, and calls React's synchronous server renderer.

There is no JavaScript entry point in this package — no `phext/server.js` that
your app has to be able to resolve. The host is already inside the JS runtime,
so it builds the tree itself. React stays your dependency, resolved from your
`node_modules`, exactly as `next` does not vendor React.

`renderToString` by default; set `"render": "renderToStaticMarkup"` in
package.json for a page nothing will hydrate.

## Requirements

React 19 (18 should work; only 19 is tested), and `app/` with at least a
`layout.tsx` that renders `<html>` and a `page.tsx`.

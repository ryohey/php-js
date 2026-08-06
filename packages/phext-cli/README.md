# phext-cli

The `phext` command: build, serve and export a [phext](../phext) site.

This is the only package a site depends on — `next` rather than Next.js's
internals. Nothing in an app should ever name `phext` itself.

```console
$ npm install                  # react, react-dom
$ composer require ryohey/phext-cli
$ phext build                  # compile everything ahead of any request
$ phext start                  # http://127.0.0.1:3000/
```

## Commands

| | |
|---|---|
| `phext build` | Compiles the engine's standard library, your pinned dependencies (to native PHP), and every page (to bytecode) into `node_modules/.phpjs-aot/`. Also renders every page once, so a build that succeeds is a build whose pages are known to work. |
| `phext start` | Serves the app, with opcache and PHP's tracing JIT on — neither is the default and both matter. |
| `phext export` | Renders every path to static HTML, plus `public/`. |
| `phext routes` | Prints the route table the `app/` directory defines. |
| `phext cache:clear` | Drops every cached page. |

Options: `--dir` (project root, default `.`), `--out`, `--host`, `--port`,
`--no-cache`, `--no-jit`.

## Why `build` exists

PHP is shared-nothing: whatever is not compiled before a request is compiled
*during* it and then thrown away. Parsing React's server build alone is a few
hundred milliseconds — per request, forever. `phext build` writes all of it to
disk in a form opcache holds in shared memory, and `phext start` works without
it only in the sense that a dev server works without it.

Two different things get compiled, and the difference is a trust boundary
rather than an optimization:

- **Dependencies** become native PHP as well as bytecode
  ([`packages/aot`](../aot)). Generated PHP leaves the VM behind, and with it
  the wall-clock limit and the recursion guard ([docs/aot-php.md](../../docs/aot-php.md))
  — a fine trade for a version a lockfile pins and that you upgrade
  deliberately.
- **Your pages** become bytecode only. Bytecode is data: loading it defines
  nothing and calls nothing, and the VM interprets it with every guard in
  place. Code you are editing is exactly the code that needs those guards.

That this package depends on the ahead-of-time compiler and `phext` does not
is the same boundary once more: a deployment that only renders needs the
runtime, not the compiler that produced its cache.

## Configuration

`"phext"` in your package.json, all of it optional:

```json
{
  "phext": {
    "cacheDir": "public/cache",
    "ttl": 3600,
    "render": "renderToString",
    "aot": ["some-other-pinned-dependency"]
  }
}
```

One file rather than a second config format, and not a `phext.config.js`,
because that would have to be *evaluated* to be read — which means booting the
JS runtime before knowing how to configure it.

| | |
|---|---|
| `cacheDir` | Where rendered pages are cached. Under the document root on purpose: it is what lets a web server serve a hit without starting PHP. |
| `ttl` | Seconds before a page is re-rendered. Omit it for a site whose content changes only when you rebuild. |
| `render` | `renderToString` (default) or `renderToStaticMarkup`. |
| `aot` | Extra `node_modules` specifiers to compile to PHP, resolved as `require()` would. |

## Deploying

`phext start` is PHP's built-in server, which is for development. In
production, point a real server at `src/front-controller.php` — it is the same
code path `start` serves, deliberately, so that what you test is what runs.
`phext export` is the other option, and needs no PHP at the far end at all.

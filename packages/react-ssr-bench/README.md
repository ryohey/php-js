# React SSR benchmark

Renders a React app server-side on the [php-js](../..) runtime, using React's
own published CommonJS build loaded through
[node-compat](../node-compat) — no bundler, no pre-baked snapshot.

The point is twofold: prove the runtime can carry a real dependency graph, and
give the performance question a number instead of an opinion.

## Running it

```console
$ npm install                    # fetches react + react-dom into node_modules
$ composer install
$ php bin/react-ssr-bench --items 20 --iterations 25 --compare-node
boot: 172.5 ms (7 modules, 155.9 ms compiling), react 17.0.2
compare: byte-identical to Node (5492 bytes)
render: 88.19 ms each (11.3/s over 25 iterations), 5492 bytes of HTML

$ php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
      -d opcache.jit=tracing bin/react-ssr-bench --items 20 --iterations 25
render: 70.84 ms each (14.1/s over 25 iterations), 5492 bytes of HTML
```

| Flag | Meaning |
|---|---|
| `--items N` | rows in the rendered table (default 20) |
| `--iterations N` | timed renders after one warm-up (default 20) |
| `--method NAME` | `renderToStaticMarkup` (default) or `renderToString` |
| `--print` | write the HTML to stdout |
| `--compare-node` | render the same app under Node and diff the output |

`--compare-node` is the correctness check that matters: the assertion is
byte-identical output, not merely "looks like HTML". `tests/ReactSsrTest.php`
runs the same comparison under PHPUnit.

## Where it stands

`renderToStaticMarkup`, PHP 8.4.19 vs Node 22.22.2, all measured in one
sitting so the columns are comparable:

| Rows | php-js | php-js + JIT | Node 22 | ratio (JIT) |
|---|---|---|---|---|
| 20 | 88.2 ms | 70.8 ms | 1.34 ms | 53x |
| 100 | 442 ms | 368 ms | 5.14 ms | 72x |

Output is byte-identical in every case. Boot is a further ~170 ms, of which
~156 ms is compiling React — a cost the bytecode-file path removes entirely.

Absolute numbers move by 20% or more between machines, so treat the ratio as
the number and re-measure the rest locally. Comparing two versions of the
runtime needs interleaved paired runs; a batch of "before" followed by a batch
of "after" will happily report a 5% change that is entirely drift.

**Turn the JIT on.** `-d opcache.jit=tracing -d opcache.jit_buffer_size=64M`
is worth ~20% here and costs nothing but configuration. It is off by default
in PHP, which makes it the cheapest thing on this list.

### Where the time goes

Not where it looked. One render executes ~188k bytecode instructions and makes
only ~4000 native calls, so it is interpretation rather than the builtins — but
it is not raw dispatch either. Fusing the four hottest opcode pairs
(DESIGN.md §2.4) removed 13% of the instructions and bought about 4% of the
time, because the instructions it removes are the cheap ones. A per-opcode
profile puts a third of the render in `CALL`, `GET_METHOD` and property
lookups, each around ten times the cost of a cheap opcode. `CALL` is the
largest single item at 15% of the time for 3.9% of the instructions.

The corollary is that plausible micro-optimizations lose about as often as they
win: replacing the current frame's PHP reference with indexed writes measured
slower, and so did fusing `GET_LOCAL`+`CALL`. Measure one at a time.

## Reading the numbers

`boot` and `render` are reported separately because they behave differently
under the runtime's deployment model. Boot is dominated by *compiling* React,
and that cost is exactly what the bytecode-file/opcache path in DESIGN.md §11
exists to remove: precompile with `phpjs compile` and a warm process pays only
for evaluating the modules. `render` is the number that has to stand on its own.

The app (`js/app.js`) is written in ES5 with `React.createElement` directly, so
there is no JSX build step between the source and what the engine runs.

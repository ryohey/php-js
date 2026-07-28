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
$ php bin/react-ssr-bench --items 20 --iterations 50 --compare-node
boot: 127.2 ms (7 modules, 114.1 ms compiling), react 17.0.2
compare: byte-identical to Node (5492 bytes)
render: 80.11 ms each (12.5/s over 10 iterations), 5492 bytes of HTML
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

Measured on this machine (PHP 8.4, no opcache preload), `renderToStaticMarkup`:

| Rows | php-js | Node 22 | ratio |
|---|---|---|---|
| 20 | 76.6 ms | 1.36 ms | 56x |
| 100 | 391 ms | 5.25 ms | 75x |

Output is byte-identical in both cases. Boot is a further ~127 ms, of which
~114 ms is compiling React — a cost the bytecode-file path removes entirely.

The gap is dispatch, not the builtins: one render makes about 4000 native calls
in total, so essentially all of the time is the `while/switch` loop executing
bytecode at roughly 3M instructions per second. That is the risk DESIGN.md §15
named, now with a number attached. Closing it means fewer instructions per
operation (superinstructions, fused compare-and-branch) rather than a faster
instruction.

## Reading the numbers

`boot` and `render` are reported separately because they behave differently
under the runtime's deployment model. Boot is dominated by *compiling* React,
and that cost is exactly what the bytecode-file/opcache path in DESIGN.md §11
exists to remove: precompile with `phpjs compile` and a warm process pays only
for evaluating the modules. `render` is the number that has to stand on its own.

The app (`js/app.js`) is written in ES5 with `React.createElement` directly, so
there is no JSX build step between the source and what the engine runs.

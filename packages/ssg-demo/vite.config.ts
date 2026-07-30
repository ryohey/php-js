import { getBabelOutputPlugin } from '@rollup/plugin-babel';
import { defineConfig } from 'vite';

/**
 * Bundles the TSX sources into one CommonJS file that the php-js runtime can
 * `require`.
 *
 * Two things here are not the Vite default and both are load-bearing:
 *
 * - **ES5 output.** php-js implements ES5.1 (plus Promise) and its compiler
 *   will not grow ES6+ *syntax* support. esbuild cannot target ES5, so Babel
 *   downlevels the finished chunk. That is the same advice the top-level README
 *   gives anyone running their own code on this engine.
 * - **Classic JSX.** `React.createElement` calls rather than the automatic
 *   runtime, so the bundle needs nothing from `react/jsx-runtime`.
 *
 * React itself stays external: the runtime requires React's own published
 * CommonJS build out of node_modules, which is the thing worth measuring.
 */
export default defineConfig({
  esbuild: {
    jsx: 'transform',
    jsxFactory: 'React.createElement',
    jsxFragment: 'React.Fragment',
  },
  build: {
    ssr: true,
    outDir: 'bundle',
    emptyOutDir: true,
    minify: false,
    // esbuild's floor is ES2015; Babel takes it the rest of the way.
    target: 'es2015',
    rollupOptions: {
      input: {
        // Two entries so the same components can be rendered by php-js and by
        // Node, for the byte-identity check.
        entry: 'app/entry.tsx',
        'entry.node': 'app/entry.node.tsx',
      },
      external: [
        'react',
        'react-dom/server',
        'react-dom/cjs/react-dom-server-legacy.node.production.js',
      ],
      output: {
        format: 'cjs',
        // `.cjs`, not `.js`: this package is `type: "module"`, so Node would
        // read a `.js` file as ESM and the bundle's own `require` calls would
        // fail. php-js resolves an explicit extension either way.
        entryFileNames: '[name].cjs',
        chunkFileNames: 'shared/[name].cjs',
        exports: 'named',
        // An *output* plugin, so Babel sees each finished chunk rather than
        // each module: by then the code is plain ES2015 JavaScript and the
        // only job left is to downlevel it.
        plugins: [
          getBabelOutputPlugin({
            allowAllFormats: true,
            // `modules: false` because the chunk is already CommonJS; there is
            // no import syntax left for Babel to rewrite.
            presets: [
              ['@babel/preset-env', { targets: { ie: '11' }, loose: true, modules: false }],
            ],
          }),
        ],
      },
    },
  },
});

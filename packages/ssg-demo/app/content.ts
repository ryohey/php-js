/** The demo site's content, as plain data. */

export interface NavItem {
  href: string;
  label: string;
}

export interface Feature {
  title: string;
  body: string;
}

export interface DocSection {
  heading: string;
  paragraphs: string[];
  code?: string;
}

export interface Doc {
  slug: string;
  title: string;
  summary: string;
  sections: DocSection[];
}

export interface InventoryItem {
  index: number;
  id: number;
  name: string;
  category: string;
  tags: string[];
  tone: 'ok' | 'warn' | 'muted';
  coverage: number;
  price: number;
}

export const site = {
  name: 'php-js',
  tagline: 'A JavaScript runtime written in PHP',
  year: 2026,
};

export const nav: NavItem[] = [
  { href: '/', label: 'Home' },
  { href: '/docs/', label: 'Docs' },
  { href: '/inventory/', label: 'Inventory' },
  { href: '/about/', label: 'About' },
];

export const features: Feature[] = [
  {
    title: 'One interpretation layer',
    body:
      'JavaScript compiles to bytecode and a PHP VM executes it. No WASM, no ' +
      'extensions, nothing to install beyond PHP itself.',
  },
  {
    title: 'Unboxed values',
    body:
      'A JS number is a PHP int or float, a JS string is a PHP string. Only ' +
      'undefined needs a sentinel, so crossing between JS and PHP costs a ' +
      'function call and no marshaling.',
  },
  {
    title: 'Built for opcache',
    body:
      'Bytecode is emitted as plain PHP arrays, so opcache keeps a compiled ' +
      'program in shared memory across requests. Nothing on the JS heap is a ' +
      'PHP closure or a resource.',
  },
  {
    title: 'Compiled ahead of time',
    body:
      'A build step translates most of React itself into PHP. The bytecode ' +
      'stays as the fallback, so the generated code is optional at run time.',
  },
];

export const docs: Doc[] = [
  {
    slug: 'getting-started',
    title: 'Getting started',
    summary: 'Install, render a page, and see where the time goes.',
    sections: [
      {
        heading: 'Install',
        paragraphs: [
          'The runtime is a Composer package with one dependency, a JavaScript ' +
            'parser. There is no compiled extension and no external service.',
        ],
        code: [
          '$ composer require ryohey/php-js',
          "$ bin/phpjs eval '[1,2,3].map(function (x) { return x * x; }).join(\",\")'",
          "'1,4,9'",
        ].join('\n'),
      },
      {
        heading: 'Render something',
        paragraphs: [
          'A host package adds CommonJS module loading, a read-only filesystem ' +
            'confined to a root, a process object and virtual-clock timers. That ' +
            "is enough to require React's published server build and call it.",
          'The page you are reading was produced that way: React ran inside PHP, ' +
            'and the HTML it returned was written to a file or served straight to ' +
            'your browser.',
        ],
        code: [
          "$host = new NodeHost(__DIR__);",
          "$app = $host->requireModule('./bundle/entry.js');",
          "$page = $host->call($app->get('renderPage', $vm), null, ['/docs/', '']);",
        ].join('\n'),
      },
      {
        heading: 'Where the time goes',
        paragraphs: [
          'Boot and render behave differently and are reported separately. Boot ' +
            'is dominated by compiling JavaScript, which a build step removes ' +
            'entirely by writing the bytecode to a file that opcache holds. ' +
            'Render is the number that has to stand on its own.',
        ],
      },
    ],
  },
  {
    slug: 'ahead-of-time-php',
    title: 'Ahead-of-time PHP',
    summary: 'Why a pinned library should be compiled, not interpreted.',
    sections: [
      {
        heading: 'The premise',
        paragraphs: [
          'A dependency that is pinned at a version and never edited can be ' +
            'optimized as hard as you like at build time. React is exactly that: ' +
            'it changes on a release cadence measured in months, and a site ' +
            'rebuilds far more often than it upgrades.',
          'So a build step compiles each JavaScript function it can into a PHP ' +
            'function, and the runtime calls that instead of interpreting ' +
            'bytecode. A function it cannot compile keeps running as bytecode, ' +
            'which makes the whole thing optional rather than load-bearing.',
        ],
      },
      {
        heading: 'What it is worth',
        paragraphs: [
          "On this site's inventory page the ahead-of-time build takes about two " +
            'thirds off the render. The switch in the toolbar above runs the same ' +
            'page both ways, so you can measure it rather than take that number ' +
            'on trust.',
        ],
      },
      {
        heading: 'What it refuses',
        paragraphs: [
          'The compiler refuses any function it cannot prove it can translate ' +
            'faithfully: one whose locals are captured by a nested closure, one ' +
            'containing a nested function expression, one using a regular ' +
            'expression literal. Refusals are a normal outcome and cost only the ' +
            "interpreter's speed.",
        ],
      },
    ],
  },
  {
    slug: 'static-generation',
    title: 'Static generation',
    summary: 'Rendering every route to a file, and serving it.',
    sections: [
      {
        heading: 'Two ways to serve the same markup',
        paragraphs: [
          'This demo can render a route on every request, or render every route ' +
            'once into a directory of HTML files and let the web server return ' +
            'those. The markup is identical either way, which is the point: ' +
            'static generation is a caching decision, not a different renderer.',
          'Rendering per request is what the toolbar timings above measure. The ' +
            'exported files are what a real deployment would put behind a CDN.',
        ],
        code: ['$ bin/phpjs-ssg export', '$ bin/phpjs-ssg serve'].join('\n'),
      },
      {
        heading: 'Shared nothing',
        paragraphs: [
          'PHP throws away all memory at the end of a request, so a server that ' +
            'boots this runtime per request pays for it per request. The build ' +
            'writes the compiled bytecode as a plain PHP array file; opcache ' +
            'keeps it in shared memory, and boot becomes a couple of ' +
            'milliseconds of instantiating objects rather than a few hundred of ' +
            'parsing JavaScript.',
        ],
      },
    ],
  },
];

export const about = {
  paragraphs: [
    'php-js is an experiment in how far a language runtime can be taken inside ' +
      "another language's runtime, with no native code on either side.",
    'The engine implements an ES5.1 subset plus Promise, compiled to bytecode ' +
      'and run by a single dispatch loop. Progress is tracked as a pass rate ' +
      'over the relevant part of test262, and the React output on this site is ' +
      'checked byte for byte against the same render under Node.',
    'This site is the demo: every page you can reach from the navigation was ' +
      'rendered by React running on PHP.',
  ],
  facts: [
    { label: 'Target language', value: 'ES5.1 plus Promise' },
    { label: 'Demo sources', value: 'TypeScript and JSX, bundled to ES5 by Vite' },
    { label: 'Execution', value: 'Stack bytecode, single dispatch loop' },
    { label: 'Host requirement', value: 'PHP 8.2 or newer, no extensions' },
  ],
};

/** The inventory table's rows. Deliberately the heaviest page, and scalable. */
export function buildInventory(count: number): InventoryItem[] {
  const tones: InventoryItem['tone'][] = ['ok', 'warn', 'muted'];
  const categories = ['Compiler', 'Runtime', 'Builtins', 'Host', 'Tooling'];
  const rows: InventoryItem[] = [];
  for (let i = 0; i < count; i++) {
    rows.push({
      index: i,
      id: 4200 + i,
      name: `Component ${i + 1}`,
      category: categories[i % categories.length],
      tags: ['alpha', 'beta', 'gamma'].slice(0, (i % 3) + 1),
      tone: tones[i % tones.length],
      coverage: (i * 7) % 101,
      price: ((i % 97) + 0.5) * 1.25,
    });
  }
  return rows;
}

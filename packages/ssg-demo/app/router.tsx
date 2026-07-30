import React from 'react';

import { About, NotFound } from './components/About';
import { DocPage, DocsIndex } from './components/Docs';
import { Home } from './components/Home';
import { Inventory } from './components/Inventory';
import { Layout, METRICS_ID } from './components/Layout';
import { docs, site } from './content';

/**
 * The route table. Deliberately free of any react-dom import: the two server
 * entries beside it reach the renderer by different specifiers, because php-js
 * and Node can each load only one of them (see entry.tsx).
 */

export interface RenderOptions {
  /** Rows on the inventory page. */
  items?: number;
}

export interface ResolvedPage {
  status: number;
  title: string;
  element: React.ReactElement;
}

export const metricsId = METRICS_ID;

/** Reported by the host, so the demo can show which React it actually ran. */
export const reactVersion: string = React.version;

/**
 * Rows on the inventory page when the URL does not say.
 *
 * Chosen to be felt rather than to look good: a render you can notice, but not
 * one you wait for. `?items=2000` is where it stops being subtle.
 */
const DEFAULT_ITEMS = 120;

/** Every path the static export writes, in the order it writes them. */
export function routePaths(): string[] {
  return ['/', '/docs/', '/inventory/', '/about/', ...docs.map((doc) => `/docs/${doc.slug}/`)];
}

function normalize(path: string): string {
  let p = path;
  const query = p.indexOf('?');
  if (query >= 0) {
    p = p.slice(0, query);
  }
  if (p === '' || p.charAt(0) !== '/') {
    p = `/${p}`;
  }
  // "/docs" and "/docs/" are the same page; the root stays "/".
  if (p.length > 1 && p.charAt(p.length - 1) !== '/') {
    p = `${p}/`;
  }
  return p;
}

interface Body {
  title: string;
  description?: string;
  status?: number;
  children: React.ReactNode;
}

function body(path: string, options: RenderOptions): Body {
  if (path === '/') {
    return { title: 'Home', description: site.tagline, children: <Home /> };
  }
  if (path === '/docs/') {
    return { title: 'Documentation', children: <DocsIndex /> };
  }
  if (path === '/about/') {
    return { title: 'About', children: <About /> };
  }
  if (path === '/inventory/') {
    const items = options.items && options.items > 0 ? options.items : DEFAULT_ITEMS;
    return {
      title: 'Inventory',
      description: 'A heavy React page rendered server-side by php-js.',
      children: <Inventory items={items} />,
    };
  }
  const prefix = '/docs/';
  if (path.slice(0, prefix.length) === prefix) {
    const slug = path.slice(prefix.length, path.length - 1);
    const doc = docs.filter((candidate) => candidate.slug === slug)[0];
    if (doc) {
      return { title: doc.title, description: doc.summary, children: <DocPage doc={doc} /> };
    }
  }
  return { title: 'Not found', status: 404, children: <NotFound path={path} /> };
}

export function resolve(path: string, options: RenderOptions = {}): ResolvedPage {
  const normalized = normalize(path);
  const page = body(normalized, options);
  return {
    status: page.status ?? 200,
    title: page.title,
    element: (
      <Layout title={page.title} description={page.description} path={normalized}>
        {page.children}
      </Layout>
    ),
  };
}

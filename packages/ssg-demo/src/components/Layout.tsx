import React from 'react';

import { nav, site } from '../content';

/**
 * The id of the element the host fills in with its timings.
 *
 * Rendered empty on purpose: only the host knows how long the render took, so
 * it substitutes its own markup into this element afterwards. A deployment that
 * does not want a toolbar just leaves the empty element alone.
 */
export const METRICS_ID = 'phpjs-metrics';

function Nav({ path }: { path: string }) {
  return (
    <nav className="nav">
      {nav.map((item) => {
        const current = item.href === path;
        return (
          <a
            key={item.href}
            href={item.href}
            className={current ? 'nav-link current' : 'nav-link'}
            aria-current={current ? 'page' : undefined}
          >
            {item.label}
          </a>
        );
      })}
    </nav>
  );
}

export interface LayoutProps {
  title: string;
  description?: string;
  path: string;
  children: React.ReactNode;
}

/**
 * The whole document, React's output all the way out to `<html>` — the browser
 * receives markup React produced, not a PHP template wrapped around it.
 */
export function Layout({ title, description, path, children }: LayoutProps) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>{`${title} — ${site.name}`}</title>
        <meta name="description" content={description ?? site.tagline} />
        <link rel="stylesheet" href="/assets/site.css" />
        {/* Inline, so a demo run produces no 404 for a file nobody shipped. */}
        <link
          rel="icon"
          href={
            'data:image/svg+xml,' +
            encodeURIComponent(
              '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">' +
                '<rect width="16" height="16" rx="4" fill="#1f6feb"/>' +
                '<text x="8" y="12" font-family="monospace" font-size="11" ' +
                'font-weight="bold" fill="#fff" text-anchor="middle">js</text></svg>'
            )
          }
        />
      </head>
      <body>
        <div id={METRICS_ID} />
        <header className="masthead">
          <a className="brand" href="/">
            {site.name}
          </a>
          <p className="tagline">{site.tagline}</p>
          <Nav path={path} />
        </header>
        <main className="main">{children}</main>
        <footer className="footer">
          <p>
            © {site.year} {site.name} · every page here was rendered by React
            running on PHP
          </p>
        </footer>
      </body>
    </html>
  );
}

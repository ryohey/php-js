import { nav, site } from './_components/content';

export const metadata = {
  title: site.name,
  description: site.tagline,
};

function Nav({ pathname }: { pathname: string }) {
  return (
    <nav className="nav">
      {nav.map((item) => {
        const current = item.href === pathname;
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

/**
 * The whole document, React's output all the way out to `<html>` — the browser
 * receives markup React produced, not a PHP template wrapped around it.
 *
 * `pathname` is a phext addition: Next.js does not hand a server layout the
 * current path, which is why highlighting the active link there normally needs
 * a client component. This site has no client JavaScript at all.
 */
export default function RootLayout({
  children,
  pathname,
}: {
  children: React.ReactNode;
  pathname: string;
}) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <link rel="stylesheet" href="/site.css" />
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
        <header className="masthead">
          <a className="brand" href="/">
            {site.name}
          </a>
          <p className="tagline">{site.tagline}</p>
          <Nav pathname={pathname} />
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

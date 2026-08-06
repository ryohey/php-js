export const metadata = { title: 'Fixture site', description: 'default description' };

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
      </head>
      <body>
        <nav>site nav</nav>
        {children}
      </body>
    </html>
  );
}

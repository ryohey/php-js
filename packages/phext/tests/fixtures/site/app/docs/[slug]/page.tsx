const DOCS: Record<string, string> = { intro: 'Introduction', deep: 'Going deeper' };

export function generateStaticParams() {
  return Object.keys(DOCS).map((slug) => ({ slug }));
}

export const metadata = { title: 'A document' };

export default function Doc({ params }: { params: { slug: string } }) {
  return <article>{DOCS[params.slug] ?? 'Unknown'}</article>;
}

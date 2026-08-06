import { docs } from '../../_components/content';

/**
 * Which documents exist, for the build. A dynamic route names a *shape*, so
 * something has to say which pages that shape actually has — this is that,
 * and it is the same `generateStaticParams` Next.js uses.
 */
export function generateStaticParams() {
  return docs.map((doc) => ({ slug: doc.slug }));
}

export const metadata = { title: 'Documentation' };

export default function DocPage({ params }: { params: { slug: string } }) {
  const doc = docs.filter((candidate) => candidate.slug === params.slug)[0];
  if (!doc) {
    return (
      <section className="prose">
        <h1>Not found</h1>
        <p className="lede">There is no document called “{params.slug}”.</p>
        <p>
          <a href="/docs/">Back to the documentation</a>
        </p>
      </section>
    );
  }

  return (
    <article className="prose">
      <p className="breadcrumb">
        <a href="/docs/">Docs</a>
        {` / ${doc.title}`}
      </p>
      <h1>{doc.title}</h1>
      <p className="lede">{doc.summary}</p>
      {doc.sections.map((section) => (
        <section className="doc-section" key={section.heading}>
          <h2>{section.heading}</h2>
          {section.paragraphs.map((text, i) => (
            <p key={i}>{text}</p>
          ))}
          {section.code ? (
            <pre>
              <code>{section.code}</code>
            </pre>
          ) : null}
        </section>
      ))}
    </article>
  );
}

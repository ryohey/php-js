import { docs } from '../_components/content';

export const metadata = { title: 'Documentation' };

export default function DocsIndex() {
  return (
    <section className="prose">
      <h1>Documentation</h1>
      <p className="lede">Three short pages on what this is and how it is built.</p>
      <ul className="doc-list">
        {docs.map((doc) => (
          <li key={doc.slug}>
            <a href={`/docs/${doc.slug}/`}>{doc.title}</a>
            <span className="doc-summary">{doc.summary}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

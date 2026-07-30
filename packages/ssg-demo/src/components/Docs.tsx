import React from 'react';

import { docs, type Doc } from '../content';

export function DocsIndex() {
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

export function DocPage({ doc }: { doc: Doc }) {
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

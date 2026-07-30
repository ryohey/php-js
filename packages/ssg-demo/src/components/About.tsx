import React from 'react';

import { about } from '../content';

export function About() {
  return (
    <section className="prose">
      <h1>About</h1>
      {about.paragraphs.map((text, i) => (
        <p key={i} className={i === 0 ? 'lede' : undefined}>
          {text}
        </p>
      ))}
      <dl className="facts">
        {about.facts.map((fact) => (
          <div className="fact" key={fact.label}>
            <dt>{fact.label}</dt>
            <dd>{fact.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export function NotFound({ path }: { path: string }) {
  return (
    <section className="prose">
      <h1>Not found</h1>
      <p className="lede">There is no page at {path}.</p>
      <p>
        <a href="/">Back to the home page</a>
      </p>
    </section>
  );
}

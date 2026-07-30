import React from 'react';

import { features } from '../content';

export function Home() {
  return (
    <>
      <section className="hero">
        <h1>JavaScript, executed by PHP</h1>
        <p className="lede">
          An ES5.1 engine written in pure PHP: a compiler, a bytecode VM and the
          standard library, with no extensions and no WASM. This site is React 19
          rendering server-side inside it.
        </p>
        <p className="cta-row">
          <a className="cta" href="/docs/getting-started/">
            Read the docs
          </a>
          <a className="cta secondary" href="/inventory/">
            See a heavy page
          </a>
        </p>
      </section>
      <section className="features">
        {features.map((feature) => (
          <article className="card" key={feature.title}>
            <h2>{feature.title}</h2>
            <p>{feature.body}</p>
          </article>
        ))}
      </section>
    </>
  );
}

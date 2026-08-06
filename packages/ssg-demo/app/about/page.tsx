import { about } from '../_components/content';

export const metadata = { title: 'About' };

export default function About() {
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

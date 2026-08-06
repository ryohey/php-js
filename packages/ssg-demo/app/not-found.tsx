

export const metadata = { title: 'Not found' };

export default function NotFound() {
  return (
    <section className="prose">
      <h1>Not found</h1>
      <p className="lede">There is no page at that address.</p>
      <p>
        <a href="/">Back to the home page</a>
      </p>
    </section>
  );
}

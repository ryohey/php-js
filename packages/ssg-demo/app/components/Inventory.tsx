import React from 'react';

import { buildInventory, type InventoryItem } from '../content';

function Badge({ tone, children }: { tone: string; children: React.ReactNode }) {
  return <span className={`badge badge-${tone}`}>{children}</span>;
}

function Meter({ value }: { value: number }) {
  return (
    <span className="meter" title={`${value}%`}>
      <span className="meter-fill" style={{ width: `${value}%` }} />
      <span className="meter-label">{value}%</span>
    </span>
  );
}

function Row({ item }: { item: InventoryItem }) {
  return (
    <tr className={item.index % 2 === 0 ? 'row even' : 'row odd'}>
      <td className="cell id">{String(item.id)}</td>
      <td className="cell name">
        <a href={`/inventory/#${item.id}`}>{item.name}</a>
      </td>
      <td className="cell category">{item.category}</td>
      <td className="cell tags">
        {item.tags.map((tag, i) => (
          <Badge key={tag} tone={i % 2 ? 'warn' : item.tone}>
            {tag}
          </Badge>
        ))}
      </td>
      <td className="cell coverage">
        <Meter value={item.coverage} />
      </td>
      <td className="cell price">{`$${item.price.toFixed(2)}`}</td>
    </tr>
  );
}

/**
 * The heavy page: every row is a component tree of its own, so the render cost
 * scales with `items` and the toolbar timings move visibly with it.
 */
export function Inventory({ items }: { items: number }) {
  const rows = buildInventory(items);
  return (
    <section className="prose wide">
      <h1>Inventory</h1>
      <p className="lede">
        A deliberately heavy page: {rows.length} rows, each one a component tree
        of its own. Append <code>?items=N</code> to the URL to change the size and
        watch the render time in the toolbar move with it.
      </p>
      <table className="inventory">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Category</th>
            <th>Tags</th>
            <th>Coverage</th>
            <th>Price</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((item) => (
            <Row key={item.id} item={item} />
          ))}
        </tbody>
      </table>
    </section>
  );
}

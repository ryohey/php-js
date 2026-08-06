import { Inventory } from '../_components/Inventory';

export const metadata = {
  title: 'Inventory',
  description: 'A heavy React page rendered server-side by php-js.',
};

/**
 * Rows when the URL does not say.
 *
 * Chosen to be felt rather than to look good: a render you can notice, but not
 * one you wait for. `?items=2000` is where it stops being subtle.
 */
const DEFAULT_ITEMS = 120;

/**
 * `searchParams` reaches a page the same way `params` does. A request that
 * carries any is rendered rather than served from the cache — the cache is
 * keyed by path alone, which is what lets a web server answer a hit without
 * starting PHP.
 */
export default function InventoryPage({
  searchParams,
}: {
  searchParams: { items?: string };
}) {
  const asked = Number(searchParams.items);
  const items = asked > 0 ? Math.min(asked, 5000) : DEFAULT_ITEMS;
  return <Inventory items={items} />;
}

import ReactDOMServer from 'react-dom/server';

import { metricsId, reactVersion, resolve, routePaths, type RenderOptions } from './router';

/*
 * Node entry point, used only by `phpjs-ssg compare` to check that the HTML
 * php-js produces is byte-identical to the HTML Node produces from the same
 * source. It differs from entry.tsx in one import: Node's `exports` map forbids
 * the deep path into react-dom, so this goes through the public specifier.
 */

export interface RenderedPage {
  status: number;
  title: string;
  html: string;
}

export function routeManifest(): string {
  return JSON.stringify(routePaths());
}

export { metricsId, reactVersion };

export function renderPage(path: string, options: string): RenderedPage {
  const parsed: RenderOptions = options ? JSON.parse(options) : {};
  const page = resolve(path, parsed);
  return {
    status: page.status,
    title: page.title,
    html: ReactDOMServer.renderToStaticMarkup(page.element),
  };
}

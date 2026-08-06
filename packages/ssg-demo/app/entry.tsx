// @ts-expect-error -- deep path into react-dom, deliberately (see below)
import ReactDOMServer from 'react-dom/cjs/react-dom-server-legacy.node.production.js';

import { metricsId, reactVersion, resolve, routePaths, type RenderOptions } from './router';

/*
 * php-js entry point.
 *
 * Requires the legacy (synchronous) server build by path rather than through
 * `react-dom/server`, because that entry also pulls in the streaming renderer
 * and with it Web APIs this host does not provide (MessageChannel,
 * AbortController). `react-dom/server` assigns `renderToStaticMarkup` straight
 * from this file, so this is the same function that entry point would give.
 */

export interface RenderedPage {
  status: number;
  title: string;
  html: string;
}

/** Serialized, so the host reads one string instead of walking a JS array. */
export function routeManifest(): string {
  return JSON.stringify(routePaths());
}

export { metricsId, reactVersion };

/**
 * Render one route.
 *
 * @param path    request path, e.g. "/docs/getting-started/"
 * @param options JSON object of render options, or "" for none. A string
 *                because it crosses the host boundary, where a plain scalar is
 *                cheaper and clearer than a shared object.
 */
export function renderPage(path: string, options: string): RenderedPage {
  const parsed: RenderOptions = options ? JSON.parse(options) : {};
  const page = resolve(path, parsed);
  return {
    status: page.status,
    title: page.title,
    html: ReactDOMServer.renderToStaticMarkup(page.element),
  };
}

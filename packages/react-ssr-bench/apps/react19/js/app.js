/*
 * php-js entry point.
 *
 * Requires the legacy (synchronous) server build by path rather than going
 * through `react-dom/server`, because that entry also loads the streaming
 * renderer, which pulls in Web APIs this host does not provide
 * (MessageChannel, AbortController). Nothing is lost in the comparison:
 * `react-dom/server` sets `exports.renderToStaticMarkup = l.renderToStaticMarkup`
 * from this very file, so both sides run the same function. Node cannot use the
 * deep path -- the package's `exports` map blocks it -- so the Node side uses
 * app.node.js, which differs only in how it names the renderer.
 */
'use strict';

var components = require('./components.js');
var ReactDOMServer = require('react-dom/cjs/react-dom-server-legacy.node.production.js');

exports.reactVersion = components.reactVersion;
exports.element = components.element;

exports.renderToString = function (itemCount, runtime) {
  return ReactDOMServer.renderToString(components.element(itemCount, runtime));
};

exports.renderToStaticMarkup = function (itemCount, runtime) {
  return ReactDOMServer.renderToStaticMarkup(components.element(itemCount, runtime));
};

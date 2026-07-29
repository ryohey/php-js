/*
 * Node entry point for the comparison run. Same component tree, same renderer;
 * it just names it through the public `react-dom/server` export, which is the
 * only path Node's `exports` map allows.
 */
'use strict';

var components = require('./components.js');
var ReactDOMServer = require('react-dom/server');

exports.reactVersion = components.reactVersion;
exports.element = components.element;

exports.renderToString = function (itemCount, runtime) {
  return ReactDOMServer.renderToString(components.element(itemCount, runtime));
};

exports.renderToStaticMarkup = function (itemCount, runtime) {
  return ReactDOMServer.renderToStaticMarkup(components.element(itemCount, runtime));
};

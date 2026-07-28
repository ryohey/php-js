/*
 * The benchmark's React app, written in ES5 with React.createElement directly
 * so no JSX build step sits between the source and what the engine runs.
 */
'use strict';

var React = require('react');
var ReactDOMServer = require('react-dom/server');

var e = React.createElement;

function Badge(props) {
  return e('span', { className: 'badge badge-' + props.tone }, props.children);
}

function Row(props) {
  var item = props.item;
  return e(
    'tr',
    { className: item.index % 2 === 0 ? 'row even' : 'row odd' },
    e('td', { className: 'cell id' }, String(item.id)),
    e('td', { className: 'cell name' }, e('a', { href: '/items/' + item.id }, item.name)),
    e('td', { className: 'cell tags' }, item.tags.map(function (tag, i) {
      return e(Badge, { key: tag + i, tone: i % 2 ? 'warn' : 'ok' }, tag);
    })),
    e('td', { className: 'cell price' }, '$' + item.price.toFixed(2))
  );
}

function Panel(props) {
  return e(
    'section',
    { className: 'panel' },
    e('h2', null, props.title),
    e('p', { className: 'summary' }, props.summary),
    e('table', null, e('tbody', null, props.items.map(function (item) {
      return e(Row, { key: item.id, item: item });
    })))
  );
}

function App(props) {
  return e(
    'div',
    { id: 'app', 'data-count': props.items.length },
    e('header', null, e('h1', null, props.title)),
    e(Panel, {
      title: 'Inventory',
      summary: 'Rendered by php-js on ' + props.runtime,
      items: props.items
    }),
    e('footer', null, e('small', null, '© ' + props.year + ' php-js'))
  );
}

function buildItems(count) {
  var items = [];
  for (var i = 0; i < count; i++) {
    items.push({
      index: i,
      id: 1000 + i,
      name: 'Item number ' + i,
      tags: ['alpha', 'beta', 'gamma'].slice(0, (i % 3) + 1),
      price: (i % 97) + 0.5
    });
  }
  return items;
}

exports.renderToString = function (itemCount, runtime) {
  return ReactDOMServer.renderToString(
    e(App, { title: 'php-js SSR', items: buildItems(itemCount), runtime: runtime, year: 2026 })
  );
};

exports.renderToStaticMarkup = function (itemCount, runtime) {
  return ReactDOMServer.renderToStaticMarkup(
    e(App, { title: 'php-js SSR', items: buildItems(itemCount), runtime: runtime, year: 2026 })
  );
};

exports.reactVersion = React.version;

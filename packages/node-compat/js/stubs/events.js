/*
 * A minimal EventEmitter. Bundles construct one at load time; a host with no
 * event loop never delivers anything through it, but the shape has to exist.
 */
'use strict';

function EventEmitter() {
  this._events = {};
}

EventEmitter.prototype.on = function (name, fn) {
  (this._events[name] = this._events[name] || []).push(fn);
  return this;
};
EventEmitter.prototype.addListener = EventEmitter.prototype.on;
EventEmitter.prototype.once = EventEmitter.prototype.on;

EventEmitter.prototype.removeListener = function (name, fn) {
  var list = this._events[name];
  if (list) {
    var i = list.indexOf(fn);
    if (i >= 0) { list.splice(i, 1); }
  }
  return this;
};

EventEmitter.prototype.removeAllListeners = function (name) {
  if (name === undefined) { this._events = {}; } else { delete this._events[name]; }
  return this;
};

EventEmitter.prototype.listeners = function (name) {
  return (this._events[name] || []).slice();
};

EventEmitter.prototype.emit = function (name) {
  var list = this._events[name];
  if (!list || list.length === 0) { return false; }
  var args = Array.prototype.slice.call(arguments, 1);
  for (var i = 0; i < list.length; i++) {
    list[i].apply(this, args);
  }
  return true;
};

module.exports = EventEmitter;
module.exports.EventEmitter = EventEmitter;

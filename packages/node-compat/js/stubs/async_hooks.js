/*
 * node:async_hooks, enough for AsyncLocalStorage.
 *
 * The store is a plain stack: `run` pushes, calls, and pops. That is exactly
 * right for synchronous execution, which is what this host does — the VM has
 * one thread and `renderToStaticMarkup` never yields. It is *not* right across
 * a real await boundary: a continuation resumed from the microtask queue will
 * see whatever store is on top at that moment, not the one it suspended under.
 *
 * Rather than pretend otherwise, async continuation is left broken and
 * documented. Making it correct needs continuation-local storage in the VM,
 * which is a runtime feature, not a shim.
 */
'use strict';

function AsyncLocalStorage() {
  this._stack = [];
}

AsyncLocalStorage.prototype.run = function (store, callback) {
  var args = Array.prototype.slice.call(arguments, 2);
  this._stack.push(store);
  try {
    return callback.apply(null, args);
  } finally {
    this._stack.pop();
  }
};

AsyncLocalStorage.prototype.getStore = function () {
  return this._stack.length === 0 ? undefined : this._stack[this._stack.length - 1];
};

AsyncLocalStorage.prototype.enterWith = function (store) {
  this._stack.push(store);
};

AsyncLocalStorage.prototype.exit = function (callback) {
  var args = Array.prototype.slice.call(arguments, 1);
  var saved = this._stack;
  this._stack = [];
  try {
    return callback.apply(null, args);
  } finally {
    this._stack = saved;
  }
};

AsyncLocalStorage.prototype.disable = function () {
  this._stack = [];
};

function AsyncResource(type) {
  this.type = type;
}
AsyncResource.prototype.runInAsyncScope = function (fn, thisArg) {
  return fn.apply(thisArg, Array.prototype.slice.call(arguments, 2));
};
AsyncResource.prototype.emitDestroy = function () { return this; };
AsyncResource.prototype.bind = function (fn) { return fn; };

exports.AsyncLocalStorage = AsyncLocalStorage;
exports.AsyncResource = AsyncResource;
exports.executionAsyncId = function () { return 0; };
exports.triggerAsyncId = function () { return 0; };
exports.createHook = function () {
  return { enable: function () { return this; }, disable: function () { return this; } };
};

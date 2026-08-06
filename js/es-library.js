/*
 * The parts of the ECMAScript standard library that are implemented in
 * JavaScript rather than in PHP.
 *
 * Not a polyfill: this file is the runtime's own, and what it defines is
 * ordinary ES2015+ library surface an engine is expected to have. It is
 * written in ES5.1 and everything is feature-detected, so anything later
 * reimplemented natively in `src/Builtins/` wins automatically and the entry
 * here becomes dead weight to be deleted. `Math.clz32` and `Map`/`Set` took
 * that route already, for measured reasons -- see MathBuiltins and
 * CollectionBuiltins.
 *
 * Nothing host-shaped belongs here. Globals like `process`, `require` or
 * timers come from a PhpJs\Host\Environment implementation
 * (packages/node-compat), which the runtime knows nothing about.
 */
(function (global) {
  'use strict';

  function def(obj, name, value) {
    if (obj[name] === undefined) {
      Object.defineProperty(obj, name, {
        value: value,
        writable: true,
        enumerable: false,
        configurable: true
      });
    }
  }

  // ---- Object -------------------------------------------------------------
  def(Object, 'assign', function assign(target) {
    if (target === null || target === undefined) {
      throw new TypeError('Cannot convert undefined or null to object');
    }
    var to = Object(target);
    for (var i = 1; i < arguments.length; i++) {
      var source = arguments[i];
      if (source === null || source === undefined) {
        continue;
      }
      var keys = Object.keys(Object(source));
      for (var k = 0; k < keys.length; k++) {
        to[keys[k]] = source[keys[k]];
      }
    }
    return to;
  });

  def(Object, 'is', function is(a, b) {
    if (a === b) {
      // +0 and -0 are the only values === cannot separate.
      return a !== 0 || 1 / a === 1 / b;
    }
    return a !== a && b !== b;
  });

  def(Object, 'values', function values(o) {
    return Object.keys(Object(o)).map(function (k) { return o[k]; });
  });

  def(Object, 'entries', function entries(o) {
    return Object.keys(Object(o)).map(function (k) { return [k, o[k]]; });
  });

  def(Object, 'setPrototypeOf', function setPrototypeOf(obj, proto) {
    // Cannot rewire [[Prototype]] in place without engine support; copying the
    // prototype's own properties covers the "inherit statics" use.
    if (proto !== null && proto !== undefined) {
      var names = Object.getOwnPropertyNames(proto);
      for (var i = 0; i < names.length; i++) {
        if (!Object.prototype.hasOwnProperty.call(obj, names[i])) {
          var d = Object.getOwnPropertyDescriptor(proto, names[i]);
          if (d) {
            Object.defineProperty(obj, names[i], d);
          }
        }
      }
    }
    return obj;
  });

  // ---- Array --------------------------------------------------------------
  def(Array, 'of', function of() {
    return Array.prototype.slice.call(arguments);
  });

  def(Array.prototype, 'find', function find(pred, thisArg) {
    var o = Object(this);
    var len = o.length >>> 0;
    for (var i = 0; i < len; i++) {
      if (pred.call(thisArg, o[i], i, o)) {
        return o[i];
      }
    }
    return undefined;
  });

  def(Array.prototype, 'findIndex', function findIndex(pred, thisArg) {
    var o = Object(this);
    var len = o.length >>> 0;
    for (var i = 0; i < len; i++) {
      if (pred.call(thisArg, o[i], i, o)) {
        return i;
      }
    }
    return -1;
  });

  def(Array.prototype, 'includes', function includes(needle, from) {
    var o = Object(this);
    var len = o.length >>> 0;
    for (var i = from | 0; i < len; i++) {
      if (o[i] === needle || (needle !== needle && o[i] !== o[i])) {
        return true;
      }
    }
    return false;
  });

  def(Array.prototype, 'fill', function fill(value, start, end) {
    var o = Object(this);
    var len = o.length >>> 0;
    var s = start === undefined ? 0 : start | 0;
    var e = end === undefined ? len : end | 0;
    if (s < 0) { s += len; }
    if (e < 0) { e += len; }
    for (var i = s; i < e; i++) {
      o[i] = value;
    }
    return o;
  });

  // ---- String -------------------------------------------------------------
  def(String.prototype, 'startsWith', function startsWith(search, pos) {
    return this.indexOf(search, pos || 0) === (pos || 0);
  });

  def(String.prototype, 'endsWith', function endsWith(search, len) {
    var s = String(this);
    var end = len === undefined ? s.length : len;
    return s.slice(end - String(search).length, end) === String(search);
  });

  def(String.prototype, 'includes', function includes(search, pos) {
    return String(this).indexOf(search, pos || 0) !== -1;
  });

  def(String.prototype, 'repeat', function repeat(count) {
    var n = count | 0;
    if (n < 0) {
      throw new RangeError('Invalid count value');
    }
    var s = String(this);
    var out = '';
    while (n > 0) {
      if (n & 1) { out += s; }
      n >>= 1;
      if (n) { s += s; }
    }
    return out;
  });

  def(String.prototype, 'trimStart', function trimStart() {
    return String(this).replace(/^\s+/, '');
  });
  def(String.prototype, 'trimEnd', function trimEnd() {
    return String(this).replace(/\s+$/, '');
  });
  def(String.prototype, 'padStart', function padStart(target, pad) {
    var s = String(this);
    pad = pad === undefined ? ' ' : String(pad);
    if (s.length >= target || pad === '') { return s; }
    var fill = pad.repeat(Math.ceil((target - s.length) / pad.length));
    return fill.slice(0, target - s.length) + s;
  });
  def(String.prototype, 'padEnd', function padEnd(target, pad) {
    var s = String(this);
    pad = pad === undefined ? ' ' : String(pad);
    if (s.length >= target || pad === '') { return s; }
    var fill = pad.repeat(Math.ceil((target - s.length) / pad.length));
    return s + fill.slice(0, target - s.length);
  });

  // ---- Number / Math ------------------------------------------------------
  def(Number, 'isNaN', function isNaN_(v) { return typeof v === 'number' && v !== v; });
  def(Number, 'isFinite', function isFinite_(v) {
    return typeof v === 'number' && isFinite(v);
  });
  def(Number, 'isInteger', function isInteger(v) {
    return typeof v === 'number' && isFinite(v) && Math.floor(v) === v;
  });
  def(Number, 'isSafeInteger', function isSafeInteger(v) {
    return Number.isInteger(v) && Math.abs(v) <= 9007199254740991;
  });
  def(Number, 'MAX_SAFE_INTEGER', 9007199254740991);
  def(Number, 'MIN_SAFE_INTEGER', -9007199254740991);
  def(Number, 'EPSILON', 2.220446049250313e-16);
  def(Number, 'parseInt', parseInt);
  def(Number, 'parseFloat', parseFloat);
})(this);

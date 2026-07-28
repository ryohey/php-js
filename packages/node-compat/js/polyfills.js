/*
 * ES2015+ library surface for an ES5.1 engine.
 *
 * Shims only. Syntax stays ES5.1 — this file is itself ES5, and guest code is
 * still expected to be downleveled, which is the runtime's input contract.
 * Everything is feature-detected so a later engine version that implements a
 * builtin natively wins automatically.
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

  // ---- Symbol -------------------------------------------------------------
  // A registry of unique strings. Enough for the one thing library code
  // actually needs from Symbol: an unforgeable-ish tag to brand values with
  // (React's element $$typeof). Not a distinct primitive type.
  if (typeof global.Symbol !== 'function') {
    var symbolCounter = 0;
    var symbolRegistry = {};

    var SymbolPolyfill = function Symbol(description) {
      var name = '@@Symbol(' + (description === undefined ? '' : description) + ')#' + (++symbolCounter);
      return name;
    };
    SymbolPolyfill['for'] = function (key) {
      key = String(key);
      if (!Object.prototype.hasOwnProperty.call(symbolRegistry, key)) {
        symbolRegistry[key] = '@@' + key;
      }
      return symbolRegistry[key];
    };
    SymbolPolyfill.keyFor = function (sym) {
      for (var key in symbolRegistry) {
        if (symbolRegistry[key] === sym) {
          return key;
        }
      }
      return undefined;
    };
    ['iterator', 'asyncIterator', 'toStringTag', 'hasInstance', 'species', 'toPrimitive'].forEach(
      function (name) {
        SymbolPolyfill[name] = '@@' + name;
      }
    );
    def(global, 'Symbol', SymbolPolyfill);
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

  def(Object, 'getOwnPropertySymbols', function getOwnPropertySymbols() {
    // Symbols are plain strings here, so they are already own property names.
    return [];
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
  def(Array, 'from', function from(arrayLike, mapFn, thisArg) {
    var items = Object(arrayLike);
    var len = items.length >>> 0;
    var out = [];
    for (var i = 0; i < len; i++) {
      out.push(mapFn ? mapFn.call(thisArg, items[i], i) : items[i]);
    }
    return out;
  });

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

  def(Math, 'trunc', function trunc(x) {
    x = +x;
    return x < 0 ? Math.ceil(x) : Math.floor(x);
  });
  def(Math, 'sign', function sign(x) {
    x = +x;
    if (x !== x || x === 0) { return x; }
    return x > 0 ? 1 : -1;
  });
  def(Math, 'log2', function log2(x) { return Math.log(x) / Math.LN2; });
  def(Math, 'log10', function log10(x) { return Math.log(x) / Math.LN10; });
  def(Math, 'cbrt', function cbrt(x) {
    var y = Math.pow(Math.abs(x), 1 / 3);
    return x < 0 ? -y : y;
  });
  def(Math, 'hypot', function hypot() {
    var sum = 0;
    for (var i = 0; i < arguments.length; i++) {
      sum += arguments[i] * arguments[i];
    }
    return Math.sqrt(sum);
  });
  def(Math, 'clz32', function clz32(x) {
    var v = x >>> 0;
    if (v === 0) { return 32; }
    var n = 0;
    while (!(v & 0x80000000)) { v <<= 1; n++; }
    return n;
  });
  def(Math, 'imul', function imul(a, b) {
    var ah = (a >>> 16) & 0xffff, al = a & 0xffff;
    var bh = (b >>> 16) & 0xffff, bl = b & 0xffff;
    return ((al * bl) + (((ah * bl + al * bh) << 16) >>> 0)) | 0;
  });

  // ---- Map / Set ----------------------------------------------------------
  // Keys are compared with SameValueZero. Primitives go through a string-keyed
  // bucket; objects get a non-enumerable id stamped on them, so lookup stays
  // O(1) without a real hash table.
  var objectIdKey = '@@phpjs.objectId';
  var objectIdCounter = 0;

  function keyOf(value) {
    var t = typeof value;
    if (t === 'object' || t === 'function') {
      if (value === null) {
        return 'null';
      }
      if (!Object.prototype.hasOwnProperty.call(value, objectIdKey)) {
        Object.defineProperty(value, objectIdKey, {
          value: 'o' + (++objectIdCounter),
          enumerable: false,
          writable: false,
          configurable: false
        });
      }
      return value[objectIdKey];
    }
    if (t === 'number' && value !== value) {
      return 'n:NaN';
    }
    return t.charAt(0) + ':' + String(value);
  }

  function MapPolyfill(entries) {
    this._k = {};
    this._entries = [];
    this.size = 0;
    if (entries) {
      for (var i = 0; i < entries.length; i++) {
        this.set(entries[i][0], entries[i][1]);
      }
    }
  }
  MapPolyfill.prototype.get = function (key) {
    var i = this._k[keyOf(key)];
    return i === undefined ? undefined : this._entries[i][1];
  };
  MapPolyfill.prototype.set = function (key, value) {
    var k = keyOf(key);
    var i = this._k[k];
    if (i === undefined) {
      this._k[k] = this._entries.length;
      this._entries.push([key, value]);
      this.size++;
    } else {
      this._entries[i][1] = value;
    }
    return this;
  };
  MapPolyfill.prototype.has = function (key) {
    return this._k[keyOf(key)] !== undefined;
  };
  MapPolyfill.prototype['delete'] = function (key) {
    var k = keyOf(key);
    var i = this._k[k];
    if (i === undefined) {
      return false;
    }
    this._entries[i] = null;
    delete this._k[k];
    this.size--;
    return true;
  };
  MapPolyfill.prototype.clear = function () {
    this._k = {};
    this._entries = [];
    this.size = 0;
  };
  MapPolyfill.prototype.forEach = function (cb, thisArg) {
    for (var i = 0; i < this._entries.length; i++) {
      var e = this._entries[i];
      if (e) {
        cb.call(thisArg, e[1], e[0], this);
      }
    }
  };
  def(global, 'Map', MapPolyfill);
  def(global, 'WeakMap', MapPolyfill);

  function SetPolyfill(values) {
    this._map = new MapPolyfill();
    this.size = 0;
    if (values) {
      for (var i = 0; i < values.length; i++) {
        this.add(values[i]);
      }
    }
  }
  SetPolyfill.prototype.add = function (value) {
    this._map.set(value, value);
    this.size = this._map.size;
    return this;
  };
  SetPolyfill.prototype.has = function (value) {
    return this._map.has(value);
  };
  SetPolyfill.prototype['delete'] = function (value) {
    var removed = this._map['delete'](value);
    this.size = this._map.size;
    return removed;
  };
  SetPolyfill.prototype.clear = function () {
    this._map.clear();
    this.size = 0;
  };
  SetPolyfill.prototype.forEach = function (cb, thisArg) {
    var self = this;
    this._map.forEach(function (v) { cb.call(thisArg, v, v, self); });
  };
  def(global, 'Set', SetPolyfill);
  def(global, 'WeakSet', SetPolyfill);

  // ---- TypedArray ---------------------------------------------------------
  // Backed by ordinary index properties, not a byte buffer. Element values are
  // coerced on construction and through set(), but a direct `a[i] = x` cannot
  // be intercepted without an exotic object the engine does not expose, so it
  // stores the value as-is. Adequate for library code that keeps in-range
  // integers (React's thread-id table); not a substitute for real binary data.
  function defineTypedArray(name, bytesPerElement, coerce) {
    if (global[name] !== undefined) {
      return;
    }
    function TypedArray(source) {
      var i;
      if (typeof source === 'number') {
        this.length = source >>> 0;
        for (i = 0; i < this.length; i++) {
          this[i] = coerce(0);
        }
      } else if (source && typeof source.length === 'number') {
        this.length = source.length >>> 0;
        for (i = 0; i < this.length; i++) {
          this[i] = coerce(source[i]);
        }
      } else {
        this.length = 0;
      }
      this.byteLength = this.length * bytesPerElement;
    }
    TypedArray.BYTES_PER_ELEMENT = bytesPerElement;
    TypedArray.prototype.BYTES_PER_ELEMENT = bytesPerElement;
    TypedArray.prototype.set = function (source, offset) {
      var at = offset === undefined ? 0 : offset >>> 0;
      var len = source.length >>> 0;
      for (var i = 0; i < len; i++) {
        this[at + i] = coerce(source[i]);
      }
    };
    TypedArray.prototype.subarray = function (begin, end) {
      var out = [];
      var from = begin === undefined ? 0 : begin | 0;
      var to = end === undefined ? this.length : end | 0;
      for (var i = from; i < to; i++) {
        out.push(this[i]);
      }
      return new TypedArray(out);
    };
    TypedArray.prototype.fill = function (value, begin, end) {
      var from = begin === undefined ? 0 : begin | 0;
      var to = end === undefined ? this.length : end | 0;
      for (var i = from; i < to; i++) {
        this[i] = coerce(value);
      }
      return this;
    };
    ['forEach', 'map', 'filter', 'indexOf', 'join', 'slice', 'reduce'].forEach(function (method) {
      TypedArray.prototype[method] = function () {
        return Array.prototype[method].apply(this, arguments);
      };
    });
    def(global, name, TypedArray);
  }

  function wrapUnsigned(bits) {
    var mod = Math.pow(2, bits);
    return function (v) {
      v = Math.trunc(+v) || 0;
      v %= mod;
      return v < 0 ? v + mod : v;
    };
  }
  function wrapSigned(bits) {
    var mod = Math.pow(2, bits);
    var half = mod / 2;
    var unsigned = wrapUnsigned(bits);
    return function (v) {
      v = unsigned(v);
      return v >= half ? v - mod : v;
    };
  }
  function asFloat(v) {
    return +v;
  }

  defineTypedArray('Uint8Array', 1, wrapUnsigned(8));
  defineTypedArray('Uint8ClampedArray', 1, function (v) {
    v = Math.round(+v) || 0;
    return v < 0 ? 0 : (v > 255 ? 255 : v);
  });
  defineTypedArray('Int8Array', 1, wrapSigned(8));
  defineTypedArray('Uint16Array', 2, wrapUnsigned(16));
  defineTypedArray('Int16Array', 2, wrapSigned(16));
  defineTypedArray('Uint32Array', 4, wrapUnsigned(32));
  defineTypedArray('Int32Array', 4, wrapSigned(32));
  defineTypedArray('Float32Array', 4, asFloat);
  defineTypedArray('Float64Array', 8, asFloat);

  // ---- console ------------------------------------------------------------
  // Bundles call these unconditionally in development builds.
  ['group', 'groupEnd', 'groupCollapsed', 'table', 'trace', 'time', 'timeEnd', 'dir'].forEach(
    function (name) {
      def(global.console, name, function () {});
    }
  );
})(this);

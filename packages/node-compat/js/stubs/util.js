/*
 * The parts of `util` that CommonJS bundles reach for at load time.
 * Anything genuinely tied to Node internals is left out rather than faked.
 */
'use strict';

exports.inherits = function inherits(ctor, superCtor) {
  if (superCtor) {
    ctor.super_ = superCtor;
    function TempCtor() {}
    TempCtor.prototype = superCtor.prototype;
    ctor.prototype = new TempCtor();
    ctor.prototype.constructor = ctor;
  }
};

exports.inspect = function inspect(value) {
  try {
    return JSON.stringify(value);
  } catch (e) {
    return String(value);
  }
};

exports.format = function format(f) {
  var args = Array.prototype.slice.call(arguments, 1);
  if (typeof f !== 'string') {
    return [f].concat(args).map(exports.inspect).join(' ');
  }
  var i = 0;
  var out = f.replace(/%[sdj%]/g, function (token) {
    if (token === '%%') { return '%'; }
    if (i >= args.length) { return token; }
    var arg = args[i++];
    if (token === '%s') { return String(arg); }
    if (token === '%d') { return String(Number(arg)); }
    return exports.inspect(arg);
  });
  for (; i < args.length; i++) {
    out += ' ' + exports.inspect(args[i]);
  }
  return out;
};

exports.deprecate = function deprecate(fn) {
  return fn;
};

exports.types = {
  isDate: function (v) { return Object.prototype.toString.call(v) === '[object Date]'; },
  isRegExp: function (v) { return Object.prototype.toString.call(v) === '[object RegExp]'; }
};

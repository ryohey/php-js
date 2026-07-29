/*
 * Enough of node:crypto for library code that reaches for a unique id.
 *
 * React 19's `react-dom/server.node` entry pulls this in for randomUUID; the
 * sync renderer this runtime targets never calls it. Anything genuinely
 * cryptographic throws rather than returning weak bytes, because a silent
 * insecure fallback is worse than an unimplemented one.
 */
'use strict';

var counter = 0;

function unsupported(name) {
  return function () {
    throw new Error('crypto.' + name + ' is not available in php-js (node-compat stub)');
  };
}

function hex(n) {
  var out = '';
  for (var i = 0; i < n; i++) {
    out += ((Math.random() * 16) | 0).toString(16);
  }
  return out;
}

exports.randomUUID = function randomUUID() {
  // Not a secure UUID: Math.random is not a CSPRNG. Unique within a build,
  // which is all the SSR path uses it for.
  counter++;
  return hex(8) + '-' + hex(4) + '-4' + hex(3) + '-a' + hex(3) + '-' + hex(12);
};

exports.randomBytes = unsupported('randomBytes');
exports.createHash = unsupported('createHash');
exports.createHmac = unsupported('createHmac');
exports.webcrypto = undefined;

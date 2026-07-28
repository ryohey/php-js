/*
 * Enough of `stream` for libraries that require it at load time but only use
 * it on paths a synchronous host never takes — react-dom/server is the
 * motivating case: it defines a Readable subclass for renderToNodeStream and
 * never touches it from renderToString.
 *
 * Readable is a constructor with the prototype shape subclassing expects.
 * Calling read() throws rather than returning nothing, so a caller that really
 * wanted a stream finds out immediately instead of silently getting no output.
 */
'use strict';

function Readable(options) {
  this._readableState = { options: options || {}, ended: false };
}

Readable.prototype._read = function () {
  throw new Error('stream.Readable is a stub in this host: streaming APIs are unavailable');
};

Readable.prototype.read = function () {
  return this._read();
};

Readable.prototype.push = function () {
  throw new Error('stream.Readable is a stub in this host: streaming APIs are unavailable');
};

Readable.prototype.destroy = function () {
  this._readableState.ended = true;
  return this;
};

Readable.prototype.pipe = function () {
  throw new Error('stream.Readable is a stub in this host: streaming APIs are unavailable');
};

Readable.prototype.on = function () { return this; };
Readable.prototype.once = function () { return this; };
Readable.prototype.emit = function () { return false; };
Readable.prototype.removeListener = function () { return this; };
Readable.prototype.setEncoding = function () { return this; };

function Writable() {
  throw new Error('stream.Writable is not available in this host');
}

exports.Readable = Readable;
exports.Writable = Writable;
exports.Stream = Readable;
exports.PassThrough = Readable;
exports.Transform = Readable;
exports.Duplex = Readable;

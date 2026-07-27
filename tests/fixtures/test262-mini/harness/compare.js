// Fixture include file: detect strict mode.
function isStrict() {
  'use strict';
  return typeof this === 'undefined' ? isStrictOuter() : false;
}
function isStrictOuter() {
  return (function () { return this; })() === undefined;
}

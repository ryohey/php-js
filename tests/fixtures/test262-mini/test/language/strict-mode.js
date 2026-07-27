/*---
description: behaves differently per mode; valid in both
includes: [compare.js]
---*/
function whoIsThis() {
  return this;
}
if (isStrict()) {
  assert.sameValue(whoIsThis(), undefined);
} else {
  assert.notSameValue(whoIsThis(), undefined);
}

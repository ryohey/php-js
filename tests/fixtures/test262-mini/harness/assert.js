// Minimal stand-in for test262's assert.js (fixture for runner self-tests only).
function assert(mustBeTrue, message) {
  if (mustBeTrue !== true) {
    throw new Test262Error(message || 'assert failed');
  }
}
assert.sameValue = function (actual, expected, message) {
  if (actual !== expected) {
    throw new Test262Error((message || '') + ' expected ' + expected + ' got ' + actual);
  }
};
assert.notSameValue = function (actual, unexpected, message) {
  if (actual === unexpected) {
    throw new Test262Error(message || 'values unexpectedly equal');
  }
};
assert.throws = function (expectedErrorConstructor, func, message) {
  try {
    func();
  } catch (thrown) {
    if (thrown instanceof expectedErrorConstructor) {
      return;
    }
    throw new Test262Error((message || '') + ' threw wrong error type');
  }
  throw new Test262Error((message || '') + ' did not throw');
};

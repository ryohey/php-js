// Minimal stand-in for test262's sta.js (fixture for runner self-tests only).
function Test262Error(message) {
  this.message = message || '';
}
Test262Error.prototype.name = 'Test262Error';
Test262Error.prototype.toString = function () {
  return 'Test262Error: ' + this.message;
};
var $ERROR = function (message) {
  throw new Test262Error(message);
};

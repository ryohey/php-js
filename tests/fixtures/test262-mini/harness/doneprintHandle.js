// Minimal stand-in for test262's doneprintHandle.js (fixture only).
function $DONE(error) {
  if (error) {
    print('Test262:AsyncTestFailure: ' + error);
  } else {
    print('Test262:AsyncTestComplete');
  }
}

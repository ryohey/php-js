/*---
description: async test completing via $DONE
flags: [async]
---*/
Promise.resolve(1).then(function (v) {
  if (v === 1) {
    $DONE();
  } else {
    $DONE('wrong value');
  }
});

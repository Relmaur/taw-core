## Why it usually fails

A block's unit test expects data the block no longer returns, usually because a taw/core helper the block
uses changed in this update, or because the block changed and its test didn't.

## Steps

1. Run the tests yourself: `composer run test`. Each failure names the test and what it expected.
2. Open the test (`tests/Unit/...`) and the block it tests (`Blocks/<Name>/<Name>.php`, its `getData()`).
3. If the block's output changed on purpose, update the test's expectation; if not, fix the block.

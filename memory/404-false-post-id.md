---
name: 404-false-post-id
description: getData() receives false on 404 pages because get_the_ID() returns false — meta helpers handle this
metadata:
  type: project
---

`MetaBlock::getData(int|false $postId)` — the `false` type is not a mistake or edge-case guard; it is a real runtime value.

**Why:** `get_the_ID()` returns `false` (not `0`, not `null`) on 404 pages, archive pages, and any context where there is no current post. BlockLoader calls blocks in all these contexts. If `getData()` only accepted `int`, blocks would fatal on 404.

**How to apply:** The framework's meta helpers (`getMeta`, `getImageUrl`, `getRepeater`) all short-circuit on `false` and return type-appropriate empty values (`''`, `[]`, `null`). New meta helper additions must maintain this contract. When writing `getData()` implementations, don't assume `$postId` is truthy — always pass it through to the helpers rather than using it directly.

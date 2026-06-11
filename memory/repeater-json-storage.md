---
name: repeater-json-storage
description: Repeater and files fields store JSON strings — why JSON was chosen over PHP serialize()
metadata:
  type: project
---

`repeater` and `files` field values are stored as JSON strings via `wp_update_post_meta()`. Callers must `json_decode()` to use the data.

**Why JSON over `serialize()`:** WordPress runs all meta values through `wp_unslash()` before passing them to `update_post_meta()`. PHP's `serialize()` output contains backslash sequences that `wp_unslash()` corrupts, making the stored value undeserializable. JSON has no such sequences and survives the round-trip intact.

**How to apply:** When reading repeater or files fields, always `json_decode(Metabox::get($id, 'field'), true)`. Never store structured data in these fields as a PHP serialized string.

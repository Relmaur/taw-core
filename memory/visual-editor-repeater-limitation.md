---
name: visual-editor-repeater-limitation
description: Repeater sub-fields are intentionally excluded from the visual editor's field registry — planned for a future iteration
metadata:
  type: project
---

`Metabox::$fieldRegistry` (the static field index used by the visual editor) is populated in the constructor. Top-level fields and `group` sub-fields are registered. Repeater sub-fields are **explicitly skipped** with a comment:

> "Repeater TODO: sub-fields intentionally excluded from visual editor. Repeater data is a single JSON blob requiring row-index-aware editing — planned for a future iteration."

Group sub-fields get compound IDs (`{group_id}_{sub_field_id}`) and ARE registered.

**Why:** Repeater values are a single JSON blob; the visual editor would need to know the row index to edit a specific sub-field value. That row-index-aware UI hasn't been built yet.

**How to apply:** If asked to add visual editor support for a repeater field, the limitation is architectural — the field won't appear in `$fieldRegistry` and the editor's save endpoint doesn't handle JSON-array values. This requires a new feature, not a simple fix. Don't try to work around it by registering repeater sub-fields individually.

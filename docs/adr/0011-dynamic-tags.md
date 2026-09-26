# ADR-0011: Dynamic tags, live values inside text

## Status

Accepted (2026-09-26); decision 6 (the canvas `@`) superseded by ADR-0012. Plan: umbrella `docs/plans/dynamic-tags.md` (data layer Phase 7a, roadmap row
"7. Dynamic tags / expressions", built before Phase 5). Builds on ADR-0010 (Block Bindings, the
`ExpressionResolver` interface) and ADR-0009 (typed value API).

## Context

ADR-0010's `taw/field` source replaces a block attribute as a whole: a paragraph *is* the subtitle.
Editors also need values *inside* text, e.g. "Published on September 26, 2026 by Jane Doe", and more than
TAW fields: post properties (ID, title, date, author…), site properties (name, current year…), and
formatting (a date format, a fallback for empty values).

Options considered:

1. **Typed tokens** (`{{post.date}}`), replaced on render. Simple, but a typo fails silently, the editor
   can't preview them, and plain text that happens to look like a token gets replaced.
2. **One block per value** (a "TAW value" block). Values can't sit inside a sentence.
3. **An inline rich-text format.** An atomic element inserted from a list, stored with its arguments,
   and filled in on render. WordPress 7.1 supports this (`registerFormatType` with
   `contentEditable: false`, and `editor.Autocomplete.completers`), and core footnotes use the same
   pattern.

## Decision

1. **Inline tags are a rich-text format, `taw/tag`**, stored as
   `<span class="taw-tag" data-taw-tag='{…}'>last value</span>`. The JSON holds either
   `{"tag": "post.date"}` (a property) or the `taw/field` binding args (`field`, `from`, `sub`), plus the
   optional `format` (dates) and `fallback`.
2. **The front end always shows the live value.** A `render_block` filter (skipped unless the HTML
   contains `data-taw-tag`) resolves each tag with the block's context and replaces the element's text.
   The stored text is only a fallback for when taw/core is absent, and an editor-side preview.
3. **Resolution reuses ADR-0010.** Fields go through `FieldResolver`, so their privacy rules, opt-outs and
   object context are unchanged. Properties go through a new `TagResolver`, a second `ExpressionResolver`.
   Post properties follow core's rules for private and password-protected posts.
4. **Inline means text.** Every tag renders escaped text; there's no HTML from a tag.
5. **Rich-text blocks get `postId`/`postType` context**, so a tag inside a Query Loop item uses that item's
   post. This is additive: it never changes output by itself.
6. **Editor:** typing `@` (after a space or at a line start) opens a searchable list, and a toolbar button
   opens the same list. Picking inserts the chip with its current preview. A chip popover edits
   `format`/`fallback` and refreshes the stored text. Chips don't refresh on load, since that would mark
   every post modified.
7. **Always on** through `Boot::data()`, like `taw/field`.

## Consequences

- Editors can place any value mid-sentence, with a preview, in any rich-text block, templates and Query
  Loops included, without code.
- Both themes' hook snapshots grow (expected: `render_block`, `register_block_type_args`).
- Stored chip text can be stale in the editor until refreshed; the front end is never stale.
- The editor side depends on WordPress's autocomplete and format APIs. Re-check them after WordPress
  updates, as with the bindings UI.
- Removing taw/core leaves readable text (the last values), not broken markup.
- Not in this decision: an expression language, tags inside attributes (URLs, alt text), tags as
  binding args, and repeater rows (after Phase 5's loop block).

# ADR-0014: The TAW Loop block

## Status

Proposed (2026-09-26). Plan: umbrella `docs/plans/data-layer-phase-5.md` (roadmap row 5). Builds on
ADR-0010 (Block Bindings), ADR-0011/0012 (tags, expressions) and ADR-0013 (conditions).

## Context

Values, expressions and conditions read one post. Designs often repeat something: a repeater's rows,
related posts, a query, terms, gallery images. Core's Query Loop only loops over posts and can't read TAW
fields of anything but posts. The owner wants a complete loop builder.

## Decision

1. **Four dynamic blocks, rendered on the server:** `taw/loop` (source and options), `taw/loop-item` (the
   item template), `taw/loop-empty` (no items) and `taw/loop-pagination`. Registered by taw-core from
   `block.json` files; they work in taw-theme and taw-gutenberg.
2. **Five sources behind one interface:** repeater (a field's rows; `from` post/option/term/user), related
   posts (a post_select field), a query (post type, terms incl. "the current post's", author, search,
   sticky, include/exclude, order), terms (a taxonomy or the current post's) and images (a files or image
   field). Each yields items.
3. **Item values:** a new `@row` namespace (a repeater row's sub-fields; a term's or image's properties and
   fields) and `@loop` (`index`, `count`, `first`, `last`, `even`, `odd`). Post items also set `postId` /
   `postType` context, so everything that reads a post works per item unchanged. `BindingContext` carries the
   item; chips, bindings and conditions resolve `@row`/`@loop` through it.
4. **Options:** order by any item value (as text, number or date) or the source's order or random; limit,
   offset; a filter condition (ADR-0013) per item; pagination per loop (`?taw-loop-<id>=N`); layout (list or
   grid with columns and gap; wrapper element). **Hard cap: 200 items per loop; nesting depth 3.**
5. **Nesting:** a loop inside an item can use the item's own values (its repeater, its post's fields, its
   terms). An inner `@row` shadows the outer one.
6. **Privacy:** queries return only posts the visitor may read; related posts drop unreadable ones; `@row`
   of a repeater follows the parent field's rules (`bindings: false` → no rows).
7. **Editor:** a setup placeholder with starting designs, a sidebar Loop panel, live previews from a
   preview route (`taw/v1/loop/items`, same limits and permissions as the bindings preview), the first item
   editable. The TAW data popup lists Row and Loop values inside an item.

## Consequences

- New blocks mean new registrations: each theme's hook list grows (counted in Step 1).
- The expression grammar gains two namespaces (`row`, `loop`), in both parsers and the shared fixture.
- If taw/core is removed, the loop blocks render nothing (they're dynamic); their saved inner template stays
  in the content.
- Out of scope for now: `@row.parent.*` (reaching an outer loop's row), loops over options-page repeaters of
  other sites, and writing to rows from the front end.

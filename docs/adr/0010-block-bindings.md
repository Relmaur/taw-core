# ADR-0010: Block Bindings through a `taw/field` source

## Status

Accepted (2026-09-25). Plan: umbrella `docs/plans/data-layer-phase-4.md` (data layer Phase 4, roadmap
row "4. Gutenberg Block Bindings"). Builds on ADR-0009 (typed value API), ADR-0008 (qualified
registry, storage contexts) and ADR-0005 (editing policies, whose `allowBound` key this completes).

## Context

Since ADR-0009, PHP reads any TAW field as a typed, escaped `Value` (`Taw::post()`, `term()`, `user()`,
`option()`). Block themes and the block editor can't use that: a core Paragraph, Heading, Image or
Button can't show a TAW field, so taw-gutenberg has no way to put field data into templates without
writing a custom block per field.

WordPress's Block Bindings API (7.1 on the `taw` site) is the native answer:
- `register_block_bindings_source()` takes a `get_value_callback($args, $block, $attributeName)`;
- a fixed, filterable list of bindable attributes (paragraph/heading/list-item `content`, image
  `id`/`url`/`title`/`alt`/`caption`, button `url`/`text`/`linkTarget`/`rel`, post-date `datetime`,
  navigation `url`);
- rich-text values are inserted with `wp_kses_post()` and attribute values through
  `WP_HTML_Tag_Processor::set_attribute()`;
- `postId`/`postType` block context, which Query Loops set per item;
- in the editor, `registerBlockBindingsSource()` with `getValues` (canvas preview) and
  `getFieldsList` (the Attributes panel's picker, filtered by attribute type).

Core's own `core/post-meta` source can't serve TAW: it refuses protected meta (keys starting with
`_`), so every `_taw_*` field is invisible to it, and it returns stored values (an attachment ID, a
JSON string), never typed ones.

Constraints: taw/core serves TAW sites only (ADR-0003 addendum); stored data and existing APIs are
unchanged; bindings must not become a way to print data that REST and templates keep private.

## Decision

1. **One source, `taw/field`**, registered by `Boot::data()` for every TAW theme (the owner chose
   "always on"; it adds one `init` hook, and one editor-assets hook in Step 2).
2. **Arguments:**
   - `field`: a bare id, qualified id or meta key, resolved like `Taw::post()->field()`;
   - `from`: `post` (default), `option`, `term` or `user`;
   - `sub`: a group's sub-field;
   - `size`: an image size (default `full`).
3. **Where the object comes from:**
   - `post`: the block's `postId` context (so Query Loop items resolve their own post), else the queried post;
   - `term`: the queried term;
   - `user`: the context post's author, or the queried author on author archives;
   - `option`: site-wide.
4. **Only registered TAW fields bind.** Unlike `Taw::post()`, there is no untyped `_taw_<id>`
   fallback, so a binding can't read arbitrary meta.
5. **Privacy follows core's post-meta source, plus two TAW rules:**
   - a post that isn't publicly viewable needs `read_post`, and a password-protected post returns nothing;
   - user fields bind only with `'bindings' => true`, because user fieldsets are REST edit-only (ADR-0008);
   - any other field can opt out with `'bindings' => false`.
6. **Values are typed by field type × block attribute** (`Bindings\AttributeMap`), built on `Value`:
   - text-like fields give escaped text;
   - wysiwyg gives `wp_kses_post` HTML;
   - image gives URL/ID/alt/title/caption;
   - link and url give a button's `url`/`text`/`linkTarget`/`rel`;
   - a single post_select gives its title, permalink or featured image.

   Repeaters, files, checkboxes, groups and the composite types don't bind; a group's sub-field
   does. An attribute the map doesn't cover returns `null`, so the block keeps its saved content.
7. **Read-only.** Bound blocks preview their value in the editor and are edited in the metabox or
   data panel. The source registers no `setValues`; two-way editing is left for a later decision.
8. **Editor previews use the server's resolver** (`POST taw/v1/bindings/preview`, batched), so the
   canvas shows what the front end renders. `getFieldsList` comes from a descriptor inlined into
   editor settings.
9. **A resolver interface, `Bindings\ExpressionResolver`** (`resolve(Reference, BindingContext,
   Target)`). `FieldResolver` is the first implementation; Phase 7's dynamic tags add others without
   changing the source.
10. **`allowBound` (ADR-0005's reserved key) is implemented.** A content rule may list blocks (globs)
    that can be added to a locked-down post type only when bound to `taw/field`:
    - the editor offers them only as pre-bound variations;
    - `ContentLayer::checkSave()` refuses a newly added one that isn't bound.

    Presets don't change.

## Consequences

- Block themes can place TAW fields with core blocks, in templates, patterns and Query Loops,
  without a custom block per field. taw-gutenberg gets a reference template.
- One reader serves PHP templates, REST-adjacent code and bindings, so a field renders the same
  way everywhere.
- Both themes' golden hook snapshots grow by the registration hooks (+1 in Step 1, +1 in Step 2).
- The editor side depends on recent, partly `__experimental` WordPress APIs (the Attributes panel
  and the supported-attributes setting), so it needs a browser check after WordPress upgrades, as
  the data panel does.
- Rich-text fields preview as plain text in the canvas; the front end renders their HTML.

## Alternatives considered

- **Use `core/post-meta` and register un-prefixed meta keys.** Rejected: it would mean renaming or
  duplicating every stored key, and it returns raw values (IDs, JSON), not typed ones.
- **One custom block per field type** ("TAW Image", "TAW Text"). Rejected: it duplicates core blocks
  and their styling, and doesn't work with patterns, core block styles or Query Loops.
- **Two-way editing now.** Deferred (owner's choice): images, links and rich text need their own
  write paths, and the metabox and data panel already edit every type with validation.
- **Opt-in boot switch.** Rejected (owner's choice): the source is inert until a block uses it.

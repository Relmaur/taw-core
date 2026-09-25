# ADR-0009: A typed value API (`TAW\Taw`) and a `link` field type

## Status

Accepted (2026-09-25). Plan: umbrella `docs/plans/data-layer-phase-3.md` (data layer Phase 3, roadmap
row "3. Typed value API"). Builds on ADR-0003 (data layer), ADR-0004 (schema registry) and ADR-0008
(qualified registry, storage contexts).

## Context

Templates read TAW fields through static helpers that return the **stored** value:
`Metabox::get()`, `get_image_url()`, `get_repeater()`, `get_posts()`, `get_bool()`, `OptionsPage::get()`,
and `MetaBlock::getMeta()` / `getRepeater()` inside a block's `getData()`. Terms and users got
`Metabox::term()` / `user()` (`FieldReader`) in Phase 2.

A read-only survey of the four live sites (2026-09-25) counted about 400 reads, mostly `getMeta()`
(239) and `OptionsPage::get()` (114). Around them, templates repeat the same work by hand: attachment
IDs turned into URLs and alt text, checkboxes compared with `=== '1'`, `?: 'default'` fallbacks,
repeater rows mapped one by one, `post_select` JSON decoded, and links assembled from a URL field, a
label field and a "new tab" checkbox. Escaping is left to each template; wysiwyg values are printed
with `wp_kses_post()` (21 of 24 cases, without `wpautop()`).

Decoding already has one home: `Content\FieldCodec::decode()` gives REST `taw_<id>` fields, content
export and the data panel the same shapes. And since ADR-0008, `Metabox::fieldFor()` resolves a
field's config for a post type, taxonomy or users from a bare id, qualified id or meta key.

Phase 4 (Gutenberg Block Bindings) needs a single typed reader to resolve bound values.

Constraints: taw/core serves TAW sites only (ADR-0003 addendum); every existing reader, meta key and
stored format keeps working; no new hooks (golden hook snapshot identical); templates must not
fatal on a missing post or field.

## Decision

1. **One entry point, `TAW\Taw`:** `Taw::post($idOrPost = null)` (the current post when null),
   `Taw::term($id)`, `Taw::user($id)`, `Taw::option($field)` and `Taw::options($page)`. Each returns a
   reader whose `field($ref)` returns a `Value`. `$ref` is a bare id, a qualified id
   (`fieldset.field`) or a meta key, resolved through `Metabox::fieldFor()` (options: the options
   registry). `MetaBlock::fields($postId)` returns `Taw::post($postId)`.
2. **`Value` knows its field's type** and offers typed accessors: `raw()` (stored), `value()`
   (decoded by `FieldCodec`, the same shape as REST and export), `text()`, `bool()`, `int()`,
   `float()`, `or($default)`, `isEmpty()`, `image()`, `images()`, `post()`, `posts()`, `rows()`,
   `field($sub)` for group sub-fields, `link()`, `paragraphs()`. Value objects: `Image`, `PostRef`,
   `Rows` / `Row` (rows are typed from the repeater's sub-field configs), `Link`.
3. **Echo is escaped by default**, per type: `esc_html()` for plain values, `esc_url()` for urls,
   `wp_kses_post()` for wysiwyg (no `wpautop()`, matching how the fleet prints it; `paragraphs()`
   adds it), `wp_get_attachment_image()` markup for images, the escaped title for a single
   `post_select`, an `<a>` for links, and nothing for structured types. `raw()` is the escape hatch.
4. **Never throws.** A missing post, unknown field or empty value yields an empty value (`''`,
   `false`, `0`, `[]`, or an object whose `exists()` is false). An accessor that doesn't fit a known
   type converts best-effort and raises `_doing_it_wrong()` under `WP_DEBUG`. A field no metabox
   registers is still read from `_taw_<id>`, untyped.
5. **The static helpers, `getMeta()` and `FieldReader` stay** and are not deprecated. REST, content
   export and the data panel keep calling `FieldCodec` directly; they aren't rewired through the API.
6. **A `link` field type**, stored as one meta or option holding JSON
   `{"url": "…", "label": "…", "new_tab": true|false}` (empty when there's no URL and no label).
   It's a structured type like `repeater`: raw string in REST meta plus a decoded `taw_<id>` object,
   decoded by `FieldCodec`, carried by content interchange, edited by three inputs in the metabox and
   options page and a link control in the data panel. `required` means a URL.

## Consequences

- Templates and block `render.php` files get typed, escaped values in one call, and Phase 4 bindings
  get a reader to resolve through.
- Two ways to read a field coexist; the docs recommend the new API for new code and keep the old
  helpers documented.
- Escaping by default changes nothing existing (it's a new API), but people moving code to it must
  know the echo table; it's in the docs, and `raw()` is always available.
- `__toString()` can't throw, so every echo path is total over empty values, which is tested.
- Reads before metaboxes register (before `init:10`) are untyped; templates run later.
- The `link` type touches every layer that knows field types; the existing-type fixtures must stay
  byte-identical.

## Alternatives considered

- **Extend `Metabox` (`Metabox::post($id)->…`).** No new name, but ties the read API to the metabox
  engine, while options, terms and users are read too. Rejected for `TAW\Taw`.
- **Explicit-only output (no `__toString()`).** Nothing implicit, but every template line grows, and
  forgetting to escape stays possible with `raw()`-style habits. Rejected for escape-by-default.
- **Return raw values from `echo`, as today.** Keeps templates responsible for escaping. Rejected.
- **Rewire REST, export and the data panel through the API now.** One code path, but a larger,
  riskier release for no user-visible gain; they already share `FieldCodec`. Deferred.
- **Keep links as three fields.** No new type, but every site keeps assembling links by hand and the
  `Link` value would have nothing to read. Rejected.

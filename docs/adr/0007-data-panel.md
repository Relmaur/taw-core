# ADR-0007: A "TAW Data" sidebar in the block editor, opt-in per fieldset, never alongside the metabox

## Status

Accepted (2026-09-25). Plan: umbrella `docs/plans/vite-and-data-panel.md`, Track P. Builds on ADR-0004
(schema registry), the REST field registration in `Rest\FieldMetaRegistrar`, and ADR-0006 (Vite adapter,
for the panel's own build).

## Context

TAW fields (`Metabox`, and schema fieldsets compiled into it) appear in the block editor as classic
metaboxes under the canvas. The owner wants **one consistent "Data" place** in the editor, for every TAW
site (taw-theme and taw-gutenberg): a sidebar the user can open and close. It must be additive, opt-in
and **off by default**.

Facts that shape the design (taw/core v1.50.0, WordPress 7.1):

- **Every field is already on REST.** `FieldMetaRegistrar` registers scalar fields as post meta
  (`meta.<prefix><id>`), with the field's sanitizer and an `edit_post` auth check. Repeater, files and
  post_select fields are exposed as a decoded `taw_<id>` field that re-encodes through
  `Metabox::writeMeta()` on write. The block editor saves `meta` and top-level fields with the post.
- **The block editor saves metaboxes after the post.** It first saves the post (REST), then posts the
  metabox form separately. If a fieldset were in both places, the metabox's stale values would
  overwrite what the panel just saved.
- **`Metabox::save()` returns early without its nonce.** No metabox form means no nonce, so it never
  runs.
- **Some decisions happen when the editor loads.** The `show_on` callback and slug/template screens
  are evaluated then (`Metabox::register()`).
- **Validation isn't on the REST path.** `required` and `validate` callbacks run only in
  `Metabox::save()`, so REST writes skip them. `readonly` fields aren't saved from the form, but REST
  doesn't refuse them.
- **The metabox pairs `conditions` with its fields.** Operators are `==`, `!=`, `contains`, `empty` and
  `!empty` (AND), evaluated live in the browser (Alpine) and again on save.
- **Metaboxes cost the iframed canvas.** When any metabox is present, WordPress drops the iframed
  editor canvas.
- **The key `editor` is taken:** the field registry already uses it (Visual Editor opt-out).

## Decision

1. **Opt-in resolution. A fieldset shows as `panel` or `metabox`:**
   1. the fieldset's own `ui` (`new Metabox(['ui' => 'panel'])`, `Schema::fieldset()->ui('panel')`,
      JSON `"ui": "panel"`);
   2. else `TAW_DATA_UI` in wp-config.php;
   3. else the site default from a new schema kind **`settings`** (key `site`, e.g.
      `{"fieldsetUi": "panel"}`; `Schema::settings()`);
   4. else **`metabox`**.

   `settings` is a new kind so later site-wide data options have a home. It's validated by
   `schema:validate` and mirrored in the JSON Schema file.

2. **One place per fieldset per screen.**
   - In the block editor, a `panel` fieldset gets no `add_meta_box()`, so the overwrite above can't
     happen.
   - The classic editor, nav-menu item fields and options pages are unchanged. So is any screen
     where `use_block_editor_for_post()` is false.
   - A fieldset is never split between the two.

3. **Inert unless used.** `Boot::data()` adds one check on `init` (priority 999, after fieldsets
   compile at init:8 and MetaBlocks at init:10). If no fieldset resolves to `panel`, the check
   removes itself and nothing else is hooked, so taw-theme's golden hook snapshot stays identical.
   It's wired by `Boot::data()`, because it's data UI, not presentation. The Metabox asks through
   the `taw_metabox_ui` filter, so it never depends on the panel.

4. **Storage and transport don't change.**
   - The panel reads and writes the existing REST fields, and changes save with the post (Save,
     autosave and the unsaved-changes warning all work as usual).
   - No new endpoint.
   - The same meta keys and the same sanitizers (`Metabox::sanitizeValue()` / `writeMeta()`), so
     front-end reads (`Metabox::get()`) and content interchange are unaffected.

5. **Server-side checks on REST saves of `panel` fieldsets** (`rest_pre_insert_{post_type}`):
   - `required` and `validate` callbacks run.
   - Fields whose `conditions` aren't met are skipped, as in `Metabox::save()`.
   - Writes to `readonly` fields are refused.
   - A failure is a 400 naming each field (`taw_data_invalid`, `data.fields`). The panel shows it as
     an editor notice and marks the fields.

   Fieldsets that stay metaboxes keep today's REST behaviour.

6. **A descriptor, not code, crosses to the browser.**
   - For the edited post, PHP sends a JSON description of the applicable panel fieldsets: fields,
     tabs, groups, repeater sub-fields, conditions, `readonly`/`required`, and each type's options.
   - It never includes PHP callables.
   - `show_on` is evaluated server-side when the editor loads.
   - Template-scoped screens are sent with their template list, so the panel follows a template
     change made in the editor without a reload.

7. **The UI.**
   - A `PluginSidebar` named "TAW Data", with its own icon in the editor header. Clicking it opens
     and closes the sidebar, and it can be pinned. There's a matching ⋮ menu entry.
   - Inside: one section per fieldset (its title and icon), then its tabs, groups and fields.
   - Conditions are evaluated live with the metabox's operators.
   - `required` fields also lock saving on the client (`lockPostSaving`) until they're filled.

8. **Every field type before the first release.**
   - **Simple fields:** text, textarea, url, number, range, select, checkbox, color, datepicker.
   - **Media:** image and files (media library).
   - **Other types:** icon (the Icons REST endpoint), post_select (core-data search), gradient_text,
     hubspot_form.
   - **Structure:** group, and repeater (add, remove, reorder, collapse, nested conditions).
   - **If a fieldset contains a type the panel doesn't know, the whole fieldset stays a metabox.**
     It's never half in each place.

9. **`wysiwyg` uses a mini block editor, not TinyMCE, a third-party editor, or one built from
   scratch** (owner's decision):
   - **How it runs:** a nested `BlockEditorProvider` (its own sub-registry, so the post's blocks are
     untouched), in a large modal opened from a preview in the sidebar.
   - **Loading:** `wp.autop()` (legacy values often have no `<p>`), then `rawHandler()` (as
     "Convert to blocks"). Shortcodes stay shortcodes, and markup the blocks can't represent lands in
     Custom HTML.
   - **Saving:** each block's `getBlockContent()` joined, so plain HTML with no block comments.
   - **Which blocks:** a curated set, overridable per field (`'blocks' => [...]`). `teeny` fields get a
     single `RichText` box instead.
   - **Opening a field never writes it:** only an edit converts and saves.

10. **The build.**
    - TSX source in `resources/data-panel/` with its own `package.json`, built with Vite through
      `Assets\Vite` and the shared `taw-vite.mjs`.
    - The built output (with its manifest) is **committed** to `assets/data-panel/`, so Composer
      installs need no Node.
    - CI fails if the committed build differs from a fresh one.
    - Vitest and React Testing Library test the components.
    - The Node tooling is kept out of the Composer package with `.gitattributes export-ignore`.

11. **Scope: post meta in the post editor.** Options pages, term and user fields, nav-menu item fields
    and the Site Editor are out of scope. They can be added later on the same descriptor.

## Trade-offs

- **Two renderers for one field set (PHP metabox, React panel).** Accepted: the descriptor is the
  only contract, a test per field type pins its shape, and unknown types fall back to the metabox as
  a whole fieldset.
- **A committed build in a PHP package.** Accepted over requiring Node on every site. The CI freshness
  check keeps it honest.
- **`wysiwyg` conversion.** The first edit through the panel rewrites legacy HTML into block-style HTML
  (e.g. `<img class="alignleft">` → `<figure class="wp-block-image alignleft">`), which can look
  slightly different on the front end. This is mitigated three ways:
  - opening never writes;
  - before release, real `wysiwyg` values from the live sites' Local copies are round-tripped
    read-only and the differences shown to the owner;
  - the behaviour is documented.
- **Block-editor APIs change often** (`PluginSidebar` moved to `@wordpress/editor` in 6.6, nested
  editors are less common). This is built against 7.1, and it joins the "re-check in a browser after a
  WordPress upgrade" list, with the content-only editing script.
- **REST validation covers panel fieldsets only.** Headless writes to metabox fieldsets stay as they
  are, so the panel doesn't change behaviour for anyone who didn't opt in.

## Consequences

- With the panel on and no metaboxes left, WordPress brings back the iframed editor canvas.
- New docs: a taw-docs "Data panel" page, README sections, and CLAUDE.md conventions (the placement
  rule, the descriptor, the build check).
- taw-gutenberg keeps `metabox` by default. Its README shows how to switch a site or a fieldset to the
  panel.
- The editing-policy content lock (`contentOnly`) doesn't affect the panel: it edits meta, not
  blocks. Whether locked clients should be able to edit data fields is left to WordPress's
  `edit_post` capability, as today.

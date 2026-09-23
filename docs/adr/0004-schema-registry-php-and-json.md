# ADR-0004: One schema registry for post types, taxonomies, fieldsets and options pages — defined in PHP or JSON, compiled to the existing engine

## Status

Accepted (2026-09-23). Plan: umbrella `docs/plans/data-layer.md`, Phase 1 Steps 3–4. Depends on
ADR-0003 (`Boot::data()`).

## Context

A data layer needs to *define* data, not only store it. Today taw/core can only define fields by
constructing `new Metabox([...])` (usually inside a PHP block's `registerMetaboxes()`, or in the
theme's `inc/options.php`) or `new OptionsPage([...])`. It has no primitive for custom post types
or taxonomies. The only `register_post_type` in the package is its own internal `taw_submission`
(`src/Core/Form/SubmissionsHandler.php:48`). And field definitions are scattered across block
classes instead of living in one place.

VX Framework, the reference product, defines post types, taxonomies, fieldsets and option pages
as configuration, either PHP builders or JSON files, and reads them into one model. The user
wants the same: **PHP fluent API and JSON files, both feeding one registry**.

Constraints from the existing code:

- **Hook timing.** `register_post_type` must run on `init`. `MetaBlock` compiles its metaboxes on
  `init` at priority 10 (`MetaBlock.php:31`). `FieldMetaRegistrar` runs at `init:20`
  (`FieldMetaRegistrar.php:50`) and resolves post types through
  `Metabox::screensToPostTypes()`, which calls `post_type_exists()`. So custom post types must
  exist before `init:20`, or their fields silently fall back to `page`.
- **Collisions.** `Metabox::$fieldRegistry` is keyed by the *bare* field id
  (`Metabox.php:196`), so two metaboxes with the same field id overwrite each other in the
  registry that REST, the exporter and the CLI read.
- **Backward compatibility.** `new Metabox([...])`, `MetaBlock::registerMetaboxes()`,
  `new OptionsPage([...])` and every static reader/writer must keep working unchanged, and so
  must the `_taw_` meta-key default.
- **Minimal dependencies**, per the TAW philosophy: no third-party JSON Schema library.

## Decision

1. **Registry.** `TAW\Core\Schema\Registry` holds definitions keyed by qualified names:
   `post_type:book`, `taxonomy:genre`, `fieldset:book_details`, `options_page:site`.
   - PHP definitions are added on the `taw_schema_register` action, via `Schema::postType()`,
     `::taxonomy()`, `::fieldset()`, `::optionsPage()` and `Field::text()`, `::image()`,
     `::repeater()`, and so on.
   - Each definition records its `Source` (`php`, or `json:<path>`).
2. **Fieldsets compile into the existing engine.** Every definition normalizes to the same array
   `new Metabox([...])` / `new OptionsPage([...])` already accept, and `Compiler` constructs those
   objects.
   - Save path, admin UI, `writeMeta()`, the field registry, REST registration, content export
     and the visual editor are all reused unchanged.
   - `Field::*` builders only emit those arrays. `->with([...])` passes any existing key through
     (`show_on`, `tabs`, `icon`, `editor`, …), so the fluent API can express everything the array
     API can from day one.
   - Meta keys stay `_taw_{field}` by default, overridable per fieldset with `prefix`.
     `FieldMetaRegistrar` already builds keys as `prefix . fieldId`, so REST follows.
3. **Hook priorities on `init`:**

   | Priority | Step |
   |---|---|
   | 1 | Collect: fire `taw_schema_register`, load JSON, validate |
   | 5 | Freeze the registry (a later `add()` triggers `_doing_it_wrong`), then `register_post_type` |
   | 6 | `register_taxonomy` |
   | 8 | Compile fieldsets and options pages |
   | 10 | Existing `MetaBlock::initMetaboxes()` |
   | 15 | `CollisionReport` |
   | 20 | Existing `FieldMetaRegistrar` |
   | 99 | Rewrite fingerprint: when the hash of all post type + taxonomy definitions differs from option `taw_schema_rewrite_hash`, call `flush_rewrite_rules(false)` in admin requests only |

4. **JSON format**, versioned from day one:
   - One entity per file: `{"version": 1, "kind": "post_type|taxonomy|fieldset|options_page", "key": "…", …}`.
   - `args` is passed through to `register_post_type`/`register_taxonomy` untouched. The
     validator checks structure, not WordPress's own arguments.
   - Field `type` must be one of the types Metabox renders: `text, url, number, textarea,
     wysiwyg, select, checkbox, color, datepicker, range, image, icon, files, gradient_text,
     hubspot_form, group, post_select, repeater`.
   - The contract ships as `resources/schema/taw-schema-1.0.json` (JSON Schema, for editor
     autocomplete). Validation at runtime is a hand-written `Validator`. A unit test asserts the
     schema file's type enum equals `Validator::FIELD_TYPES`, so they can't drift.
5. **Discovery.**
   - Folders: `{stylesheet}/taw-schema`, `{template}/taw-schema` (deduplicated by realpath),
     `WP_CONTENT_DIR/taw-schema`, plus the `taw_schema_paths` filter.
   - Files `*.json` and `*/*.json` (one level of subfolders such as `post-types/`).
   - In production, the normalized result is cached in a transient keyed by paths, file mtimes,
     sizes and the installed taw/core version, and validation is skipped on a cache hit.
6. **Precedence**, highest first: PHP, child-theme JSON, parent-theme JSON, `wp-content` JSON. A
   higher source replaces a lower one; without `"override": true` it also triggers a `WP_DEBUG`
   notice naming both sources.
7. **Collisions are detected, not fixed, in this phase.** `CollisionReport` flags a schema field
   id that clashes with another fieldset or with a legacy Metabox field from a different metabox,
   via `_doing_it_wrong()` plus an admin notice when `WP_DEBUG` is on. Clashes between two legacy
   fields are reported only by `bin/taw schema:validate`, so taw-theme's runtime output is
   unchanged.
8. **CLI.** `bin/taw schema:validate [--json]` validates JSON definitions without booting
   WordPress. The Schema classes therefore omit the `ABSPATH` guard, like `Content\*` and
   `Framework`.

## Trade-offs

- **Compile to Metabox vs. a new field engine.** *Rejected:* a fresh engine with its own storage
  and UI. It would give a clean model, but duplicate 3,800 lines of proven save/sanitize/UI code
  and fork the two engines' behavior. Compiling keeps one engine. The cost: the schema inherits
  Metabox's limits (bare-id registry, post-only locations) until later phases lift them.
- **Detect collisions vs. fix the registry now.** Fixing it (a qualified `metabox_id.field_id`
  registry) changes how `FieldMetaRegistrar`, the exporter and the importer resolve fields,
  which is a riskier change for existing sites. It is deferred to Phase 2 on purpose.
- **Hand-written validator vs. a JSON Schema library.** A library would validate the shipped
  schema file directly, but adds a dependency, against the TAW philosophy. The drift test keeps
  the two in sync instead.
- **PHP beats JSON.** Code is where conditional logic lives, so it gets the last word. JSON files
  can't override PHP definitions, which is documented.
- **Rewrite flush automation.** A soft flush when definitions change is convenient, but it is
  work on an admin request. It is limited to when the fingerprint changes, and never runs on the
  front end.

## Consequences

- New: `src/Core/Schema/{Registry, Schema, Field, Source, Compiler, CollisionReport, JsonLoader,
  Validator}.php`, `src/Core/Schema/Definition/{PostType, Taxonomy, Fieldset, OptionsPage}.php`,
  `resources/schema/taw-schema-1.0.json`, `src/CLI/SchemaValidateCommand.php`. `Boot::data()`
  wires the registry.
- Released as semver minors: v1.43.0 (registry + PHP API), v1.44.0 (JSON + CLI).
- Existing consumers see only new, additive `init` callbacks. Verified against the golden hook
  snapshot from ADR-0003.
- Documented limitation: post types registered by *other* code after `init:20` resolve their
  fields to `page` (pre-existing `FieldMetaRegistrar` behavior). `schema:validate` warns when a
  fieldset targets an unknown post type.
- Phase 2 builds on this: a qualified registry, term and user fieldsets, and options over REST.
  Later phases hang the typed value API, Block Bindings, the loop block and native editor panels
  off the same registry.

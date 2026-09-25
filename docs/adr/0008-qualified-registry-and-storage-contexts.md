# ADR-0008: A qualified field registry, storage contexts for terms and users, options over REST, and the `taw-core` text domain

## Status

Accepted (2026-09-25). Plan: umbrella `docs/plans/data-layer-phase-2.md` (data layer Phase 2). Builds
on ADR-0003 (data layer, `Boot::data()`), ADR-0004 (schema registry) and ADR-0007 (data panel).

## Context

Phase 1 knowingly left four gaps (umbrella `docs/plans/data-layer.md`, roadmap row 2):

- **The field registry is keyed by bare field id.** `Metabox::$fieldRegistry[$id]` is overwritten
  when two fieldsets use the same id, so the second fieldset's config (type, sanitizer, post types)
  replaces the first's for everything that reads the registry: REST meta registration, export and
  import, the visual editor, `fields:set`, the data panel's stored-value reader. Phase 1 only reports
  collisions (`Schema\CollisionReport`).
- **Export and import only know the `_taw_` prefix.** A fieldset with another prefix is silently
  missing from content snapshots.
- **Fieldsets only target posts.** The metabox engine reads and writes post meta directly. Terms and
  users have no TAW fields; term and user export can't carry them either (term export skips
  underscore keys, user export copies a fixed list).
- **Options pages have no REST exposure.** They call `register_setting()` without `show_in_rest`.

A fifth, older issue: taw-core's own admin strings use the **`taw-theme` text domain**, borrowed from
the classic theme. taw-gutenberg's domain is `taw-gutenberg`, so those strings can't be translated
there, and the live classic sites translate some of them inside their own theme files.

Constraints: taw/core serves TAW sites only (ADR-0003 addendum); every existing API, meta key,
option name and stored format keeps working; taw-theme's golden hook snapshot stays identical; no
site changes until it opts into a new feature.

## Decision

1. **Qualified field ids.** Every registered field is also indexed as `"{fieldset}.{field}"` (group
   sub-fields `"{fieldset}.{group}_{sub}"`), with its prefix, meta key and targets. The bare index is
   kept exactly as it is for existing callers (`Metabox::get()`, `get_field_config()`), and
   collisions are still reported, now as "ambiguous for bare-id calls" rather than "one config lost".
2. **One lookup answers "which fields apply here".** `Metabox::fieldsFor(string $objectType, string
   $subtype)` returns meta key → config for `post`/`term`/`user` and a post type or taxonomy. REST
   registration, export, import, the data panel and the CLI use it instead of bare ids, so each
   fieldset keeps its own config and any prefix works.
3. **Content interchange 1.2.** A record's `fields` keys stay the bare id for `_taw_` fields (so 1.1
   documents and 1.2 documents from `_taw_`-only sites are identical) and are the full meta key for
   other prefixes. Term and user records gain `fields` the same way (users only with
   `--with-users`). The importer accepts 1.0–1.2.
4. **Storage contexts.** `Metabox` reads and writes values only through a store
   (`Metabox\Store\MetaStore`: `get`, `set`, `delete`), with `PostMetaStore`, `TermMetaStore` and
   `UserMetaStore`. One renderer, one sanitizer and one validator serve every object type; nothing is
   duplicated per type (the P4 repeater bug came from two write paths drifting apart).
5. **Targets in `on`.** A fieldset's `on` accepts post types (as today), `"term:<taxonomy>"` and
   `"user"`, in PHP and JSON. A fieldset may mix them. Term fieldsets appear on the taxonomy's Add and
   Edit screens and save with the `edit_term` capability; user fieldsets appear on Profile, Edit User
   and Add New User and save with `edit_user`. Both register their meta for REST like posts do, and
   get `Metabox::get_term()` / `Metabox::get_user()` readers. The data panel stays post-only (it's a
   block-editor feature).
6. **Options over REST is opt-in per page.** Key `rest`: `"private"` passes a schema to
   `register_setting()` (`show_in_rest`), so the page's options appear in core's `/wp/v2/settings`
   (administrators); `"public"` also adds a read-only `GET taw/v1/options/<page>` returning decoded
   values. Default: neither.
7. **Text domain `taw-core`.** All taw-core strings (PHP and the data panel's JS) use `taw-core`,
   loaded from translations bundled in the package. When taw-core has no translation for a string,
   a `gettext` fallback asks the `taw-theme` domain, so translations a site already ships keep
   working. The bundled es_MX starts from the fleet's existing translations of taw-core strings.

## Consequences

- Shared field ids across fieldsets become safe for everything except the bare-id convenience
  readers, which stay as they were (and documented as ambiguous when ids collide).
- Custom prefixes, terms and users travel in content snapshots; old snapshots import unchanged.
- The metabox engine gets one indirection (the store). Its post output must not change: a rendered
  HTML fixture with every field type is captured before the refactor and compared after it.
- Term Add forms submit over AJAX; field types that need scripts after the AJAX refresh are checked
  in a browser, and any that can't work there show on the Edit screen only.
- `public` options expose every field on the page to anonymous readers; the docs say so, and pages
  must opt in one by one.
- The text-domain change touches ~200 strings; the fallback runs only for untranslated strings.

## Alternatives considered

- **Replace the bare index with qualified ids.** Cleaner, but breaks every theme calling
  `Metabox::get($postId, 'field')`. Rejected.
- **Separate `TermFields` / `UserFields` classes.** Less risk to the metabox engine, but a second
  renderer and write path to keep in sync. Rejected for storage contexts.
- **Rename the text domain without a fallback.** Spanish admin screens on sites that translate
  taw-core strings in their theme would regress. Rejected.
- **Options readable by anyone who can read the site, by default.** Options pages can hold API keys
  and other secrets. Rejected for an explicit per-page opt-in.

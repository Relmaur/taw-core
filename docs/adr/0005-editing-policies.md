# ADR-0005: Editing policies — a layered, preset-driven block-editor lockdown, booted separately from the data layer

## Status

Accepted (2026-09-23). Plan: umbrella `docs/plans/editing-policies.md` (roadmap item E of
`docs/plans/data-layer.md`). Depends on ADR-0003 (`Boot::data()`) and ADR-0004 (schema registry).

## Context

TAW client sites need the block editor locked down, and how much depends on the client. Some
clients should compose pages freely. Others should only change text and images inside layouts the
developer built. Today the only implementation is `app/Setup/ThemeMode.php` in the
ml-theme--custom-gutenberg theme (its ADR-0005 and ADR-0008), which does four things:

- a block allow-list
- a starting template for new posts
- `templateLock: 'all'` for users below `edit_theme_options`
- no custom colors or font sizes

It is one theme's code, has one on/off level, and covers only post content. taw-gutenberg, the
FSE theme that consumes taw/core, has nothing like it. The owner wants the tooling in taw/core,
shared by every TAW site, and wants lockdown **in several layers, chosen per client**.

Constraints and facts (WordPress 7.1.2, checked in core):

- **Post content.** The editor reads `allowedBlockTypes` from `allowed_block_types_all`. It reads
  `template`, `templateLock` (`insert`, `contentOnly`, `all`), `canLockBlocks`,
  `codeEditingEnabled`, `enableOpenverseMediaCategory` and `supportsTemplateMode` from
  `block_editor_settings_all` (`wp-includes/block-editor.php`, `wp-admin/edit-form-blocks.php`).
- **Site structure.** The FSE post types (`wp_template`, `wp_template_part`, `wp_global_styles`,
  `wp_navigation`) map every capability to `edit_theme_options`
  (`wp-includes/post.php:471`), so `register_post_type_args` can re-map them.
- **Design tokens** come from theme.json and can be adjusted through
  `wp_theme_json_data_theme`. theme.json data is global and cached, not per user. Setting
  `appearanceTools: true` expands into individual flags, so one toggle can't switch them off
  later (lesson from ml-theme's `applyDesignSettings`).
- **Clients are often Administrators.** A bypass based on `edit_theme_options` or `manage_options`
  would not lock them.
- All of the above is editor UI. A user who calls the REST API directly can send any block markup
  unless the server checks it.
- taw/core serves TAW sites only (ADR-0003 addendum). Its data boot must never lock an editor by
  accident.

## Decision

1. **Four layers**, each resolved independently:
   - **content**: post content, per post type (allowed blocks, starting template, template lock)
   - **site**: site structure (templates, template parts, Global Styles, Navigation, the Site
     Editor)
   - **design**: theme.json tokens
   - **features**: editor features (code editor, Custom HTML, block directory, Openverse, core and
     remote patterns, block-lock UI)
2. **A preset ladder**: `open` → `guided` → `structured` → `locked`. The plan's table defines
   each layer at each level. It lives in code (`Editing\Presets`) and is covered by a snapshot
   test, so a preset never changes silently.
3. **Declared in the schema registry** as a new kind, `editing`: one per site, key `site`, in PHP
   (`Schema::editing()`) or `taw-schema/*.json`. The same source precedence as ADR-0004 applies.
   - Post types can carry content rules (`PostType::editing()`, JSON `"editing"`).
   - Each layer is either a level string or an object of overrides.
   - **Resolution order, lowest first:** preset → layer level → individual keys → post-type
     `editing` → site `content` map.
   - The `TAW_EDITING_PRESET` constant replaces only the preset, so one theme can serve clients
     at different levels.
   - The JSON format stays at version 1, because this change is additive.
   - `allowBound` is reserved for Phase 4 (Block Bindings) and rejected until then.
4. **Bypass.** A user bypasses when their login is in `TAW_EDITING_BYPASS_USERS` (wp-config.php) or
   they have `taw_unlock_editing`, the policy's `bypass.capability`, granted through
   `user_has_cap`. It is **not** tied to a core role. With no bypass configured, nobody bypasses
   (safe by default), and the admin screen warns about it. The bypass applies to the content,
   features and site layers. The design layer is site-wide for everyone (decision 6).
5. **Enforcement boundary:**
   - **Enforced on the server:**
     - Site-structure capabilities: the edit, create, delete and publish capabilities of the FSE
       post types are re-mapped to `taw_edit_site_<area>`. Read capabilities are left alone, so
       the post editor still loads global styles.
     - The block allow-list: `rest_pre_insert_{post_type}` rejects blocks that are newly added
       and not allowed. Blocks already in the saved post are exempt, so legacy content still
       saves.
   - **Editor guardrails only:** template locks and design tokens.
6. **Design tokens are site-wide.** When the design layer is locked, Administrators and bypass
   users also pick only from presets. Developers change tokens in theme.json.
7. **A separate boot, `Boot::editing()`.** It is idempotent and calls `Boot::data()` itself.
   `Theme::boot()` never calls it, so taw-theme's hooks don't change.
8. **Recovery.**
   - `TAW_EDITING_OFF` makes `Boot::editing()` a no-op.
   - wp-cli runs without a user and is never restricted.
   - A read-only screen, Tools → TAW Editing, shows the effective policy and its source.

## Trade-offs

- **Not a security boundary against Administrators.** An Administrator can install a plugin that
  removes the filters. This protects against accidents and clients doing things they shouldn't,
  which is the actual need. Stated here so nobody relies on it for more.
- **Design locks also bind the developer in wp-admin.** The alternative, per-user theme.json
  filtering, fights WordPress's global-settings cache. It was rejected as fragile.
- **Template locks aren't checked on save.** Validating block *structure* against `contentOnly` or
  `all` means re-implementing Gutenberg's lock semantics in PHP, which is easy to get wrong.
  Rejected in favor of guardrails plus server-side allow-lists.
- **Rejected: extending ml-theme's `ThemeMode` in each theme.** taw-theme and future themes
  couldn't share it, which goes against the reason for owning the tooling in taw/core.
- **Rejected: an admin settings screen as the source of truth.** Config in the database doesn't
  travel with the code and can't be reviewed in a diff. The screen is read-only.
- **Rejected: bypass by `edit_theme_options`.** It doesn't lock clients who are Administrators.

## Consequences

- New code:
  - `src/Core/Editing/*`: Presets, Policy, Resolver, Bypass, and one class per layer.
  - `src/Core/Schema/Definition/EditingPolicy.php`.
  - `Boot::editing()`.
  - Validator and JSON Schema additions.
- **New public names** (a compatibility obligation once shipped): the constants
  `TAW_EDITING_PRESET`, `TAW_EDITING_BYPASS_USERS` and `TAW_EDITING_OFF`; the capabilities
  `taw_unlock_editing` and `taw_edit_site_{templates,template_parts,global_styles,navigation}`;
  the preset names; and the JSON `editing` kind.
- taw-gutenberg adopts it through a `Bootable` service (its ADR-0002). ml-theme--custom-gutenberg
  keeps its own `ThemeMode` and doesn't adopt this.
- Deliberately not done yet:
  - per-role levels (only the one bypass)
  - `allowBound` (Phase 4)
  - checking structural locks on save
  - adoption by taw-theme (it can opt in later by calling `Boot::editing()`)

## Addendum: site-structure enforcement is a REST guard, not capability re-mapping (v1.47.0)

Decision 5 said the site-structure layer would re-map the FSE post types' capabilities to
`taw_edit_site_<area>` through `register_post_type_args`. Building it showed that can't work:

- `wp_template`, `wp_template_part`, `wp_global_styles` and `wp_navigation` are registered by
  `create_initial_post_types()` at `init:0`, before the policy can be resolved (the schema registry
  freezes at `init:5`).
- The REST controllers that the Site Editor saves through check `current_user_can('edit_theme_options')`
  directly for writes (for example `WP_REST_Templates_Controller::permissions_check()`), not the post
  type's capabilities.

**What's built instead:** `Editing\SiteLayer` refuses **writes** (anything but GET/HEAD/OPTIONS) to
`/wp/v2/templates`, `/wp/v2/template-parts`, `/wp/v2/global-styles` and `/wp/v2/navigation` at
`rest_pre_dispatch`, which also runs for every request inside a `/batch/v1` call. It returns 403
`taw_editing_site_locked`. Reads are never blocked. `templateMode` sets `supportsTemplateMode: false`,
and `siteEditor: false` hides Appearance → Editor / Patterns and refuses `site-editor.php`.

**Consequences:**
- The public names `taw_edit_site_{templates,template_parts,global_styles,navigation}` are **not**
  introduced. The bypass is still the one capability (`taw_unlock_editing` by default).
- Non-REST ways to change the same things (the Customizer's Additional CSS, direct DB edits, WP-CLI)
  aren't covered. The Site Editor itself only saves through REST.

## Addendum: `lock: contentOnly` is enforced as `all` plus an editor script on WordPress 7.1 (v1.48.0)

The first browser check (on WordPress 7.1.2) showed that the `structured` preset didn't lock layout. The
editor received `templateLock: contentOnly`, but 7.1 **exempts** a page-level `contentOnly` lock:
`canRemoveBlock()` and `canInsertBlockType()` only refuse when `rootTemplateLock && rootTemplateLock !== "contentOnly"`.
In 7.1, `contentOnly` only applies to "section" blocks: patterns, template parts, and blocks that carry
their own `contentOnly` lock under an unlocked parent. So `structured` pages could still be restructured
freely.

**Decision (owner's choice among three):** keep `lock: contentOnly` as the policy's meaning (edit text
and media, nothing else), and implement it directly:

1. `ContentLayer` sends `templateLock: all`, which 7.1 enforces: no inserting, removing or moving blocks.
2. For a locked user in the post editor on a post type whose rule is `contentOnly`, `ContentLayer`
   enqueues `assets/editing-content-only.js` (handle `taw-editing-content-only`, depends on `wp-data` and
   `wp-block-editor`). It puts every block in the `contentOnly` **editing mode** through the public
   `core/block-editor` action `setBlockEditingMode()`, which hides block design tools and movers.
3. The editor resets editing modes while it mounts the canvas, so the script re-applies them whenever a
   block isn't `contentOnly`, capped at 10 attempts per block so it can't fight a deliberate override.

Rejected alternatives:
- `structured` = `insert`: clients could still reorder blocks.
- `structured` = `all`: the same as `locked` for pages.

**Verified in the browser:** every block is in `contentOnly` mode, groups included; text stays editable;
the toolbar is down to formatting; the sidebar is down to Typography; blocks can't be removed or moved;
no console errors.

**Consequences:** taw/core now ships a small plain-JS editor asset (no build step). It relies on
`setBlockEditingMode()`, so a future WordPress change to editing modes needs a browser re-check.
`Blocks`, the REST save check and the policy format are unchanged.

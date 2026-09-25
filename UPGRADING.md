# Upgrading taw/core on an existing TAW site

For the agent (or developer) updating a live TAW site's `taw/core`. It ships inside the package, so after
an update it's at `vendor/taw/core/UPGRADING.md` in the site's theme.

Every 1.x release is a minor or patch release: existing theme code keeps working, and stored data never
changes. A few releases changed defaults, though (a route hidden, a feature made opt-in, fields exposed
over REST). This file lists them by the version you're coming from, so you can check exactly what applies
to the site you're updating.

## How to upgrade

1. **Find the installed version:** `composer show taw/core | grep versions` (or `composer.lock`).
2. **Theme scaffold first (optional, recommended):** run the `update-theme` skill (`php bin/taw sync`).
   It syncs `functions.php`, `bin/`, CI and the framework skills, and never touches `Blocks/`, `inc/` or
   templates.
3. **Update the package:** `composer update taw/core`. The theme's constraint (`"taw/core": "^1.0"`)
   allows every 1.x release.
4. **Read the sections below** for every version newer than the one you came from, and do the checks
   marked **Check**.
5. **Verify:**
   - `composer run test`, and `composer run phpstan` when the theme has it;
   - load the front page and a few pages with forms and blocks (the `visual-check` skill);
   - open wp-admin screens with metaboxes and options pages.
   With `WP_DEBUG` on, read `wp-content/debug.log` for new notices.
6. **Commit `composer.lock`**, and only when the site's rules allow it. The live site gets the update on
   its next deploy.

Nothing here changes stored data. Meta keys, option names and stored formats are the same in every 1.x
release, so a rollback is `composer update taw/core:<old version>` (or restoring `composer.lock`).

## Quick check: what applies to you

| Coming from | Worth checking |
|---|---|
| < v1.24 | The public users REST route is hidden (user-enumeration hardening) |
| < v1.25 | TAW metabox fields show up over REST; a Tools → TAW Data screen appears |
| < v1.30 | The RAG chatbot needs `RagSettings::enable()` |
| < v1.39.1 | Forms reject an empty required email (a bug fix) |
| < v1.41 | Metaboxes with `tabs` now render as tabs |
| < v1.42 | Performance tweaks register only when the theme boots |
| < v1.56 | taw-core's text has its own translations (the site's own still win) |
| any | New, opt-in features you may want (see the end) |

## Per version

### v1.24.0: the public users REST route is hidden
`Theme::boot()` calls `Hardening::hideUsersEndpoint()`: anonymous requests to `/wp/v2/users` and
`/wp/v2/users/<id>` get a 404. Logged-in access and `/wp/v2/users/me` are unchanged.

**Check:** does the front end or an integration fetch users anonymously?
`grep -rn "wp/v2/users" --include=*.{php,js,ts,tsx} . | grep -v vendor`.
If it does, opt out in `inc/customizations.php`:
`add_filter('taw_security_hide_users_endpoint', '__return_false');`.

### v1.25.0: fields over REST, content interchange
- **Every TAW metabox field is registered as REST post meta** (`show_in_rest`). Values of published
  posts appear under `meta` in `/wp/v2/<type>/<id>`, as WordPress shows meta. Structured fields also
  appear decoded as `taw_<id>`. Writes need `edit_post`.
- **Tools → TAW Data** (export and import of a content snapshot) and `GET /wp-json/taw/v1/content/export`
  (capability `export`) appear.

**Check:** if a field holds something that must not be public for published posts, keep it out of
metaboxes, or opt out of REST fields entirely: `add_filter('taw_register_meta_in_rest', '__return_false');`.
Secrets always belong in `wp-config.php` constants.

### v1.30.0: the RAG chatbot is opt-in
**Check:** if the site uses the chatbot (Settings → TAW Chatbot, `POST /taw/v1/chat`), call
`TAW\Core\Rag\RagSettings::enable();` in `inc/customizations.php` before the theme boots. Otherwise it's
off.

### v1.32.1: rich text in metaboxes and options pages
Fixes the admin form breaking when a rich-text value contained quotes (entities are decoded before the
form's initial state is built). Admin only; **nothing to do.**

### v1.33.0 – v1.40.0: forms
All of these are additive:
- `on_submit` receives the submission ID as an optional second argument;
- help popovers and `help_modal`, `submit_icon`, `required_if`;
- several `to_self` recipients;
- per-form `class`, `button_class` and `--taw-span`.

v1.39.1 fixes a bug: a **required `email` field left empty is now rejected** instead of saving blank.
**Check:** submit each form once (the `visual-check` skill or by hand).

### v1.41.0: metabox tabs render
A `Metabox` config with `'tabs' => [...]` was rendered as a flat list before; it now shows tabs. Fields
no tab lists stay visible above the tab bar, so nothing disappears and saving is unchanged.
Options-page tabs always worked.

**Check:** `grep -rn "'tabs'" Blocks/` shows which metaboxes change; open one of those posts in wp-admin.

### v1.42.0: data layer boot
`Theme::boot()` / `Theme::bootstrapFullSite()` now also run `TAW\Core\Boot::data()`. It adds nothing new
for a site already past v1.25. The Performance tweaks (block CSS dequeue, emoji removal, preloads)
register when the theme boots, not when Composer loads.

**Check:** only if the theme never calls `Theme::boot()` or `bootstrapFullSite()` (no TAW site does)
does it need `define('TAW_PERFORMANCE_AUTOLOAD', true);` in `wp-config.php`.

### v1.43.0 – v1.48.1: schema registry, editing policies
Both are opt-in: post types, fieldsets and options pages can now be defined in PHP or
`taw-schema/*.json`, and editing policies apply only through `Boot::editing()`. Neither changes an
existing site. With `WP_DEBUG` on, a schema field that shares an id with another field gets a notice.

### v1.49.0: shared Vite adapter
It adds `TAW\Core\Assets\Vite` for block themes. `ViteLoader` moves its dev-server check into
`Assets\DevServer` and behaves as before, including the hot-file-only detection (since v1.16.84).
**Nothing to do.**

### v1.51.0: the data panel
TAW fields can show in a block-editor sidebar instead of metaboxes. It's **off unless a fieldset asks for
it**, so a classic site sees no change. Repeater values written through REST, the visual editor,
`fields:set` or `content:import` now keep nested repeaters, files and post selects inside rows; before,
those were lost.

### v1.52.0 – v1.54.0: qualified fields, terms, users
- Fields have qualified ids (`fieldset.field`).
- Fieldsets can target terms (`term:<taxonomy>`) and users (`user`).
- Content snapshots use format 1.2 (older snapshots still import).

All of it is additive. The one fix: `fields:set` and `content:import` wrote group sub-fields to the
wrong key (`_taw_{sub}` instead of `_taw_{group}_{sub}`).

**Check:** if an agent used `fields:set` on a group sub-field before v1.52.0, look for stray `_taw_{sub}`
meta:
`php bin/taw wp db query "SELECT meta_key, COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '\_taw\_%' GROUP BY meta_key"`
(the table prefix may differ).

### v1.55.0: options pages over REST
It's opt-in per page (`'rest' => 'private' | 'public'`); pages without it expose nothing.

### v1.56.0: taw-core's own translations
taw-core's admin and form text uses the `taw-core` text domain and ships its own Spanish translation.
**The theme's own translations still win.** If `languages/es_MX.po` translates a taw-core string (for
example "Add Row"), that wording stays. One visible change on Spanish sites: the form message
"%s is required." is now translated.

**Check:** nothing to do. You no longer need to add taw-core strings to the theme's `.po`, though
existing entries are harmless.

### v1.57.0 – v1.59.0: typed reads, the link field
These are new APIs, and nothing existing changes:
- `Taw::post()->field('x')` returns typed, escaped values (see "Reading fields" in the README / docs);
- `$this->fields($postId)` in blocks;
- `Taw::term()`, `Taw::user()`, `Taw::option()`;
- a `link` field type.

**When adopting them in an existing block:**
- keep `getData()` returning plain values (`->text()`, `->bool()`, `->image()->url()`,
  `->link()->url()`), so the template's `esc_html()` calls and the block's unit test stay valid;
- **never pass a `Value` to `esc_html()`/`wp_kses_post()`**, because it's already escaped. Echo it
  directly, or escape `->text()`;
- converting a block from three fields (URL, label, new tab) to one `link` field changes the stored
  data: it needs a content migration, so treat it as a site change, not part of the upgrade.

## Opt-in features you may want

These appeared since v1.22, and none is on until the site asks for it:
- **Fields:** the data panel (`"ui": "panel"`), the `link` field, and term and user fieldsets.
- **Reading and REST:** typed reads (`Taw::post()`), and options over REST.
- **Content:** content snapshots (`bin/taw content:export` / `content:import`).
- **Lockdown:** editing policies (`Boot::editing()`).
- **Integrations:** Lucide icons (`Lucide::enable()`), media folders (`MediaFolders::enable()`), the
  RAG chatbot, and the Bible and Catechism readers.

The README of the installed version (`vendor/taw/core/README.md`) documents each one.

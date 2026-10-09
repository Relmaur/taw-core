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
   templates. Its `composer.json`/`package.json` diffs include optional starter features (Reactiph with
   `minimum-stability: dev`, the chatbot's `marked`/`dompurify`): skip those unless the site wants them.
   The shared docs also mention `inc/security.php` and `Blocks/Chatbot`, which older sites may not have.
3. **Update the package:** `composer update taw/core`. The theme's constraint (`^1.0`, or `^1.22` and
   similar on older sites) allows every later 1.x release.
4. **Read the sections below** for every version newer than the one you came from, and do the checks
   marked **Check**.
5. **Verify:**
   - `composer run test`, and `composer run phpstan` when the theme has it;
   - load the front page and a few pages with forms and blocks (the `visual-check` skill);
   - open wp-admin screens with metaboxes and options pages.
   `wp-content/debug.log` exists only with `WP_DEBUG` and `WP_DEBUG_LOG` on, and most sites keep them off.
   Under Local, read the site's `logs/php/error.log` instead, or turn `WP_DEBUG` on for the check and
   restore `wp-config.php` afterwards.
6. **Commit `composer.lock`**, and only when the site's rules allow it. The live site gets the update on
   its next deploy.

**Under Local by Flywheel,** start the site in Local before running `php bin/taw …` commands that need
the database. Since v1.59.2, `bin/taw wp` says so when the site is stopped, and `bin/taw wp db …` reaches
the site's database (before, it failed with `ERROR 2002 … /tmp/mysql.sock`).

**Forms have side effects.** A real submission can send email to a client or create a post. To check
that validation works, prefer a submission that must be rejected (a required field left empty), which
saves and sends nothing. Delete any test entries you create.

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
| < v1.59.2 | taw-core's Spanish translation now actually loads in classic themes; tabs work by keyboard |
| < v1.76.1 | Options-page and metabox tabs have a new look; check theme CSS that restyles `.taw-tabbed` |
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

**Check:** only forms with a required `email` field are affected (`php bin/taw inspect` lists the
forms). For each one that a published page shows, send it with the email empty and the other fields
filled, and expect "… is required." Nothing is saved or sent. Skip forms that no page shows.

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

**Check:** skip this if the site has no `group` fields (`grep -rn "'group'" Blocks/ inc/`). Otherwise,
list the posts where a group sub-field's value sits under the bare key. This asks the field registry, so
ordinary leftover `_taw_` meta doesn't show up:

```bash
php bin/taw wp eval-file - <<'PHP'
<?php
foreach (get_post_types() as $type) {
    foreach (\TAW\Core\Metabox\Metabox::fieldsFor('post', $type) as $key => $f) {
        if (empty($f['parent_group'])) { continue; }
        $bare = substr($key, 0, -strlen($f['field_key'])) . $f['id'];
        foreach (get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => $bare, 'fields' => 'ids']) as $id) {
            printf("%s #%d: %s is set, the field reads %s (%s) -> fields:set %d %s\n", $type, $id, $bare, $key,
                get_post_meta($id, $key, true) === '' ? 'empty' : 'also set', $id, $f['qualified_id']);
        }
    }
}
PHP
```

**If it finds some, the bare key may be the only copy of the data**, so the page shows nothing (or a
block's default) where the value should be. The fix is a data change, so ask first:
1. `php bin/taw fields:set <id> <qualified id> "<value>" --dry-run`, then without `--dry-run`;
2. check that the page shows the value;
3. delete the bare key: `php bin/taw wp post meta delete <id> <bare key>`.

Do the same on production, which needs its own access. Also check the block: if its `getData()` reads the
group with `getMeta($postId, '<group>')` (a single `_taw_<group>` key), it never sees the stored
sub-fields. Read them with `$this->fields($postId)->field('<group>')->value()` instead.

### v1.55.0: options pages over REST
It's opt-in per page (`'rest' => 'private' | 'public'`); pages without it expose nothing.

### v1.56.0: taw-core's own translations
taw-core's admin and form text uses the `taw-core` text domain and ships a partial Spanish translation
(about 20 of the most visible strings, such as "Add Row" and "%s is required."; the rest stay in
English). **The theme's own translations still win.** If `languages/es_MX.po` translates a taw-core
string, that wording stays.

In classic themes the bundled file didn't load until v1.59.2 (see below), so from v1.56.0 to v1.59.1
only the theme's own `.po` translated taw-core text.

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

### v1.59.2: fixes found by the first fleet upgrade
- **taw-core's Spanish translation loads in classic themes.** taw-core sits in the theme's `vendor/`, and
  for a path inside the theme WordPress looked for `es_MX.l10n.php` instead of `taw-core-es_MX.l10n.php`.
  Spanish sites now show the bundled wording for strings their own `.po` doesn't translate.
- **`bin/taw wp db …` works under Local**, and `bin/taw wp` says when the Local site is stopped.
- **Metabox and options-page tabs work by keyboard** (Tab to the active tab, arrows to move, Enter or
  Space to select). They look the same.
- **`php bin/taw sync` keeps a site's own skill** (`owner: site`) when a framework skill with the same
  name appears. Before, it replaced it. The report names the clash, and the framework copy isn't
  installed until the site renames or removes its own.

**Check:** on a Spanish site, open a form with a required field and leave it empty: the message is in
Spanish.

### v1.60.0: Block Bindings (`taw/field`)
New and additive: core blocks can show TAW fields through the `taw/field` Block Bindings source (see
"Block Bindings" in the README). `Boot::data()` registers it on `init`, so the theme's hook list gains
one line. Nothing renders differently until a block uses it.

**Check:** nothing to do. If you keep private data in a post, term or options field, add
`'bindings' => false` to it; user fields never bind unless they say `'bindings' => true`.

### v1.61.0: Block Bindings in the editor
Bound blocks preview their value in the block editor. Connectable blocks get a "TAW field" toolbar button and an Options menu (⋮) item "Connect to TAW field…", and the Attributes panel lists TAW fields to bind.
The theme's hook list gains two lines (`rest_api_init` for the preview route, and
`enqueue_block_editor_assets` for the editor script). **Nothing to do.**

### v1.62.0: `allowBound` in editing policies
An editing policy's content rule can now say `allowBound`: blocks clients may add only bound to a
`taw/field` (they appear as "Field …" blocks in the inserter). The key was reserved and rejected before,
so no existing policy uses it; no preset sets it. No hooks change. **Nothing to do.**

### v1.63.0: dynamic tags (server side)
Inline `<span class="taw-tag" data-taw-tag='…'>` elements in content now render live values (see "Dynamic
tags" in the README). The theme's hook list gains two lines: `render_block` (skips any block without a
tag) and `register_block_type_args` (adds `postId`/`postType` context to rich-text blocks). Content
without tags renders exactly as before. **Nothing to do.**

### v1.64.0: expressions
Tags and `taw/field` bindings accept expressions, `{"expr": "Published on @post.date.format('Y')"}` (see
"Expressions" in the README). No hooks change; existing tags and bindings are unchanged. **Nothing to do.**

### v1.65.0: the TAW data popup
The block toolbar's database button opens the TAW data popup (Fields and Expression tabs, inline chips or
block text), replacing v1.61's "TAW field" dropdown. The ⋮ menu's "Connect to TAW field…" stays. The
theme's hook list gains one line (`enqueue_block_assets`: the chip style in the editor canvas, admin only).
Options-page field registry entries gain `option_page_title`. **Nothing to do.**

### v1.65.1: chip popover fixes
A chip's popover closes after **Save** or **Refresh**, and on Escape or a click elsewhere. Removing a chip (its
**Remove** button, Backspace or Delete) no longer throws an error in the editor. **Nothing to do.**

### v1.66.0: conditions
Chips, bound block text and whole blocks can carry a condition (ADR-0013; see "Conditions" in the README).
New values `@viewer.logged_in`, `@viewer.role`, `@date.today`, `@date.now`. The theme's hook list gains one
line (`render_block` at priority 9: `BlockVisibility`); blocks and chips without a condition render as
before. `@viewer.*` values vary per visitor, so check the page cache varies by login before using them.
**Nothing to do.**

### v1.67.0: conditions in the editor
The TAW data popup gains a **Visibility** tab and a **Show only when…** section (Expression tab and chip
editor); every block's sidebar gains **TAW visibility**. Conditional blocks and chips are marked in the canvas.
No hooks change. **Nothing to do.**

### v1.67.1: conditions preview in templates
In the Site Editor, a template's conditions are checked against the latest post of its type (as its field
previews are), not against the template itself, which always read "hidden". **Nothing to do.**

### v1.68.0: the TAW Loop blocks (server side)
`taw/loop`, `taw/loop-item`, `taw/loop-empty` and `taw/loop-pagination` render repeater rows, related posts,
queries, terms and images (ADR-0014; see "TAW Loop" in the README). The theme's hook list gains two lines
(`init`: block registration; `register_block_type_args`: every block type also `uses_context`
`taw/loopItem`, `postId` and `postType`). Checkbox values now read as `1` inside loops (`@row.x`). The editor UI
comes in v1.69.0. **Nothing to do.**

### v1.69.0: the TAW Loop in the editor
Insert **TAW Loop** from the inserter: a setup (source, field, starting design), a sidebar (source, order,
limits, pages, filter, layout), live previews of every item, and Row/Loop values in the TAW data popup. The
preview route gains `POST taw/v1/loop/render` (same permissions as `bindings/preview`). No hooks change.
`{"row": …}` and `{"loop": …}` bindings count as bound for `allowBound`. **Nothing to do.**

### v1.69.1: an empty loop shows nothing
A TAW Loop with no items and no **No items** block now renders nothing (it used to leave an empty wrapper).
**Nothing to do.**

### v1.69.2: metabox field changes reach repeaters
The gradient-text and HubSpot form fields' change events bubble, so a repeater row holding one saves its edits
(#84). **Nothing to do.**

### v1.69.3: `Performance` is a normal class
`src/Support/performance.php` became `src/Support/Performance.php` (PSR-4); only the `TAW_PERFORMANCE_AUTOLOAD`
escape hatch stays file-autoloaded. Hooks are identical. **Check:** nothing, unless a theme `require`s the old
file path directly (none of ours do).

### v1.70.0 – v1.73.0: formulas and functions in expressions
Expressions gain formulas `@( … )` and function calls `@fn( … )`: arithmetic, comparisons, `if()`, about 45
functions (numbers and money, dates, text, lists) and a theme filter for your own, `taw_expression_functions`
(ADR-0015; see "Expressions" in the README). Expressions also fill links and images (v1.72.0), and the
Expression tab gains the **ƒ Functions** picker (v1.73.0). Every v1 expression reads as before. No hooks
change. **Nothing to do.**

### v1.73.1: Spanish, complete
Every taw-core string has an es_MX translation. **Nothing to do.**

### v1.73.2: the TAW data popup is a dialog
It opens in the middle of the screen (close it with ×, Escape or a click outside), and conditional blocks no
longer show a blue box on hover. **Nothing to do.**

### v1.74.0: dynamic block settings (server side)
A block's `metadata.tawSettings` sets its classes, colors and HTML attributes from expressions at render time
(ADR-0016; see "Dynamic block settings" in the README). The theme's hook list gains one line (`render_block` at
priority 11: `BlockSettings`); blocks without settings render as before. Button new-tab/rel and post-date
bindings accept expressions. **Nothing to do.**

### v1.75.0: dynamic block settings in the editor
Every block's sidebar gains **TAW dynamic settings**, and the TAW data popup's **Use it for** gains more targets
(new tab, rel, caption, title, navigation link, post date). No hooks change. **Nothing to do.**

### v1.76.0: the Code of Canon Law corpus
New and opt-in: `TAW\Core\Rest\CanonLawEndpoint::enable()` serves `taw/v1/canon-law/*`. It reads a Code installed with `bin/taw canon-law:install <edition> <.sqlite|.json>`, which goes to MySQL on hosts without `pdo_sqlite`. `bin/taw` gains `canon-law:install` and `canon-law:export`; themes register them in `bin/taw` (taw-theme v1.12.42). When the endpoint is enabled and an edition is installed, the chatbot gains a `lookup_canon_law` tool, and `ChatOrchestrator`'s system prompt no longer names specific corpora. No hooks change for sites that don't enable it. **Nothing to do.**

### v1.76.1: tabs look like tabs
Options-page and metabox tabs are a row of labels with the active one underlined in the admin color (they were
bordered boxes with faded labels); on narrow screens the row scrolls sideways. Markup and keyboard behavior are
unchanged. **Check:** a theme that restyles `.taw-tabbed .tabs` or `.tab-title` in its own admin CSS.


### v1.77.0: `hub:install` and `hub:enroll` retired

taw-hub was retired (2026-10-08). Both commands are now hidden stubs that print what replaced them
and exit 1; nothing else changed. If your theme's `bin/taw` still registers them, it keeps working;
`php bin/taw sync` brings the scaffold's `bin/taw`, which no longer does.

What replaced them: the companion ships with the theme as an mu-plugin. Add the VCS repository
`https://github.com/Relmaur/taw-hub-companion`, require `"taw/hub-companion": "^0.3"`, put the
fleet key in `extra.taw-companion.keys`, and have your deploy copy
`vendor/taw/hub-companion/mu-loader/taw-companion.php` to `wp-content/mu-plugins/`. Then delete the
regular TAW Hub Companion plugin. taw-fleet reads the site (`taw-fleet live`).

**Check:** `php bin/taw list` doesn't show `hub:install`/`hub:enroll` (hidden), and
`php bin/taw hub:install` prints the retirement notice.

## Opt-in features you may want

These appeared since v1.22, and none is on until the site asks for it:
- **Fields:** the data panel (`"ui": "panel"`), the `link` field, and term and user fieldsets.
- **Reading and REST:** typed reads (`Taw::post()`), Block Bindings (`taw/field`), and options over REST.
- **Content:** content snapshots (`bin/taw content:export` / `content:import`).
- **Lockdown:** editing policies (`Boot::editing()`), including `allowBound` for field-only blocks.
- **Integrations:** Lucide icons (`Lucide::enable()`), media folders (`MediaFolders::enable()`), the
  RAG chatbot, and the Bible, Catechism and Code of Canon Law readers.

The README of the installed version (`vendor/taw/core/README.md`) documents each one.

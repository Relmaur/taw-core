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
   templates. For `composer.json`/`package.json`, use its suggestions (`merge` in `sync --json`, since
   v1.78.0) rather than the raw diff: they list only what the scaffold has and the site lacks, never a
   removal, and `--apply-manifests` writes them. Optional starter features (Reactiph with
   `minimum-stability: dev`, the chatbot's `marked`/`dompurify`) are listed as optional: skip those
   unless the site wants them.
   The shared docs also mention `inc/security.php` and `Blocks/Chatbot`, which older sites may not have.
3. **Update the package:** `composer update taw/core --with-dependencies`. The theme's constraint (`^1.0`, or
   `^1.22` and similar on older sites) allows every later 1.x release. `--with-dependencies` (`-W`) lets
   Composer move taw/core's own dependencies too. Without it, a release that needs a newer one (v1.77.0
   needs `enshrined/svg-sanitize ^1.0`) changes nothing and still exits 0 with "Nothing to modify in lock
   file". **Check the version moved** (`composer show taw/core | grep versions`); if it didn't,
   `composer why-not taw/core <version>` says what holds it back.
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
| < v1.81 | Per-IP limits stop trusting `X-Forwarded-For` (sites behind Cloudflare: set `TAW_TRUSTED_PROXIES`); an enabled chatbot needs Turnstile keys and an updated widget |
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

Also in v1.77.0: `enshrined/svg-sanitize` moves to `^1.0` (1.0.0 fixes three advisories against
0.22.0, which current Composer refuses to install). Same API; SVG uploads are sanitized as before,
with the DTD stripped first.

**Check:** `composer audit` reports no advisories for svg-sanitize, and `php bin/taw list` doesn't
show `hub:install`/`hub:enroll`. Run before the scaffold sync, the theme's old `bin/taw` still
registers them, and `php bin/taw hub:install` prints the retirement notice. After the sync, the new
`bin/taw` doesn't register them at all ("There are no commands defined in the hub namespace"). Both
are a pass.

### v1.78.0: `sync` suggests composer.json/package.json changes

`php bin/taw sync` now compares `composer.json` and `package.json` with the scaffold by rule instead of
leaving a raw diff to judge (`resources/update-manifest.json` § `manifestMerge`). Each gets a `merge`
entry in `--json`:
- `add`: keys or repositories the scaffold has and the site lacks;
- `bump`: dependencies whose scaffold constraint is higher;
- `review`: other differences (a changed script, an autoload path), for a person to decide;
- `optional`: Reactiph and chatbot lines, never suggested;
- `site_only`: how many of the site's own keys it kept.

Nothing is ever removed, and `name`, `extra` and other sections outside the rules are never read, so a
site's own dependencies and its `extra.taw-companion` key stay. `--apply-manifests` writes `add` and
`bump`, keeping the file's indentation; `--apply` still never writes Tier 2. Nothing changes unless you
use the new flag.

**Check:** none needed. To see it, `php bin/taw sync` lists the suggestions under `composer.json` and
`package.json` (often "nothing to apply").

### v1.78.1: upgrading needs `--with-dependencies`

Docs only. "How to upgrade" now says `composer update taw/core --with-dependencies`. A site coming from
before v1.77.0 with `enshrined/svg-sanitize` 0.22.0 in its lock otherwise stays where it is without an
error. The v1.77.0 check also covers a theme whose `bin/taw` was already synced.

**Check:** none.

### v1.79.0: content imports keep what they're given

Content Interchange fixes, import side, plus snapshot schema 1.3. Nothing changes for sites that
don't import content. What an import now does differently:

- Content keeps embeds, SVG and forms, and `&` stays `&`. `content:import` runs as the first
  administrator (`--user` picks another).
- Comments aren't duplicated on every import, and keep their dates.
- Password-protected posts stay protected. Date-only and term-only changes travel, and terms removed
  at the source are removed.
- A failed post write no longer writes its fields onto post 1. A user keeps their role when the source's
  role doesn't exist here. Unregistered fields and array options arrive as they left.

**Check:** if you keep content snapshots from before v1.79.0, they still import. A 1.3 snapshot fed to
an older taw/core is imported best-effort (the password and the empty-taxonomy lists are ignored).

### v1.80.0: field defaults, and saving them as records

New and opt-in: a metabox or options page can declare `'defaults' => ['field_id' => value]`. While a
field has nothing stored, the getters return its default and the edit screen shows it. Tools → TAW
Data → *Defaults* (or `php bin/taw content:defaults --apply`) writes them into the empty fields, with
an undo. Nothing changes for a site that declares no defaults.

**Check:** none. To use it, move a block's `getMeta(...) ?: 'text'` fallbacks into its metabox's
`defaults` and drop the `?:`; the page should render the same (README → Metabox System → Defaults). For
`content:defaults`, add `$app->add(new ContentDefaultsCommand($themeDir));` to `bin/taw`, or sync it
(`php bin/taw sync`).

### v1.80.1: field defaults treat `'0'` and `'[]'` as empty

Only for sites using v1.80.0's `defaults`. A field now falls back to its default for every value a
`?:` fallback treated as empty, including `'0'` (an image field saved blank) and `'[]'` (a repeater
saved with no rows); a checkbox's `'0'` still counts as unchecked. In v1.80.0 those kept the empty
value, so a page whose fallbacks moved into `defaults` could lose an image or a list, and
`content:defaults` skipped those fields.

**Check:** none.

### v1.81.0: client IPs can't be spoofed; the chatbot gets a budget and a human check

**Every site.** Rate limits (forms, page passwords, corpus endpoints) and the IP saved with form
submissions now come from `REMOTE_ADDR`. `X-Forwarded-For` is read only when the request comes
through a trusted proxy: private and loopback addresses by default, plus anything in
`TAW_TRUSTED_PROXIES`. Before, any client could send `X-Forwarded-For` and dodge every per-IP limit.

**Check:** a site behind Cloudflare or another public proxy must list it in `TAW_TRUSTED_PROXIES`
(see README § Security / Hardening), or all visitors share one rate-limit bucket. To check: submit a
form yourself and compare the IP saved on the submission with your own public IP (sites with the
chatbot: **TAW Chatbot → Usage** shows it directly).

**Sites that call `RagSettings::enable()`** (ADR-0017). `POST taw/v1/chat` now:
- needs a session from `POST taw/v1/chat/session` (Turnstile) in the `X-TAW-Chat-Session` header,
  and refuses every message (`503 not_protected`) while the human check is on and
  `TAW_TURNSTILE_SITE_KEY`/`TAW_TURNSTILE_SECRET_KEY` are missing;
- stops at a $1/day, $10/month budget and stricter limits (1000-character messages, 10 per 10 min and
  60 per day per visitor, 30 per minute site-wide);
- declines questions unrelated to the site.

**Check:**
1. Define the Turnstile keys in `wp-config.php`, or set **TAW Chatbot → Access → Human Check** to Off.
2. Update the theme's `Blocks/Chatbot` (run Turnstile, send the session header, handle the refusal
   `code`s). The taw-theme scaffold's widget does this.
3. Set the budgets, prices and **Assistant Scope**, then open **TAW Chatbot → Usage** and check
   that "Resolved" is your own IP. A site that saved the settings page before keeps its saved
   **Max Tool-Call Iterations** (the old default was 4, the new one 3); Usage shows the value in force.
4. Register `RagUsageCommand` in the theme's `bin/taw` (`php bin/taw sync` brings it).
5. Ship the widget change and the taw/core bump together: the updated widget calls methods
   (`RagSettings::humanCheck()`, `chatPaused()`, `maxMessageChars()`) that older taw/core doesn't have.

### v1.82.0: content imports map references and URLs

Content Interchange, export and import (snapshot schema 1.4). Nothing changes for sites that don't
move content. What a snapshot from another site (`taw-fleet pull`, a staging copy) now does:

- Post references (`post_select`, query loops, navigation links) point at this site's posts, found
  by slug, instead of keeping the source's IDs. Attachment and term IDs in blocks are mapped too, and
  only in the block attributes that hold them.
- Links to the source site become links to this site; image URLs become this site's copies. Files
  this site doesn't have (yet) keep loading from the source.
- Media is matched by its path under uploads before its filename, so two images with the same name
  in different month folders no longer swap.
- Importing the same snapshot twice reports nothing to change the second time.

**Check:** none on the site itself. The **exporter** runs on the site that's the source of a pull,
so production sites need this release for their snapshots to carry `refs` (what lets `post_select`
values move between sites). Older snapshots still import.

### v1.82.1: a pull settles in one import

Import side. Media is downloaded before the import decides which records already match, so a
record that links to a file arriving in the same import is updated in that import, not the next
one. **Check:** none.

### v1.83.0: content imports carry media metadata

Import side (fidelity Phase 4). An image already on this site gets the source's alt text, caption,
title and description when they differ; the dry run (and `taw-fleet pull`'s preview) lists files to
download, files missing, and metadata changes as `media:<path>` records; and a reference to a file
this site can't get is cleared instead of pointing at whatever attachment has that number here.
Captions keep their markup. **Check:** none.

### v1.84.0: content imports keep page and category trees

Content Interchange, export and import (snapshot schema 1.5, fidelity Phase 5):

- Pages are matched by their path (`about/team`), so two pages with the same slug under different
  parents no longer overwrite each other. A page moved at the source is moved here, not duplicated.
- Parents are set even when the source lists a child before its parent, and a parent removed at the
  source is removed here (pages and categories).
- Category and tag meta outside TAW fields is imported (it was exported, but never written).
- Terms of a private taxonomy the exported posts use arrive with their names.

**Check:** none on the site itself. As with v1.82.0, the **exporter** runs on the source of a pull, so
production needs this release for its snapshots to carry page paths. Older snapshots still import
(pages matched by slug, as before).

### v1.85.0: scoped exports and pushes

Content Interchange (snapshot schema 1.6, fidelity Phase 6):

- `content:export --posts/--types/--since` (and the REST export with `types`/`since`) now leave out
  options and keep only the terms those posts use, so importing a few posts can't overwrite the rest
  of the site's settings. `--with-options` / `--with-terms` restore the old output.
- `content:diff` change-sets carry the source, its references and the media they use: a push maps
  IDs, rewrites links and downloads files. Diffing a scoped snapshot deletes nothing outside its scope.
- An import never sets the front page (or posts page) to a page the site doesn't have.
- A file an import downloaded is recognised on the next import even if WordPress renamed it.

**Check:** scripts that pass `--posts`, `--types` or `--since` and rely on options or every term in
the file need `--with-options` / `--with-terms`. `taw-fleet pull` exports in full and isn't affected.

### v1.86.0: content imports can be undone

Import side (fidelity Phase 7). Every applied import now writes a journal next to its rollback snapshot
in `uploads/taw-private/`, and `content:import --undo --yes` (or *Undo this import* in Tools → TAW Data)
reverses it: values it changed go back, what it created is deleted, what it deleted is recreated, and
anything edited by hand since is kept. A record that fails no longer stops the import; it's listed as
failed. If the rollback snapshot or the journal can't be written, nothing is imported.

**Check:** none. Scripts reading `content:import --json` get new `failed`, `journal` and `error` keys;
without `--json`, the command now exits non-zero when a record failed.

### v1.87.0: content moves carry the site icon, logo and menus

Content Interchange, export and import (snapshot schema 1.7, fidelity Phase 8). A pull, push or
`--migrate` now also carries the site icon and logo, the classic menus built in wp-admin and which
location each sits in, reusable blocks and block navigation menus, and footnotes. Menus a theme builds
in code (stored in `taw_managed_menu_*` options) are left alone. Other plugins' post meta (an SEO
plugin's titles, say) is carried only when asked: `content:export --meta=_wds_`.

**Check:** none on the site itself. As with v1.84.0, the **exporter** runs on the source of a pull, so
production needs this release for its snapshots to carry these.

### v1.87.1: a post picked inside a repeater row is saved

Fix. A `post_select` sub-field in a repeater row could lose the editor's pick: the picker didn't
signal the change, so the row's value only reached the saved repeater if something else in the
form changed afterwards (or the click was quick enough). **Check:** none; re-pick and save any
repeater rows whose post selections went missing.

### v1.87.2: pulls settle when a host drops large files

Fix, import side. A content import now tries a media download three times before giving up (some
hosts cut large files off mid-transfer), and its warning says why a download failed. A post whose
comment or ping status is stored empty at the source (WordPress itself saves the site's default) no
longer shows as changed on every pull. **Check:** none; only the site you import into needs it.

### v1.88.0: a pull carries everything in one run

Content Interchange, import and export, found by the new fidelity suite (`composer run fidelity`, a
two-site round trip in CI). A page or term already here whose only change is a link to a page the same
import creates is now written and linked in that run (before, the link came on the next pull); so is a
front page, posts page or sticky post the import brings to a site without one. Downloaded files keep the
source's month folder, so two files of one name stay two files, and a `-scaled` image arrives under its
own name. An option the source never set (`WPLANG`) is no longer carried, and a site address before a
sentence's period is mapped. **Check:** none; a site that was pulled into before may hold a duplicate
`hero-1.jpg`-style copy or a reference to the wrong one of two same-name files: pull again to set the
references right, then delete the unused copy from the media library.

### v1.89.0: site skills ship with taw/core

taw/core now carries the site skills every TAW site gets (`resources/skills/`: `resolve-comments`,
`perf-audit`). `bin/taw sync` installs them into `.claude/skills/` with the scaffold's skills, and the new
`bin/taw skills:sync [--apply]` installs only these (block themes use it). **Check:** none. To install them
by hand: `php bin/taw skills:sync --apply`, then commit `.claude/skills/`. To undo: delete the two folders
(the next sync puts them back unless you mark a same-named skill `owner: site`).

### v1.90.0: `vendor/bin/taw`, and commands of your own

**What changed.** The `taw` command-line tool now ships with taw/core as `vendor/bin/taw`
(`TAW\CLI\Application`): its command list updates with `composer update taw/core` instead of waiting for a
scaffold release. A theme's `bin/taw` becomes a short file that hands over to it, so `php bin/taw …`
keeps working. Commands you add yourself go through `TAW\CLI\CommandRegistry`, never into `bin/`
(framework-owned, replaced on sync).

**Why.** The theme's own `bin/taw` listed ~28 commands and was synced with `rsync --delete`: a site
couldn't add a command, and a new command needed a scaffold release. This is the first step of making an
update a plain `composer update` (umbrella plan `docs/plans/taw-platform.md` § 5.0).

**Check:** none; nothing a site wrote changes. Run `vendor/bin/taw list` (or `php bin/taw list`): a
classic theme lists 28 commands, a block theme `schema:validate` and `skills:sync`.

**By hand** (the scaffold's sync does it for you): replace the theme's `bin/taw` with the scaffold's new
one (taw-theme v1.12.57+ / taw-gutenberg v0.3.59+), or call taw/core directly:
`vendor/bin/taw <command>`. To add a command of your own, put this in a file the theme's
`composer.json` autoloads (`"autoload": {"files": ["inc/cli.php"]}`):

```php
TAW\CLI\CommandRegistry::add(fn (string $themeDir) => new App\Cli\ReportCommand($themeDir));
```

**Undo:** restore the previous `bin/taw` from git (`git checkout <commit> -- bin/taw`); taw/core keeps
working either way.

### v1.91.0: `taw.json`, the site's update policy

**What changed.** A theme can now say, in its own `taw.json`, what an update changes on its own:
taw/core's range (`patch`, `minor`, or `pinned:1.90.0`), framework files, `composer.json`/`package.json`
additions, the framework's sections of the agent docs, which checks must pass, and how the update is
delivered (`pr`, `pr+merge`, `branch`). `bin/taw policy` shows the effective policy in words;
`--init` writes a starter file. Nothing acts on it yet: the coming `bin/taw update` (and taw-fleet's
"Update this site") will.

**Why.** So "update this site" can run without asking questions: the site decides once, in a file it
owns and commits, and every updater (the dashboard, the weekly CI job, a person) follows the same rules
(umbrella plan `docs/plans/taw-platform.md` § 5).

**Check:** none. A site without `taw.json` gets the defaults: every 1.x release, framework files
replaced, additions applied, lint + phpstan + test + build, a pull request to merge.

**Also in v1.91.0: CI comes from taw/core.** A theme's `.github/workflows/ci.yml` and `framework-sync.yml`
become stubs of a few lines that call taw/core's shared workflows (`theme-ci.yml`,
`theme-framework-sync.yml` at `@v1`): the checks now update with taw/core. Each check runs only when the
theme has what it checks (phpstan/test scripts, `Blocks/`, `taw-schema/`, a front-end check or build).
The CI scripts move to `vendor/taw/core/resources/ci/`; until a site's taw/core has them, the shared
workflows fall back to the theme's own `bin/ci/`. **Check:** after the sync, the next push runs CI:
it should pass as before. **By hand:** copy the stubs from taw-theme v1.12.58+ (`smoke: true`,
`build: false` for a classic theme) — each workflow's header lists the same checks as commands.
**Undo:** restore the old workflow files from git.

**By hand (taw.json):** `php bin/taw policy --init`, edit `taw.json` (editors that read JSON schemas complete it from
`vendor/taw/core/resources/schema/taw-json-1.0.json`), check it with `php bin/taw policy`, commit it.
**Undo:** delete `taw.json` (the defaults apply).

## Opt-in features you may want

These appeared since v1.22, and none is on until the site asks for it:
- **Fields:** the data panel (`"ui": "panel"`), the `link` field, and term and user fieldsets.
- **Reading and REST:** typed reads (`Taw::post()`), Block Bindings (`taw/field`), and options over REST.
- **Content:** content snapshots (`bin/taw content:export` / `content:import`).
- **Lockdown:** editing policies (`Boot::editing()`), including `allowBound` for field-only blocks.
- **Integrations:** Lucide icons (`Lucide::enable()`), media folders (`MediaFolders::enable()`), the
  RAG chatbot, and the Bible, Catechism and Code of Canon Law readers.

The README of the installed version (`vendor/taw/core/README.md`) documents each one.

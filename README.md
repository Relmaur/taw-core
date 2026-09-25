# TAW Core

The data layer of TAW, packaged for Composer. It covers **data**: a config-driven field engine
(metaboxes, options pages), typed storage, fields exposed over the REST API, and portable content
import/export. Alongside it is an **optional classic-theme toolkit**: PHP block system, Vite asset
pipeline, frontend visual editor, forms and performance tools. You boot either the data layer alone or
both, with one call.

**PHP 8.2+ · GPL-2.0-or-later · `composer require taw/core`**

---

## Quickstart

**Classic theme (the full toolkit)** — in your theme's `functions.php`:

```php
use TAW\Core\Theme\Theme;

Theme::boot();
```

Auto-discovers blocks, initializes Vite, registers REST endpoints, enables the visual editor, and applies performance config.

**Data layer only** (taw-gutenberg, or another TAW theme that needs only data) — after requiring the Composer autoloader:

```php
\TAW\Core\Boot::data();
```

Boots only the data layer: the Tools → TAW Data import/export screen, `GET taw/v1/content/export`, and
REST-registered field meta. It adds nothing presentational — no Vite, no block discovery, and no
Performance optimizations (so your theme's block library CSS is left alone). `Theme::boot()` calls
`Boot::data()` itself, and calling both is harmless: it only runs once per request. See
[ADR-0003](docs/adr/0003-data-layer-and-boot-split.md).

---

## Versioning — a change on `main` is invisible downstream until tagged

Consuming projects (`taw-theme` and any client site) never install `main` — they pin via
`composer.json`'s `"taw/core": "^1.0"` constraint, resolved and locked to a specific **tag** in
their own `composer.lock`. Pushing a commit to this repo's `main` branch, by itself, changes
nothing for any consumer, no matter how long it sits there:

1. **Tag a release** (see the `taw-release` Claude Code skill, umbrella-side) — an annotated
   `vX.Y.Z` tag, pushed.
2. **Only then** does a consumer's `composer update taw/core` have anything new to pick up —
   and that update still needs to happen and be committed (`composer.lock`) in the consumer's
   own repo before its CI, or anyone else's `composer install`, sees the change.

**This bit a real release**: a new `TAW\Core\Seo\Schema` class was added and pushed to `main`,
a consumer block (`taw-theme`'s `FAQ`) was written against it, and local validation passed —
but only because validation copied source files directly into the consumer's `vendor/taw/core/`
by hand, which bypasses Composer's actual version resolution entirely. CI then failed with
`class.notFound`, because the consumer's real `composer.lock` was still pinned to the previous
tag, which predated the new class. **Never validate a `taw-core` change against a consumer by
hand-copying files into its `vendor/`** — the only way to get a true read is: tag → push tag →
`composer update taw/core` in the consumer → run its checks against what that actually
resolved to.

---

## Architecture

```
src/
├── Core/
│   ├── Block/          # BaseBlock, Block, MetaBlock, BlockRegistry, BlockLoader
│   ├── Metabox/        # Metabox & repeater engine
│   ├── OptionsPage/    # Settings pages backed by wp_options
│   ├── Form/           # Frontend form processing and submissions
│   ├── Mail/           # Email delivery with HTML templating
│   ├── Theme/          # Boot entry point
│   ├── Editor/         # Frontend visual editor shell
│   ├── Rest/           # REST API endpoints
│   └── Menu/           # WordPress menu OOP wrappers
├── Support/
│   ├── ViteLoader.php  # Vite asset pipeline
│   ├── Performance.php # WordPress bloat removal and preloads
│   └── utilities.php   # Global helper functions (file-autoloaded, no namespace)
├── Helpers/
│   ├── Framework.php   # Path/URL resolver for portable use
│   ├── Image.php       # Attachment image utilities
│   ├── Svg.php         # SVG upload, sanitization, rendering
│   ├── Editor.php      # Visual editor utilities
│   └── Dump.php        # Debug utilities
└── CLI/
    ├── MakeBlockCommand.php
    ├── ImportBlockCommand.php
    └── ExportBlockCommand.php
```

**Namespace:** `TAW\` → `src/` (PSR-4). `src/Support/utilities.php` is file-autoloaded (global scope).

---

## Block System

Blocks live in the theme's `/Blocks` directory. `BlockLoader` auto-discovers them at boot.

### MetaBlock — block with post meta

```php
namespace TAW\Blocks\Sections\Hero;

use TAW\Core\Block\MetaBlock;
use TAW\Core\Metabox\Metabox;

class Hero extends MetaBlock {
    protected string $id = 'hero';

    protected function registerMetaboxes(): void {
        new Metabox([
            'id'      => 'taw_hero',
            'title'   => 'Hero Fields',
            'screens' => ['page'],
            'fields'  => [
                ['id' => 'headline', 'label' => 'Headline', 'type' => 'text'],
                ['id' => 'image',    'label' => 'Image',    'type' => 'image'],
            ],
        ]);
    }

    protected function getData(int|false $postId): array {
        return [
            'headline' => $this->getMeta($postId, 'headline') ?: 'Default',
            'image'    => $this->getImageUrl($postId, 'image'),
        ];
    }
}
```

**Non-obvious:** `registerMetaboxes()` is internally deferred to `init`, so `__()` calls are always safe. `getData()` receives `int|false` because `get_the_ID()` returns `false` on 404 pages — `getMeta`, `getImageUrl`, and `getRepeater` return safe empty values when passed `false`.

### Simple Block (no meta)

```php
namespace Blocks\Hero;
use TAW\Core\Block\Block;

class Hero extends Block {
    protected function defaultData(): array {
        return ['title' => 'Default Title'];
    }
}
```

### Block Variations

```php
public static function variations(): array {
    return ['', 'footer', 'landing']; // '' = default variation
}
```

Each variation is a separate registered block sharing metaboxes and templates. Asset enqueuing is deduplicated automatically.

### boot() — early registration

Override `boot()` for early hooks, including form registration. Called at `after_setup_theme` — wrap `__()` calls in `add_action('init', ...)`:

```php
public static function boot(): void
{
    add_action('init', static function () {
        Form::register(['id' => 'hero_cta', 'fields' => [/* ... */]]);
    });
}
```

---

## Metabox System

```php
use TAW\Core\Metabox\Metabox;

new Metabox([
    'id'      => 'page_settings',
    'title'   => 'Page Settings',
    'screens' => ['page', 'page-about.php', 'homepage'], // post type, template file, or slug
    'fields'  => [
        ['id' => 'tagline',    'label' => 'Tagline',    'type' => 'text', 'required' => true],
        ['id' => 'hero_image', 'label' => 'Hero Image', 'type' => 'image'],
    ],
]);
```

### Field Types

| Type | Notes |
|------|-------|
| `text` | Single-line |
| `textarea` | `rows` key |
| `url` | Validated |
| `number` | `min`, `max`, `step` |
| `select` | `options` as `['value' => 'Label']` |
| `checkbox` | Stored as `'1'` / `'0'` |
| `wysiwyg` | WordPress rich text |
| `color` | WP Color Picker |
| `range` | `min`, `max` |
| `image` | WP Media picker; stores attachment ID |
| `files` | Multi-file; drag-to-reorder; stores JSON array of IDs |
| `group` | Nested fields under shared prefix |
| `post_select` | AJAX post picker; single or multi |
| `repeater` | Dynamic rows stored as JSON; supports nesting |
| `datepicker` | jQuery UI; stored as date string; `date_format`, `min_date`, `max_date` |
| `icon` | Lucide icon picker; stores icon name — **opt-in**, requires `Lucide::enable()` (see [Icon System](#icon-system)) |
| `gradient_text` | Ordered `{text, highlighted}` segments; stores JSON array (see [Gradient Text](#gradient-text)) |
| `hubspot_form` | HubSpot embed config (`portal_id`/`form_id`/`region`); stores JSON object (see [HubSpot Form](#hubspot-form)) |

All fields accept: `id`, `label`, `description`, `placeholder`, `default`, `required`, `width` (%), `readonly` (see [Readonly Fields](#readonly-fields)).

### Tabs

```php
new Metabox([
    'id'     => 'hero',
    'title'  => 'Hero',
    'screens' => ['page'],
    'fields' => [/* all fields */],
    'tabs'   => [
        ['id' => 'content', 'label' => 'Content', 'fields' => ['headline', 'body']],
        ['id' => 'media',   'label' => 'Media',   'fields' => ['image', 'video']],
    ],
]);
```

Each tab lists field IDs from `fields`; fields keep their declared order inside a tab, and `width` / `conditions` work as in a flat metabox. Every tab's inputs stay in the DOM (only the active panel is shown), so the whole metabox still posts and saves as one form. Fields that no tab lists are **not** dropped — they render above the tab bar, so a shared field (e.g. a section heading) needs no tab of its own. A tab whose `fields` match nothing is skipped; if no tab resolves to any field, the metabox renders flat. An optional `icon` (image URL) shows beside the tab label.

### Repeater

```php
[
    'id'     => 'team',
    'type'   => 'repeater',
    'max'    => 10,
    'layout' => 'tabbed_horizontal', // omit = accordion; 'tabbed_horizontal'; 'tabbed_vertical'
    'fields' => [
        ['id' => 'name',  'type' => 'text'],
        ['id' => 'photo', 'type' => 'image'],
        ['id' => 'links', 'type' => 'repeater', 'fields' => [  // nested repeaters supported
            ['id' => 'url',   'type' => 'url'],
            ['id' => 'label', 'type' => 'text'],
        ]],
    ],
]
```

Repeater data is JSON; survives WordPress's `wp_unslash()` in `update_post_meta()`. Callers must `json_decode()`.

### Gradient Text

A `gradient_text` field authors a heading as an ordered list of plain/highlighted segments, rather than the older convention of a plain `heading` field plus one always-trailing `highlight` field — that convention can't express a heading like "How can **we help**?" where the highlighted run isn't the last word. Each segment is `{text: string, highlighted: bool}`; the admin UI is a lightweight Alpine-only segment editor (add/remove/toggle), no drag-reorder — reach for a real `repeater` instead if row reordering matters for a given field.

```php
['id' => 'heading', 'label' => 'Heading', 'type' => 'gradient_text'],
```

Render with `Metabox::renderGradientText()`, passing the highlighted segments' class(es) — the actual gradient is a per-theme design decision this framework method has no business hard-coding:

```php
$segments = Metabox::get_gradient_text($post_id, 'heading');
echo Metabox::renderGradientText($segments, 'bg-clip-text text-transparent bg-gradient-to-r from-cyan-500 to-blue-500');
// How can <span class="bg-clip-text text-transparent bg-gradient-to-r from-cyan-500 to-blue-500">we help</span>?
```

`renderGradientText()` also accepts the raw JSON string directly (e.g. straight from `OptionsPage::get()`), so it works the same on either storage backend.

### HubSpot Form

A `hubspot_form` field stores a HubSpot embed's `{portal_id, form_id, region}` as a single JSON object — for a block offering a HubSpot embed with a fallback to the native [Forms](#forms) system when unconfigured:

```php
['id' => 'hubspot_form', 'label' => 'HubSpot Form', 'type' => 'hubspot_form'],
```

```php
use TAW\Core\Integrations\Hubspot;

$config = Metabox::get($post_id, 'hubspot_form');

if (Hubspot::isConfigured($config)) {
    echo Hubspot::render($config);
} else {
    // render the site's own Form block instead
}
```

`Hubspot::render()` prints HubSpot's own `forms/embed/v2.js` loader plus a scoped `hbspt.forms.create()` call targeting a freshly generated container id — safe to call more than once per page. `region` defaults to `na1` when blank. No `enable()` gate — unlike `icon`'s bundled Lucide set, there's no asset here to opt into; the field type is available as soon as it's used.

### Reading Values

```php
$value = Metabox::get($post_id, 'field_id');
$rows  = json_decode(Metabox::get($post_id, 'team'), true); // repeater
```

### Term fields (v1.53.0+)

A fieldset can target a taxonomy's terms with `term:<taxonomy>` in its screens (JSON/PHP `on`), alone or mixed
with post types ([ADR-0008](docs/adr/0008-qualified-registry-and-storage-contexts.md)):

```php
Schema::fieldset('genre_details')->on('term:genre')->fields([...]);      // or 'screens' => ['book', 'term:genre']
```

- **Where:** the taxonomy's Add and Edit screens (`{taxonomy}_add_form_fields` / `_edit_form_fields`), saved
  on `created_{taxonomy}` / `edited_{taxonomy}` with the same nonce, validation, conditions, read-only rules and
  sanitizing as a post, gated on `edit_term`. It's the same engine: every field type works. Values are term
  meta with the fieldset's prefix. Validation messages show on the Edit screen.
- **Add screen (AJAX):** an empty required field blocks the submit, repeaters and rich text are serialized
  before WordPress posts the form, and after a successful add the screen reloads with WordPress's "added"
  notice so no value carries over to the next term.
- **Read:** `Metabox::get_term($termId, 'genre_tagline')`, or `Metabox::term($termId)` for typed reads
  shaped like the post helpers: `->bool()`, `->repeater()`, `->imageUrl()`, `->color()`, `->posts()`,
  `->gradientText()`.
- **REST:** `register_term_meta()` per taxonomy (edit: `edit_term`); repeater/files/post_select also get the
  decoded `taw_<id>` field on the term (on `post_tag` its REST object type is `tag`).
- **Content snapshots:** term records of a taxonomy with term fieldsets gain `fields` (decoded, keyed like post
  fields); taxonomies without them keep their row shape.
- The data panel stays post-only (it's a block-editor feature).

### User fields (v1.54.0+)

`user` in a fieldset's screens (JSON/PHP `on`) targets the user screens, alone or mixed with other targets:

```php
Schema::fieldset('author_details')->on('user')->fields([...]);          // or 'screens' => ['user']
```

- **Where:** Profile, Edit User and Add New User, saved on `personal_options_update` / `edit_user_profile_update`
  / `user_register` with the same nonce, validation, conditions and sanitizing as a post, gated on `edit_user`.
  Validation messages show on the screen you land on (Profile, Edit User, or the Users list after adding).
  Values are user meta with the fieldset's prefix. `user` is never a page slug.
- **Read:** `Metabox::get_user($userId, 'author_twitter')`, or `Metabox::user($userId)->repeater('author_links')`
  etc. (same typed readers as terms).
- **REST:** `register_meta('user', …)` plus `taw_<id>` for structured types, **in the `edit` context only** (users
  who can edit that user). `/wp/v2/users/<id>` is public for authors, and user fields never appear there.
- **Content snapshots:** with `--with-users`, user records gain `fields` (only when a fieldset targets users).

### Readonly Fields

Set `'readonly' => true` on any field whose value is authoritatively written by something other than the wp-admin form — an external sync pipeline, a computed value, etc. — so the metabox stops implying it's editable there:

```php
['id' => 'case_client', 'label' => 'Client', 'type' => 'text', 'readonly' => true],
```

Renders as plain, non-interactive text (no `<input>`) instead of an editable control, with a small lock icon next to the label (`title` tooltip: "Managed externally — read-only") so the intent is visible at a glance, not just on close inspection. This is enforced on both ends:

- **Render:** no form control is printed, so there's nothing for devtools to re-enable and submit.
- **Save:** `readonly` fields are skipped entirely in `save()` — a forged `$_POST` key is ignored regardless of what the rendered markup looked like.

`readonly` composes with the other field types rather than being one itself:

- On a `group` field, it propagates to every sub-field.
- On a `repeater` field, it disables Add/Remove/reorder for the whole row list (not just the sub-field values) and propagates `readonly` to every sub-field — a synced list whose *membership* can still be edited would be just as misleading as an editable input. A `readonly` sub-field inside an otherwise-editable repeater is also supported and enforced per-row.

Not currently enforced by `TAW\Core\Rest\VisualEditorEndpoint` or the `fields:set`/`seo:inject` CLI commands — those are separate write paths (see [CLI](#cli)) and don't consult this flag yet.

### Conditional Fields

```php
['id' => 'show_cta', 'type' => 'checkbox'],
[
    'id'         => 'cta_text',
    'type'       => 'text',
    'conditions' => [
        ['field' => 'show_cta', 'operator' => '==', 'value' => '1'],
    ],
],
```

### Locking Metabox Order

By default WordPress lets any user drag-and-drop reorder metaboxes, saved per-user — so the same screen can look different for every editor. `MetaboxOrder` forces a fixed order and disables dragging.

Explicit order:

```php
use TAW\Core\Metabox\MetaboxOrder;

MetaboxOrder::lock('page', ['hero_settings', 'video_settings', 'faq_settings']);
```

Or derive the order automatically per-post from the page template's `BlockRegistry::render()` call sequence — call once in `functions.php`:

```php
MetaboxOrder::lockFromTemplate(); // screen defaults to 'page'
```

For a template like:

```php
BlockRegistry::render('hero_standard');
BlockRegistry::render('post_grid--videos');
BlockRegistry::render('post_grid--guias');
BlockRegistry::render('post_grid--galerias');
BlockRegistry::render('post_grid--noticias');
```

the edit screen for any page assigned that template will always show those blocks' metaboxes in that exact order, and dragging is disabled. This works via a static scan of the template file (it's never executed in wp-admin). Boxes not tied to a block on the page (e.g. core WordPress boxes) keep their relative position and render after the ordered ones.

Template resolution mirrors WordPress's own hierarchy, not just the raw Page Attributes selection. Candidates are tried highest-priority first:

- An explicitly-selected page template (`_wp_page_template`, the Page Attributes dropdown) — as above.
- `front-page.php` for the site's static front page (Settings → Reading) — no `Template Name:` header or Page Attributes selection required, since `front-page.php` renders whenever `is_front_page()` is true regardless of what's picked in that dropdown.
- `home.php` for the posts page (Settings → Reading → "Posts page") — same filename convention, no meta written.
- `page-{slug}.php` for a page whose slug is `{slug}` — WordPress applies it automatically via the template hierarchy, again with no meta written.
- Posts matching none of these are left unordered.

Both this resolution and the `screens` template matching in `Metabox` share one code path (`Metabox::templateCandidatesForPost()`), so they can't drift apart.

### Data panel: fields in a block-editor sidebar (v1.51.0+)

In the block editor, a fieldset can show in a **"TAW Data" sidebar** instead of as a metabox under the canvas: one place for a post's data, opened and closed (and pinned) from its own icon in the editor header, with an entry in the ⋮ menu. It's **off by default**: nothing changes until you switch it on (ADR-0007).

Switch it on per fieldset, for the whole site, or per install. The first one set wins:

```php
Schema::fieldset('book_details')->on('book')->ui('panel');   // this fieldset (or new Metabox(['ui' => 'panel', …]))
Schema::settings()->fieldsetUi('panel');                      // site default (JSON: {"kind": "settings", "key": "site", "fieldsetUi": "panel"})
define('TAW_DATA_UI', 'panel');                               // wp-config.php: replaces only the site default
```

Values are `panel` or `metabox`; unset means `metabox`. So `->ui('metabox')` keeps one fieldset as a metabox on a panel site.

- **One place, never both.** A `panel` fieldset gets no metabox in the block editor (a metabox would post stale values after the panel's save). The classic editor and nav-menu metaboxes are unchanged, and when every fieldset on a screen is in the panel, WordPress's iframed editor canvas comes back.
- **Every field type**, with the metabox's rules: conditions (live), tabs, `group`, `repeater` (add, remove, reorder, collapse, `min`/`max`, nested repeaters), read-only, and the theme's color palette. A fieldset with a type the panel can't show (an unknown custom type, or a `group` inside a `repeater`) stays a metabox as a whole.
- **`wysiwyg`** opens a small block editor in a modal (paragraphs, headings, lists, quotes, images, buttons, separators, tables; `teeny` fields get text blocks only; `'blocks' => [...]` sets the list, `media_buttons => false` drops images). It loads existing HTML the way "Convert to blocks" does and saves in `wp_editor()`'s own format, so templates render values exactly as before. Opening it never changes anything.
- **Saving** happens with the post (Save, autosave, the unsaved-changes warning) through the REST fields taw/core already registers, so values are stored exactly as the metabox stores them.
- **The server checks REST saves too:** `required` and `validate` callbacks run for panel fieldsets (a refused save names the fields, and the panel marks them); read-only fields can't be changed; values of fields hidden by their conditions are cleared, as `Metabox::save()` does. Autosaves are never refused.
- **In the editor:** while a required field is empty, saving is locked and a notice names the field, with a button that opens the panel.

The panel is a prebuilt React bundle in `assets/data-panel/` (Composer installs need no Node). To work on it: `cd resources/data-panel && npm ci && npm run dev` (Vite on port 5175; WordPress switches to it through `assets/data-panel/hot`), and `npm run check` before committing — CI fails if the committed build doesn't match the source.

---

## Schema — post types, taxonomies, fieldsets, options pages

Define your data model in one place and let taw/core register it ([ADR-0004](docs/adr/0004-schema-registry-php-and-json.md)).
Works with both entry points (`Theme::boot()` and `Boot::data()`). Define in PHP, in JSON files, or both.

```php
use TAW\Core\Schema\{Field, Registry, Schema};

add_action('taw_schema_register', function (Registry $schema): void {
    $schema->add(Schema::postType('book')->labels('Book', 'Books')->args(['menu_icon' => 'dashicons-book']));
    $schema->add(Schema::taxonomy('genre')->for('book')->labels('Genre', 'Genres'));
    $schema->add(
        Schema::fieldset('book_details')->title('Book details')->on('book')->fields([
            Field::text('subtitle')->label('Subtitle'),
            Field::image('cover')->label('Cover'),
            Field::repeater('awards')->fields([Field::text('name')->label('Award')]),
        ])
    );
    $schema->add(Schema::optionsPage('library')->title('Library settings')->fields([Field::text('library_phone')]));
});
```

**Or as JSON** (v1.44.0+): one entity per file in a `taw-schema/` folder. It's scanned one level deep, so
`taw-schema/post-types/book.json` works too. Same words as the PHP API:

```json
{
  "$schema": "https://taw.mlizardo.com/schema/taw-schema-1.0.json",
  "version": 1,
  "kind": "fieldset",
  "key": "book_details",
  "title": "Book details",
  "on": ["book"],
  "fields": [
    { "id": "subtitle", "type": "text", "label": "Subtitle", "required": true },
    { "id": "awards", "type": "repeater", "fields": [{ "id": "name", "type": "text" }] }
  ]
}
```

| `kind` | Keys (besides `version`, `kind`, `key`, optional `override`) |
|---|---|
| `post_type` | `labels` `{singular, plural}`, `args` (→ `register_post_type`) |
| `taxonomy` | `for` (post types, required), `labels`, `args` (→ `register_taxonomy`) |
| `fieldset` | `on` (required), `fields` (required), `title`, `context`, `priority`, `prefix`, `config` (other Metabox keys) |
| `options_page` | `fields` (required), `title`, `menu_title`, `capability`, `config` (other OptionsPage keys) |

Unknown top-level keys are errors, which catches typos. Unknown *field* keys (`conditions`, `width`, …) pass through
to the engine, like `->with()`.

- **Where, and what wins:** folders are scanned in the child theme, then the parent theme, then
  `wp-content/taw-schema/` (site level), plus the `taw_schema_paths` filter. PHP definitions outrank every
  file, a child theme outranks its parent, and the theme outranks wp-content. An invalid file is skipped
  with a `_doing_it_wrong()` notice, and the others still load.
- **Caching:** in production (`wp_get_environment_type()`), validated files are cached in a transient keyed
  by each file's path, mtime and size plus the taw/core version, so editing a file invalidates the cache.
  Elsewhere they're re-read on every request.
- **Validate without WordPress:** `php bin/taw schema:validate [paths…] [--json]`. It exits non-zero on
  errors, each reported with a JSON pointer (`/fields/2/type`). It warns about duplicates and about targets
  that aren't defined post types. Editors get autocomplete from `"$schema"` →
  `resources/schema/taw-schema-1.0.json`.

- **Fieldsets compile into a regular `Metabox`, and options pages into an `OptionsPage`.** Storage, the admin UI,
  REST meta and content export all work exactly as for hand-written ones: `_taw_subtitle` post meta, read with
  `Metabox::get()`. `Field::*` builders produce the same arrays `new Metabox([...])` accepts. Raw arrays are
  accepted too, and `->with([...])` passes through any key the builder has no method for.
- **Post type defaults:** `public` and `show_in_rest` are on, and `custom-fields` is **always** added to
  `supports`. WordPress hides registered meta from REST otherwise, so TAW fields would silently vanish from
  `wp/v2`. Taxonomies default to `show_in_rest`. Anything in `->args([...])` overrides the defaults (except
  `custom-fields`).
- **Timing:** `taw_schema_register` fires on `init:1`, so `__()` is safe. The registry freezes at `init:5`,
  and a later `add()` is refused with a `_doing_it_wrong()` notice. Post types register at `init:5`,
  taxonomies at `init:6`, and fieldsets/options pages at `init:8`.
- **Validation:** invalid keys throw immediately. These include reserved names like `post`, keys over the
  WordPress length limits, and taxonomies named like query vars such as `year`. Definitions missing a
  required part (a fieldset with no `->on()`, a taxonomy with no `->for()`) are skipped with a notice.
- **Duplicates:** the same entity defined twice keeps the higher-precedence source (PHP beats JSON). The
  later one wins on a tie. Mark a deliberate replacement with `->override()` to silence the notice.
- **Qualified field ids (v1.52.0+, [ADR-0008](docs/adr/0008-qualified-registry-and-storage-contexts.md)):**
  every field is also registered as `"{fieldset}.{field}"` (group sub-fields `"{fieldset}.{group}_{sub}"`),
  with its meta key. `Metabox::fieldsFor('post', 'book')` returns meta key → config for the fields stored
  on a post type, and `Metabox::fieldFor('post', 'book', $ref)` takes a qualified id, a meta key or a bare
  id. REST meta, content export/import, the data panel, the visual editor and `fields:get`/`fields:set`
  use them, so two fieldsets can share a field id (on different post types, or with different prefixes)
  and each keeps its own type and sanitizer. `Metabox::getQualifiedRegistry()` lists them all.
  `Metabox::fieldsFor('term', 'genre')` and `Metabox::fieldsFor('user')` do the same for [term](#term-fields-v1530)
  and [user](#user-fields-v1540) fieldsets.
- **Term targets:** `on` entries of the form `term:<taxonomy>` are checked (a taxonomy key, 1–32 lowercase
  letters, numbers, `_` or `-`); `schema:validate` warns when the taxonomy isn't defined in the schema files
  or by WordPress core.
- **Field id collisions:** the bare-id lookups (`Metabox::get_field_config()`, `getFieldRegistry()`) still
  hold one entry per id, the later one. A schema field sharing an id with another field (in another
  fieldset or a hand-written metabox) is reported via `_doing_it_wrong()`, plus an admin notice when
  `WP_DEBUG` is on.
- **Permalinks:** when post types or taxonomies change, rewrite rules are flushed once, on the next admin
  request (fingerprint stored in the `taw_schema_rewrite_hash` option). The front end never flushes.

### Editing policies

An editing policy declares how far the block editor is locked down for a client, layer by layer
([ADR-0005](docs/adr/0005-editing-policies.md); the full guide is the "Editing policies" page in
taw-docs):

| Layer | Controls |
|---|---|
| **content** | Per post type: allowed blocks (globs), a starting template for new posts, `lock` (`false`, `insert`, `contentOnly`, `all`) |
| **site** | Templates, template parts, Global Styles, Navigation, the post editor's "edit template", the Site Editor itself |
| **design** | theme.json: custom colors, gradients, font sizes, drop cap, spacing, line height, border, shadow, duotone |
| **features** | Code editor, Custom HTML, block directory, Openverse, core and remote patterns, block lock UI |

Pick a preset, `open` → `guided` → `structured` → `locked` (the table is `Editing\Presets`, pinned by
`PresetsTest`), and override any layer or setting. Preset content rules apply to `page` only; other
post types stay open unless named. Define it with `Schema::editing()`, `PostType::editing()`, or the
JSON kind `editing` (key `site`, checked by `schema:validate`):

```json
{ "version": 1, "kind": "editing", "key": "site", "preset": "structured",
  "themeBlocks": ["taw-gutenberg/*"],
  "layers": { "features": { "customHtml": true }, "content": { "post": "open" } } }
```

**Theme blocks (v1.50.0+):** `themeBlocks` (PHP `->themeBlocks('taw-gutenberg/*')`) lists the theme's
own blocks. They're added to every content allow list, the curated one included, so clients can still
insert them at `guided` and above. Levels that allow every block are unchanged. Unlike `allow`, it
doesn't replace a level's list, so it works at every preset. Tools → TAW Editing lists them and warns
when a pattern matches no registered block.

It's applied only when the theme calls **`\TAW\Core\Boot::editing()`** (v1.46.0+; it boots the data
layer too). `Boot::data()` and `Theme::boot()` never apply it, so taw-theme is unaffected.

- **Per install (`wp-config.php`):** `TAW_EDITING_PRESET` switches the preset (overrides still apply).
  `TAW_EDITING_BYPASS_USERS` (an array or a comma-separated list of logins) names who stays unlocked,
  even when clients are Administrators; users with the policy's capability (default `taw_unlock_editing`)
  bypass too. With nobody named, everyone is locked. `TAW_EDITING_OFF` turns everything off, as the
  recovery switch. wp-cli is never restricted.
- **Enforced on the server:** a save that *adds* a block the rule doesn't allow gets a 400
  (`taw_editing_block_not_allowed`); blocks already in the post still save. Writes to locked templates,
  template parts, Global Styles or Navigation get a 403 at REST dispatch (`taw_editing_site_locked`);
  reads always work. At `locked`, the Site Editor menu and the dashboard welcome panel are removed and
  `site-editor.php` returns a 403.
- **Editor guardrails:** the starting template, the layout locks and the features layer. On WordPress
  7.1 the editor ignores a page-level `contentOnly` lock, so `lock: contentOnly` is sent as
  `templateLock: all` plus `assets/editing-content-only.js`, which puts every block in `contentOnly`
  editing mode (v1.48.0). Re-check a `structured` page in a browser after WordPress updates.
- **Design** locks are written into theme.json, so they're site-wide and apply to everyone.
- **Tools → TAW Editing** (`manage_options`, read-only) shows the preset and where it's set, whether you
  bypass, each layer's values, the rule per post type, and warnings.

This guards against accidents, not against an Administrator: they can still install plugins.

---

## Options Page

Same field types, tabs, groups, repeaters, and conditional fields as Metabox. Backed by `wp_options` instead of post meta.

```php
use TAW\Core\OptionsPage\OptionsPage;

new OptionsPage([
    'id'         => 'taw_settings',
    'title'      => 'Theme Settings',
    'menu_title' => 'Settings',
    'capability' => 'manage_options', // default
    'prefix'     => '_taw_',          // default; option names are prefix + field id
    'icon'       => 'dashicons-admin-generic', // default; admin menu icon
    'position'   => null,              // default; admin menu position
    'fields'     => [
        ['id' => 'company_phone', 'label' => 'Phone', 'type' => 'text', 'required' => true],
        ['id' => 'logo',          'label' => 'Logo',  'type' => 'image'],
    ],
]);

$phone = OptionsPage::get('company_phone');
$logo  = OptionsPage::get_image_url('logo', 'thumbnail');
```

> **Note:** If you're registering an `OptionsPage` from `inc/options.php` in a `Theme::bootstrapFullSite()` scaffold, translated field labels (`__('Phone', 'taw-theme')`) are safe to use as-is — `bootstrapFullSite()` defers both the textdomain load and `inc/options.php`'s own require to `after_setup_theme` (in that order) specifically so this doesn't trip WordPress 6.7+'s `_load_textdomain_just_in_time` notice. Don't call `load_theme_textdomain()` yourself in `inc/customizations.php` — it's already handled.

### Tabs

```php
new OptionsPage([
    'id'     => 'taw_settings',
    'title'  => 'Theme Settings',
    'fields' => [/* all fields */],
    'tabs'   => [
        ['id' => 'general', 'label' => 'General', 'icon' => '...', 'fields' => ['company_phone']],
        ['id' => 'media',   'label' => 'Media',   'fields' => ['logo']],
    ],
]);
```

### Groups

Group sub-fields are each stored as their own option, named `{prefix}{group_id}_{sub_id}` — read them individually with `OptionsPage::get('group_id_sub_id')`, not the group's own id.

### Validation

`required`, `url`, and `number` (`min`/`max`) fields are validated on save; a custom `validate` callable may also be supplied per field. Failures are surfaced inline via WordPress's `settings_errors()` and the previous saved value is kept.

---

## Vite Asset Pipeline

Dev mode detection: connects to the dev server host:port (default `localhost:5173`, or whatever the theme's `vite.config.js` hot-file plugin last wrote to `dist/hot` / `public/build/hot`, if present) and confirms it's actually Vite by requesting `GET /@vite/client` and checking for an HTTP 200 response — not just that *something* is listening on the port. A bare TCP-connect check is a false-positive trap: any unrelated process (another dev server, a Docker container, anything) can end up bound to that port for reasons that have nothing to do with this project, which would otherwise make the theme serve dead dev-server asset URLs in production with no assets loading at all, even though the production build and manifest are completely correct. Production reads `dist/.vite/manifest.json` (or `dist/manifest.json`, or the `public/build/` equivalents — checks all four), cached in the WP object cache keyed on the manifest file's own mtime — a new deploy always rewrites this file with a new mtime, so a stale cached manifest can't survive a rebuild even on a site with a persistent object cache (Redis/Memcached); no manual cache flush needed after deploying.

**Hot-file convention (required for dev mode):** have the theme's `vite.config.js` write the dev server's actual URL to `dist/hot` or `public/build/hot` on startup and delete it on shutdown (Laravel Vite plugin-style; `hotFile()` in `resources/vite/taw-vite.mjs` does this). `ViteLoader` only probes the host:port from that file. **Without a hot file, dev mode is off**: there's no fallback to `localhost:5173`, because another project's Vite server on that port once passed the check. The detection lives in `TAW\Core\Assets\DevServer`, shared with `Assets\Vite` below.

**Optimizer-exclusion hardening:** every module `<script>` tag (plus its `modulepreload`/`preload` `<link>`s) and every Vite-extracted stylesheet `<link>` carries `data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"` — standard exclusion signals WP Rocket, Autoptimize, Perfmatters, and LiteSpeed Cache all document and honor when they hook WordPress's own `script_loader_tag`/`style_loader_tag` filters. Vite's output is already minified, hashed, and (for JS) split into ES modules requiring exact, un-mangled execution order — nothing a generic optimizer does to it is safe. **This does not help against a tool that rewrites the raw HTML output buffer directly instead of hooking those filters** — e.g. a host-side CDN-rehosting feature — that class of tool needs its own exclusion-list configuration in its own admin UI regardless; see `taw-theme`'s `AGENTS.md` "Vite Integration" section for a real incident (WPMUdev Hummingbird) this doesn't cover.

```php
use TAW\Support\ViteLoader;

ViteLoader::init('resources/js/app.js');
ViteLoader::enqueueAsset('my-block', 'resources/js/blocks/my-block.js');
$url = ViteLoader::assetUrl('resources/fonts/Inter.woff2');
ViteLoader::inlineCriticalCss('resources/css/critical.css');
ViteLoader::preloadAssets(['resources/js/chunks/vendor.js']);
```

`enqueueThemeAssets()`'s own call to `inlineCriticalCss()` always resolves the entry through a
`taw_critical_css_entry` filter first, defaulting to `resources/scss/critical.scss` when
unhooked — a full-page CPT template with its own above-the-fold hero (an event microsite, a
landing page builder, anything with more than one visually distinct "page type") can swap in its
own critical file per request instead of the one site-wide default:

```php
add_filter('taw_critical_css_entry', function (string $entry) {
    return is_singular('event_invite') ? 'resources/scss/critical-event-invite.scss' : $entry;
});
```


### Block themes and packages: `Assets\Vite` (v1.49.0+)

`TAW\Core\Assets\Vite` is the theme-agnostic adapter ([ADR-0006](docs/adr/0006-shared-vite-adapter.md)),
used by taw-gutenberg. It's one instance per project root, isn't booted by `Boot::data()` or
`Theme::boot()`, and adds no hooks until it registers something. `ViteLoader` above is unchanged.

```php
use TAW\Core\Assets\Vite;

$vite = Vite::theme();                                   // the active parent theme; or new Vite($dir, $url)
$vite->script('acme-main', 'src/js/main.ts');            // enqueue; CSS it imports comes along
$vite->style('acme-editor', 'src/scss/editor.scss', [], false); // register only
$vite->block(get_theme_file_path('src/blocks/hero'));    // block.json with file:./index.tsx, style.scss…
$url = $vite->url('src/images/logo.svg');
```

- **Dev:** assets load from the dev server named in `{outDir}/hot` (default `dist/hot`), verified
  by `DevServer` as above.
- **Build:** hashed files come from `dist/.vite/manifest.json`, cached with the file's mtime in the
  key.
- **No build and no dev server:** nothing is enqueued, and users who can `edit_theme_options` see
  one notice naming what's missing. The page still renders.
- **ES modules:** `type="module"` is added through `wp_script_attributes`, so each tag keeps its
  id, inline scripts and translations.
- **`block($dir, $args, $editorScriptDeps)`:**
  - registers each `file:` asset in block.json through Vite;
  - leaves plain handles alone;
  - drops an unbuilt file instead of letting WordPress register the raw `.tsx`;
  - adds CSS extracted from the editor/view script to `editorStyle`/`viewStyle`.

  The editor script depends on `Vite::EDITOR_SCRIPT_DEPS` unless you pass your own list.

**Shared Vite config.** Import it from `vendor/`, so every theme uses the same WordPress-globals
list:

```js
import { defineConfig } from 'vite';
import { hotFile, phpReload, wordpressExternals } from './vendor/taw/core/resources/vite/taw-vite.mjs';

export default defineConfig({
    plugins: [hotFile(), wordpressExternals() /*, phpReload() */],
    build: { outDir: 'dist', manifest: true, rolldownOptions: { input: { main: 'src/js/main.ts' } } },
});
```

`wordpressExternals()` maps `@wordpress/*`, `react`, `react-dom` and `react/jsx-runtime` to the
browser globals.
- Importing a name that isn't in `WP_EXPORT_NAMES` fails the build.
- Add names with `wordpressExternals({ extraExports: [...] })`.
- A script that imports a package must also depend on its WordPress handle (for example
  `wp-blocks`).

---

## Forms

Configuration-driven AJAX forms with CSRF protection, honeypot spam filtering, rate limiting, optional Cloudflare Turnstile bot verification, per-field validation, email delivery, and submission persistence.

> **Critical:** Forms must be registered before templates load — `admin-ajax.php` never runs theme templates, so AJAX handlers registered inside a template don't exist on submission. Register in `MetaBlock::boot()` wrapped in `add_action('init', ...)`.

```php
public static function boot(): void
{
    add_action('init', static function () {
        Form::register([
            'id'           => 'contact',
            'submit_label' => __('Send Message', 'taw-theme'),
            'messages'     => ['success' => __('Thanks!', 'taw-theme')],
            'fields' => [
                ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true],
                ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
                ['id' => 'message', 'label' => 'Message', 'type' => 'textarea'],
            ],
        ]);
    });
}
```

Render in a template: `Form::display('contact');`

### Submit Button

`submit_label` sets the button text (default `'Send Message'`, or `'Submit'` for a multi-step form's final step). `submit_icon` optionally renders an icon after the label — a Lucide icon name (via `Lucide::render()` — no `Lucide::enable()` needed, same as any other direct template call to it) or raw `'<svg>...</svg>'`/HTML, printed as-is:

```php
Form::register([
    'id'           => 'contact',
    'submit_label' => 'Send message',
    'submit_icon'  => 'send',
    'fields'       => [...],
]);
```

The icon renders inside its own `<span class="taw-btn-icon" aria-hidden="true">` (decorative — the button's accessible name already comes from the label), after the label span, alongside the button's existing loading spinner. Nothing renders when `submit_icon` is unset, empty, or an icon name `Lucide::render()` doesn't recognize.

### Styling Hooks

Per-form styling without an ancestor wrapper or `!important` (taw/core ≥ v1.40.0):

```php
Form::register([
    'id'           => 'contact',
    'class'        => 'contact-form',   // appended to the <form>'s `taw-form`
    'button_class' => 'btn-black',      // appended to `taw-btn taw-btn-primary` on the submit button (and Next, on a multi-step form)
    'fields'       => [
        ['id' => 'first', 'label' => 'First name', 'type' => 'text', 'width' => 50],
        ['id' => 'last',  'label' => 'Last name',  'type' => 'text', 'width' => 50],
    ],
]);
```

Both options **add to** the built-in classes, never replace them, so the framework defaults still apply underneath and a theme overrides only what it needs — scoped to just that form: `.contact-form .taw-input { … }`, `.contact-form .btn-black { … }`. Blank or non-string values are ignored.

A field's `width` (percent, 1–100) is emitted as the `--taw-span` custom property (1–12 grid columns) on its wrapper, which `form.css` turns into `grid-column: span N`. Unlike the inline `grid-column` it replaced, this leaves `grid-column` free for a theme to override with an ordinary selector — e.g. `.contact-form .taw-form-field { grid-column: 1 / -1; }` — no `!important`. Every grid cell (fields, `html`, `heading`, `divider`) now collapses to full width below 640px.

### Security

Every form has CSRF (nonce) protection and honeypot spam filtering by default, no configuration needed. Two more layers are available:

**Rate limiting** — on by default (5 attempts per 60 seconds, per IP, per form), backed by WP transients (no Redis/external cache required). Checked before the nonce check, since a flooding script doesn't need a valid nonce to cause load.

```php
Form::register([
    'id' => 'contact',
    'rate_limit' => ['max' => 3, 'window' => 120], // override the default
    // 'rate_limit' => false, // or disable entirely
    'fields' => [...],
]);
```

**Cloudflare Turnstile** — opt-in bot verification. Requires site/secret keys defined as PHP constants in `wp-config.php` (the same pattern as DB credentials — never store a secret key in `wp_options`, which is readable via REST by anyone with `edit_posts`):

```php
// wp-config.php
define('TAW_TURNSTILE_SITE_KEY', '0x...');
define('TAW_TURNSTILE_SECRET_KEY', '0x...');
```

```php
Form::register([
    'id' => 'contact',
    'turnstile' => true,
    'fields' => [...],
]);
```

Get keys from the [Cloudflare Turnstile dashboard](https://dash.cloudflare.com/?to=/:account/turnstile). If a form opts in but keys aren't configured, the widget silently doesn't render and no verification runs (a `WP_DEBUG`-only notice flags the misconfiguration to developers, not visitors) — it degrades gracefully rather than blocking submission outright. `Turnstile::verify()` fails closed on any network error or malformed response.

**Field validation rules** — beyond `required`, any input field accepts:

```php
['id' => 'name',  'type' => 'text', 'min_length' => 2, 'max_length' => 80],
['id' => 'phone', 'type' => 'tel',  'pattern' => '[0-9+ ()-]{7,20}', 'pattern_message' => 'Enter a valid phone number.'],
['id' => 'guests','type' => 'number', 'min' => 1, 'max' => 20],
```

`pattern` is a PHP regex (no delimiters — the field wraps it), matched against the whole value. These also render as native HTML `minlength`/`maxlength`/`pattern`/`min`/`max` attributes for client-side UX, but the authoritative check is always server-side — HTML attributes are trivially removable from the DOM. An empty, non-required field never fails these checks.

**Custom per-field error messages** — every rule (`required`, the built-in `email` format check, `min_length`, `max_length`, `pattern`, `min`, `max`) accepts a `{rule}_message` override; falls back to a generic default (with the field's `label` interpolated) when not set:

```php
['id' => 'name',  'type' => 'text',  'required' => true, 'required_message' => 'Please tell us your name.'],
['id' => 'email', 'type' => 'email', 'required' => true, 'email_message' => 'That doesn\'t look like a real email address.'],
['id' => 'age',   'type' => 'number', 'min' => 18, 'min_message' => 'You must be 18 or older.'],
```

**Form-level default messages** — to set validation copy once for an entire form (e.g. translating every rule for a non-English site) instead of repeating a `{rule}_message` on every field, pass a `messages` entry per rule. Precedence: field-level `{rule}_message` > form-level `messages.{rule}` > built-in English default. `required`/`min_length`/`max_length`/`pattern`/`min`/`max` templates take the same `sprintf()` placeholders as the built-in defaults (field label as `%s`/`%1$s`, the rule's numeric bound as `%2$d`/`%2$s`); `email` takes no placeholders.

```php
Form::register([
    'id' => 'contact',
    'messages' => [
        'required'   => '%s es obligatorio.',
        'email'      => 'Correo electrónico no válido.',
        'min_length' => '%1$s debe tener al menos %2$d caracteres.',
    ],
    'fields' => [...],
]);
```

### Email Configuration

```php
'email' => [
    'to_self'   => [
        'subject'  => 'New submission',
        'template' => 'contact-self',
        'to'       => ['ops@example.com', 'sales@example.com'], // optional — defaults to admin_email
    ],
    'to_client' => ['subject' => 'Got your message!',  'template' => 'contact-client'],
],
```

No `template` → plain-text fallback via `wp_mail()`. `to_client` requires an `email` field in the form and always goes only to the submitter — it has no `to` override. `to_self.to` accepts a single address, an array of addresses, or a pre-joined comma-separated string; omit it to keep sending to `admin_email` as before.

### Field Types

**Input fields**

| Type | Notes |
|------|-------|
| `text` | |
| `email` | Validated with `is_email()` |
| `tel` | |
| `url` | |
| `number` | |
| `textarea` | `rows` (default `4`) |
| `select` | `options` as `['value' => 'Label']` |
| `radio` | `options`; `layout`: `'horizontal'` (default) / `'vertical'` |
| `checkbox` | Value is `'1'` when checked |
| `checkbox_group` | `options`; `layout`; stored as comma-separated string |
| `date` | `min_date`, `max_date` (ISO `YYYY-MM-DD`) |
| `image` | Renders `<input type="file" accept="image/*">`. On submit, uploaded via `media_handle_upload()` and validated as a real image server-side (`wp_attachment_is_image()` — the `accept` attribute is a client-side hint only); the field's value in `on_submit`'s `$data` is the resulting attachment ID (`0` if optional and omitted). Automatically adds `enctype="multipart/form-data"` to the `<form>` tag. |
| `wysiwyg` | Renders WordPress's own classic editor (`wp_editor()`, `media_buttons` off) — gives TinyMCE's built-in paste-from-Word cleanup for free. Sanitized server-side with `wp_kses_post()` (never trust client-side cleanup as a security boundary). |
| _(any other)_ | Passed straight through as HTML `type` attribute |

**Structural fields** — no `id`, no validation, no submission data

| Type | Notes |
|------|-------|
| `heading` | `label` + optional `subtitle` |
| `divider` | `<hr>` |
| `html` | `content` key; rendered with `wp_kses_post` |

All input fields accept: `id`, `label`, `type`, `required`, `placeholder`, `width`, `conditions`, `help`.

### Field Help Popover

Any field — including labelless `checkbox`/`radio`/`checkbox_group` — accepts a `help` string, rendered as a small "?" icon next to its label. By default, hover (mouse) or focus (keyboard/tap) reveals a popover with the text — pure CSS (`form.css`), no JS dependency. Newlines in `help` become line breaks; the text itself is always escaped, so it's plain text only, not HTML.

```php
[
    'id'       => 'privacy_consent',
    'label'    => 'I have read the Privacy Notice and consent to the use of my data.',
    'type'     => 'checkbox',
    'required' => true,
    'help'     => "Full plain-language summary of what data is collected and why...\n\nA second paragraph.",
],
```

For a `checkbox`/`radio` field specifically, the trigger renders *outside* the `<label>` that wraps the input — nesting it inside would let a click on "?" also toggle the control.

**`trigger_on_click`** — add `'trigger_on_click' => true` to open the popover on click instead of hover/focus. Hover holds up poorly for longer text: the pointer has to cross from the trigger into the popup to scroll it, and hover is lost — hiding the popup — the instant it leaves either element. Click mode keeps the trigger's own hover/focus styling (still a visible "this is interactive" cue) but the popup itself only opens/closes on click, tracked via the trigger's `aria-expanded`; clicking elsewhere or pressing Escape closes it (handled in `renderScript()`, not CSS, for this mode).

```php
['id' => 'privacy_consent', 'type' => 'checkbox', 'help' => '...', 'trigger_on_click' => true],
```

**`help_modal`** — add `'help_modal' => true` to open the help text as a centered modal dialog with its own dimmed backdrop, instead of a popover anchored to the trigger icon. For longer or more prominent content, an anchored popup can still read as "a tooltip on a small icon" rather than its own piece of content — a modal makes it unambiguous. Implies click triggering regardless of `trigger_on_click`'s value (a full-screen backdrop opening on hover isn't usable).

Renders a native `<dialog>`, opened via `.showModal()`, not a positioned `<span>` with a manually flex-centered fixed backdrop — deliberately. A fixed-position element's z-index only ever competes for stacking order within whatever ancestor stacking context it happens to be nested in (any ancestor with `position` + a non-`auto` z-index, `transform`, `opacity < 1`, `filter`, `isolation`, or `will-change` creates one) — since this markup can land inside an arbitrary page's arbitrary section wrappers, no z-index value is safe from ending up trapped behind something like a fixed header that happens to sit in a different stacking context. A `<dialog>`'s top layer renders above the entire document by construction, independent of ancestor stacking contexts entirely — closes via its own close button, a backdrop click, outside click, or Escape (native, no JS needed for that part).

```php
['id' => 'privacy_consent', 'type' => 'checkbox', 'help' => '...', 'help_modal' => true],
```

### Multi-column Layout

Fields live in a 12-column CSS grid. `width` is a percentage; all fields collapse to full width on mobile.

| `width` | Grid span |
|---------|-----------|
| ≤ 25 | 3/12 |
| ≤ 33 | 4/12 |
| ≤ 50 | 6/12 |
| ≤ 67 | 8/12 |
| ≤ 75 | 9/12 |
| > 75 or omitted | 12/12 |

### Conditional Fields

Evaluated in JS and on the server — hidden fields are excluded from validation and submission data regardless of client state.

```php
['id' => 'is_company', 'type' => 'checkbox'],
[
    'id'         => 'company_name',
    'type'       => 'text',
    'required'   => true,
    'conditions' => [
        ['field' => 'is_company', 'operator' => '==', 'value' => '1'],
    ],
],
```

Default: all conditions are AND. Use `'relation' => 'any'` for OR:

```php
'conditions' => [
    'relation' => 'any',
    'rules'    => [
        ['field' => 'estado_civil', 'operator' => '==', 'value' => 'married'],
        ['field' => 'estado_civil', 'operator' => '==', 'value' => 'cohabiting'],
    ],
],
```

Supported operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`.

### Multi-step Forms

Replace `fields` with `steps`. Per-step client-side validation on Next; all steps submitted in one AJAX request; server validates all steps. Server errors auto-navigate back to the first failing step.

```php
Form::register([
    'id'         => 'signup',
    'next_label' => 'Continue',
    'prev_label' => 'Back',
    'steps' => [
        ['title' => 'Personal Info', 'fields' => [/* ... */]],
        ['title' => 'Details',       'fields' => [/* ... */]],
        ['title' => 'Confirm',       'fields' => [/* ... */]],
    ],
]);
```

### Submission Persistence

Every successful submission is saved as a `taw_submission` CPT entry (WP Admin → Submissions). A webhook at **Settings → Form Webhook** forwards submissions as signed JSON POST (HMAC-SHA256).

**Per-form webhooks**, in precedence order:

1. An admin-configured override for that specific form, set in the **Per-Form Webhooks** table on the **Settings → Form Webhook** page (one row per form registered via `Form::register()`).
2. A code-level default set on the form itself, via its `webhook` config key:
   ```php
   Form::register([
       'id'      => 'contact',
       'webhook' => ['url' => 'https://n8n.example.com/webhook/contact', 'secret' => 'optional-hmac-secret'],
       'fields'  => [...],
   ]);
   ```
3. The **Default Webhook** configured at the top of the same settings page — the site-wide fallback for any form with neither of the above.

A form with none of the three configured simply doesn't fire a webhook (submission is still saved to the CPT either way).

**Payload shape:**

```json
{
    "event":        "new_submission",
    "form_id":      "contact",
    "post_id":      142,
    "submitted_at": "2026-02-07T12:30:00+00:00",
    "site_url":     "https://example.com",
    "page_url":     "https://example.com/contact",
    "ip":           "203.0.113.42",
    "data":         { "name": "Jane Doe", "email": "jane@example.com", "message": "Hello!" }
}
```

`page_url` is the full URL of the page the form was actually submitted from, captured server-side at render time (not read from the request's `Referer` header, which browsers and privacy tools can strip) — the same registered form is often embedded on several different pages, and this is what lets a downstream automation tell those submissions apart without needing a separate `form_id` per page.

**Customizing the payload** — a `taw_form_webhook_payload` filter runs on the payload right before it's sent (and before the HMAC signature is computed over it), letting a specific site add or override anything the default shape doesn't cover, without touching `taw-core` itself:

```php
// In the theme's inc/customizations.php:
add_filter('taw_form_webhook_payload', function (array $payload, string $formId, int $postId, array $data) {
    // Route the same form's submissions to different n8n destinations
    // depending on which section of the site they came from.
    $path = wp_parse_url($payload['page_url'], PHP_URL_PATH) ?? '';

    if (str_contains($path, '/financiera/')) {
        $payload['destination'] = 'financiera-sheet';
    } elseif (str_contains($path, '/fideicomisos/')) {
        $payload['destination'] = 'fideicomisos-sheet';
    }

    return $payload;
}, 10, 4);
```

### On Submit Callback

`'on_submit' => callable` runs custom logic right after a successful submission is saved (the
`taw_submission` CPT record is guaranteed to exist first, same as the email step) — the escape
hatch for anything beyond "save it and maybe email it," e.g. creating a real post from the
submitted data:

```php
Form::register([
    'id'     => 'client_post_submission',
    'fields' => [
        ['id' => 'post_title',      'label' => 'Title',           'type' => 'text',    'required' => true],
        ['id' => 'post_body',       'label' => 'Body',             'type' => 'wysiwyg', 'required' => true],
        ['id' => 'featured_image',  'label' => 'Featured Image',  'type' => 'image'],
    ],
    'on_submit' => function (array $data, int|false $submissionId) {
        // $submissionId is the taw_submission record's own post ID — this
        // callback creates a *different*, unrelated post from the submitted
        // data, so it deliberately uses its own $newPostId rather than reusing
        // (or shadowing) the parameter name.
        $newPostId = wp_insert_post([
            'post_title'   => $data['post_title'],
            'post_content' => $data['post_body'],
            'post_status'  => 'draft',
        ], true);

        if (is_wp_error($newPostId)) {
            // Thrown message is shown to the submitter as a general error.
            throw new \RuntimeException($newPostId->get_error_message());
        }

        if (!empty($data['featured_image'])) {
            set_post_thumbnail($newPostId, (int) $data['featured_image']);
        }
    },
]);
```

The callback's signature is `function(array $data, int|false $postId): void` — `$data` is the
same fully-validated, sanitized field data the CPT record and webhook payload are built from,
and the second parameter is the `taw_submission` post `SubmissionsHandler::saveSubmission()` just
created (`false` if that save itself failed, e.g. `wp_insert_post()` erroring — check before
relying on it as a real ID). Name it whatever fits the callback — the example above calls it
`$submissionId` specifically to avoid colliding with its own `wp_insert_post()` result for the
*different* post it creates from the submitted data. A callback that doesn't need it can just
omit the second parameter — PHP
silently ignores extra arguments passed to a closure declaring fewer, so every existing
`on_submit` callback written against the old one-argument signature keeps working unchanged.
**Throw a `\RuntimeException` to reject the submission** with a message shown to the submitter
as a general error, rather than returning a value — there's no magic return-value contract to
remember. `\RuntimeException` specifically, not any `\Throwable`: this handler runs for
anonymous, unauthenticated submitters, so any *other* exception type is treated as unexpected
(a bug, a DB failure, etc.) — logged server-side via `error_log()` and shown a generic message
instead, rather than echoing a potentially internals-revealing message straight to a visitor.
If an `image` field already uploaded a file before the callback threw, that attachment is
automatically deleted rather than left orphaned in the Media Library. A form with no
`on_submit` behaves exactly as before this existed.

---

## Page Password Protection

`TAW\Core\Auth\PagePassword` gates a page template behind a single shared password — declared
directly in the template, not a wp-admin toggle:

```php
<?php
/**
 * Template Name: Client Post Submission
 *
 * Requires TAW_CLIENT_PORTAL_PASSWORD defined in this site's wp-config.php.
 */

use TAW\Core\Auth\PagePassword;

PagePassword::protect([
    'password' => defined('TAW_CLIENT_PORTAL_PASSWORD') ? TAW_CLIENT_PORTAL_PASSWORD : '',
    'title'    => 'Client Submission Portal',
]);

get_header();
// ... the real template — only reached once unlocked ...
```

**Must be the very first thing in the template — before `get_header()`, before any output.**
It needs to be able to send a redirect and set a cookie, which requires no prior output; on a
locked visit it renders its own minimal, standalone gate screen and calls `exit` — the calling
template's code below `protect()` never runs until unlocked.

Like `Form`'s Turnstile secret key, the password itself should live in that site's
`wp-config.php` as a PHP constant, never hardcoded in a committed template file.

**Config:**

| Key | Default | Notes |
|---|---|---|
| `password` | *(required)* | Empty or missing **fails closed** — the gate denies access rather than falling open, since this is access control, not a supplementary check. A `WP_DEBUG`-only notice flags the misconfiguration for developers. |
| `id` | `substr(hash_hmac('sha256', $password, wp_salt('auth')), 0, 12)` | Explicit scope key for the unlock cookie. The default means two `protect()` calls sharing the same password automatically share one unlock — no config needed for "this client has several protected pages." |
| `title` | `'Protected Page'` | Heading shown on the gate screen. |
| `duration` | 30 days | How long the signed unlock cookie lasts once the correct password is entered. |

**Security:** the unlock cookie is a signed, stateless token
(`hash_hmac('sha256', ..., wp_salt('auth'))`, not the plaintext password) — a client can't
forge one by just setting a cookie manually. Password comparison uses `hash_equals()`
(timing-safe). Gate attempts are rate-limited per-IP via the same `TAW\Core\Form\RateLimiter`
used by `Form`. A correct submission redirects (POST/redirect/GET) rather than re-rendering,
so a page refresh never resubmits the password. Both the gate screen and the unlocked page send
`nocache_headers()` — without this, a full-page cache or CDN sitting in front of PHP could serve
a cached copy of the *unlocked* page to a completely different visitor who never entered the
password.

**Known limitations, by design:**
- **Gates template rendering only** — it does not restrict WordPress's own REST API. If the
  underlying post's status is `publish`, its `post_content` remains readable via
  `wp/v2/pages/{id}` (or similar) regardless of the password gate, since that's a separate code
  path this class never touches. Irrelevant for a form-only page like the client-portal example
  above (there's no real content in `post_content` to leak), but matters if you ever reuse this
  to gate an actual content page — keep the post `private`/`draft`, or additionally restrict
  REST access, if the body text itself needs to stay secret.
- **Rate limiting is per-IP** via `SubmissionsHandler::getUserIp()`, which trusts
  `X-Forwarded-For`/`X-Real-IP` when present. Behind a reverse proxy or CDN that sets these
  correctly, this works as intended; on a host reachable directly (no trusted proxy in front),
  a client can set an arbitrary `X-Forwarded-For` value per request to appear as a "new" IP each
  time, bypassing the rate limit — so the password itself is the real defense against sustained
  brute-forcing, not the rate limiter alone. Use a genuinely random, non-guessable password.
- **The gate screen renders its own standalone `<html>` document**, deliberately before
  `get_header()`/`wp_head()` — so a security plugin that adds headers (CSP, `X-Frame-Options`,
  etc.) via those hooks won't have applied them to the gate screen itself.

---

## Email

`TAW\Support\EmailConfig::useEmailit()` routes **all** `wp_mail()` calls — form submissions, password resets, WooCommerce order emails, anything else in WordPress that goes through `wp_mail()` — through [Emailit](https://emailit.com)'s API instead of the site's default mail transport (usually PHP's `mail()`, which is unreliable for deliverability on most hosts).

**Opt-in per site**, gated on a `defined('EMAILIT_API_KEY')` check — a true no-op on any site that doesn't define the constant:

```php
// In the theme's inc/customizations.php, before Theme::boot():
use TAW\Support\EmailConfig;

if (defined('EMAILIT_API_KEY')) {
    EmailConfig::useEmailit(
        apiKey:   EMAILIT_API_KEY,
        from:     defined('EMAILIT_FROM_EMAIL') ? EMAILIT_FROM_EMAIL : get_bloginfo('admin_email'),
        fromName: defined('EMAILIT_FROM_NAME') ? EMAILIT_FROM_NAME : '',
    );
}
```

Requires the official SDK (not bundled by default — this is a paid, per-client add-on, not every site needs it):

```bash
composer require emailit/emailit-php
```

`EMAILIT_API_KEY` (and optionally `EMAILIT_FROM_EMAIL` / `EMAILIT_FROM_NAME`) belong in that site's `wp-config.php` as constants — they're site-specific secrets, never commit them into the theme repo.

If the SDK isn't installed, the API key is empty, or the Emailit API call throws for any reason, `EmailConfig` falls back to normal `wp_mail()` transparently (logging the failure via `error_log()`) rather than silently dropping the email.

---

## Visual Editor

Inline admin editing on the frontend. **Opt-in** — must be explicitly enabled per theme:

```php
// In inc/customizations.php (not functions.php, which is framework-owned in
// taw-theme scaffolds using Theme::bootstrapFullSite()) — must run before
// Theme::boot(), which bootstrapFullSite() guarantees by loading
// customizations.php first.
use TAW\Core\Editor\VisualEditor;
VisualEditor::enable();
```

Once enabled, activate via **Edit Visually** in the admin bar or append `?taw_visual_edit=1` (requires `edit_posts`).

**What works automatically (no template changes needed):**
- All MetaBlock sections are wrapped in a clickable container (`data-taw-block-section`) showing hover/active outlines
- Clicking a section on the page opens its fields in the panel
- Typing in a panel text field updates the matching text on the page in real time (content-matching heuristic — works when the field value appears as a discrete text node)
- The panel shows only the blocks queued for the current page (via `BlockRegistry::queue()`)

All registered metabox fields appear in the editor panel automatically. Set `'editor' => false` on a field to exclude it.

Changes saved via `POST /wp-json/taw/v1/visual-editor/save` using the same sanitization pipeline as metaboxes.

**Optional template annotations** (for precise inline editing):

```php
// Wrap a value so it's directly clickable on the page
<?= Editor::field($data['headline'], 'hero', 'headline', 'h2') ?>

// Add attrs to an existing element (e.g. <img>)
<img <?= Editor::attrs('hero', 'hero_image') ?> src="...">
```

Annotations give the editor a direct DOM reference, making live updates exact and enabling "Edit inline on page" mode. Without them, the panel still shows and saves all fields, and live preview works via content matching.

---

## REST Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/taw/v1/visual-editor/save`   | Save visual editor changes |
| `GET`  | `/taw/v1/visual-editor/fields` | Load all registered fields + current values for the editor panel |
| `GET`  | `/taw/v1/search-posts`         | Post search for `post_select` fields |
| `GET`  | `/taw/v1/icons`                | Lucide icon search for the `icon` field type — only registered when `Lucide::enable()` was called |
| `POST` | `/taw/v1/chat`                 | Hybrid-RAG chatbot — see [Sovereign Hybrid-RAG Chatbot](#sovereign-hybrid-rag-chatbot). Public by default, rate-limited |
| `GET`  | `/taw/v1/bible/books`          | Bible reader — see [Bible Reader Corpus](#bible-reader-corpus). Opt-in, rate-limited |
| `GET`  | `/taw/v1/bible/books/{slug}/chapters/{n}` | Bible reader — one chapter's verses/sections/notes |
| `GET`  | `/taw/v1/bible/search`         | Bible reader — FTS5 search over verses or notes |

Cross-origin access to these routes (and to `admin-ajax.php?action=taw_form_*`) is opt-in and off by default — see [Static Export & Headless CORS](#static-export--headless-cors).

---

## Logging

`TAW\Core\Log\Logger` is the framework's structured log facade — a single, queryable replacement for the ad-hoc `error_log('[TAW …] …')` calls the codebase used to scatter around. It's always on, no `enable()` call.

```php
use TAW\Core\Log\Logger;

Logger::error('mail.emailit_send_failed', 'Emailit send failed — falling back to wp_mail().', [
    'exception' => $e::class,
    'error'     => $e->getMessage(),
]);

// Level helpers: debug() info() notice() warning() error() critical()
// (PSR-3 minus alert/emergency). Logger::log($level, $code, $message, $context) is the generic form.
```

Every entry carries **both** a human sentence (`message`) and a machine-stable, dot-namespaced `code` (`subsystem.event` — `form.email_delivery_failed`, `svg.sanitizer_library_missing`, …) plus a `context` array of the concrete values. The `code` is the contract an AI agent or the [TAW Hub](https://github.com/Relmaur/taw-hub) filters on; treat shipped codes as stable, add new ones freely.

**Two sinks by default:**

| Sink | Destination | For |
|---|---|---|
| `ErrorLogSink` | PHP `error_log()` — one line: `[TAW] [ERROR] mail.emailit_send_failed: … {"context":"json"}` | humans; whatever already tails the error log |
| `JsonlFileSink` | `wp-content/taw-logs/taw.log.jsonl` — one JSON object per line, size-rotated (~5 MB × 3) | machines: `bin/taw log:tail`, and the `taw-hub-companion` `/logs` route |

The log directory sits in `wp-content/` (not the public `uploads/`) and is seeded with a deny-all `.htaccess` + `index.php` on first write. Two filters: `taw_core_log_sinks` (swap/add destinations) and `taw_core_log_entry` (enrich every entry — e.g. tag it with a site id — without `taw/core` knowing what's listening). `Logger::setSinks()` overrides them outright, mainly for tests.

`TAW\Core\Log\LogReader` reads the JSONL file back (level / `code`-prefix / `since` filters); it's the shared building block behind `bin/taw log:tail` and the Hub route.

---

## SVG Support

```php
use TAW\Helpers\Svg;

Svg::register();                                    // Enable SVG uploads (call once at boot)
Svg::render($attachment_id);                        // <img> tag — safest for user uploads
Svg::inline($attachment_id, ['class' => 'icon']);   // Inline SVG — allows CSS styling
$url = Svg::url($attachment_id);
```

Sanitized on upload via `enshrined/svg-sanitize`. Sub-sizes are not generated.

---

## Icon System

Lucide's full icon set (~1,750 icons, [lucide.dev](https://lucide.dev)) is vendored locally into this package (`resources/icons/lucide/` + `resources/icons/lucide-index.json`, populated by `php bin/taw icons:sync`) — the admin picker never makes a network call.

**Opt-in.** The `icon` field type and its wp-admin picker only work once enabled, in the theme's `inc/customizations.php`, before `Theme::boot()`:

```php
TAW\Core\Icons\Lucide::enable();
```

Without it, an `'type' => 'icon'` field renders an inline notice instead of the picker, telling you to call `enable()`.

Block themes that boot only the data layer call it before `Boot::data()` runs (it does at `after_setup_theme` priority 0 in TAW Gutenberg), for example in `functions.php` or a site plugin. Since v1.51.1, `Boot::data()` registers the picker's search endpoint too, so the metabox picker and the [data panel](#data-panel-fields-in-a-block-editor-sidebar-v1510) work there without `Theme::boot()`.

Once enabled, use it like any other Metabox/OptionsPage field:

```php
['id' => 'feature_icon', 'label' => 'Icon', 'type' => 'icon']
```

The stored value is a bare icon name (e.g. `'house'`), sanitized with `sanitize_key()`. Render it in a template with `Lucide::render()` — this part needs **no** `enable()` call, same relationship as `Svg::register()` (upload support) vs `Svg::inline()`/`Svg::render()` (template output):

```php
use TAW\Core\Icons\Lucide;

echo Lucide::render('house', ['class' => 'w-5 h-5', 'title' => 'Home']);
```

Icons are Lucide's raw `stroke="currentColor"` SVGs, so CSS/Tailwind text-color utilities control their color for free. `Lucide::render()` returns `''` for an unknown or malformed icon name — safe to echo unconditionally.

---

## Performance

Performance hooks are registered by `Theme::boot()` / `Theme::bootstrapFullSite()` — **not** by
`Boot::data()`, and (since v1.42.0) no longer by merely loading the Composer autoloader. A consumer
that relied on the old load-time registration without ever calling `boot()` can restore it with
`define('TAW_PERFORMANCE_AUTOLOAD', true);` in `wp-config.php`.

```php
use TAW\Support\Performance;

Performance::configure([
    'remove_bloat'               => true,   // Gutenberg/FSE CSS in non-block themes
    'remove_emoji'               => true,   // ~20KB emoji detection scripts
    'remove_meta_tags'           => true,   // generator, wlw, rsd tags
    'preconnect_origins'         => ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
    'preload_fonts'              => [get_theme_file_uri('resources/fonts/Inter.woff2')],
    'preload_images'             => [[$hero_id, 'full']],
    'font_cache_htaccess'        => true,   // root .htaccess: fonts only, Apache
    'build_asset_cache_htaccess' => true,   // scoped .htaccess: whole dist/assets dir, Apache
    'modern_image_formats'       => true,   // new uploads generate as AVIF/WebP, core-only
]);
```

Also accepted as the `'performance'` key in `Theme::boot([...])`.

### Static-asset cache headers

Two independent, Apache-only `.htaccess`-injection mechanisms run once on `after_switch_theme`:

- **`font_cache_htaccess`** — writes a fonts-only `Cache-Control: immutable` block into the **site root** `.htaccess`, matched by extension (`woff2|woff|ttf|otf|eot`).
- **`build_asset_cache_htaccess`** — writes an unconditional `Cache-Control: immutable` block into a **`.htaccess` scoped to Vite's own `{dist}/assets/` directory** (JS, CSS, fonts, everything Vite emits). Scoped by directory rather than matched by extension in the root file on purpose: every file in that directory is content-hashed by Vite, so `immutable` is provably safe there — `wp-content/uploads/` images share the same extensions but are **not** hashed, so a root-level extension match would risk serving a stale image after a re-upload. This key is independent of `font_cache_htaccess`; both can run without conflict.

Both no-op silently if the relevant directory/file isn't writable, and `build_asset_cache_htaccess` also no-ops if the build hasn't run yet (`npm run build`) — it simply does nothing until the directory exists, no error state.

**Neither does anything on nginx** — `.htaccess` is an Apache-only mechanism, and a PHP process has no equivalent way to write nginx's own config. When `build_asset_cache_htaccess` is enabled and the site is detected running behind nginx (`$_SERVER['SERVER_SOFTWARE']`), a dismissible `wp-admin` notice shows the logged-in admin the exact block to paste into their server config, with this site's real theme/build path filled in:

```nginx
location ^~ /wp-content/themes/your-theme/public/build/assets/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

### Modern image formats (AVIF/WebP)

`modern_image_formats` hooks WordPress core's own `image_editor_output_format` filter (added 5.8 —
not a plugin) so newly-uploaded JPEG/PNG images generate their subsizes as AVIF, falling back to
WebP, checked at runtime via `wp_image_editor_supports()` — never assumed. Does nothing at all on a
host whose image library (Imagick/GD) can't actually encode either format.

This is a **replace**, not a dual-format generation — core has no built-in mechanism to save both
the original format and a modern-format sibling side by side, so the resulting files simply *are*
AVIF/WebP. `wp_get_attachment_image_src()`, `srcset`, and everything built on them — including
`TAW\Helpers\Image::render()` — pick this up automatically with **no template changes required**.
No `<picture>`-based fallback is needed either: AVIF/WebP support has been universal in shipping
browsers for years.

Only affects sizes generated going forward (new uploads, or a `Regenerate Thumbnails`-style
regeneration) — existing media library files are untouched until regenerated. Animated GIFs are
never in scope (the filter only ever sees `image/jpeg`/`image/png`).

---

## Menus

```php
use TAW\Core\Menu\Menu;

$menu = new Menu('primary-navigation');
foreach ($menu->items() as $item) {
    echo $item->label();
    echo $item->url();
    foreach ($item->children() as $child) { /* ... */ }
}
```

---

## TAW Media

Nestable Media Library folders, built on a single hierarchical taxonomy (`taw_media_folder`) registered on `attachment` with `show_in_rest => true`. That one flag is what gives the admin UI, for free, from WordPress core itself — no custom REST endpoint exists for this feature:

- full folder (term) CRUD, including re-nesting via `parent`, at `wp/v2/taw_media_folder`
- a `taw_media_folder` param on the existing `wp/v2/media` route, for filtering and for reassigning a file's folder, and the same route's own multipart upload support for direct-to-folder uploads

**Opt-in**, same pattern as everything else here:

```php
TAW\Core\Media\MediaFolders::enable();
```

`taw-theme`'s own `inc/customizations.php` scaffold calls this by default, so it ships active on every new taw-theme site — remove the line there if a given site doesn't need it. Folder management needs only the `upload_files` capability, not `manage_options`.

Three admin surfaces:

- **Media → TAW Media** — a dedicated, full Alpine.js app: a folder tree (create/rename/delete/drag-to-reparent), a breadcrumb, folder cards for navigating into subfolders from the grid pane, direct drag-and-drop file upload straight into the currently open folder, and multi-select with bulk move/delete. Including an "Unfiled" pseudo-folder for attachments with no folder assigned. Entirely our own markup/JS/REST calls — no WordPress core Grid-view (Backbone) internals are touched here.
- The classic Media Library **List view** (`upload.php?mode=list`) — a folder filter dropdown, a "Folder" column, and a "Move to folder…" bulk action, for anyone who prefers browsing there.
- The default Media Library **Grid view** (`upload.php`) — a FileBird-style sidebar (Alpine.js) with the same folder tree and full CRUD, bolted onto WordPress core's own thumbnail grid. Clicking a folder filters the grid live, via an `ajax_query_attachments_args` filter plus a narrow JS bridge that sets props on `wp.media`'s existing Backbone query object (`wp.media.frame.state().get('library').props`) — the same technique folder plugins like FileBird use, not a Backbone *view* override. Selecting a folder here stays in sync with the List view's dropdown filter (and vice versa) via the same `taw_media_folder` query param, so switching view modes doesn't lose your place. Thumbnails in the grid (single or multi-selected) are draggable directly onto a folder row or folder card to file them — a `dragstart`/drop-target bridge on top of WP core's own Backbone attachment views, not a fork of them — and internal drags are prevented from triggering WP core's own "drop files to upload" overlay (which otherwise fires on any drag reaching it, regardless of what's being dragged). Two independent sort controls, each remembered per-browser (`localStorage`): the folder tree sorts by name or creation order (oldest/newest), and the file grid sorts by name, upload date, or file size (largest/smallest) — file-size sorting reads a dedicated `_taw_media_filesize` postmeta value (backfilled once for pre-existing attachments the first time it's used, since WP core only stores file size nested inside a serialized metadata array that SQL can't `ORDER BY`).

A folder's place in the tree is its only "category" — one folder per attachment (`wp_set_object_terms()`), no separate tagging layer.

---

## Security / Hardening

`TAW\Core\Security\Hardening` collects opinionated hardening helpers. Unlike the opt-in subsystems above, these are **wired into `Theme::boot()` by default** — they are auth-gated, their failure mode is minimal, and each carries an `apply_filters()` escape hatch so a headless or integration site can restore stock WordPress behaviour without editing framework code.

### `Hardening::hideUsersEndpoint()` — user-enumeration lockdown

Removes the public `/wp/v2/users` REST collection and the single-user `/wp/v2/users/(?P<id>[\d]+)` route for **anonymous** requests. `/wp/v2/users/me` and every logged-in request are left untouched.

```php
// Already called for you by Theme::boot(). To opt a site back out,
// in inc/customizations.php (or a plugin):
add_filter('taw_security_hide_users_endpoint', '__return_false');
```

**Why filter at `rest_endpoints` and not match a URL.** The filter runs at REST dispatch — *after* the request has been resolved to a route — so it closes every routing form at once:

- `/wp-json/wp/v2/users` — the canonical path form.
- `/?rest_route=/wp/v2/users` — the **query-routed** form. Host WAF anti-enumeration rules and "hide users endpoint" plugins routinely match only the `/wp-json/` path prefix and miss this one, which still returns `200` and leaks `id`, `name`, and the author `slug` (≈ the login name) for every user on the site.
- `/batch/v1` sub-requests that wrap a `/wp/v2/users` call.

**Why it's gated on `is_user_logged_in()` rather than a capability.** Enumeration is an anonymous-attacker threat. The block editor's author selector fetches `/wp/v2/users?who=authors` for Editors too, who lack the `list_users` capability — gating on authentication keeps that (and the mobile apps, Jetpack, etc.) working while still closing the hole for logged-out visitors. `/wp/v2/users/me` already `401`s without a valid login, so it is not an enumeration vector and stays available to everyone.

The classic `?author=N` → `/author/{slug}/` redirect probe is **not** handled here — it's site policy (it also kills author-archive query URLs) and overlaps with security-plugin behaviour, so it lives in `taw-theme`'s scaffold `inc/security.php`, not in the framework.

---

## CLI

```bash
php bin/taw make:block HeroSection --type=meta --group=sections
php bin/taw import:block path/to/block
php bin/taw export:block HeroSection
php bin/taw inspect --json                          # live registry: blocks, fields, forms
php bin/taw fields:get 42 hero_heading --json        # read a field's current value
php bin/taw fields:set 42 hero_heading "Welcome"     # write a field's value
php bin/taw fields:set 42 book_details.subtitle "…"  # qualified id (fieldset.field) or the meta key also work
php bin/taw fields:set options company_phone "555-1234"  # write a site-wide OptionsPage field instead
php bin/taw sync --json                              # check for framework drift (see below)
php bin/taw sync --apply                             # also write Tier 1 scaffold changes
php bin/taw export:static                            # static HTML export for edge hosting (see below)
php bin/taw export:static --dir=/path --prod-url=https://my-site.pages.dev
php bin/taw seo:extract 42 --output=.taw/seo-dump.json         # copy audit: extract text fields (see below)
php bin/taw seo:inject 42 --input=.taw/seo-optimized.json      # copy audit: write rewrites back
php bin/taw wp post list --post_type=page                      # WP-CLI passthrough, socket/--path auto-resolved (see below)
php bin/taw icons:sync                                          # re-vendor the Lucide icon set (see Icon System)
php bin/taw hub:install --activate                              # install the taw-hub-companion fleet-management plugin (see below)
php bin/taw hub:enroll --token=enrol_…                          # register this site with a TAW Hub fleet (see below)
php bin/taw log:tail --level=error --limit=100                  # read back the structured log (see Logging)
php bin/taw content:export --output=/tmp/site.json              # portable content snapshot (see Content Interchange)
php bin/taw content:import /tmp/site.json                       # dry-run diff; add --yes to apply (rollback snapshot written first)
php bin/taw content:diff a.json b.json --out=changes.json       # two snapshots → a change-set for content:import
php bin/taw content:reindex --post-type=post,page --batch=20    # backfill/refresh the RAG chatbot's WP-content vectors (see Sovereign Hybrid-RAG Chatbot)
php bin/taw content:reindex-kb kb-a1b2c3d4                      # re-run ingestion for one admin-uploaded RAG knowledge base
php bin/taw corpus:install /path/to/bible.sqlite bible-straubinger.sqlite   # install a reference corpus (see Bible Reader Corpus)
php bin/taw corpus:export /path/to/bible.sqlite /path/to/bible-export.json  # portable export for a host with no pdo_sqlite
```

`make:block` generates the block folder, PHP class, template file, and Vite entry points.

`hub:install` fetches [`taw-hub-companion`](https://github.com/Relmaur/taw-hub-companion) into `wp-content/plugins/` (git clone; `--update` to pull latest, `--activate` to also `wp plugin activate` it). That plugin is the signed `wp-json/taw-hub/v1/` receiver the [TAW Hub](https://github.com/Relmaur/taw-hub) control hub talks to — telemetry, framework syncs, allow-listed `bin/taw` runs. It is **not** bundled with the theme on purpose: only sites that join a managed fleet need it, and it's a security boundary with its own release cadence. The command only fetches the code and prints the remaining (deliberate) steps — adding `TAW_HUB_PUBLIC_KEY` to `wp-config.php` and registering the site's key with the Hub; the `hub-connect` skill orchestrates those interactively. Until `TAW_HUB_PUBLIC_KEY` is defined the plugin is inert.

`hub:enroll` automates the last of those steps — registering the site with the Hub. It reads the identity `taw-hub-companion` generated on activation (`taw_hub_companion_public_key` / `_key_id` in the options table), the Hub URL (`TAW_HUB_URL`), and a one-time enrolment token (`--token`, or the `TAW_HUB_ENROLMENT_TOKEN` constant), and `POST`s them to the Hub's `POST /api/fleet/enroll` endpoint ([taw-hub ADR-0011](https://github.com/Relmaur/taw-hub/blob/main/docs/ADR/0011-site-enrolment.md)). That endpoint is not signature-guarded (the Hub does not know the site yet) — the token is the credential (single-use, 30-minute TTL, hashed at rest) — but every response *is* RESPONSE-signed with the Hub's Ed25519 identity, and `hub:enroll` verifies that signature against `TAW_HUB_PUBLIC_KEY` before trusting the reply. A signed `409 site_already_enrolled` is treated as idempotent success (a dropped connection after the Hub consumed the token); a signature-verification failure is a hard error and enrolment is reported as **not** confirmed. Boots WordPress via `WpLoader` like `inspect`. `--base-url` overrides the Hub-reachable URL (`home_url()` is often wrong behind a proxy or Herd's `:80`), `--dry-run` prints the request without sending, `--insecure` skips TLS verification for a local self-signed Hub.

`fields:get`/`fields:set` are the read/write halves of the same primitive `VisualEditorEndpoint` uses for its REST-driven saves — they resolve a field's type from the live `Metabox` registry (the post type's own fields first; a bare id that matches fields with two prefixes on that post type is refused, so pass the qualified id or the meta key), then dispatch to the matching type-aware getter/sanitizer (`Metabox::get_repeater()`, `sanitizeRepeaterRows()`, etc.), so a repeater, `post_select`, or `files` field is read/written in exactly the shape the admin form itself would produce, with the same sanitization rules (XSS-stripping, ID coercion, JSON re-encoding). `fields:set` takes `--file=path.json` for repeater/array-shaped values, to sidestep shell JSON-quoting, and `--dry-run` to preview the sanitized result without writing. Both commands boot WordPress, like `inspect` — field configs and post data only exist once WordPress is loaded, so they walk up from the theme directory to find `wp-load.php` via the shared `TAW\CLI\WpLoader` helper.

Pass the literal `options` in place of the post ID to target a site-wide `OptionsPage` field instead of a per-post `Metabox` field — the field is resolved via `OptionsPage::getFieldConfig()` (a bare-id lookup over `OptionsPage::getFieldRegistry()`) and written via `OptionsPage::writeOption()` (`update_option()`, sanitized the same way `writeMeta()` sanitizes a Metabox field — no `wp_slash()` first, since `update_option()` doesn't run the value through `wp_unslash()` the way `update_post_meta()` does). Everything else about the two commands — `--dry-run`, `--file`, `--json`, the per-type sanitize/decode rules — works identically for both scopes.

`sync` is the scriptable core of the `update-theme` Claude Code skill and the `.github/workflows/framework-sync.yml` CI workflow — it checks whether the installed `taw/core` version is behind the latest GitHub tag, and whether the project's Tier 1/Tier 2 `taw-theme` scaffold paths (defined once in `resources/update-manifest.json`, shipped with this package) differ from the canonical repo. Unlike every other command here, it deliberately does **not** boot WordPress — the checks don't need it, and CI runners won't have a WP+DB environment available. Tier 1 paths (nothing client-specific has ever lived there) can be applied directly with `--apply`; Tier 2 paths (docs/build config that can legitimately accumulate client-specific additions) are always report-only — `sync` never writes them, by design, regardless of flags. It never touches `taw/core` itself either; run `composer update taw/core` separately.

The two skills directories (`.claude/skills/`, `.agents/skills/`) are Tier 1 but use `type: skills-dir` rather than `type: dir`: instead of `rsync -a --delete` (which would wipe a client's own agent skills — they have to live in the same directory the framework's do, since that's the only place the agent runtimes auto-discover skills), `sync` reconciles per skill folder. A skill present in the canonical repo is overwritten; a skill present only in the client is kept or removed based on its `SKILL.md` YAML frontmatter — `owner: site` is preserved untouched, `owner: taw` (a retired framework skill) is deleted, and an unmarked skill is preserved with a warning. Every framework skill in the canonical `taw-theme` repo carries `owner: taw`. The marker key and values are declared in `resources/update-manifest.json` under `skillsReconcile`.

`export:static` fetches every published `page`/`post` over HTTP against its own permalink (a real request, same as a visitor's browser would make — this deliberately picks up anything hooked onto `template_redirect`/`the_content`, unlike rendering templates in-process would), rewrites absolute site-URL references, and writes `<dir>/<slug>/index.html` — plus the built Vite assets (`dist/`) and `wp-content/uploads/` — into a self-contained static bundle for edge hosting (Cloudflare Pages, Vercel, etc.). Boots WordPress the same way `inspect`/`fields:get`/`fields:set` do, via `WpLoader`. By default, absolute links are rewritten to root-relative paths (works on any deploy domain); pass `--prod-url` to rewrite to an absolute URL instead. See the `export-static` Claude Code skill (`taw-theme`'s `.claude/skills/export-static/`) for the guided agent workflow, including the one part of this that's easy to skip: forms and search are **not** exported statically — see below.

`seo:extract`/`seo:inject` are the read/write halves of a copy-and-SEO audit loop — see below.

`wp` is a thin passthrough to WordPress's own official CLI (the real `wp` binary, found on `PATH` via `Symfony\Component\Process\ExecutableFinder`) — every argument after `wp` is forwarded exactly as given, unparsed. Resolves two things that otherwise need manual, hand-typed configuration every time under Local by Flywheel: `--path` (the WordPress root, via the same `WpLoader::locate()` logic every other WP-booting command here uses) and the per-site MySQL socket (`WpLoader::resolveLocalSocket()` — Local runs a separate MySQL instance per site on its own Unix socket, not the system default `mysqli.default_socket`/`pdo_mysql.default_socket` PHP CLI otherwise uses, so a bare `wp` command run from an ordinary terminal fails with a DB connection error even though the site works fine in the browser). A no-op wrapper everywhere this doesn't apply (real hosting, CI, DDEV, Herd) — it still resolves `--path` and runs `wp` normally, just without the extra `-d` flags.

`log:tail` prints the most recent entries from `wp-content/taw-logs/taw.log.jsonl` (the file `TAW\Core\Log\JsonlFileSink` writes) — `--level=`, `--code=` (prefix match), `--since=` (ISO-8601), `--limit=` (default 50), `--json` for raw output to pipe. Resolves `wp-content` from `wp-load.php` via `WpLoader` like the other WP-adjacent commands; it does not boot WordPress. See [Logging](#logging).

`icons:sync` re-vendors the Lucide icon set this package ships (see [Icon System](#icon-system)) — shallow-clones `lucide-icons/lucide`, copies every icon SVG into `resources/icons/lucide/`, and rebuilds `resources/icons/lucide-index.json`. Doesn't boot WordPress or touch a consuming theme; only run it here, in `taw-core` itself, when Lucide ships new icons.

`content:reindex` and `content:reindex-kb` are the RAG chatbot's manual-backfill commands — see [Sovereign Hybrid-RAG Chatbot](#sovereign-hybrid-rag-chatbot). Uploading a new knowledge base itself is a wp-admin action, not a CLI one.

`corpus:install` installs a developer-curated reference corpus — see [Bible Reader Corpus](#bible-reader-corpus). Unlike a RAG knowledge base, this is CLI-only by design: no wp-admin upload screen. Accepts either a raw `.sqlite` file (needs `pdo_sqlite` on this host) or a portable JSON export (works on any host — see `corpus:export`), auto-detected by content.

`corpus:export` dumps a `.sqlite` reference corpus to the portable JSON format `corpus:install` can import into MySQL storage — run it on a machine that has `pdo_sqlite` (typically a developer's local machine), then transfer the output to and install it on a target host that doesn't.

---

## SEO & Copy Audit

`seo:extract <post_id>` (or `--all` for every published page/post in one run) walks the same live field registry `TAW\Core\Metabox\SeoContentIntegration` already walks to feed Yoast/SmartCrawl (`Metabox::getFieldRegistry()`, recursing into repeater rows via their own `fields` sub-schema), but keeps only `text`/`textarea`/`wysiwyg` fields with non-empty content — no image/URL/`post_select`/layout fields, to keep the dump small and focused on rewritable copy. Also extracts per-post SEO meta via `TAW\Core\Seo\SeoMeta` (see below). Output is hierarchical JSON grouped by block (`--all`'s shape wraps this in `{"posts": [...]}`):

```json
{
    "post_id": 42,
    "post_title": "Contact",
    "post_type": "page",
    "post_status": "publish",
    "seo_meta": {
        "source": "taw_native",
        "meta_title": "",
        "meta_description": "",
        "og_image_id": 0,
        "og_image_url": "",
        "featured_image_id": 0,
        "candidate_images": [
            { "field_id": "hero_image", "label": "Image", "block_id": "hero", "attachment_id": 12, "url": "https://..." }
        ]
    },
    "blocks": [
        {
            "block_id": "hero",
            "metabox_title": "Hero Section",
            "fields": [
                { "field_id": "hero_heading", "label": "Heading", "type": "text", "value": "Lorem Ipsum" }
            ]
        }
    ]
}
```

`seo:inject <post_id>` (or `--all`) writes an edited copy of that same shape back, with real safeguards — this isn't a thin wrapper around `update_post_meta()`:

- **Every field is validated against the live registry before anything is written for a given post.** Renamed/removed field, wrong field type (only `text`/`textarea`/`wysiwyg`/repeaters-of-them are accepted — anything else is rejected, use `fields:set` instead), or a malformed row all fail that post's batch, atomically — never a partial write. With `--all`, each post is validated and applied *independently* — one post failing doesn't block the others; the summary reports exactly which posts succeeded.
- **Repeater rows are merged, never replaced.** Extraction only keeps a row's text sub-fields (an image/URL sub-field on the same row is dropped, on purpose, to save tokens) — so injection re-reads the *current* live row and overwrites only the sub-field keys actually present in the input, leaving every other sub-field on that row untouched.
- **Row-count drift refuses instead of guessing.** If the live repeater's row count doesn't match the input (someone edited the post in the admin between extract and inject), the command refuses and says so — index-based row alignment is only meaningful if nothing moved in between.
- **`seo_meta` is validated against the live SEO-plugin state at write time, not what the dump recorded** — if a non-Yoast plugin (RankMath, etc.) has since become active, the write is rejected with a clear reason rather than silently landing in unused fields. `og_image_id: 0` (extraction's "no image set" sentinel) is never mistaken for an instruction to set the image to ID 0 — only a real attachment ID counts as a change, and it's verified to actually be an attachment before being accepted.
- **Never touches core post data.** Only `blocks[].fields[]` and `seo_meta` are ever read from the input file; a `post_title` key, if present, is silently ignored — same hard boundary `fields:set` documents.
- `--dry-run` reports the sanitized values that would be written, without writing.

The analysis itself — keyword presence, copywriting/CTA quality, readability, meta title/description/social image quality — is deliberately not part of either command; that's LLM judgment, not mechanical extraction. See the `audit-seo` Claude Code skill (`taw-theme`'s `.claude/skills/audit-seo/`) for the guided workflow: extract → analyze → report Red Flags/Polish Opportunities → (with explicit approval, direct write or a client-facing report) inject.

### `TAW\Core\Seo\SeoMeta` — per-post SEO meta, Yoast-aware

TAW has never owned meta title/description/social image natively — every real site either has an SEO plugin installed (Yoast, most commonly) or has had nothing at all: no `<title>` override, no `<link rel="canonical">`, no `<meta name="description">`, no Open Graph/Twitter tags, no robots control. `SeoMeta` (wired into `Theme::boot()`) fixes this without fighting whatever else might be installed:

- **No SEO plugin active** (the common case): registers its own lightweight metabox (SEO & Social — meta title, meta description, social share image, "hide from search engines" checkbox, editor-enabled like any other field) and:
  - Overrides the real `<title>` tag via the `document_title_parts` filter (only the page-specific half — WordPress's own "Title — Site Name" assembly still applies).
  - Adds `noindex` via the `wp_robots` filter (core's own pipeline since WP 5.7) when the checkbox is set — never a hand-printed `<meta name="robots">` tag, which would duplicate/conflict with core's own `max-image-preview:large` default.
  - Renders `<link rel="canonical">`, `<meta name="description">`, and OG/Twitter tags on `wp_head` (priority 1) — `og:type` is `article` for posts and `website` for everything else, with `article:published_time`/`article:modified_time` added for posts. `og:site_name`/`og:locale` are always included; `twitter:site` is pulled from the Twitter/X handle configured on `Schema`'s options page (see below), if set. Core's own default `rel_canonical()` output is removed at the same time, so there's never a duplicate canonical tag.
  - **Covers more than singular posts/pages** — home (front page or blog index), archives (category/tag/taxonomy/post-type/date/author), and search results all get a title/description/canonical/OG/Twitter treatment too, not just single posts/pages. A static front page (a `Page` selected under Settings → Reading) is still `is_singular()`, so it falls back to the site name/tagline (not the page's raw, possibly internal-only title) when no per-post meta title/description is set.
- **Yoast active** (`defined('WPSEO_VERSION')`): TAW's own metabox and all of the above output stand down entirely — Yoast already owns it, and duplicating any of it would split/duplicate SEO signal. `SeoMeta::write()` and the `seo:extract`/`seo:inject` CLI commands read and write Yoast's own meta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_opengraph-image-id`) directly in this case, so an agent-driven rewrite still lands somewhere the site owner's existing Yoast UI reflects it.
- **A different plugin active** (RankMath, SmartCrawl): detected only far enough to stand TAW's own UI/output down (avoiding duplicates) — not to write its meta. `SeoMeta::targetMetaKeys()['source']` reports `'unsupported'` in this case; `seo:inject` refuses any `seo_meta` write with a clear reason rather than guessing at that plugin's own key scheme.

**Auto-detection can be wrong** — it relies on version constants (`WPSEO_VERSION`, `RANK_MATH_VERSION`, `WPMU_DEV_SITE_ID`) that aren't guaranteed to be defined by every install of every plugin; a real production site running SmartCrawl produced simultaneous TAW + SmartCrawl output (duplicate `og:title`/canonical/JSON-LD) because `WPMU_DEV_SITE_ID` wasn't defined for that particular install. `SeoMeta::OUTPUT_MODE_FIELD` (on `Schema`'s **SEO Schema** settings page, since that page always registers regardless of detection state — see below) lets a site owner override detection explicitly:

- **Automatic** (default) — today's detection-based behavior.
- **Always on** — ignore detection, TAW's own `<title>`/meta/OG/JSON-LD always render.
- **Always off** — TAW stands down entirely, deferring to whatever plugin is actually installed.

```php
use TAW\Core\Seo\SeoMeta;

SeoMeta::isSeoPluginActive();      // true if a plugin is detected active, OR the override forces it true
SeoMeta::outputMode();             // 'auto' | 'force_on' | 'force_off' — the resolved OUTPUT_MODE_FIELD value
SeoMeta::targetMetaKeys();         // ['source' => 'taw_native'|'yoast'|'unsupported', 'title_key' => ?string, 'description_key' => ?string]
SeoMeta::metaTitle($postId);       // resolves from whichever store is currently authoritative
SeoMeta::write($postId, $title, $description, $ogImageId);  // null = leave that field unchanged
```

### `TAW\Core\Seo\Schema` — sitewide JSON-LD structured data

Before this existed, TAW emitted zero `schema.org` structured data anywhere — the single largest gap for AI-search/GEO visibility, regardless of whether `SeoMeta`'s own tags were present. `Schema` (also wired into `Theme::boot()`, right after `SeoMeta`) mirrors `SeoMeta`'s plugin-detection stand-down for its actual JSON-LD *output* — Yoast/RankMath/etc. already emit their own Organization/WebSite/Article/Breadcrumb schema, and duplicating it would produce conflicting structured data. The **SEO Schema** admin settings page itself always registers, regardless of detection state — see `SeoMeta::OUTPUT_MODE_FIELD` below for why.

When no SEO plugin is detected as active, it renders one `<script type="application/ld+json">` per page on `wp_footer` — deliberately not `wp_head`, since blocks (which can contribute their own nodes) render in the body, after `wp_head` has already fired. The **SEO Schema** page also holds organization type, name, logo, phone, Twitter/X handle, and a repeater of social profile URLs feeding the `sameAs` entity signal. The `@graph` always includes:

- **Organization** (or **LocalBusiness**, if selected) — name/url/logo/telephone/`sameAs`.
- **WebSite** — linked to the Organization node via `publisher`.
- **Article** — on singular posts only: headline, `datePublished`/`dateModified`, author (`Person`), image, linked to the Organization node via `publisher`.
- **BreadcrumbList** — on any singular post/page: Home → parent pages (for a `Page`) or primary category (for a `Post`) → current.

Blocks can add their own nodes to the same graph:

```php
use TAW\Core\Seo\Schema;

// Anywhere during template rendering, before wp_footer fires:
Schema::push(Schema::faqPage($items));  // $items: [['question' => ..., 'answer' => ...], ...]

// Or push an arbitrary schema.org node directly:
Schema::push(['@type' => 'HowTo', 'name' => '...', /* ... */]);
```

`Schema::faqPage()` is the reference example — `taw-theme`'s `FAQ` block calls it in its `index.php` template, from the exact same `$items` array the accordion markup renders from, so the two can never drift out of sync.

---

## Content Interchange

A first-class way to move a TAW site's **state** — posts / CPT entries, `_taw_*` metabox values, `_taw_*` options, terms, referenced media, and (opt-in) authorship, users, comments and environment settings — between environments, or to hand to a code agent to transform. Because a disciplined TAW site keeps everything in `_taw_` fields and options instead of a plugin stack, that state fits in one reviewable, diffable, rollback-able JSON file — no `.wpress` black box, no 400 MB `.sql`. Same **serialize → review → apply** loop as `seo:extract`/`seo:inject`, generalized to the whole site.

> **Migrate state, deploy code.** This tool never touches theme/plugin PHP or the DB binary internals — code travels through git and the deploy pipeline. Single-site only (no multisite).

```bash
php bin/taw content:export --output=/tmp/site.json          # content snapshot
php bin/taw content:export --migrate --output=/tmp/site.json # + users, settings, all media, drafts
php bin/taw content:import /tmp/site.json                   # dry-run: field-level diff, writes nothing
php bin/taw content:import /tmp/site.json --yes             # apply (rollback snapshot written first)
php bin/taw content:import /tmp/site.json --yes --with-settings   # also apply environment settings
php bin/taw content:diff before.json after.json --out=changes.json
```

Also, in wp-admin: **Tools → TAW Data** (Export with option checkboxes + Import-with-review), and `GET /wp-json/taw/v1/content/export` (capability `export`; content-only — no users/settings over REST).

### The snapshot

`TAW\Core\Content\Exporter::snapshot($scope)` — a plain array, `json_encode`-ready. Schema: [`resources/schema/content-interchange-1.2.json`](resources/schema/content-interchange-1.2.json) (`schema` is `"1.2"`; the importer also accepts `"1.0"` and `"1.1"`).

**Field keys (1.2):** a post's `fields` are keyed by the bare field id for `_taw_` fields (`"hero_heading"`), exactly as in 1.0 and 1.1, and by the full meta key for fields with any other prefix (`"_book_author"`). Each value is decoded with the config of the field registered for that post type (`Metabox::fieldsFor()`). Before 1.2, fields with another prefix were left out.

| Section | Contents |
|---|---|
| `meta` | `schema`, `generated_at`, `source` (url, `taw/core` version, theme), and a `registry_fingerprint` (block IDs + a `field_id → type` map) so the importer can warn on drift |
| `options` | every `_taw_*` option (repeater/files **decoded to arrays**), plus an allowlisted core set — `blogname`, `blogdescription`, `show_on_front`, `page_on_front`/`page_for_posts` **resolved to slugs**. Filter: `taw_content_export_core_options`. With `--with-settings`, also a **second** allowlist of environment settings (`permalink_structure`, `timezone_string`, `sticky_posts` → slugs, …). Filter: `taw_content_export_settings_options` |
| `terms` | per public taxonomy (except `nav_menu`): `{slug, name, description, parent (by slug), meta}`, plus `fields` (decoded, keyed like post fields) when the taxonomy has term fieldsets (v1.53.0+) |
| `posts` | `page`/`post`, every **public** CPT, **and** every CPT with a `Metabox` attached (so `public => false` content CPTs export without a manual filter) — except `taw_submission` and framework-internal types. Filter: `taw_content_export_post_types` (runs last). Per post: `type, slug, status, title, excerpt, content, menu_order, date, author ({login,email}), comment_status, ping_status, parent (by slug), template, terms, featured_media (filename), fields`. A slug-less draft also carries a composite `match_key` |
| `users` | opt-in (`--with-users`): `{login, email, display_name, roles[], meta{first_name,last_name,description,nickname,locale}, user_registered}`, plus `fields` when a fieldset targets users (v1.54.0+). Password hashes only with the second flag `--with-user-passwords` |
| `comments` | opt-in (`--with-comments`): comments on exported posts, with `parent_ref` threading |
| `media` | every referenced attachment: `{id, ref (filename), filename, url, title, description, alt, caption, mime}`. `--all-media` also carries unreferenced attachments |

**Never exported:** revisions, transients, non-allowlisted core/plugin options, `nav_menu`/`nav_menu_item` (code-owned in TAW themes). Drafts are excluded unless `--include-drafts`.

Scope options: `--types=`, `--since=`, `--posts=` (IDs or slugs), `--no-media`, `--all-media`, `--include-drafts`, `--with-users`, `--with-user-passwords`, `--with-comments`, `--with-settings`, `--migrate` (= `--with-users --with-settings --all-media --include-drafts`).

### Import — dry-run mandatory, rollback automatic

`TAW\Core\Content\Importer` consumes a snapshot **or** a change-set (`{taw_changeset, operations: [...]}`). Records are matched by natural key — posts by `(type, slug)` (or `(type, match_key)` for slug-less drafts), options by key, terms by `(taxonomy, slug)`, users by login→email, comments by a content hash — **never by numeric ID**.

- **Apply order** is dependency-first: `users → terms → media (sideload + id-map) → posts (resolve author, terms, media refs) → comments (threading rebuilt) → settings`.
- **Media:** each `media[]` entry is matched to an existing attachment by filename, else sideloaded from `url` (with its title / description / alt / caption). An `old id → new id` map is applied to `wp-image-N` / `"id":N` / `"ids":[…]` in `post_content` and to `image`/`files` field values before anything is written.
- **Portable transforms are reversed on import** — `page_on_front`/`page_for_posts` and `sticky_posts` (slugs), `post.parent` (slug), `featured_media` (filename), `author` (`{login,email}`) all resolve back to a local ID before the diff and the write. A clean **export → import of the same site is a verified no-op** (`--migrate` export then `import --yes` → 0 created / 0 updated / 0 deleted): `plan()` reports zero changes and `apply()` skips every record the dry-run shows unchanged.
- **`Importer::plan()`** produces the field-level diff (`unchanged` / `changed old→new` / `new` / `would-delete`) and writes nothing. `""` ↔ missing ↔ `null` ↔ `[]`, `"1"` ↔ `true`, `"[…]"` ↔ the decoded array all compare equal. `content:import` without `--yes` stops here.
- **Apply** writes a full **maximal-scope** `Exporter` snapshot to `wp-content/uploads/taw-private/` (an `.htaccess`-denied dir) first, then: posts via `wp_insert_post`/`wp_update_post`; **meta via `Metabox::writeMeta()`**; options via `update_option`; terms via `wp_insert_term`/`wp_update_term`; users via `wp_insert_user`/`wp_update_user` (roles sanitised against the target's defined roles); comments via `wp_insert_comment`. Per-record conflict policy `update` / `create` / `skip`. **Environment settings are also import-gated** — skipped unless `--with-settings` / the admin checkbox.
- **Report:** created / updated / skipped / deleted / media sideloaded / warnings (registry drift, unresolved author/parent, undefined role, missing media) / rollback path.

### REST-registered field meta

`Theme::boot()` also registers every TAW field over the REST API (`TAW\Core\Rest\FieldMetaRegistrar`), on every post type its metabox attaches to:

- **scalar fields** → `register_post_meta()` with `show_in_rest`, the field's own sanitizer, and an `auth_callback` gated on `edit_post` for that specific post. Since v1.52.0 each fieldset registers its own fields ([qualified ids](#schema--post-types-taxonomies-fieldsets-options-pages)): two fieldsets sharing a field id on different post types each get their own type and sanitizer.
- **repeater / files / post_select** (stored as JSON strings) → the raw meta stays a string, **and** a `register_rest_field()` computed field `taw_<id>` exposes the decoded object/array shape (and re-encodes on write) — so `Metabox::get_repeater()`'s physical storage is untouched. If fields with different prefixes share an id on one post type, the `_taw_` field owns `taw_<id>`.
- OptionsPage fields → `register_setting(..., 'show_in_rest' => …)`.

This exposes field values over `wp/v2` for **headless front-ends and external integrations**. It does **not** add a mobile-app editing UI — classic metaboxes stay desktop-only; direct on-phone editing to the [Visual Editor](#visual-editor). Opt out with `add_filter('taw_register_meta_in_rest', '__return_false')`.

---

## Sovereign Hybrid-RAG Chatbot

A visitor-facing chat widget that answers from **any number of named knowledge bases** — the site's own WordPress content, plus any `.sqlite` file an admin uploads — with an OpenAI-compatible LLM doing semantic search across whichever one the question calls for. Content-agnostic by design: no particular schema is assumed or required of an uploaded file. "Sovereign" describes data ownership, not hosting — the LLM endpoint is admin-configurable (`Settings → TAW Chatbot`), defaulting to OpenAI's cloud API but swappable to any OpenAI-compatible endpoint (self-hosted Ollama/vLLM/etc.) — your SQLite files and WordPress DB never leave the site either way.

> **Strict separation of concerns.** Every piece of this — SQLite connections, embeddings, LLM orchestration, REST — lives here in `taw/core`. The consuming theme owns only the chat widget's Alpine.js/Tailwind presentation and talks to `POST /taw/v1/chat`, nothing else — no LLM base URL or API key ever reaches the browser.

**Opt-in, same posture as Lucide/TAW Media** — the settings page, knowledge-base uploads, WP-content ingestion, and the `POST /taw/v1/chat` route all stay off (no admin menu, no hooks, no REST route registered) until a theme explicitly calls:

```php
// In the theme's inc/customizations.php, before Theme::boot():
TAW\Core\Rag\RagSettings::enable();
```

### Knowledge bases

`Settings → TAW Chatbot → Knowledge Bases` (`TAW\Core\Rag\KnowledgeBase\KnowledgeBaseAdminScreen`) — upload any `.sqlite` file with a name and description; that's the entire setup. On ingestion, every table is scanned and every column whose declared SQLite type has TEXT affinity (`CHAR`/`CLOB`/`TEXT`, or no declared type at all) is extracted as `"column: value"` lines per row, chunked, embedded, and written into a `taw_rag_chunks` table **inside that same file** — one file per knowledge base, not two. A table with no text-affinity column is skipped; nothing schema-specific is assumed.

The site's own WordPress content is always present as a built-in, non-deletable `wp-content` knowledge base — it isn't stored in the registry option at all, since it's fully derived from the `Settings → TAW Chatbot` post-type/chunking settings below and the existing `save_post`/`before_delete_post` ingestion pipeline. A post is eligible only while `publish` status, an indexed post type, and not password-protected — losing any of those (unpublish, trash, add a password) removes it from the index immediately, inline (no WP-Cron round-trip), so it never stays searchable after it's no longer publicly readable.

```bash
php bin/taw content:reindex --post-type=post,page --batch=20   # backfill/refresh the wp-content knowledge base
php bin/taw content:reindex-kb kb-a1b2c3d4                     # re-run ingestion for one uploaded knowledge base
```

Uploading itself is wp-admin only, by design — there's no CLI import step; the upload handler validates the SQLite magic-byte header before accepting a file, and ingestion runs via WP-Cron (never inline on the upload request).

### Settings

`Settings → TAW Chatbot` (`TAW\Core\Rag\RagSettings`): API base URL (default `https://api.openai.com/v1`), embedding/chat model names, indexed post types for the `wp-content` knowledge base (comma-separated, default `post,page`), chunk size/overlap, max tool-call iterations, and whether anonymous visitors can chat (default on). The LLM API key is **not** one of these fields — like `TAW_TURNSTILE_SECRET_KEY`, it's wp-config-constant-only, since OptionsPage fields are REST-readable by anyone with `edit_posts`:

```php
// wp-config.php
define('TAW_RAG_API_KEY', 'sk-...');
```

### Vector search

Every knowledge base's `taw_rag_chunks` table is searched with pure-PHP cosine similarity by default (`TAW\Core\Rag\Vector\VectorRepository`) — the [`sqlite-vec`](https://github.com/asg017/sqlite-vec) loadable extension isn't installed on most PHP hosts, and `PDO::loadExtension()` only exists on PHP 8.4+'s `Pdo\Sqlite` driver subclass in the first place, not on a plain `PDO` connection on any version. `VectorCapability::sqliteVecAvailable()` detects it at runtime and search falls back to the brute-force path on any failure — never a hard dependency.

### `POST /wp-json/taw/v1/chat`

```json
{"message": "Do you have anything about return policies?", "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]}
```

Runs an OpenAI-compatible tool-calling loop (`TAW\Core\Rag\Orchestrator\ChatOrchestrator`, capped at the configured max iterations, then forces one final non-tool answer) against a single tool:

- **`search_knowledge_base(knowledge_base, query)`** (`TAW\Core\Rag\Tools\SearchKnowledgeBaseTool`) — semantic search over one named knowledge base. Its `knowledge_base` parameter is an enum built fresh from the current registry on every request (so a newly-uploaded knowledge base is searchable the moment ingestion finishes, no redeploy needed), and the tool's own description lists each available knowledge base's id + human description inline so the model can pick the right one without a clarifying round-trip.

**Public by default** (`RagSettings::publicChatEnabled()`) — the endpoint's `permission_callback` doesn't gate anonymous requests, since WP's cookie-auth nonce check only protects logged-in callers anyway. The actual defense is unconditional rate limiting via `TAW\Core\Form\RateLimiter` (20 requests/10 min per IP), applied regardless of the public/logged-in-only setting.

---

## Bible Reader Corpus

A read-only REST surface over a developer-installed reference corpus — currently a Straubinger-translation Spanish Catholic Bible (`books`/`chapters`/`verses`/`sections`/`notes` + full-text search), built for the fsspx-taw client site's `/formacion/bible` reader but framework-level and content-agnostic in the same spirit as [Content Interchange](#content-interchange): the schema is fixed (this isn't a generic query layer — see [Sovereign Hybrid-RAG Chatbot](#sovereign-hybrid-rag-chatbot)'s knowledge bases for that), but the storage/install/REST plumbing generalizes to any future reference corpus the same shape describes. See `docs/adr/0001-reference-corpus-storage.md` and `docs/adr/0002-corpus-mysql-fallback.md` for the full reasoning behind the decisions below.

**Opt-in** — call `TAW\Core\Rest\BibleEndpoint::enable()` in the theme's `customizations.php` before `Theme::boot()`, same posture as `Lucide::enable()`/`RagSettings::enable()`. Nothing here registers on a site that doesn't call it.

### Two storage backends — SQLite by default, MySQL when `pdo_sqlite` isn't available

`TAW\Core\Storage\ProtectedSqlite::isAvailable()` checks `extension_loaded('pdo_sqlite')` *and* attempts a real `sqlite::memory:` connection (a loaded extension isn't always a functional one on every host build). This is a real, confirmed gap on production managed hosting — not theoretical: WPMUdev's managed hosting has no `pdo_sqlite`/`sqlite3` on either PHP-FPM or CLI, on multiple PHP versions, and declined to add it ("As a Managed Hosting service, we are unable to implement custom extensions... the database we offer is MySQL, not SQLite"). `$wpdb` — and the `mysqli` extension it's built on — is guaranteed on every WordPress host, since WP core itself can't function without it, unlike `pdo_sqlite`, which nothing requires.

Every consumer (`bin/taw corpus:install`, `TAW\Core\Rest\BibleEndpoint`) checks `isAvailable()` and picks a backend accordingly — **zero behavior change on a host where `pdo_sqlite` already works**; the SQLite path is checked first and used unconditionally whenever it's both available and installed.

### Installing the corpus file

A reference corpus is a curated, developer-placed dataset, not end-user content — installed via CLI, not a wp-admin upload form. `bin/taw corpus:install` accepts either of two source formats, auto-detected by content:

```bash
# 1. Raw .sqlite file — needs pdo_sqlite on THIS host:
php bin/taw corpus:install /path/to/bible_straubinger.sqlite bible-straubinger.sqlite

# 2. Portable JSON export — works on any host, no pdo_sqlite required here at all:
php bin/taw corpus:install /path/to/bible-export.json bible-straubinger.sqlite   # filename arg is unused for this path
```

**Path 1 (`.sqlite`)** — `TAW\CLI\CorpusInstallCommand` validates the SQLite magic-byte header (same check `KnowledgeBaseAdminScreen` uses for RAG knowledge-base uploads), confirms `ProtectedSqlite::isAvailable()` on *this* host, then copies the file into a protected uploads subdirectory — `TAW\Core\Corpus\Storage`, `wp-content/uploads/taw-private/corpus/`, a directory deliberately separate from the RAG chatbot's `taw-private/rag/` (see ADR-0001: a reference corpus and a RAG knowledge base are different concerns that happen to share "protected dir + read-only PDO" plumbing, extracted into `TAW\Core\Storage\ProtectedSqlite` rather than duplicated). If `pdo_sqlite` isn't available here, the command fails with a clear message pointing at path 2 instead of a bare `PDOException` from deep inside the reader.

**Path 2 (portable JSON)** — for a target host with no `pdo_sqlite` at all. First, on a machine that *does* have it (typically a developer's local machine):

```bash
php bin/taw corpus:export /path/to/bible_straubinger.sqlite /path/to/bible-export.json
```

`TAW\CLI\CorpusExportCommand` reads the source `.sqlite` via plain PDO (no WordPress dependency — both paths are given directly as arguments) and writes a portable JSON export carrying only the columns `BibleReader` actually reads. Transfer that JSON file to the target server, then run `corpus:install` against it there — `TAW\Core\Corpus\Bible\MysqlBibleInstaller` creates `{$wpdb->prefix}taw_corpus_bible_{books,chapters,verses,sections,notes}` (`FULLTEXT` indexes on `verses.text`/`notes.body`) and bulk-loads the export via `$wpdb`, no `pdo_sqlite` needed on that host at any point.

Both paths are safe to re-run against an updated source: the `.sqlite` path just overwrites the copied file; the JSON path truncates and reloads its MySQL tables. The installed filename (path 1) is fixed and versionless — the file's own `meta` table carries `release_channel`/`generated_at`/`source_revision`, so reader code never needs to know which build is installed.

> **A real MySQL query failure is a loud CLI error, not a false success.** Every `$wpdb->query()` call in `MysqlBibleInstaller` is checked — a `false` result throws with `$wpdb->last_error`, and `install()` reports row counts read back from MySQL via `COUNT(*)` *after* the import, not the input export's own counts. (`TRUNCATE TABLE` is DDL on InnoDB/MariaDB and causes an implicit commit, so the transaction wrapping around the five-table reload isn't a true cross-table atomicity guarantee — the loud-failure behavior is the real safety net, not the transaction. See the ADR's addendum.)

### Reading the corpus

`TAW\Core\Corpus\Bible\BibleReaderInterface` is implemented by two independent readers with identical output shapes — `BibleReader` (SQLite, plain PDO, opened via `Storage::openReadOnly()` — `PRAGMA query_only = 1`, a reference corpus is never written to at runtime) and `MysqlBibleReader` (MySQL, plain `$wpdb`, selected automatically when `pdo_sqlite` isn't available). Deliberately two separate classes rather than a shared abstract base — SQLite FTS5 and MySQL boolean-mode `FULLTEXT` differ enough (no `snippet()` equivalent in MySQL; `MysqlBibleReader` builds excerpts by hand) that sharing internals would mostly move complexity around, and it keeps the already-shipped `BibleReader` completely untouched by this addition:

```php
$reader = new TAW\Core\Corpus\Bible\BibleReader();        // or MysqlBibleReader() — same contract

$reader->books();                       // every book, grouped by testament then division, in canonical order
$reader->chapter('genesis', 1);         // verses + any overlapping section headings + any overlapping notes
$reader->searchVerses('en el principio'); // every word must match, any order, with a <mark>-highlighted excerpt
$reader->searchNotes('creación');         // same word-matching semantics over Straubinger's own footnote commentary
```

`chapter()` deliberately returns `verses`/`sections`/`notes` as three flat, chapter-scoped lists rather than an interleaved rendering shape — where a heading sits relative to a verse, or how a note marker anchors into verse text, is presentation, and stays the consuming theme's decision. `searchVerses()`/`searchNotes()` treat every whitespace-separated word of the query as a separate AND'd term — a row must contain every word, in any order or position — rather than exposing raw `MATCH` operator syntax to a public search box, or requiring the whole query as one exact contiguous phrase (which would silently return nothing for almost any realistic multi-word search). SQLite FTS5 does this via per-word quoted phrases; MySQL boolean mode via `+word1 +word2` — same semantics, different syntax.

> **`innodb_ft_min_token_size`.** `MysqlBibleReader` additionally drops any word shorter than MySQL's actual configured `innodb_ft_min_token_size` (default 3, read at query time via `SELECT @@innodb_ft_min_token_size`) from the required set before searching. InnoDB never indexes a word below that length, so a required `+word` term for one recreates the exact-match failure this whole design avoids — confirmed against a real MySQL server: `+amor +de +Dios` returned nothing until "de" was dropped. Spanish is full of words this short (`de`/`la`/`el`/`en`/`un`/...), so this isn't an edge case.

`TAW\Core\Rest\BibleEndpoint` resolves which reader it queries — SQLite first, MySQL otherwise — through a filter, so a theme can override either the pick itself or supply an entirely different implementation:

```php
add_filter('taw_corpus_bible_reader', function () {
    return new MyThemeBibleReader(); // any BibleReaderInterface implementation
});
```

The filter's return is narrowed via `instanceof BibleReaderInterface`, falling back to the computed default (SQLite-or-MySQL) if a filter callback returns something else. `BibleReader` itself is not `final` — every internal fetch method is `protected`, `FILENAME` is overridable — so a theme can also subclass it directly (different installed filename, different fetch behavior) without a taw-core fork.

### `GET /wp-json/taw/v1/bible/books`

Every book, grouped by testament then division — the shape a books-list nav needs directly.

### `GET /wp-json/taw/v1/bible/books/{slug}/chapters/{n}`

One chapter: `{book, chapter_number, verses, sections, notes}`. 404 if the book slug or chapter number doesn't exist.

### `GET /wp-json/taw/v1/bible/search?q=...&scope=verses|notes&limit=20`

Full-text search (`scope` defaults to `verses`; `limit` is clamped to 1-50) — SQLite FTS5 or MySQL `FULLTEXT`, whichever backend is installed.

All three routes 404 with `{"error": "No Bible corpus is installed."}` until `corpus:install` has been run. **Public** (no auth — this is public Scripture text) but rate limited regardless: 120 requests/10 min per IP for `books`/chapter reads, 30 requests/10 min per IP for `search` (a heavier query, and a more attractive scraping target).

---

## Catechism Reader Corpus

One level deeper than [Bible Reader Corpus](#bible-reader-corpus)'s book → chapter → verse: a catechism's part → section → chapter → numbered question/answer. Unlike the single-installed Bible, this is explicitly **edition-parameterized** from day one — more than one catechism (the Catechism of Saint Pius X today, others later) can be installed side by side. Same storage/install/REST posture and the same two-backend split as the Bible corpus, applied one navigational level deeper.

**Opt-in** — call `TAW\Core\Rest\CatechismEndpoint::enable()` in the theme's `customizations.php` before `Theme::boot()`.

### Editions

`TAW\Core\Corpus\Catechism\CatechismEditions` is the one place valid edition slugs are declared — a plain, developer-curated array, not admin-configurable, same posture as the Bible's fixed filename:

```php
'pius-x' => ['filename' => 'catechism-pius-x.sqlite', 'name' => 'Catecismo Mayor de San Pío X'],
```

Adding a second edition is one new array entry plus installing its own `.sqlite` file or export — no other code in this namespace changes. `GET /wp-json/taw/v1/catechism/editions` lists every registered edition, installed or not.

### Installing an edition

`bin/taw catechism:install <edition> <path> [filename]` — the same two source formats as `corpus:install`, scoped to one edition at a time:

```bash
# Raw .sqlite — needs pdo_sqlite on THIS host:
php bin/taw catechism:install pius-x /path/to/catechism-pius-x.sqlite

# Portable JSON export — any host, no pdo_sqlite required here at all:
php bin/taw catechism:export /path/to/catechism-pius-x.sqlite /path/to/export.json
php bin/taw catechism:install pius-x /path/to/export.json
```

Unlike the Bible's five MySQL tables (one corpus, one table-set), `MysqlCatechismInstaller` writes into **one shared table-set across every edition** — each row carries both `edition` and the source file's own `source_id` (`UNIQUE (edition, source_id)` stands in for the source's primary key, since two independently-exported editions can both legitimately use `id = 1`). Re-running `catechism:install` for one edition only ever touches that edition's own rows: `reload()` issues `DELETE FROM ... WHERE edition = ?`, not `TRUNCATE TABLE` — DML, not DDL, so it carries none of the implicit-commit caveat `MysqlBibleInstaller`'s per-table `TRUNCATE` has. That makes the transaction wrapping around a catechism install a genuine cross-table atomicity guarantee: a failure partway through actually rolls back every `DELETE`/`INSERT` the run issued, not just the table it happened to be mid-way through.

Every `$wpdb->query()` call is checked the same way `MysqlBibleInstaller` learned to check it in production — a `false` result throws with `$wpdb->last_error`, and `install()` returns row counts read back via `COUNT(*)` after the import, never the input export's own counts.

### Reading an edition

`TAW\Core\Corpus\Catechism\CatechismReaderInterface` — implemented by `CatechismReader` (SQLite) and `MysqlCatechismReader` (MySQL fallback), the same independent-implementation choice as the Bible reader. Every method takes `$edition` explicitly rather than assuming a single installed catechism:

```php
$reader = new TAW\Core\Corpus\Catechism\CatechismReader();    // or MysqlCatechismReader() — same contract

$reader->parts('pius-x');                     // full part → section → chapter tree; each chapter carries its own paragraph_count
$reader->chapter('pius-x', 12);                // one chapter's question/answer paragraphs + part/section breadcrumb
$reader->searchParagraphs('pius-x', 'gracia'); // full-text search across both question_text and answer_text
```

`MysqlCatechismReader` reuses `MysqlBibleReader`'s `innodb_ft_min_token_size` short-word filtering from the start rather than rediscovering the same production gap — see [Bible Reader Corpus](#bible-reader-corpus)'s note on that setting.

`TAW\Core\Rest\CatechismEndpoint` resolves the reader the same SQLite-first, filterable way `BibleEndpoint` does, via the `taw_corpus_catechism_reader` filter.

### Routes

- `GET /wp-json/taw/v1/catechism/editions` — every registered edition, installed or not.
- `GET /wp-json/taw/v1/catechism/{edition}/parts` — the full navigable tree for that edition.
- `GET /wp-json/taw/v1/catechism/{edition}/chapters/{id}` — one chapter's paragraphs + breadcrumb.
- `GET /wp-json/taw/v1/catechism/{edition}/search?q=...&limit=20` — full-text search (limit clamped 1-50).

An unknown edition slug 404s with `{"error": "Unknown catechism edition."}`; a known-but-not-yet-installed edition 404s separately with `{"error": "This catechism edition is not installed."}` — kept distinct so a misconfigured `customizations.php` doesn't look identical to "just hasn't been installed yet." **Public** (no auth), rate limited the same as the Bible routes: 120 requests/10 min per IP for reads, 30 requests/10 min per IP for search.

---

## Static Export & Headless CORS

`export:static` only freezes what's actually static: rendered page/post HTML, Vite assets, and uploads. Forms (`admin-ajax.php?action=taw_form_*`) and search (`GET /taw/v1/search-posts`) stay dynamic by design — they keep hitting this WordPress install, over the network, exactly as before. That's the right call: there's no server at a static host to answer them otherwise.

**`export:static` bakes the absolute WordPress URL into every form `action` and search `fetch()` call, unconditionally — this is not affected by `TAW_HEADLESS_ORIGINS` in either direction.** That constant doesn't change *where* those requests go; it only changes whether the *browser* is allowed to let them complete once the page is loaded from a different origin than this WordPress install. Without it, the request still fires at the WordPress domain — the browser's CORS preflight/response check just fails and blocks it client-side. With it set for the exported bundle's actual origin, that block goes away. If you're debugging "the exported form/search doesn't work," check this distinction first: a request that never left the browser (CORS block) and a request that 404s at the WordPress domain are different failures with different fixes.

Once the exported bundle is deployed to a **different domain** than this WordPress install, those requests become cross-origin and the browser will block them until CORS is explicitly opened up — off by default, same posture as Turnstile:

```php
// wp-config.php
define('TAW_HEADLESS_ORIGINS', 'https://my-site.pages.dev');
// or a comma-separated list:
define('TAW_HEADLESS_ORIGINS', 'https://my-site.pages.dev,https://staging.my-site.pages.dev');
```

`TAW\Core\Rest\Cors::register()` (wired into `Theme::boot()`, no-op unless the constant is set) handles both surfaces differently, on purpose:

- **REST (`/taw/v1/...`)** — extends WordPress core's own `allowed_http_origins` filter rather than emitting Access-Control headers by hand. Core's `rest_send_cors_headers()` already answers OPTIONS preflights correctly for any origin core considers allowed; this just adds your headless origin(s) to that list.
- **`admin-ajax.php`** — core has no CORS awareness here at all (same-origin only, always). `Cors` adds the headers itself and short-circuits OPTIONS preflights, but only for `taw_form_*` actions — not opened up for every admin-ajax action on the install.

Never `Access-Control-Allow-Origin: *` — origins are checked against an explicit allowlist and reflected back, which is required anyway once cookies/credentials are ever in play, and is a meaningfully smaller attack surface for form-accepting endpoints than a wildcard.

---

## Helpers

```php
use TAW\Helpers\Framework;
Framework::path('assets/admin.css');   // Absolute path within taw-core
Framework::url('assets/admin.css');    // URL within taw-core
Framework::themePath('resources/');
Framework::themeUrl('resources/');

use TAW\Helpers\Image;
Image::render($attachment_id, 'large', ['class' => 'hero-img', 'loading' => 'lazy']);
Image::preloadTag($attachment_id, 'full');

use TAW\Helpers\Dump;
Dump::dd($value);
Dump::log($value);
```

---

## Dependencies

| Package | Purpose |
|---------|---------|
| `ext-pdo_sqlite` _(optional, suggested)_ | The RAG chatbot's SQLite knowledge-base files (see [Sovereign Hybrid-RAG Chatbot](#sovereign-hybrid-rag-chatbot)) and reference-corpus files (see [Bible Reader Corpus](#bible-reader-corpus)). Not required to install taw/core — each feature checks for it at runtime, and the corpus readers fall back to MySQL. |
| `symfony/console ^7.4` | CLI commands |
| `symfony/process ^7.4` | `wp` command — shells out to the real WP-CLI binary |
| `enshrined/svg-sanitize ^0.22.0` | SVG XSS prevention on upload |
| `spatie/mjml-php ^1.0` _(dev)_ | Email template transpilation |
| `phpstan/phpstan ^2.2` _(dev)_ | Static analysis |
| `szepeviktor/phpstan-wordpress ^2.0` _(dev)_ | WordPress core stubs for PHPStan |
| `phpunit/phpunit ^11` _(dev)_ | Unit test runner |
| `brain/monkey ^2.7` _(dev)_ | Mocks individual WP functions for unit tests, no real WordPress install needed |
| `emailit/emailit-php` _(optional, suggested)_ | Powers `EmailConfig::useEmailit()` (see § "Email") — only needed on sites that opt into it |

**Alpine.js** (every admin-side interactive widget: Metabox fields, Options Page, the Icon picker, Media Folders) is vendored at `assets/vendor/alpine.min.js` (pinned version, currently 3.15.12) and enqueued via `TAW\Support\Alpine::enqueue()` — not loaded from a CDN. `taw/core` is installed on arbitrary client sites, some offline or behind restrictive CSPs, so a CDN dependency for a required admin script isn't safe to assume.

## Static Analysis

```bash
composer run phpstan   # level 5, src/ only, WordPress-aware — also runs in CI
```

`phpstan-baseline.neon` currently holds 26 pre-existing findings (mostly WP_Post dynamic-property access in `MenuItem`, a Symfony Console helper interface gap, and a few PHPDoc-narrowing false positives) captured when the check was first introduced — don't add newly-introduced errors to it; fix those at the source. Chip away at the baseline over time rather than treating it as permanent.

## Unit Tests

```bash
composer run test   # tests/Unit/ — also runs in CI
```

Uses [Brain Monkey](https://brain-wp.github.io/BrainMonkey/) to stub individual WordPress functions (`add_action`, `get_transient`, `wp_remote_post`, etc.) per test rather than booting a real WordPress install — fast, no MySQL, no network. This is a deliberate division of labor with `taw-theme`'s `bin/ci/smoke-test.php`, which boots a real WordPress + MySQL environment and exercises the full render path against a live theme: this suite covers `taw-core`'s own logic in isolation (validation rule precedence, rate limiting, Turnstile verification), the smoke test covers "does this actually work end-to-end against a real site."

Every file in `src/` starts with `if (!defined('ABSPATH')) exit;` (the standard WordPress direct-access guard) — `tests/bootstrap.php` defines a dummy `ABSPATH` before the autoloader ever loads a class, or the test process would exit the moment one is included. Documented exceptions: `TAW\Core\Boot` (the data-layer entry point — `BootTest` covers it), `Content\*` (autoloaded by `content:*` commands before WordPress boots — see "Content Interchange"), and `TAW\Core\Corpus\Storage`/`TAW\Core\Storage\ProtectedSqlite` (autoloaded by `CorpusInstallCommand` before `require $wpLoad` — see "Reading the corpus"). Each has neither a WordPress dependency of its own nor the guard, and a subprocess regression test (`PreBootAutoloadTest`) asserting it loads with no `ABSPATH` defined at all.

**Reflection is used deliberately** for testing private methods (`Form::validateRules()`, `Form::requiredMessage()`, `Form::emailMessage()`) — see `tests/TestCase::callMethod()`. These stay private by design (internal details of a public API), reflection lets tests verify that logic without widening the class's real surface just to make it testable.

**`Brain\Monkey\Functions` is a namespace of functions, not a static class** — call sites are `Functions\when(...)`/`Functions\expect(...)` (after `use Brain\Monkey\Functions;` imports the namespace), not `Functions::when(...)`. Easy to get wrong once from muscle memory with other mocking libraries; the whole suite failed with "Class not found" the first time for exactly this reason.

**Constants defined via `define()` (e.g. `TAW_TURNSTILE_SITE_KEY`) are process-global and permanent** — a test can't "un-define" one for a later test in the same PHPUnit process. `TurnstileTest` defines them once (guarded, `setUpBeforeClass`) and only tests the configured state; `TurnstileNotConfiguredTest` covers the undefined-constants state in its own class, using `#[RunInSeparateProcess]` on every method so neither class's constant state can leak into the other regardless of run order.

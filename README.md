# TAW Core

A configuration-driven WordPress theme framework. Block system, metabox engine, Vite asset pipeline, frontend visual editor, forms, and performance tools — wired up with a single call.

**PHP 8.1+ · GPL-2.0-or-later · `composer require taw/core`**

---

## Quickstart

In your theme's `functions.php`:

```php
use TAW\Core\Theme\Theme;

Theme::boot();
```

Auto-discovers blocks, initializes Vite, registers REST endpoints, enables the visual editor, and applies performance config.

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

All fields accept: `id`, `label`, `description`, `placeholder`, `default`, `required`, `width` (%).

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

### Reading Values

```php
$value = Metabox::get($post_id, 'field_id');
$rows  = json_decode(Metabox::get($post_id, 'team'), true); // repeater
```

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

Template resolution mirrors WordPress's own hierarchy, not just the raw Page Attributes selection:

- If the post has an explicit page template selected (`get_page_template_slug()`), that file is used — as above.
- Otherwise, if the post is the site's static front page (Settings → Reading), `front-page.php` is used automatically — no `Template Name:` header or Page Attributes selection required, since `front-page.php` renders whenever `is_front_page()` is true regardless of what's picked in that dropdown.
- Posts matching neither are left unordered.

The posts page (`page_for_posts` / `home.php`) has the same filename-convention resolution in core WordPress but isn't handled yet — a candidate for the same treatment if needed.

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

Dev mode detected via socket to `localhost:5173`. Production reads `dist/.vite/manifest.json` (cached 24 hours in-process).

```php
use TAW\Support\ViteLoader;

ViteLoader::init('resources/js/app.js');
ViteLoader::enqueueAsset('my-block', 'resources/js/blocks/my-block.js');
$url = ViteLoader::assetUrl('resources/fonts/Inter.woff2');
ViteLoader::inlineCriticalCss('resources/css/critical.css');
ViteLoader::preloadAssets(['resources/js/chunks/vendor.js']);
```

---

## Forms

Configuration-driven AJAX forms with CSRF protection, honeypot spam filtering, per-field validation, email delivery, and submission persistence.

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

### Email Configuration

```php
'email' => [
    'to_self'   => ['subject' => 'New submission',     'template' => 'contact-self'],
    'to_client' => ['subject' => 'Got your message!',  'template' => 'contact-client'],
],
```

No `template` → plain-text fallback via `wp_mail()`. `to_client` requires an `email` field in the form.

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
| _(any other)_ | Passed straight through as HTML `type` attribute |

**Structural fields** — no `id`, no validation, no submission data

| Type | Notes |
|------|-------|
| `heading` | `label` + optional `subtitle` |
| `divider` | `<hr>` |
| `html` | `content` key; rendered with `wp_kses_post` |

All input fields accept: `id`, `label`, `type`, `required`, `placeholder`, `width`, `conditions`.

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

---

## Visual Editor

Inline admin editing on the frontend. **Opt-in** — must be explicitly enabled per theme:

```php
// In the theme's functions.php, before Theme::boot()
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

## Performance

```php
use TAW\Support\Performance;

Performance::configure([
    'remove_bloat'       => true,   // Gutenberg/FSE CSS in non-block themes
    'remove_emoji'       => true,   // ~20KB emoji detection scripts
    'remove_meta_tags'   => true,   // generator, wlw, rsd tags
    'preconnect_origins' => ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
    'preload_fonts'      => [get_theme_file_uri('resources/fonts/Inter.woff2')],
    'preload_images'     => [[$hero_id, 'full']],
]);
```

Also accepted as the `'performance'` key in `Theme::boot([...])`.

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

## CLI

```bash
php bin/taw make:block HeroSection --type=meta --group=sections
php bin/taw import:block path/to/block
php bin/taw export:block HeroSection
```

`make:block` generates the block folder, PHP class, template file, and Vite entry points.

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
| `symfony/console ^7.4` | CLI commands |
| `enshrined/svg-sanitize ^0.22.0` | SVG XSS prevention on upload |
| `spatie/mjml-php ^1.0` _(dev)_ | Email template transpilation |

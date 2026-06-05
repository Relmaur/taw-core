# TAW Core

A modern, configuration-driven WordPress theme framework. Provides a block system, metabox engine, Vite asset pipeline, frontend visual editor, forms, and performance tools — all wired up with a single call.

**PHP 8.1+ · GPL-2.0-or-later · Composer package `taw/core`**

---

## Table of Contents

- [Installation](#installation)
- [Quickstart](#quickstart)
- [Architecture Overview](#architecture-overview)
- [Block System](#block-system)
- [Metabox System](#metabox-system)
- [Options Page](#options-page)
- [Vite Asset Pipeline](#vite-asset-pipeline)
- [Forms](#forms)
- [Visual Editor](#visual-editor)
- [REST Endpoints](#rest-endpoints)
- [SVG Support](#svg-support)
- [Performance Optimizations](#performance-optimizations)
- [Menus](#menus)
- [CLI Commands](#cli-commands)
- [Helpers & Utilities](#helpers--utilities)

---

## Installation

```bash
composer require taw/core
```

Requires `symfony/console` and `enshrined/svg-sanitize` (pulled automatically).

---

## Quickstart

In your theme's `functions.php`:

```php
use TAW\Core\Theme\Theme;

Theme::boot();
```

That single call auto-discovers blocks, initializes the Vite pipeline, registers REST endpoints, enables the visual editor, and applies any configured performance optimizations.

---

## Architecture Overview

```
src/
├── Core/
│   ├── Block/          # Block system (BaseBlock, Block, MetaBlock, BlockRegistry, BlockLoader)
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
│   └── utilities.php   # Global helper functions (auto-loaded)
├── Helpers/
│   ├── Framework.php   # Path/URL resolver for portable use
│   ├── Image.php       # Attachment image utilities
│   ├── Svg.php         # SVG upload, sanitization, rendering
│   ├── Editor.php      # Visual editor utilities
│   └── Dump.php        # Debug utilities
└── CLI/
    ├── MakeBlockCommand.php    # Scaffold new blocks
    ├── ImportBlockCommand.php  # Import blocks
    └── ExportBlockCommand.php  # Export blocks
```

**Namespace:** `TAW\` → `src/` (PSR-4)

---

## Block System

Blocks live in the theme's `/Blocks` directory. `BlockLoader` auto-discovers them at boot.

### Simple Block

```php
namespace Blocks\Hero;

use TAW\Core\Block\Block;

class Hero extends Block {
    protected function defaultData(): array {
        return ['title' => 'Default Title'];
    }
}
```

### Block with Post Meta (MetaBlock)

`MetaBlock` adds metabox registration and post-meta reading on top of `BaseBlock`. Two abstract methods must be implemented:

- `registerMetaboxes()` — called at `init` (translations are safe here)
- `getData(int|false $postId)` — returns template variables for the block

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
            'headline' => $this->getMeta($postId, 'headline') ?: 'Default Headline',
            'image'    => $this->getImageUrl($postId, 'image'),
        ];
    }
}
```

`registerMetaboxes()` is deferred internally to the `init` action, so calling `__()` for labels is always safe regardless of when the block class is instantiated.

`getData()` accepts `int|false` — on 404 pages `get_the_ID()` returns `false`, and the meta helpers (`getMeta`, `getImageUrl`, `getRepeater`) all return safe empty values in that case.

### boot() — early setup and form registration

Override `boot()` for anything that must hook in early on every request — including registering forms that the block renders. Unlike `registerMetaboxes()`, `boot()` is called during block discovery (at `after_setup_theme`), so wrap translation calls in `add_action('init', ...)`:

```php
public static function boot(): void
{
    add_action('init', static function () {
        Form::register([
            'id'     => 'hero_cta',
            'fields' => [/* ... */],
        ]);
    });
}
```
```

### Block Variations

```php
class Hero extends MetaBlock {
    public static function variations(): array {
        return ['', 'footer', 'landing']; // '' = default
    }
}
```

Each variation shares the same metabox registration and template, but is registered as a separate block with its own assets. Duplicate asset enqueuing is deduplicated automatically.

### Asset Enqueuing

`BaseBlock` handles Vite asset loading per block:

- **Dev mode**: served live from `localhost:5173` with HMR
- **Prod mode**: hashed filenames resolved from the Vite manifest; critical CSS inlined; non-critical CSS loaded asynchronously

---

## Metabox System

Register a metabox with a config array. Works on post types, page slugs, and page templates.

```php
use TAW\Core\Metabox\Metabox;

new Metabox([
    'id'      => 'page_settings',
    'title'   => 'Page Settings',
    'screens' => ['page', 'page-about.php', 'homepage'], // post type, template, or slug
    'fields'  => [
        ['id' => 'tagline',     'label' => 'Tagline',     'type' => 'text', 'required' => true],
        ['id' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['id' => 'hero_image',  'label' => 'Hero Image',  'type' => 'image'],
    ],
]);
```

### Field Types

| Type | Description |
|------|-------------|
| `text` | Single-line text |
| `textarea` | Multi-line text with configurable `rows` |
| `url` | URL with validation |
| `number` | Numeric with `min`, `max`, `step` |
| `select` | Dropdown; pass `options` as `['value' => 'Label']` |
| `checkbox` | Boolean toggle; stored as `'1'` / `'0'` |
| `wysiwyg` | WordPress rich text editor |
| `color` | Color picker (WordPress Color Picker UI) |
| `range` | Slider with `min`, `max` |
| `image` | Single image via WP Media picker; stores attachment ID |
| `files` | Multi-file picker with drag-to-reorder; stores JSON array of IDs |
| `group` | Nested fields under a shared prefix |
| `post_select` | AJAX-powered post picker with thumbnails; single or multi |
| `repeater` | Dynamic rows of sub-fields; stores as JSON; supports nesting |
| `datepicker` | jQuery UI date picker; stored as a date string (default `YYYY-MM-DD`); supports `date_format`, `min_date`, `max_date` |

All fields accept: `id`, `label`, `description`, `placeholder`, `default`, `required`, `width` (percentage for column layout).

### Tabs

```php
new Metabox([
    'id'     => 'hero',
    'title'  => 'Hero',
    'screens' => ['page'],
    'fields' => [ /* all fields */ ],
    'tabs'   => [
        ['id' => 'content', 'label' => 'Content', 'fields' => ['headline', 'body']],
        ['id' => 'media',   'label' => 'Media',   'fields' => ['image', 'video']],
    ],
]);
```

### Repeater Fields

```php
[
    'id'     => 'team',
    'label'  => 'Team Members',
    'type'   => 'repeater',
    'max'    => 10,
    'fields' => [
        ['id' => 'name',  'label' => 'Name',  'type' => 'text'],
        ['id' => 'photo', 'label' => 'Photo', 'type' => 'image'],
        // Nested repeaters are fully supported
        ['id' => 'links', 'label' => 'Links', 'type' => 'repeater', 'fields' => [
            ['id' => 'url',   'label' => 'URL',   'type' => 'url'],
            ['id' => 'label', 'label' => 'Label', 'type' => 'text'],
        ]],
    ],
]
```

By default repeater rows render as a collapsible accordion. Use the `layout` key to switch to a tabbed UI:

| `layout` value | Description |
|----------------|-------------|
| _(omitted)_ | Accordion rows (default) |
| `tabbed_horizontal` | Tabs along the top, content below |
| `tabbed_vertical` | Tabs stacked in a left column, content on the right |

```php
[
    'id'     => 'slides',
    'label'  => 'Slides',
    'type'   => 'repeater',
    'layout' => 'tabbed_horizontal', // or 'tabbed_vertical'
    'fields' => [
        ['id' => 'title', 'label' => 'Title', 'type' => 'text'],
        ['id' => 'image', 'label' => 'Image', 'type' => 'image'],
    ],
]
```

Repeater data is serialized as clean nested JSON and survives WordPress's `wp_unslash()` in `update_post_meta()`.

### Reading Values

```php
// From post meta
$value = Metabox::get($post_id, 'field_id');

// Repeater — returns decoded PHP array
$rows = json_decode(Metabox::get($post_id, 'team'), true);
```

### Conditional Fields

Fields can show/hide based on another field's value (evaluated server-side on save):

```php
['id' => 'show_cta', 'label' => 'Show CTA', 'type' => 'checkbox'],
[
    'id'         => 'cta_text',
    'label'      => 'CTA Text',
    'type'       => 'text',
    'conditions' => [
        ['field' => 'show_cta', 'operator' => '==', 'value' => '1'],
    ],
],
```

---

## Options Page

Global settings backed by `wp_options`. Supports the same field types as Metabox.

```php
use TAW\Core\OptionsPage\OptionsPage;

new OptionsPage([
    'id'         => 'taw_settings',
    'title'      => 'Theme Settings',
    'menu_title' => 'Settings',
    'fields'     => [
        ['id' => 'company_phone', 'label' => 'Phone', 'type' => 'text'],
        ['id' => 'logo',          'label' => 'Logo',  'type' => 'image'],
    ],
]);

// Retrieve anywhere
$phone = OptionsPage::get('company_phone');
```

---

## Vite Asset Pipeline

`ViteLoader` bridges WordPress enqueuing and Vite's build output.

```php
use TAW\Support\ViteLoader;

// Initialize with main entry point
ViteLoader::init('resources/js/app.js');

// Enqueue a per-block bundle
ViteLoader::enqueueAsset('my-block', 'resources/js/blocks/my-block.js');

// Resolve a hashed asset URL (for preload tags, etc.)
$url = ViteLoader::assetUrl('resources/fonts/Inter.woff2');

// Inline critical CSS directly into <head>
ViteLoader::inlineCriticalCss('resources/css/critical.css');

// Add modulepreload hints
ViteLoader::preloadAssets(['resources/js/chunks/vendor.js']);
```

**Dev mode** is detected automatically via a socket connection to `localhost:5173`. In production, the manifest (`dist/.vite/manifest.json`) is read and cached for 24 hours.

---

## Forms

Configuration-driven AJAX forms with CSRF protection, honeypot spam filtering, per-field validation, optional email delivery, and automatic submission persistence.

### Registration and rendering

Forms **must** be registered before templates load — `admin-ajax.php` never runs theme templates, so AJAX handlers registered inside a template simply don't exist when the form is submitted. The correct place is inside the block's `boot()` method, wrapped in `add_action('init', ...)` so translation functions are safe.

```php
// In your MetaBlock::boot()
public static function boot(): void
{
    add_action('init', static function () {
        Form::register([
            'id'           => 'contact',
            'submit_label' => __('Send Message', 'taw-theme'),
            'messages'     => [
                'success' => __('Thanks! We\'ll be in touch.', 'taw-theme'),
            ],
            'fields' => [
                ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true],
                ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
                ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false],
            ],
        ]);
    });
}
```

Then render it anywhere in a template:

```php
use TAW\Core\Form\Form;

Form::display('contact');
```

### How it works

Submissions go to `admin-ajax.php` via `fetch()`. This bypasses WordPress's page/rewrite routing entirely — no 404 risk, no full page reload. The response is JSON handled inline:

- **Success** — form resets, success message appears
- **Field errors** — per-field error spans are populated
- **General error** — an error banner is shown

### Email configuration

```php
'email' => [
    // Email to the site admin
    'to_self' => [
        'subject'  => 'New contact form submission',
        'template' => 'contact-self',   // MJML template name (optional)
    ],
    // Confirmation email to the submitter (requires an `email` field)
    'to_client' => [
        'subject'  => 'Got your message!',
        'template' => 'contact-client', // MJML template name (optional)
    ],
],
```

If no templates are configured, a plain-text fallback email is sent via `wp_mail()`.

### Field types

**Input fields**

| Type | Description |
|------|-------------|
| `text` | Single-line text |
| `email` | Email address — validated with `is_email()` |
| `tel` | Phone number |
| `url` | URL |
| `number` | Numeric input |
| `textarea` | Multi-line text; accepts `rows` (default `4`) |
| `select` | Dropdown; pass `options` as `['value' => 'Label']` |
| `radio` | Radio group; pass `options`; accepts `layout` (`'horizontal'` default / `'vertical'`) |
| `checkbox` | Boolean toggle — label rendered inline; value is `'1'` when checked |
| `checkbox_group` | Multiple checkboxes; pass `options`; accepts `layout`; stored as comma-separated string |
| `date` | Native date picker; accepts `min_date` and `max_date` (ISO format `YYYY-MM-DD`) |

Any other value (e.g. `password`, `hidden`) is passed straight through as the HTML `type` attribute.

**Structural fields** — cosmetic only; no `id`, no validation, no submission data

| Type | Description |
|------|-------------|
| `heading` | Dark section header with `label` and optional `subtitle`; mirrors the PDF-style section banners |
| `divider` | Horizontal rule (`<hr>`) |
| `html` | Raw HTML via `content` key — rendered with `wp_kses_post` |

```php
['type' => 'heading', 'label' => '1. Personal Data', 'subtitle' => 'General identification'],
['type' => 'divider'],
['type' => 'html', 'content' => '<p class="text-sm text-gray-500">All fields marked * are required.</p>'],
```

All input fields accept: `id`, `label`, `type`, `required`, `placeholder`, `width`, and `conditions`.
All fields (including structural) accept `width` (percentage) for column placement.

### Multi-column layout

Fields live inside a 12-column CSS grid. Use the `width` key (as a percentage) to control how many columns a field spans. On mobile all fields collapse to full width.

```php
'fields' => [
    ['id' => 'name',    'type' => 'text',  'label' => 'Name',    'width' => 50],
    ['id' => 'company', 'type' => 'text',  'label' => 'Company', 'width' => 50],
    ['id' => 'phone',   'type' => 'tel',   'label' => 'Phone',   'width' => 33],
    ['id' => 'email',   'type' => 'email', 'label' => 'Email',   'width' => 67],
    ['id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'width' => 100],
],
```

| `width` value | Grid span |
|---------------|-----------|
| `≤ 25` | 3 / 12 columns |
| `≤ 33` | 4 / 12 columns |
| `≤ 50` | 6 / 12 columns |
| `≤ 67` | 8 / 12 columns |
| `≤ 75` | 9 / 12 columns |
| `> 75` or omitted | 12 / 12 columns (full width) |

### Conditional fields

Any field can declare a `conditions` array. The field is hidden (and excluded from validation and submission data) until every condition is satisfied. Conditions are evaluated both in the browser and on the server — a hidden field can never sneak through even if JS is disabled or tampered with.

```php
['id' => 'is_company', 'type' => 'checkbox', 'label' => 'I represent a company'],
[
    'id'         => 'company_name',
    'type'       => 'text',
    'label'      => 'Company name',
    'required'   => true,
    'conditions' => [
        ['field' => 'is_company', 'operator' => '==', 'value' => '1'],
    ],
],
```

By default all conditions in the array are combined with **AND**. Add `'relation' => 'any'` to switch to **OR** (at least one must be true):

```php
// Show 'spouse_name' when marital status is married OR cohabiting
[
    'id'         => 'spouse_name',
    'type'       => 'text',
    'label'      => 'Spouse / Partner name',
    'conditions' => [
        'relation' => 'any',
        'rules'    => [
            ['field' => 'estado_civil', 'operator' => '==', 'value' => 'married'],
            ['field' => 'estado_civil', 'operator' => '==', 'value' => 'cohabiting'],
        ],
    ],
],
```

The old flat array format (no `relation` key) is still fully supported and defaults to AND. Supported operators:

| Operator | Meaning |
|----------|---------|
| `==` | Equals (default) |
| `!=` | Not equals |
| `>` | Greater than (numeric) |
| `<` | Less than (numeric) |
| `>=` | Greater than or equal (numeric) |
| `<=` | Less than or equal (numeric) |
| `contains` | String contains |

In the browser the form listens for `change` and `input` events on any field; when a controlling field changes, all dependent fields are re-evaluated immediately. Inputs inside a hidden wrapper are `disabled` so they are excluded from `FormData` without needing to clear their values — if the field is revealed again, the previously entered value is preserved.

### Multi-step forms

Replace the top-level `fields` key with `steps`. Each step has a `title` (shown in the step indicator) and its own `fields` array. The same field types, widths, and conditions work identically inside steps.

```php
Form::register([
    'id'           => 'fideicomitente',
    'submit_label' => 'Submit',
    'next_label'   => 'Continue',   // optional; default "Next"
    'prev_label'   => 'Back',       // optional; default "Back"
    'messages'     => ['success' => 'Your form has been received.'],
    'steps' => [
        [
            'title'  => 'Personal Info',
            'fields' => [
                ['type' => 'heading', 'label' => '1. General Data', 'subtitle' => 'Identification'],
                ['id' => 'nombre',    'label' => 'Given Name(s)',  'type' => 'text',  'width' => 33, 'required' => true],
                ['id' => 'apellido1', 'label' => 'Last Name',      'type' => 'text',  'width' => 33, 'required' => true],
                ['id' => 'apellido2', 'label' => '2nd Last Name',  'type' => 'text',  'width' => 33],
                ['id' => 'dob',       'label' => 'Date of Birth',  'type' => 'date',  'width' => 50],
                ['id' => 'pais',      'label' => 'Country',        'type' => 'text',  'width' => 50],
            ],
        ],
        [
            'title'  => 'Marital Status',
            'fields' => [
                ['type' => 'heading', 'label' => '2. Marital Status & Property Regime'],
                ['id' => 'estado_civil', 'label' => 'Marital Status', 'type' => 'radio',
                 'options' => ['single' => 'Single', 'married' => 'Married', 'cohabiting' => 'Cohabitation'],
                 'layout'  => 'horizontal'],
                ['id' => 'conyuge', 'label' => 'Spouse / Partner Name', 'type' => 'text',
                 'conditions' => [
                     'relation' => 'any',
                     'rules'    => [
                         ['field' => 'estado_civil', 'operator' => '==', 'value' => 'married'],
                         ['field' => 'estado_civil', 'operator' => '==', 'value' => 'cohabiting'],
                     ],
                 ]],
            ],
        ],
        [
            'title'  => 'Declaration',
            'fields' => [
                ['type' => 'html', 'content' => '<p>I declare that all information provided is true.</p>'],
                ['id' => 'confirm', 'label' => 'I confirm the above declaration', 'type' => 'checkbox', 'required' => true],
            ],
        ],
    ],
]);
```

**How multi-step works:**

- A numbered step indicator with labels is rendered above the form.
- **Next** validates required fields in the current step (client-side) before advancing. Hidden/conditioned-out fields are skipped.
- **Back** navigates without validation.
- **Submit** only appears on the last step.
- All fields from all steps are submitted together in a single AJAX request. The server validates every step's fields regardless of client-side step state.
- If the server returns validation errors, the form automatically navigates back to the step containing the first failing field.
- On success, all step panels are hidden and the success message is shown.

### Submission persistence

`SubmissionsHandler` is wired up automatically by `Theme::boot()`. Every successful submission is saved as a `taw_submission` CPT entry viewable in **WP Admin → Submissions**, including all field data, the source form ID, and the submitter's IP.

A webhook can be configured at **Settings → Form Webhook** to forward every submission as a signed JSON POST to n8n, Zapier, Make, or any automation platform.

### Features at a glance

- AJAX via `admin-ajax.php` — no page reload, no routing conflicts
- Nonce-based CSRF protection (`wp_nonce_field` + `check_ajax_referer`)
- Honeypot spam field (silently succeeds for bots)
- Per-field sanitization (matched to field type) and validation
- Inline field-level and general error display
- Multi-column layout via a 12-column grid and per-field `width`
- **Multi-step wizard** — `steps` key; numbered indicator; Prev/Next navigation; per-step client-side validation; auto-navigate to failing step on server error
- **Conditional fields** — AND (default) or OR (`relation: 'any'`) logic; enforced in JS and on the server
- **Input field types:** `text`, `email`, `tel`, `url`, `number`, `textarea`, `select`, `radio`, `checkbox`, `checkbox_group`, `date`, any HTML input type
- **Structural field types:** `heading`, `divider`, `html` — layout and copy inside a form with no submission data
- Optional MJML email templates for admin + submitter
- Automatic submission persistence via `taw_submission` CPT
- Optional webhook delivery with HMAC-SHA256 signing

---

## Visual Editor

Allows admins to edit field values inline on the frontend without entering wp-admin.

Enabled automatically by `Theme::boot()`. An **Edit Visually** button appears in the admin bar for users with `edit_posts` capability. Appending `?taw_visual_edit=1` to any URL activates the editing shell.

Changes are saved in batches via `POST /wp-json/taw/v1/visual-editor/save`, using the same sanitization and validation pipeline as the metabox.

---

## REST Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/taw/v1/visual-editor/save` | Save field changes from the visual editor |
| `GET`  | `/taw/v1/search-posts`       | Post search for `post_select` fields (AJAX) |

---

## SVG Support

```php
use TAW\Helpers\Svg;

// Enable secure SVG uploads (call once at boot)
Svg::register();

// Render as <img> (safest for user-uploaded SVGs)
Svg::render($attachment_id);

// Render inline (allows CSS styling)
Svg::inline($attachment_id, ['class' => 'icon']);

// Get URL only
$url = Svg::url($attachment_id);
```

SVGs are sanitized on upload using `enshrined/svg-sanitize`, with a fallback custom sanitizer. Sub-sizes are not generated (SVGs are resolution-independent).

---

## Performance Optimizations

```php
use TAW\Support\Performance;

Performance::configure([
    'remove_bloat'       => true,   // Remove Gutenberg/FSE CSS from non-block themes
    'remove_emoji'       => true,   // Remove ~20KB emoji detection scripts
    'remove_meta_tags'   => true,   // Remove generator, wlw, rsd tags
    'preconnect_origins' => [
        'https://fonts.googleapis.com',
        'https://fonts.gstatic.com',
    ],
    'preload_fonts'      => [
        get_theme_file_uri('resources/fonts/Inter-Regular.woff2'),
    ],
    'preload_images'     => [
        [$hero_attachment_id, 'full'],
    ],
]);
```

Or configure through `Theme::boot()`:

```php
Theme::boot([
    'performance' => [
        'remove_bloat' => true,
        'remove_emoji' => true,
    ],
]);
```

---

## Menus

Load WordPress menus as OOP trees:

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

## CLI Commands

Run via `php bin/taw` from the package root.

```bash
# Scaffold a new block
php bin/taw make:block HeroSection --type=meta --group=sections

# Import a block from an external source
php bin/taw import:block path/to/block

# Export a block for distribution
php bin/taw export:block HeroSection
```

`make:block` generates the block folder, PHP class, template file, and optional CSS/JS entry points with the correct Vite configuration.

---

## Helpers & Utilities

### `Framework` — Path/URL resolution

```php
use TAW\Helpers\Framework;

Framework::path('assets/admin.css');    // Absolute path within taw-core
Framework::url('assets/admin.css');     // URL within taw-core
Framework::themePath('resources/');     // Absolute path within the active theme
Framework::themeUrl('resources/');      // URL within the active theme
```

### `Image` — Attachment images

```php
use TAW\Helpers\Image;

Image::render($attachment_id, 'large', ['class' => 'hero-img', 'loading' => 'lazy']);
Image::preloadTag($attachment_id, 'full');
```

### `Dump` — Debug utilities

```php
use TAW\Helpers\Dump;

Dump::dd($value);        // Dump and die
Dump::log($value);       // Write to debug.log
```

---

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `symfony/console` | `^7.4` | CLI command framework |
| `enshrined/svg-sanitize` | `^0.22.0` | SVG XSS prevention on upload |

**Dev only:** `spatie/mjml-php ^1.0` for email template transpilation.

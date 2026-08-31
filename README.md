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

**Hot-file convention (optional but recommended):** have the theme's `vite.config.js` write the dev server's actual URL to `dist/hot` or `public/build/hot` on startup and delete it on shutdown (Laravel Vite plugin-style). `ViteLoader` reads this to resolve the correct host:port before probing — this matters if the dev server ever binds a non-default port. Without a hot file, `ViteLoader` falls back to the hardcoded default (`localhost:5173`).

**Optimizer-exclusion hardening:** every module `<script>` tag (plus its `modulepreload`/`preload` `<link>`s) and every Vite-extracted stylesheet `<link>` carries `data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"` — standard exclusion signals WP Rocket, Autoptimize, Perfmatters, and LiteSpeed Cache all document and honor when they hook WordPress's own `script_loader_tag`/`style_loader_tag` filters. Vite's output is already minified, hashed, and (for JS) split into ES modules requiring exact, un-mangled execution order — nothing a generic optimizer does to it is safe. **This does not help against a tool that rewrites the raw HTML output buffer directly instead of hooking those filters** — e.g. a host-side CDN-rehosting feature — that class of tool needs its own exclusion-list configuration in its own admin UI regardless; see `taw-theme`'s `AGENTS.md` "Vite Integration" section for a real incident (WPMUdev Hummingbird) this doesn't cover.

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
| `image` | Renders `<input type="file" accept="image/*">`. On submit, uploaded via `media_handle_upload()` and validated as a real image server-side (`wp_attachment_is_image()` — the `accept` attribute is a client-side hint only); the field's value in `on_submit`'s `$data` is the resulting attachment ID (`0` if optional and omitted). Automatically adds `enctype="multipart/form-data"` to the `<form>` tag. |
| `wysiwyg` | Renders WordPress's own classic editor (`wp_editor()`, `media_buttons` off) — gives TinyMCE's built-in paste-from-Word cleanup for free. Sanitized server-side with `wp_kses_post()` (never trust client-side cleanup as a security boundary). |
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
    'on_submit' => function (array $data) {
        $postId = wp_insert_post([
            'post_title'   => $data['post_title'],
            'post_content' => $data['post_body'],
            'post_status'  => 'draft',
        ], true);

        if (is_wp_error($postId)) {
            // Thrown message is shown to the submitter as a general error.
            throw new \RuntimeException($postId->get_error_message());
        }

        if (!empty($data['featured_image'])) {
            set_post_thumbnail($postId, (int) $data['featured_image']);
        }
    },
]);
```

The callback's signature is `function(array $data): void` — `$data` is the same
fully-validated, sanitized field data the CPT record and webhook payload are built from.
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

Cross-origin access to these routes (and to `admin-ajax.php?action=taw_form_*`) is opt-in and off by default — see [Static Export & Headless CORS](#static-export--headless-cors).

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

## CLI

```bash
php bin/taw make:block HeroSection --type=meta --group=sections
php bin/taw import:block path/to/block
php bin/taw export:block HeroSection
php bin/taw inspect --json                          # live registry: blocks, fields, forms
php bin/taw fields:get 42 hero_heading --json        # read a field's current value
php bin/taw fields:set 42 hero_heading "Welcome"     # write a field's value
php bin/taw sync --json                              # check for framework drift (see below)
php bin/taw sync --apply                             # also write Tier 1 scaffold changes
php bin/taw export:static                            # static HTML export for edge hosting (see below)
php bin/taw export:static --dir=/path --prod-url=https://my-site.pages.dev
php bin/taw seo:extract 42 --output=.taw/seo-dump.json         # copy audit: extract text fields (see below)
php bin/taw seo:inject 42 --input=.taw/seo-optimized.json      # copy audit: write rewrites back
php bin/taw wp post list --post_type=page                      # WP-CLI passthrough, socket/--path auto-resolved (see below)
php bin/taw icons:sync                                          # re-vendor the Lucide icon set (see Icon System)
```

`make:block` generates the block folder, PHP class, template file, and Vite entry points.

`fields:get`/`fields:set` are the read/write halves of the same primitive `VisualEditorEndpoint` uses for its REST-driven saves — they resolve a field's type from the live `Metabox` registry, then dispatch to the matching type-aware getter/sanitizer (`Metabox::get_repeater()`, `sanitizeRepeaterRows()`, etc.), so a repeater, `post_select`, or `files` field is read/written in exactly the shape the admin form itself would produce, with the same sanitization rules (XSS-stripping, ID coercion, JSON re-encoding). `fields:set` takes `--file=path.json` for repeater/array-shaped values, to sidestep shell JSON-quoting, and `--dry-run` to preview the sanitized result without writing. Both commands boot WordPress, like `inspect` — field configs and post data only exist once WordPress is loaded, so they walk up from the theme directory to find `wp-load.php` via the shared `TAW\CLI\WpLoader` helper.

`sync` is the scriptable core of the `update-theme` Claude Code skill and the `.github/workflows/framework-sync.yml` CI workflow — it checks whether the installed `taw/core` version is behind the latest GitHub tag, and whether the project's Tier 1/Tier 2 `taw-theme` scaffold paths (defined once in `resources/update-manifest.json`, shipped with this package) differ from the canonical repo. Unlike every other command here, it deliberately does **not** boot WordPress — the checks don't need it, and CI runners won't have a WP+DB environment available. Tier 1 paths (nothing client-specific has ever lived there) can be applied directly with `--apply`; Tier 2 paths (docs/build config that can legitimately accumulate client-specific additions) are always report-only — `sync` never writes them, by design, regardless of flags. It never touches `taw/core` itself either; run `composer update taw/core` separately.

The two skills directories (`.claude/skills/`, `.agents/skills/`) are Tier 1 but use `type: skills-dir` rather than `type: dir`: instead of `rsync -a --delete` (which would wipe a client's own agent skills — they have to live in the same directory the framework's do, since that's the only place the agent runtimes auto-discover skills), `sync` reconciles per skill folder. A skill present in the canonical repo is overwritten; a skill present only in the client is kept or removed based on its `SKILL.md` YAML frontmatter — `owner: site` is preserved untouched, `owner: taw` (a retired framework skill) is deleted, and an unmarked skill is preserved with a warning. Every framework skill in the canonical `taw-theme` repo carries `owner: taw`. The marker key and values are declared in `resources/update-manifest.json` under `skillsReconcile`.

`export:static` fetches every published `page`/`post` over HTTP against its own permalink (a real request, same as a visitor's browser would make — this deliberately picks up anything hooked onto `template_redirect`/`the_content`, unlike rendering templates in-process would), rewrites absolute site-URL references, and writes `<dir>/<slug>/index.html` — plus the built Vite assets (`dist/`) and `wp-content/uploads/` — into a self-contained static bundle for edge hosting (Cloudflare Pages, Vercel, etc.). Boots WordPress the same way `inspect`/`fields:get`/`fields:set` do, via `WpLoader`. By default, absolute links are rewritten to root-relative paths (works on any deploy domain); pass `--prod-url` to rewrite to an absolute URL instead. See the `export-static` Claude Code skill (`taw-theme`'s `.claude/skills/export-static/`) for the guided agent workflow, including the one part of this that's easy to skip: forms and search are **not** exported statically — see below.

`seo:extract`/`seo:inject` are the read/write halves of a copy-and-SEO audit loop — see below.

`wp` is a thin passthrough to WordPress's own official CLI (the real `wp` binary, found on `PATH` via `Symfony\Component\Process\ExecutableFinder`) — every argument after `wp` is forwarded exactly as given, unparsed. Resolves two things that otherwise need manual, hand-typed configuration every time under Local by Flywheel: `--path` (the WordPress root, via the same `WpLoader::locate()` logic every other WP-booting command here uses) and the per-site MySQL socket (`WpLoader::resolveLocalSocket()` — Local runs a separate MySQL instance per site on its own Unix socket, not the system default `mysqli.default_socket`/`pdo_mysql.default_socket` PHP CLI otherwise uses, so a bare `wp` command run from an ordinary terminal fails with a DB connection error even though the site works fine in the browser). A no-op wrapper everywhere this doesn't apply (real hosting, CI, DDEV, Herd) — it still resolves `--path` and runs `wp` normally, just without the extra `-d` flags.

`icons:sync` re-vendors the Lucide icon set this package ships (see [Icon System](#icon-system)) — shallow-clones `lucide-icons/lucide`, copies every icon SVG into `resources/icons/lucide/`, and rebuilds `resources/icons/lucide-index.json`. Doesn't boot WordPress or touch a consuming theme; only run it here, in `taw-core` itself, when Lucide ships new icons.

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

Every file in `src/` starts with `if (!defined('ABSPATH')) exit;` (the standard WordPress direct-access guard) — `tests/bootstrap.php` defines a dummy `ABSPATH` before the autoloader ever loads a class, or the test process would exit the moment one is included.

**Reflection is used deliberately** for testing private methods (`Form::validateRules()`, `Form::requiredMessage()`, `Form::emailMessage()`) — see `tests/TestCase::callMethod()`. These stay private by design (internal details of a public API), reflection lets tests verify that logic without widening the class's real surface just to make it testable.

**`Brain\Monkey\Functions` is a namespace of functions, not a static class** — call sites are `Functions\when(...)`/`Functions\expect(...)` (after `use Brain\Monkey\Functions;` imports the namespace), not `Functions::when(...)`. Easy to get wrong once from muscle memory with other mocking libraries; the whole suite failed with "Class not found" the first time for exactly this reason.

**Constants defined via `define()` (e.g. `TAW_TURNSTILE_SITE_KEY`) are process-global and permanent** — a test can't "un-define" one for a later test in the same PHPUnit process. `TurnstileTest` defines them once (guarded, `setUpBeforeClass`) and only tests the configured state; `TurnstileNotConfiguredTest` covers the undefined-constants state in its own class, using `#[RunInSeparateProcess]` on every method so neither class's constant state can leak into the other regardless of run order.

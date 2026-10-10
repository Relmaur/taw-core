You are working on TAW Theme — a classic WordPress theme with a custom block architecture.

Full architecture docs: see AGENTS.md in the repo root.

## Core Package

Framework internals live in the `taw/core` composer package (https://github.com/Relmaur/taw-core) at `vendor/taw/core/src/` — NOT in `inc/`. Update via `composer update taw/core`. Full per-namespace path map: AGENTS.md § "Codex — Dense Reference" (Directory map) or § "PSR-4 Autoloading".

This theme itself is a scaffold synced from a separate canonical repo, https://github.com/Relmaur/taw-theme, via the `update-theme` skill — don't conflate the two update paths.

For authoritative/current API detail, prefer the `mcp__taw-docs__search_documentation` MCP tool (if available) over guessing.

Do NOT edit anything inside `vendor/taw/core/`. To update the framework: `composer update taw/core`.

`functions.php` is 100% framework-owned — two lines, require autoload + `Theme::bootstrapFullSite(get_template_directory())`. Never hand-edit it; site-specific hooks go in `inc/customizations.php`. Full rationale: AGENTS.md § "The `functions.php` Bootstrap".

Key rules:
- Blocks live in Blocks/{Name}/{Name}.php — folder MUST match class name
- MetaBlock = data-owning (metaboxes + post_meta), Block = presentational (props)
- Auto-discovery via BlockLoader::loadAll() — never register manually in functions.php
- Template pattern: BlockRegistry::queue() before get_header(), render() in body
- PSR-4 (theme): TAW\Blocks\ → Blocks/ only — all other TAW\ classes come from taw/core
- Stack: WordPress, Tailwind v4, Alpine.js v3, Vite v7, SCSS, bespoke Metabox framework
- Always escape output: esc_html(), esc_url(), esc_attr()
- Meta key pattern: _taw_{field_id}; option key pattern: _taw_{field_id}

New blocks:
- Scaffold with: php bin/taw make:block Name --type=meta --with-style && composer dump-autoload
- Or manually create Blocks/{Name}/{Name}.php + index.php (no functions.php changes)
- Required: matching tests/Unit/Blocks/NameTest.php testing getData()'s hydration logic, run `composer run test` to confirm — pattern + rationale: AGENTS.md § "Testing Blocks"

Options page:
- OptionsPage (from taw/core) stores site settings in wp_options, same field config as Metabox
- Configured in inc/options.php; retrieve with OptionsPage::get('field_id')

Metabox order:
- MetaboxOrder::lockFromTemplate() runs automatically via Theme::bootstrapFullSite() — nothing to add to functions.php
- MetaboxOrder::lock('page', ['id1', 'id2']) in inc/customizations.php for an explicit order instead

Navigation menus:
- Use TAW\Core\Menu\Menu::get('location') instead of wp_nav_menu()
- Returns typed Menu / MenuItem tree with active-state and children support

Image helper:
- TAW\Helpers\Image::render($id, 'size', 'alt', ['above_fold' => true])
- Generates performance-optimised <img> with correct loading/fetchpriority/srcset

SVG helper:
- TAW\Helpers\Svg::register() — enable SVG uploads (call in theme setup)
- TAW\Helpers\Svg::render($id, 'alt', ['class' => '...']) — <img> tag
- TAW\Helpers\Svg::inline($id, ['class' => '...']) — inline <svg>

Forms:
- TAW\Core\Form\Form — config-driven frontend form (CSRF, honeypot, validation, AJAX via admin-ajax.php, email delivery)
- Register with Form::register([...]) in MetaBlock::boot() wrapped in add_action('init', ...) — NEVER in templates
- Display with Form::display('id') in the template
- TAW\Core\Form\SubmissionsHandler — CPT submission storage + webhook forwarding; auto-wired by Theme::boot(), do not instantiate manually
- Never use new Form([...]) + $form->render() — that API is removed
- Full field-type list, security details (rate limiting, Turnstile), multi-step/conditional config: AGENTS.md § "Form System" and § "Form security"

Mail:
- TAW\Core\Mail\Mailer — fluent wp_mail() wrapper: ->to()->subject()->template()->setVariables()->send()
- Templates in mails/html/{name}.html (production) or mails/{name}.mjml (dev); use {{variable}} placeholders
- TAW\Core\Mail\MailTester — Tools → Test Emails admin page

Debug:
- dump($value, 'label') / dd($value) — global helpers from utilities.php, WP_DEBUG only

REST API:
- GET taw/v1/search-posts — powers the post_select metabox field type

Metabox field types: text, textarea, wysiwyg, url, number, range, select, image, files, group, checkbox, color, repeater, post_select, datepicker

Visual Editor:
- Opt-in — call VisualEditor::enable() in inc/customizations.php (must run before Theme::boot(); without it, boot()'s VisualEditor::init() silently no-ops)
- Once enabled: Edit Visually button in admin bar; ?taw_visual_edit=1 activates inline editing shell

Asset helpers:
- Use ViteLoader::assetUrl('resources/fonts/Name.woff2') — not vite_asset_url()
- Use ViteLoader::isDevServerRunning() — not vite_is_dev()

Static analysis:
- composer run phpstan — level 5, Blocks/ + inc/ only, WordPress-aware via szepeviktor/phpstan-wordpress, runs in CI
- Prefer fixing types over adding ignoreErrors entries

Direct field access:
- php bin/taw fields:get <post_id> <field_id> --json / fields:set <post_id> <field_id> <value>
- Same sanitization as a real admin save (via Metabox::sanitizeValue()/sanitizeRepeaterRows())
- Use --file=path.json for repeater/array values, --dry-run to preview
- Don't hand-craft repeater JSON via raw wp post meta update — this command already knows each field's shape
- Full risk/confirmation model for content writes: AGENTS.md § "Content-writing safety model"

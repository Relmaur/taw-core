# CLAUDE.md — taw-core

**Project:** WordPress theme framework consumed as a Composer package by theme repos (e.g. taw-theme).

## Commands

| Task | Command |
|------|---------|
| Install deps | `composer install` |
| Scaffold block | `php bin/taw make:block <Name> [--type=meta] [--group=<dir>]` |
| Import block | `php bin/taw import:block <path>` |
| Export block | `php bin/taw export:block <Name>` |
| Static analysis | `composer run phpstan` |
| Unit tests | `composer run test` (Brain Monkey — no real WP install needed) |

## Architecture

- `TAW\` → `src/` (PSR-4). No other namespace roots.
- `src/Support/utilities.php` and `src/Support/performance.php` are file-autoloaded — global scope, no namespace.
- `src/Core/` — framework features (Block, Metabox, Form, OptionsPage, etc.)
- `src/Support/` — infrastructure (ViteLoader, Performance)
- `src/Helpers/` — stateless utility classes (Framework, Image, Svg, Dump)
- `src/CLI/` — Symfony Console commands
- **No templates here** — views live in the consuming theme, not in this package.

## Key Conventions

- `Theme::boot()` is the single entry point; it wires all subsystems.
- Blocks auto-discovered from theme's `/Blocks` directory at `after_setup_theme`.
- `registerMetaboxes()` is deferred to `init` by the framework — `__()` calls are always safe inside it.
- `getData(int|false $postId)` — `$postId` is `false` on 404 pages; all meta helpers (`getMeta`, `getImageUrl`, `getRepeater`) return safe empty values for `false`.
- Repeater and `files` field values are stored as JSON strings — callers must `json_decode()`.
- Use `Metabox::get()` and `OptionsPage::get()` as the read API; don't call `get_post_meta()` / `get_option()` directly for framework-managed fields.
- All registered metabox fields are visual-editor-enabled by default. Use `'editor' => false` to explicitly opt a field out.
- **Visual editor is opt-in.** Call `VisualEditor::enable()` in the theme's `functions.php` before `Theme::boot()`. Without it, `isActive()` always returns false and nothing renders.
- When the visual editor is active, `MetaBlock::render()` automatically wraps every block's output in `<div data-taw-block-section="{id}">`. The editor panel groups fields by block ID (matching the section attribute). Live text preview works via text-node content matching — no template annotations required for basic use.

## Don't

- **Don't register forms inside templates.** `admin-ajax.php` never runs templates; the AJAX handler won't exist when the form is submitted. Register in `MetaBlock::boot()` → `add_action('init', ...)`.
- **Don't call `__()` directly in `boot()`.** `boot()` fires at `after_setup_theme` before translations load. Wrap in `add_action('init', ...)`.
- **Don't call `ViteLoader::init()` more than once.** It runs the dev-mode socket check and caches the manifest; re-calling resets state mid-request.
- **Don't add templates or theme-specific logic here.** This is a library; theme code belongs in taw-theme.
- **Don't use raw WP meta/option functions** for framework fields — `get_post_meta` / `get_option` bypass the framework's type coercion.
- **Don't add `'editor' => true` to fields** — that was the old opt-in model. The default is now opt-out; only add `'editor' => false` to exclude a field.
- **Don't forget to update README.md** when changing the public API, adding field types, or changing boot behavior.

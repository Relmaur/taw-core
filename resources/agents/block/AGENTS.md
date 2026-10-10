# AGENTS.md — TAW block theme

This file ships with taw/core (`vendor/taw/core/resources/agents/block/AGENTS.md`) and updates with
`composer update taw/core`. The theme's own `AGENTS.md` points here and adds the site's notes, which win.

A TAW block (FSE) theme, built from the taw-gutenberg scaffold: a **data-only consumer of taw/core**.

## Stack (verify versions in composer.lock before using an API)

- WordPress 6.6+ (developed against 7.1), theme.json v3, full site editing (no PHP templates).
- PHP 8.2+. `taw/core` (Composer, pinned by tag in `composer.lock`), booted with
  `\TAW\Core\Boot::data()` only. Never call `Theme::boot()` here: that's taw-theme's classic toolkit.
- **Vite 8 + TypeScript** build `src/` into `dist/` (not committed; ADR-0003). PHP loads it through
  taw/core's `\TAW\Core\Assets\Vite` (taw-core ADR-0006); `vite.config.js` imports taw/core's shared
  plugins from `vendor/`, so `composer install` comes first. Node 22.12+.

## Layout

| Path | Purpose |
|---|---|
| `theme.json`, `templates/`, `parts/` | All presentation, plain Gutenberg markup. `templates/single-book.html` is the Block Bindings reference: core blocks bound to `book_details` fields through `taw/field`, expression chips, conditions (`tawShowIf`, a chip's `if`), TAW Loops (genres, awards, more books like this) and formulas/functions (`@ago()`, `@plural(@count(…))`, a link by expression) and dynamic block settings (`tawSettings`: genre-colored badges from `genre_color` in `taw-schema/genre-details.json`, an in-print class, an `#awards` anchor; cover caption from `book_cover_credit`, Buy rel by expression) |
| `functions.php` | Loads Composer (fails soft with an admin notice), registers the services |
| `app/Theme.php` | Service registry: add a service by listing it in `services()` |
| `app/Contracts/Bootable.php` | Service contract: `register()` only adds hooks |
| `app/Setup/TawData.php` | Boots taw/core's data layer at `after_setup_theme:0` |
| `app/Setup/Editing.php` | Boots taw/core's editing policies (`Boot::editing()`) at `after_setup_theme:0` (ADR-0002) |
| `app/Setup/Blocks.php` | Registers every `src/blocks/*/block.json` at `init` through `Assets\Vite::block()` |
| `app/Setup/Assets.php` | Site-wide front-end/editor entries (none shipped: list them in `FRONTEND`/`EDITOR` and in vite.config.js) |
| `src/blocks/<name>/` | One block: `block.json` (`file:./index.tsx`, `file:./style.scss`), TSX, SCSS, `*.test.ts(x)` |
| `vite.config.js` | Block entries auto-discovered; `GLOBAL_ENTRIES` for site-wide ones; dev server on 5174 |
| `tests/js/` | Vitest setup and the fake `window.wp` stubs |
| `taw-schema/*.json` | Post types, taxonomies, fieldsets, options pages (taw/core ADR-0004) |
| `taw-schema/editing.json` | The editor lockdown policy. Ships `"preset": "open"` and `"themeBlocks": ["taw-gutenberg/*"]` (this theme's blocks stay in every allow list); installs pick a level in wp-config.php |
| `bin/taw` | `schema:validate` (no WordPress) |
| `docs/adr/` | This theme's decisions |

## Commands

```bash
composer run test          # unit tests, no WordPress
composer run phpstan       # level max, must stay green
php bin/taw schema:validate
npm run dev                # Vite dev server (writes dist/hot; WordPress loads from it)
npm run build              # production build into dist/
npm run check              # lint + format + typecheck + Vitest + build (what CI runs)
```

## Rules

- Field ids are namespaced by entity (`book_author`): taw/core's field registry is keyed by bare id,
  and this theme shares the test site's database with taw-theme.
- New data goes in `taw-schema/` as JSON. `schema:validate` and `SchemaFilesTest` must stay green.
- **Editor lockdown is taw/core's, not this theme's** (ADR-0002). Don't add lockdown code here. Change
  `taw-schema/editing.json` or a post type's `"editing"` rule. The shipped preset must stay `open`
  (`EditingTest` checks this); clients get their level from `TAW_EDITING_PRESET` in wp-config.php.
- **Blocks** live in `src/blocks/<name>/`, are named `taw-gutenberg/<name>` (the editing-policy
  allow pattern) and use the **classic JSX runtime** (`import React from 'react'` in every JSX file).
  `block.json` is the single source of truth: `index.tsx` registers `metadata`. Assets are
  `file:` references; `Blocks` maps them to Vite. No `*.asset.php`: when a block imports a
  `@wordpress/*` package outside taw/core's `Vite::EDITOR_SCRIPT_DEPS`, add its handle (e.g.
  `wp-rich-text`) to `Blocks::EXTRA_EDITOR_DEPS`.
- `@wordpress/*` and `react` imports resolve to browser globals (taw/core `wordpressExternals()`).
  A name missing from its `WP_EXPORT_NAMES` fails the build: add it there (taw/core) or via
  `wordpressExternals({ extraExports })`. Tests stub the globals in `tests/js/wp-globals.tsx`.
- After every taw/core tag: `composer update taw/core`, then commit `composer.lock` (the umbrella's
  taw-bump/taw-ship flows).
- Umbrella rules apply: confirm every state-changing git operation (scope and actor), bump the
  umbrella pointer after committing here, and push this repo before the umbrella.

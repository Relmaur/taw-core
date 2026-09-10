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
| Static site export | `php bin/taw export:static [--dir=<path>] [--prod-url=<url>]` |
| SEO/copy audit — extract | `php bin/taw seo:extract <post_id>\|--all [--output=<path>]` |
| SEO/copy audit — inject | `php bin/taw seo:inject <post_id>\|--all [--input=<path>] [--dry-run]` |
| WP-CLI passthrough | `php bin/taw wp <args>` (auto-resolves Local's socket + `--path`) |

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
- **Headless CORS is opt-in.** `TAW\Core\Rest\Cors::register()` (wired into `Theme::boot()`) is a no-op unless `TAW_HEADLESS_ORIGINS` is defined in `wp-config.php` — needed only once a `export:static` bundle is served from a different domain than this WordPress install.
- **SEO meta (`TAW\Core\Seo\SeoMeta`) always registers, but stands entirely down if any known SEO plugin (Yoast, RankMath, SmartCrawl) is active** — no duplicate `<title>`/`<meta name="description">`/OG tags, no competing admin UI. Yoast specifically gets read/write support (`SeoMeta::targetMetaKeys()` resolves to its meta keys); other plugins just cause TAW's own output to stand down, without write support for their own keys yet. Covers non-singular contexts too (home, archives, search), not just posts/pages. Per-post `noindex` is a checkbox field, applied via the `wp_robots` filter rather than a hand-printed `<meta name="robots">` tag.
- **Content interchange (`TAW\Core\Content\*`) — the portable snapshot / change-set subsystem, a whole-site *state* migration path.** `Exporter::snapshot($scope)` builds a `json_encode`-ready array: posts (+ `author` as `{login,email}`, `comment_status`/`ping_status`, `_taw_*` fields), `_taw_*` options + a core allowlist, terms, referenced media; **opt-in** `users` (`--with-users`, hashes only with `--with-user-passwords`), `comments` (`--with-comments`), environment settings (`--with-settings` + `taw_content_export_settings_options` filter), unreferenced media (`--all-media`), drafts (`--include-drafts`). `--migrate` = `--with-users --with-settings --all-media --include-drafts`. `Importer::plan()` is a mandatory zero-write dry run; `Importer::apply()` writes a **maximal-scope** rollback snapshot to `uploads/taw-private/` first, then applies in dependency order **users → terms → media → posts → comments → settings**. Records matched by natural key (post `type`+`slug`, or `type`+`match_key` for slug-less drafts; option key; term `taxonomy`+`slug`; user login→email; comment content-hash) — never numeric ID. Also auto-exports any CPT with a `Metabox` attached (`Metabox::postTypesWithMetabox()`), not just `public => true`. `FieldCodec` is the pure decode/attachment-ref layer (unit-tested without WP). CLI: `content:export` / `content:import` (`--with-settings` gates settings on import too) / `content:diff` (diffs `users`/`comments` too). **The `Content\*` class files deliberately omit the `if (!defined('ABSPATH')) exit;` guard** — the `content:*` commands autoload them before booting WordPress, and the guard's `exit` silently kills the command (v1.25.1). They're pure class defs, same as `Framework`/`WpLoader`. Portable transforms (`page_on_front`/`page_for_posts`/`sticky_posts` ↔ slug, `parent` ↔ slug, `featured_media` ↔ filename, `author` ↔ `{login,email}`) are reversed on import; a clean `--migrate` export→import is a verified no-op (0 created/updated/deleted). Schema: `resources/schema/content-interchange-1.1.json` (importer accepts `1.0` + `1.1`). `GET taw/v1/content/export` stays content-only. Wired default-on in `Theme::boot()` step 13 (Tools → TAW Data screen, the REST route, and `FieldMetaRegistrar` — REST-registered field meta; `add_filter('taw_register_meta_in_rest', '__return_false')` opts out the last).
- **`Metabox::writeMeta($postId, $fieldConfig, $value)` is the one meta-write primitive** — sanitize (`sanitizeForStorage()`, covering scalars + repeater/files/post_select) + `wp_slash` + `update_post_meta`, matching `Metabox::save()` exactly. `fields:set` and `Content\Importer` both use it; don't hand-roll `update_post_meta` for framework fields.
- **Security hardening (`TAW\Core\Security\Hardening`) is default-on, not opt-in.** `Theme::boot()` calls `Hardening::hideUsersEndpoint()`, which removes the public `/wp/v2/users` REST collection + single-user route for anonymous requests (filtered at `rest_endpoints`, so it catches `/wp-json/`, `?rest_route=`, and `/batch/v1` at once). `/wp/v2/users/me` and all logged-in access stay intact. Opt a site out with `add_filter('taw_security_hide_users_endpoint', '__return_false')`. The `?author=N` redirect is deliberately *not* here — it's scaffold-level (`taw-theme` `inc/security.php`).
- **SEO structured data (`TAW\Core\Seo\Schema`) mirrors `SeoMeta`'s plugin-detection stand-down** — emits sitewide Organization/WebSite JSON-LD (configured via its own "SEO Schema" options page), Article JSON-LD on posts, and BreadcrumbList on any singular post/page, rendered once as a single `<script type="application/ld+json">` on `wp_footer` (not `wp_head` — blocks push their own nodes onto the graph during body rendering via `Schema::push()`, e.g. the FAQ block's `Schema::faqPage()` node).

## Don't

- **Don't register forms inside templates.** `admin-ajax.php` never runs templates; the AJAX handler won't exist when the form is submitted. Register in `MetaBlock::boot()` → `add_action('init', ...)`.
- **Don't call `__()` directly in `boot()`.** `boot()` fires at `after_setup_theme` before translations load. Wrap in `add_action('init', ...)`.
- **Don't call `ViteLoader::init()` more than once.** It runs the dev-mode socket check and caches the manifest; re-calling resets state mid-request.
- **Don't add templates or theme-specific logic here.** This is a library; theme code belongs in taw-theme.
- **Don't use raw WP meta/option functions** for framework fields — `get_post_meta` / `get_option` bypass the framework's type coercion.
- **Don't add `'editor' => true` to fields** — that was the old opt-in model. The default is now opt-out; only add `'editor' => false` to exclude a field.
- **Don't forget to update README.md** when changing the public API, adding field types, or changing boot behavior.

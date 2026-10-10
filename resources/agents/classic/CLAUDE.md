# TAW classic theme — Claude Code instructions (from taw/core)

> **This file ships with taw/core** (`vendor/taw/core/resources/agents/classic/CLAUDE.md`) and updates with
> `composer update taw/core`. The theme's own `CLAUDE.md` imports it and adds the site's notes under
> "This site": those win when they disagree with this file.
> Full architecture docs + code examples: **`AGENTS.md` next to this file**
> (`vendor/taw/core/resources/agents/classic/AGENTS.md`; start with its `## Codex — Dense Reference` section).
> **This theme's canonical scaffold (source of truth for base-theme sync):** https://github.com/Relmaur/taw-theme — synced via the `update-theme` skill.
> **`taw/core` framework reference (source of truth for framework APIs):** https://github.com/Relmaur/taw-core#readme — fetch when you need authoritative detail on any framework API. When this file and the `taw/core` README disagree, `taw/core` wins. Separate repo/update path from `taw-theme` — see `composer update taw/core`.
> **Live doc lookup:** prefer the `mcp__taw-docs__search_documentation` MCP tool (if available) over fetching docs by hand — it's a hybrid semantic+keyword search over the current indexed docs.
> Full online documentation: https://taw.mlizardo.com/
> **External WordPress skill references (use, don't vendor):** for general WP capabilities TAW doesn't already own an abstraction for, consult specific skills from https://github.com/WordPress/agent-skills — `wp-phpstan`, `wp-performance`, `wp-wpcli-and-ops`, `wp-playground`. Do **not** pull in `wp-block-development` or `wp-block-themes` — those teach native Gutenberg blocks and `theme.json`, which TAW replaces with its own MetaBlock/Block system and Vite pipeline.

## Project

TAW Theme — WordPress theme with component-based blocks, Vite, Tailwind v4, Alpine.js, and a bespoke metabox framework from the `taw/core` composer package.

## Commands

Full list: AGENTS.md § "Commands". The ones used almost every session:

```bash
npm run dev              # Vite dev server (port 5173, HMR)
composer run phpstan     # Static analysis (Blocks/, inc/) — also runs in CI
composer run test        # Block getData() unit tests — required for every block, AGENTS.md § "Testing Blocks"
php bin/taw inspect --json  # Live registry dump: blocks, fields, forms, taw/core version
```

`fields:get`/`fields:set` usage (including `--file`/`--dry-run`) and the content-writing safety model: AGENTS.md § "`fields:get` / `fields:set`" and § "Content-writing safety model".

## Core Architecture

See AGENTS.md § "Quick Orientation" and § "The `functions.php` Bootstrap" for the full class/path map and the `functions.php`-is-framework-owned rule. Site-specific setup always goes in `inc/options.php`, `inc/performance.php`, `inc/customizations.php` — never `functions.php`.

Two block types:
- **MetaBlock** — owns metaboxes, fetches post_meta, rendered via `BlockRegistry::render('id')`
- **Block** — presentational, receives props, rendered directly: `(new Button())->render([...])`

**`boot()` method:** Override `static boot(): void` on a MetaBlock for early-request setup (e.g. registering forms). Called during block discovery at `after_setup_theme`. Wrap translation calls in `add_action('init', ...)` inside it.

**Block Variations:** Override `static::variations()` to return an array of variation strings (default `['']`). Access the active variation with `$this->getVariation()`.

Asset loading: `BlockRegistry::queue('hero', 'stats')` BEFORE `get_header()` → assets land in `<head>`. Fallback prints inline if forgotten.

**Visual Editor:** Opt-in — call `TAW\Core\Editor\VisualEditor::enable()` in `inc/customizations.php` before `Theme::boot()`. Once enabled: **Edit Visually** button in admin bar; `?taw_visual_edit=1` activates the editing shell.

**Icon System:** Opt-in — call `TAW\Core\Icons\Lucide::enable()` in `inc/customizations.php` (commented out by default) to turn on the `icon` field type and its wp-admin picker. `Lucide::render($name, [...])` for template output needs no `enable()` call.

**TAW Media:** Opt-in at the `taw/core` level, but **on by default** in this scaffold's `inc/customizations.php` (`TAW\Core\Media\MediaFolders::enable();`). Adds a **Media → TAW Media** admin screen (a full Alpine.js app: nestable folders, breadcrumb, folder cards, drag-and-drop upload, multi-select bulk move/delete), a filter/column/bulk-action on the classic Media Library list view, and a FileBird-style sidebar on the default Grid view of `upload.php` (live click-to-filter, full folder CRUD, sort controls for both the folder tree and the file grid — name, date, and file size).

**Security hardening:** `inc/security.php` (site-owned, required from `inc/customizations.php`, never touched by `update-theme`; sites created before it existed don't have it, and sync doesn't add it — copy it from the starter by hand) locks down user enumeration — calls `TAW\Core\Security\Hardening::hideUsersEndpoint()` (also default-on in `Theme::boot()`; hides the public `/wp/v2/users` REST routes from anonymous requests across `/wp-json/`, `?rest_route=`, and `/batch/v1`) and 301-redirects logged-out `?author=N` probes. Opt the REST half out per site with `add_filter('taw_security_hide_users_endpoint', '__return_false')`. Full detail: AGENTS.md § "Security Hardening" and `taw/core` README § "Security / Hardening".

**Emailit transport:** Opt-in, per-site, paid add-on — commented out by default in `inc/customizations.php`. Routes *all* `wp_mail()` calls (including `Mailer`'s, below) through Emailit's API instead of the host's default mail transport, with automatic fallback to plain `wp_mail()` if unconfigured or the API call fails. Gated on a `defined('EMAILIT_API_KEY')` check, so leaving the block uncommented is a no-op for sites without the constant — only sites whose `wp-config.php` defines `EMAILIT_API_KEY` (a site-specific secret, never committed) activate it. Requires `composer require emailit/emailit-php` on that site. Full detail: `taw/core` README § "Email".

**Production view (taw-fleet):** the companion `taw/hub-companion` ships per client project as an mu-plugin (composer requirement + fleet key in `extra.taw-companion.keys` + the deploy copying its `mu-loader/`), and taw-fleet reads the site through it (`taw-fleet live`). Not in this scaffold: the fleet key is per owner. taw-hub and its `hub:*` commands / `hub-connect` skill are retired. Full detail: AGENTS.md § "Production view: the companion and taw-fleet".

**Schema (taw/core ≥ v1.43):** define post types, taxonomies, fieldsets and options pages via the `taw_schema_register` action (in `inc/customizations.php`) or as JSON in `taw-schema/*.json`; `php bin/taw schema:validate` checks the JSON without WordPress. Fieldsets compile into regular Metaboxes (`_taw_{field}` meta). Namespace field ids by entity (`book_author`). Full detail: AGENTS.md § "Schema".

**Content interchange:** `TAW\Core\Content\*` (from `taw/core` ≥ v1.25.0; whole-site **state** migration since v1.26.0). `php bin/taw content:export` writes a portable snapshot (posts + `_taw_*` fields + authorship, options, terms, referenced media); opt-in `--with-users` / `--with-comments` / `--with-settings` / `--all-media` / `--include-drafts`, or `--migrate` for the lot. `content:import <file>` is a dry-run diff by default, `--yes` applies it (maximal-scope rollback snapshot written to `uploads/taw-private/` first; `--with-settings` also gates settings on import); `content:diff a b` emits a change-set (incl. `users`/`comments`). Records match by natural key, never numeric ID; a clean `--migrate` export→import on the same site is a verified no-op. Any CPT with a `Metabox` attached auto-exports. wp-admin: **Tools → TAW Data** (export scope checkboxes). `GET /wp-json/taw/v1/content/export` stays content-only. `Theme::boot()` also REST-registers every TAW field over `wp/v2` for headless reads (`add_filter('taw_register_meta_in_rest', '__return_false')` to opt out). Full detail: AGENTS.md § "Content Interchange", `taw/core` README, and https://taw.mlizardo.com/content-interchange.

**Field defaults:** put a block's fallback content in the metabox's `'defaults' => ['field_id' => value]` (taw/core ≥ v1.80.0), never `getMeta(...) ?: 'text'` in `getData()`: the getters and the editor use it while nothing is stored, and `php bin/taw content:defaults --apply` (or Tools → TAW Data → *Defaults*) saves it as records. See AGENTS.md § Metabox.

**Sovereign Hybrid-RAG Chatbot:** Backend entirely `taw/core` (`TAW\Core\Rag\*`, from v1.27.0; content-agnostic knowledge bases since v1.28.0) — SQLite knowledge bases (the site's own content plus any admin-uploaded `.sqlite` file, managed at **Settings → TAW Chatbot → Knowledge Bases**), embeddings, LLM tool-calling orchestration, and `POST taw/v1/chat`, all there; full detail in its README. Optional: new sites get `Blocks/Chatbot`, and sync never adds it to an existing site. This repo owns only `Blocks/Chatbot` — a plain `Block` mounted site-wide (`header.php` enqueues, `footer.php` renders), talking exclusively to that REST endpoint (no LLM base URL/API key ever in JS), markdown rendered via `marked` + sanitized with `DOMPurify`. Since taw/core v1.81.0 the endpoint is gated: a Turnstile session once per conversation, throttles, and a hard daily/monthly budget. The widget runs Turnstile and sends `X-TAW-Chat-Session`, and refusals come back as `code`s. Never give a site an API key before setting its budget (**TAW Chatbot → Budget**) and Turnstile keys. Full detail: AGENTS.md § "Sovereign Hybrid-RAG Chatbot".

**Logging:** `TAW\Core\Log\Logger` (always on, from `taw/core`) — structured replacement for `error_log('[TAW …]')`. `Logger::error('subsystem.event', 'human message', ['ctx' => ...])` (+ `debug`/`info`/`notice`/`warning`/`critical`). Writes to `error_log()` **and** `wp-content/taw-logs/taw.log.jsonl`; read back with `php bin/taw log:tail`. Use it in blocks/`inc/`/`on_submit` callbacks instead of `error_log`. Full detail: AGENTS.md § "Logging".

## Options Page / Navigation / Helpers / Mail / REST / CSS Pipeline

Full API for these: AGENTS.md §§ "Options Page", "Navigation Menu System", "Image Helper", "Performance Compliance", "SVG Helper", "Debug Helper", "Logging", "Mail System", "REST API", "Vite Integration". Quick reference:

```php
OptionsPage::get('company_phone');
Menu::get('primary');                 // typed nav tree — never wp_nav_menu()
Image::render($id, 'large', ['above_fold' => true]);
(new Mailer())->to($email)->subject($subject)->template('name')->setVariables($vars)->send();
```

**Vite helpers are on `ViteLoader`**: use `ViteLoader::isDevServerRunning()` / `ViteLoader::assetUrl()` — never the removed `vite_is_dev()` / `vite_asset_url()`.

**`app.css` loads async (`media="print"` swap), genuinely — `resources/scss/critical.scss` must contain real hand-authored layout rules for above-the-fold markup (header/hero), not the empty placeholder scaffold, or every page flashes unstyled HTML on load.** See AGENTS.md § "CSS entry points" for why a scoped Tailwind `@source` in `critical.scss` doesn't work as a shortcut here.

## Forms

**Register in `boot()`, never in templates** — the AJAX handler won't exist on `admin-ajax.php` otherwise. Full pattern, code sample, field-type list, and security details (CSRF/honeypot/rate-limit/Turnstile): AGENTS.md § "Form System" and § "Form security".

Quick reference: `Form::register([...])` in `boot()`, `Form::display('contact')` in the template. `'submit_icon' => 'send'` renders an icon after the submit label (Lucide name or raw SVG/HTML); `'class'` / `'button_class'` (≥ v1.40.0) append extra classes to the `<form>` / primary buttons for per-form styling, and field `width` is the `--taw-span` custom property, not an inline `grid-column` — AGENTS.md § "Form System".

## Key Conventions

- Folder name === class name === `$id` property
- Meta keys: `_taw_{field_id}`, option keys: `_taw_{field_id}`
- Templates: `index.php` receives `extract()`-ed variables from `getData()`
- PSR-4: AGENTS.md § "PSR-4 Autoloading" — theme only maps `TAW\Blocks\` → `Blocks/`; everything else is `taw/core`

## Metabox Field Types

`text`, `textarea`, `wysiwyg`, `url`, `number`, `range`, `select`, `image`, `files`, `group`, `checkbox`, `color`, `repeater`, `post_select`, `datepicker`, `icon` (opt-in, see "Icon System" above), `gradient_text` (ordered `{text, highlighted}` segments — a gradient heading where the highlight isn't always the trailing run), `hubspot_form` (`{portal_id, form_id, region}`, render via `TAW\Core\Integrations\Hubspot::render()`) — full options/conditional logic: AGENTS.md § "The Metabox Framework" (or taw/core README).

Any field also accepts `'readonly' => true` — renders as non-interactive text with a lock icon next to its label, never saved from POST. Use for values an external process owns (e.g. a sync pipeline).

## Metabox Order

`MetaboxOrder::lockFromTemplate()` runs automatically. See AGENTS.md § "Locking Metabox Order" for the explicit-order override and template-resolution details.

## When Creating New Blocks

1. **CLI (preferred):** `php bin/taw make:block Name --type=meta --with-style`, then `composer dump-autoload`
2. **Manual:** Create `Blocks/{Name}/{Name}.php` and `Blocks/{Name}/index.php` — auto-discovered, no `functions.php` changes

## Static Analysis

`composer run phpstan` — level 5, `Blocks/` + `inc/` only, runs in CI. See AGENTS.md § "Static Analysis" for the ignoreErrors/baseline policy.

## WP-CLI — live site data access

`bin/taw` is for the *framework* (blocks/fields/forms); WordPress's own `wp` CLI is for live *content* — but prefer `php bin/taw wp <args>` over a bare `wp` command, since it auto-resolves `--path` and (under Local by Flywheel) the per-site MySQL socket that otherwise fails a bare `wp` command with a DB connection error. Full walkthrough: AGENTS.md § "WP-CLI — live site data access".

## CSS Studio (Development)

- **Toggle:** WP Admin → TAW Settings → Developer Tools → Enable CSS Studio
- **Active only when:** Vite dev server is running (`npm run dev`) AND the toggle is on
- **Start a session:** `/studio`

## Don't

Full list: AGENTS.md § "Do NOT". Additional Claude-Code-session-specific reminders not in that list:

- **Don't trigger an automated browser check without asking first — for any reason, not just `visual-check`'s pixel comparison, and not just Playwright.** This includes the basic "load the page and confirm it renders / the admin metabox UI works" step in `make-metablock`/`build-page`'s own Verify checklists, and any ad-hoc use of the `playwright` MCP server (`.mcp.json`) *or* the `chrome-devtools-mcp` plugin elsewhere, including the general `/verify` skill once it's bootstrapped for this repo. Chrome DevTools MCP is read/debug-leaning (console, network, performance traces, Lighthouse) but its tools can also navigate/click/fill a real page, so it carries the same tab-churn/interruption concern as Playwright — it's not exempt just because it's "just for debugging." Ask a plain question first ("want me to check this in a browser, or are you already looking at it?") — on small changes the developer often has the dev site open and can tell at a glance; don't spend the time/tab-churn of driving a browser automatically. Checks that don't open a real browser (`curl` status checks, `php bin/taw inspect`, `composer run test`) aren't gated by this — only actual browser-driving automation is. The `taw-verify` skill (umbrella-level, `.claude/skills/taw-verify/`) is the sanctioned way to actually run one of these checks once asked-for — it formalizes the confirm-then-drive-the-browser flow (dedicated test admin user, real console-message capture) for either engine, rather than bypassing this rule.
- Don't call `vite_is_dev()` or `vite_asset_url()` — use `ViteLoader::isDevServerRunning()` and `ViteLoader::assetUrl()`
- Don't use `new Form([...]) + $form->render()` — use `Form::register()` in `boot()` and `Form::display('id')` in templates
- Don't manually instantiate `SubmissionsHandler` in `functions.php` — it's auto-wired by `Theme::boot()`
- Don't register Forms inside templates — the AJAX handler won't exist when `admin-ajax.php` processes the submission
- Don't wire up `ThemeUpdater` on a client site with customizations — it does full theme-zip replacement, which would wipe `Blocks/` and every `inc/` file. Use the `update-theme` skill instead
- If a client site authors its own skill in `.claude/skills/` or `.agents/skills/`, give its `SKILL.md` frontmatter `owner: site` — that's what makes `update-theme` preserve it instead of deleting it as non-canonical scaffold (framework skills carry `owner: taw`). See AGENTS.md § "Automated framework-drift detection"

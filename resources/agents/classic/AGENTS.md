# AGENTS.md — AI Agent Guide for TAW Theme

> **This file ships with taw/core** (`vendor/taw/core/resources/agents/classic/AGENTS.md`) and updates with
> `composer update taw/core`. The theme's own `AGENTS.md` points here and adds the site's notes under
> "This site": those win when they disagree with this file.

> **TAW** = Tailwind + Alpine + WordPress
> A classic WordPress theme with a component-based block architecture, Vite asset pipeline, and a bespoke metabox framework.

---

## Codex — Dense Reference (read this first; expand to the sections below only if it doesn't answer the question)

### Directory map

| Path | Owns | Editable? |
|---|---|---|
| `Blocks/{Name}/{Name}.php` | Block class, `TAW\Blocks\{Name}\{Name}` | Yes — primary work surface |
| `Blocks/{Name}/index.php` | Template, receives `extract()`-ed `getData()` array | Yes |
| `Blocks/{Name}/style.{css,scss}`, `script.js` | Auto-enqueued block assets | Yes, optional |
| `inc/options.php` | `OptionsPage` fields | Yes — never touched by `update-theme` |
| `taw-schema/*.json` | Post types, taxonomies, fieldsets, options pages as JSON (taw/core ≥ v1.44, § "Schema") — create the folder when first needed | Yes |
| `inc/performance.php` | `Theme::performance()` config array | Yes — never touched |
| `inc/customizations.php` | theme supports, nav menus, `VisualEditor::enable()`, `MediaFolders::enable()` (on by default), `Lucide::enable()` (commented out), `MetaboxOrder::lock()`, `Svg::register()`, `MailTester`, `require inc/security.php` | Yes — never touched |
| `inc/security.php` | User-enumeration hardening — `Hardening::hideUsersEndpoint()` + logged-out `?author=N` redirect | Yes — never touched |
| `functions.php` | 2-line bootstrap only | **Never hand-edit** — blindly overwritten by `update-theme` |
| `.claude/skills/{name}/`, `.agents/skills/{name}/` | Agent skills — framework skills (`owner: taw`) refreshed by `update-theme`; site-authored skills survive **only if** their `SKILL.md` frontmatter has `owner: site` | Site skills: yes. Framework skills: edit in `taw-theme` |
| `vendor/taw/core/src/` | All `TAW\Core\*`, `TAW\Helpers\*`, `TAW\CLI\*` | **No** — edit in the `taw-core` repo, then `composer update taw/core` |
| `resources/js/app.js` | Vite JS entry, imports CSS/SCSS | Yes |
| `resources/css/app.css` | Tailwind v4 directives — imported by `app.js`, not its own Vite entry | Yes |
| `resources/scss/critical.scss` | Inlined `<head>` CSS, keep <14 KB, no `@font-face` | Yes |
| `resources/fonts/` | Self-hosted WOFF2 | Yes |
| `bin/taw` | Symfony CLI: `make:block`, `export/import:block`, `inspect`, `fields:get/set`, `sync`, `content:reindex`, `content:reindex-kb`, `schema:validate` | read-only tool, not a source dir |
| `tests/Unit/Blocks/{Name}Test.php` | Unit test for `{Name}::getData()` — required for every block, see § "Testing Blocks" | Yes — written alongside every new block |

Full path-by-path detail: § "Quick Orientation" and § "PSR-4 Autoloading" below.

### Core helpers (one-liners)

| Call | Does |
|---|---|
| `Metabox::get($postId,$field)` / `get_bool` / `get_image_url` / `get_color` / `get_posts` / `get_repeater` | Raw field read, meta key `_taw_{field}` |
| `$this->fields($postId)->field('x')` in a MetaBlock; `Taw::post()`, `Taw::term()`, `Taw::user()`, `Taw::option('x')` (taw/core ≥ 1.57) | Typed read: `->text()`, `->bool()`, `->image()->url()`, `->rows()`, `->posts()`, `->link()`; `echo` is escaped by type |
| `OptionsPage::get($field)` / `get_image_url($field,$size)` | `wp_options` read, same `_taw_` prefix |
| Block Bindings source `taw/field` (taw/core ≥ 1.60) | Core blocks in the block editor (post content here) can show TAW fields: the "TAW field" toolbar button binds a paragraph, heading, button or image, with an editor preview (≥ 1.61). Front-end PHP templates keep using the typed reads above |
| `Menu::get($location)` → `->items()` returns `MenuItem[]` | Typed nav tree — never `wp_nav_menu()` |
| `Image::render($id,$size,['above_fold'=>bool,'class'=>...])` | Perf-optimised `<img>` |
| `Svg::render($id,$alt)` / `::inline($id)` / `::register()` | SVG upload + render |
| `Lucide::render($name,['class'=>...])` | Inline Lucide SVG (opt-in field type; render itself needs no `enable()`) |
| `Form::register([...])` inside `boot()` wrapped in `add_action('init',...)`; `Form::display($id)` in template | Frontend form |
| `(new Mailer())->to()->subject()->template()->setVariables()->send()` | Email |
| `ViteLoader::assetUrl($path)` / `::isDevServerRunning()` | Asset URL / dev-server probe |
| `dump($val)` / `dd($val)` | Debug panel, `WP_DEBUG` only |
| `Logger::error($code,$msg,$context)` (`TAW\Core\Log`; also `debug`/`info`/`notice`/`warning`/`critical`) | Structured log → `error_log()` + `wp-content/taw-logs/*.jsonl` |
| `BlockRegistry::queue(...ids)` before `get_header()`; `::render($id)` in body | Asset + render pattern |

Full API + option tables: §§ "The Metabox Framework", "Form System", "Options Page", "Navigation Menu System", "Image Helper", "SVG Helper", "Icon System", "Media Folders", "Debug Helper", "Logging", "Vite Integration" below.

### Lifecycle / hooks

| When | What fires |
|---|---|
| `after_setup_theme` | `BlockLoader::loadAll()` discovers `Blocks/*/`, calls each block's `static boot()` |
| inside `boot()` | wrap `Form::register()` and any `__()`/translation calls in `add_action('init', ...)` |
| `wp_enqueue_scripts` (via `get_header()`) | `BlockRegistry::enqueueQueuedAssets()` — only queued blocks' CSS/JS |
| template body | `BlockRegistry::render($id)` outputs HTML only, no enqueue |
| `POST admin-ajax.php` | Form submission handler — only exists if `Form::register()` ran in `boot()`, **never** in a template |

### Field types

- **Metabox:** `text textarea wysiwyg url number range select image files group checkbox color repeater post_select datepicker icon gradient_text hubspot_form link` (`icon` is opt-in — requires `Lucide::enable()`; `link` = URL + text + new tab in one field, taw/core ≥ 1.59)
- **Form:** `text email tel url textarea select checkbox date`

### Fatal mistakes (full list in § "Do NOT" below)

`getData(int|false $postId): array` signature typo → whole-site fatal · folder≠class name → silent auto-discovery skip · `queue()` called after `get_header()` → assets missing from `<head>` · Form registered in a template instead of `boot()` → AJAX handler 404s · hand-edited `functions.php` → silently overwritten by the next `update-theme` run · block created without a matching `tests/Unit/Blocks/{Name}Test.php` → `getData()` hydration regressions ship silently (see § "Testing Blocks").

---

## Source of Truth

**Canonical repos:**
- **`taw-theme`** (this scaffold): https://github.com/Relmaur/taw-theme — every real client site is a divergent instance of this repo. Synced into a client site via the `update-theme` skill (direct file copy of a small delimited set of framework-owned paths — no git merge, no shared history needed — see § "Starting a New Client Project" and § "The `functions.php` Bootstrap" below).
- **`taw/core`** (the framework package, installed at `vendor/taw/core/`): https://github.com/Relmaur/taw-core#readme — this is the authoritative source for all framework internals: Metabox field types and options, ViteLoader API, Visual Editor, Forms, and more. Always trust it over any conflicting information in this file. Synced via `composer update taw/core`, independently of `taw-theme` — these are two separate repos with two separate update paths, don't conflate them.

**Live documentation lookup — prefer the MCP tool over fetching a URL by hand:** if the `mcp__taw-docs__search_documentation` tool is available, use it first for any framework API question (hybrid semantic + keyword search over the current docs). It returns the up-to-date indexed content directly rather than requiring a URL guess. Fall back to `WebFetch` on the URLs below only if the MCP tool isn't available in the current environment.

## Full Documentation

Official docs live at **https://taw.mlizardo.com/**. Fetch the relevant page whenever you need deeper detail beyond what this file covers.

| Topic | URL |
|---|---|
| Introduction & concepts | https://taw.mlizardo.com/introduction |
| Quickstart / project setup | https://taw.mlizardo.com/quickstart |
| TAW Theme (boot, performance) | https://taw.mlizardo.com/taw-theme |
| TAW Core (framework internals) | https://taw.mlizardo.com/taw-core |
| Blocks & BlockRegistry | https://taw.mlizardo.com/taw-core-blocks |
| Assets & Vite integration | https://taw.mlizardo.com/taw-core-assets-vite |
| Metabox & Editor tooling | https://taw.mlizardo.com/taw-core-metabox |
| Setup guide | https://taw.mlizardo.com/help-center/guides/setup-project |
| Changelog | https://taw.mlizardo.com/changelog |

---

## Starting a New Client Project

**Shared git history with `taw-theme` is no longer required.** Earlier versions of this doc required `composer create-project --keep-vcs` or a full `git clone` specifically because `update-theme` used to sync via `git merge`, which needs a common ancestor commit. As of `taw/core` v1.16.63 (the `functions.php`/`inc/` split — see § "The `functions.php` Bootstrap" below) and the corresponding `update-theme` rewrite, that's no longer how updates work: the skill copies a small, precisely delimited set of framework-owned paths directly from a fresh checkout of `taw-theme`, regardless of this project's own git history. A brand-new project can `git init` with a single clean commit and `update-theme` will still work, forever.

Simplest setup — just get the files, start your own history:

```bash
composer create-project taw/theme <client-name> --repository='{"type":"vcs","url":"https://github.com/Relmaur/taw-theme"}'
cd <client-name>
git init
git add -A
git commit -m "Initial commit"
git remote add origin <client-repo-url>
git push -u origin main
```

(`--keep-vcs` is no longer necessary — omitting it is fine, and gives the cleaner single-commit history most people actually want.) A plain `git clone` still works too if you'd rather keep `taw-theme`'s full history for reference; it's just no longer *required* for anything.

```bash
composer install
npm install
npm run dev
```

Activate the theme in WP admin. The `.claude/skills/` are already in the repo — an agent can start scaffolding blocks/pages immediately.

**Framework-drift detection is already wired up, from the first commit.** `.github/workflows/framework-sync.yml` ships as part of the canonical `taw-theme` repo itself (it's Tier 1), so `composer create-project` gives you a project that self-checks for `taw/core` and scaffold updates on a weekly schedule from day one — there's no separate setup step for this on a brand-new project. It still needs one manual GitHub repo setting enabled, plus a handful of genuine per-project decisions (Turnstile, email, CSS Studio, Visual Editor) that don't have safe defaults — run the **`project-init`** skill right after the first push to work through all of it as an explicit checklist rather than discovering gaps later. See § "Automated framework-drift detection" below for what the workflow itself does.

(A project created *before* this shipped won't have `framework-sync.yml` yet — run the `update-theme` skill once to pick it up, same as any other Tier 1 scaffold update, then `project-init`.)

---

## The `functions.php` Bootstrap

`functions.php` is 100% framework-owned:

```php
<?php

require_once get_template_directory() . '/vendor/autoload.php';

TAW\Core\Theme\Theme::bootstrapFullSite(get_template_directory());
```

That's the whole file. Never hand-edit it — `update-theme` overwrites it unconditionally, no diff, no confirmation, because nothing site-specific can live there by construction. `bootstrapFullSite()` (in `taw/core`) does everything that used to be spelled out line-by-line in every theme's `functions.php`: it calls `Theme::boot()`, locks metabox order via `MetaboxOrder::lockFromTemplate()`, wires the CSS Studio dev-mode config injection, and auto-loads three theme-owned files **if they exist** — none of which `update-theme` ever touches:

| File | Purpose |
|---|---|
| `inc/options.php` | `OptionsPage` field configuration (pre-existing convention) |
| `inc/performance.php` | Returns the config array passed to `Theme::performance()` |
| `inc/customizations.php` | Theme supports, `register_nav_menus()`, textdomain loading, `VisualEditor::enable()`, `MetaboxOrder::lock()` for an explicit order, `Svg::register()`, `MailTester`, `require inc/security.php`, or any other site-specific hook |
| `inc/security.php` | User-enumeration hardening — calls `TAW\Core\Security\Hardening::hideUsersEndpoint()` and redirects logged-out `?author=N` probes; trim per site |

Any code that used to go directly in `functions.php` — theme supports, nav menu locations, `Svg::register()`, an explicit `MetaboxOrder::lock()` call, anything wrapped in `add_action(...)` — goes in `inc/customizations.php` instead. This is what makes `update-theme` a plain file copy instead of a merge: the boundary between "framework" and "this client's site" is a fact about which file something is in, not something that has to be computed from a diff.

---

## Quick Orientation

| Path | Purpose |
|---|---|
| `vendor/taw/core/src/Core/Block/` | Block system — `BaseBlock`, `Block`, `MetaBlock`, `BlockLoader`, `BlockRegistry` (**read-only**) |
| `vendor/taw/core/src/Core/Metabox/` | Metabox framework (`Metabox`) |
| `vendor/taw/core/src/Core/OptionsPage/` | Options page framework (`OptionsPage`) |
| `vendor/taw/core/src/Core/Theme/` | `Theme`, `ThemeUpdater` |
| `vendor/taw/core/src/Core/Menu/` | Navigation menu object model (`Menu`, `MenuItem`) |
| `vendor/taw/core/src/Core/Rest/` | REST API endpoints (`SearchEndpoints`, `VisualEditorEndpoint`) |
| `vendor/taw/core/src/Core/Form/` | Frontend form system (`Form`, `SubmissionsHandler`) |
| `vendor/taw/core/src/Core/Mail/` | Email system (`Mailer`, `MailTemplate`, `MailTester`) |
| `vendor/taw/core/src/Core/Editor/` | Visual Editor engine — inline frontend editing for authenticated users |
| `vendor/taw/core/src/Helpers/` | Utility helpers (`Framework`, `Image`, `Svg`, `Dump`, `Editor`) |
| `vendor/taw/core/src/CLI/` | Symfony Console commands (`make:block`, `export:block`, `import:block`, `inspect`, `fields:get`/`fields:set`, `sync`) |
| `vendor/taw/core/src/Support/` | `ViteLoader.php` — OOP Vite asset pipeline; `utilities.php`, `performance.php` — autoloaded by composer |
| `Blocks/` | Dev block collection — one folder per block, auto-discovered |
| `Blocks/Menu/` | Boilerplate navigation block — two-row header with Alpine.js live-search overlay |
| `tests/Unit/Blocks/` | `getData()` hydration tests, one per block — see § "Testing Blocks" |
| `inc/options.php` | OptionsPage field configuration — theme-owned, never touched by updates |
| `inc/performance.php` | Returns the config array passed to `Theme::performance()` — theme-owned, never touched by updates |
| `inc/customizations.php` | Theme supports, nav menu registration, textdomain, any other site-specific hooks — theme-owned, never touched by updates |
| `inc/security.php` | User-enumeration hardening (REST `/wp/v2/users` + `?author=N`) — theme-owned, never touched by updates |
| `resources/js/app.js` | Alpine.js + global JS — imports Tailwind CSS and custom SCSS |
| `resources/css/app.css` | Tailwind v4 directives (`@import "tailwindcss"`) — imported by `app.js` |
| `resources/scss/app.scss` | Global custom SCSS (fonts, overrides) — imported by `app.js` |
| `resources/scss/critical.scss` | Above-the-fold CSS — compiled and inlined in `<head>` |
| `resources/scss/_fonts.scss` | `@font-face` declarations for self-hosted fonts |
| `resources/fonts/` | Self-hosted WOFF2 font files |
| `functions.php` | **100% framework-owned** — two lines: require the autoloader, call `TAW\Core\Theme\Theme::bootstrapFullSite(get_template_directory())`. Never hand-edit; blindly overwritten by `update-theme`. |

> **Important:** `TAW\Core`, `TAW\Helpers`, and `TAW\CLI` classes live inside the `taw/core` composer package (`vendor/taw/core/src/`), **not** in `inc/`. The theme's `inc/` holds only theme-owned config (`options.php`, `performance.php`, `customizations.php`) and Metabox view templates. Do not edit files inside `vendor/`.

---

## Building a Page — AI Playbook

This section is the primary guide for fulfilling requests like _"I need a homepage with a hero, features, and a contact section."_ Read this first.

### Step 1 — Map sections to block types

| If the section… | Use |
|---|---|
| Owns its own content (editable in WP Admin per-page) | **MetaBlock** |
| Is a reusable UI primitive (button, card, badge) | **Block** (presentational) |
| Needs a form with email delivery | MetaBlock that renders a `Form` instance |

**Rule of thumb:** any named section that a content editor would fill in → MetaBlock. Utility components used inside those sections → Block.

### Step 2 — Check existing blocks first

Before creating anything, run `ls Blocks/` in your mind (or via the file tree). If a block already exists for the requested section, reuse it — just queue and render it in the page template. Only create a new block if none matches.

### Step 3 — Create missing blocks

For each new MetaBlock needed:

```bash
# Preferred — scaffolds all files and runs dump-autoload reminder
php bin/taw make:block SectionName --type=meta --with-style
```

Or manually create:
- `Blocks/SectionName/SectionName.php` — the block class (see catalog below for typical fields)
- `Blocks/SectionName/index.php` — the template

Then run `composer dump-autoload`.

### Step 4 — Create or update the page template

WordPress page templates live in the theme root. Naming conventions:

| Template file | When it loads |
|---|---|
| `page.php` | All pages (fallback) |
| `front-page.php` | The site's front page |
| `page-{slug}.php` | Page with that slug (e.g. `page-about.php`) |
| `page-{id}.php` | Page with that post ID |

**Template skeleton:**

```php
<?php
// page-home.php — example for a homepage

use TAW\Core\Block\BlockRegistry;

// 1. Queue all blocks BEFORE get_header() so assets land in <head>
BlockRegistry::queue('hero', 'features', 'testimonials', 'cta');

get_header();
?>

<?php BlockRegistry::render('hero'); ?>
<?php BlockRegistry::render('features'); ?>
<?php BlockRegistry::render('testimonials'); ?>
<?php BlockRegistry::render('cta'); ?>

<?php get_footer();
```

> Never call `BlockRegistry::queue()` after `get_header()` — assets will miss `wp_head()`.

### Step 5 — No changes to functions.php needed

`BlockLoader::loadAll()` in `functions.php` auto-discovers every block in `Blocks/*/`. New blocks appear automatically after `composer dump-autoload`.

---

## Common Section Catalog

A lookup table of the most common sections. Use these as the default starting point for fields and structure when a user asks for these sections by name (or by equivalent intent).

### Hero

**Type:** MetaBlock
**Purpose:** Full-width above-the-fold opener — heading, tagline, background image, and CTA buttons.

```php
'fields' => [
    ['id' => 'hero_heading', 'label' => 'Heading',    'type' => 'text',  'required' => true, 'width' => '50'],
    ['id' => 'hero_tagline', 'label' => 'Tagline',    'type' => 'text',  'width' => '50'],
    ['id' => 'hero_image',   'label' => 'Image',      'type' => 'image', 'width' => '50'],
    ['id' => 'hero_cta_text','label' => 'CTA Text',   'type' => 'text',  'width' => '25'],
    ['id' => 'hero_cta_url', 'label' => 'CTA URL',    'type' => 'url',   'width' => '25'],
],
```

### Features / Services

**Type:** MetaBlock
**Purpose:** Section with a heading and a repeatable list of features/services (icon, title, description).

```php
'fields' => [
    ['id' => 'features_heading',    'label' => 'Section Heading',    'type' => 'text', 'width' => '50'],
    ['id' => 'features_subheading', 'label' => 'Section Subheading', 'type' => 'text', 'width' => '50'],
    [
        'id'           => 'features_items',
        'label'        => 'Features',
        'type'         => 'repeater',
        'button_label' => 'Add Feature',
        'fields' => [
            ['id' => 'icon',        'label' => 'Icon',        'type' => 'image',    'width' => '25'],
            ['id' => 'title',       'label' => 'Title',       'type' => 'text',     'width' => '75'],
            ['id' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
        ],
    ],
],
```

### Testimonials

**Type:** MetaBlock
**Purpose:** Social proof section with a repeatable list of quotes, author names, and photos.

```php
'fields' => [
    ['id' => 'testimonials_heading', 'label' => 'Section Heading', 'type' => 'text'],
    [
        'id'           => 'testimonials_items',
        'label'        => 'Testimonials',
        'type'         => 'repeater',
        'button_label' => 'Add Testimonial',
        'fields' => [
            ['id' => 'quote',   'label' => 'Quote',       'type' => 'textarea', 'rows' => 3],
            ['id' => 'name',    'label' => 'Author Name', 'type' => 'text',     'width' => '50'],
            ['id' => 'role',    'label' => 'Role/Company','type' => 'text',     'width' => '50'],
            ['id' => 'avatar',  'label' => 'Photo',       'type' => 'image',    'width' => '50'],
            ['id' => 'rating',  'label' => 'Rating (1–5)','type' => 'number',   'width' => '50'],
        ],
    ],
],
```

### Pricing Table

**Type:** MetaBlock
**Purpose:** A section with repeatable pricing tiers (name, price, feature list, CTA).

```php
'fields' => [
    ['id' => 'pricing_heading', 'label' => 'Heading', 'type' => 'text', 'width' => '50'],
    [
        'id'           => 'pricing_plans',
        'label'        => 'Plans',
        'type'         => 'repeater',
        'button_label' => 'Add Plan',
        'fields' => [
            ['id' => 'name',     'label' => 'Plan Name', 'type' => 'text',   'width' => '33'],
            ['id' => 'price',    'label' => 'Price',     'type' => 'text',   'width' => '33'],
            ['id' => 'period',   'label' => 'Period',    'type' => 'text',   'width' => '33', 'placeholder' => '/mo'],
            ['id' => 'features', 'label' => 'Features',  'type' => 'textarea', 'rows' => 5, 'placeholder' => 'One feature per line'],
            ['id' => 'cta_text', 'label' => 'CTA Text',  'type' => 'text',   'width' => '50'],
            ['id' => 'cta_url',  'label' => 'CTA URL',   'type' => 'url',    'width' => '50'],
            ['id' => 'featured', 'label' => 'Highlighted', 'type' => 'checkbox'],
        ],
    ],
],
```

### CTA (Call to Action)

**Type:** MetaBlock
**Purpose:** A full-width band with a heading, supporting text, and one or two buttons.

```php
'fields' => [
    ['id' => 'cta_heading',     'label' => 'Heading',         'type' => 'text',  'required' => true, 'width' => '50'],
    ['id' => 'cta_subtext',     'label' => 'Supporting text', 'type' => 'text',  'width' => '50'],
    ['id' => 'cta_primary_text','label' => 'Primary CTA',     'type' => 'text',  'width' => '25'],
    ['id' => 'cta_primary_url', 'label' => 'Primary URL',     'type' => 'url',   'width' => '25'],
    ['id' => 'cta_secondary_text','label' => 'Secondary CTA', 'type' => 'text',  'width' => '25'],
    ['id' => 'cta_secondary_url','label' => 'Secondary URL',  'type' => 'url',   'width' => '25'],
    ['id' => 'cta_bg_color',    'label' => 'Background',      'type' => 'color', 'width' => '50'],
],
```

### Team

**Type:** MetaBlock
**Purpose:** Team member grid with photo, name, role, bio, and optional social links.

```php
'fields' => [
    ['id' => 'team_heading', 'label' => 'Heading', 'type' => 'text'],
    [
        'id'           => 'team_members',
        'label'        => 'Team Members',
        'type'         => 'repeater',
        'button_label' => 'Add Member',
        'fields' => [
            ['id' => 'photo', 'label' => 'Photo',  'type' => 'image', 'width' => '25'],
            ['id' => 'name',  'label' => 'Name',   'type' => 'text',  'width' => '37'],
            ['id' => 'role',  'label' => 'Role',   'type' => 'text',  'width' => '37'],
            ['id' => 'bio',   'label' => 'Bio',    'type' => 'textarea', 'rows' => 3],
            [
                'id' => 'social', 'label' => 'Social links', 'type' => 'group',
                'fields' => [
                    ['id' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url', 'width' => '50'],
                    ['id' => 'twitter',  'label' => 'Twitter',  'type' => 'url', 'width' => '50'],
                ],
            ],
        ],
    ],
],
```

### FAQ

**Type:** MetaBlock
**Purpose:** Accordion-style FAQ section with a heading and repeatable question/answer pairs.

**Already implemented** (unlike the other entries in this catalog, which are copy-paste starting points) — `Blocks/FAQ/` ships with this theme, and its `index.php` also pushes a `FAQPage` JSON-LD node onto `taw/core`'s `TAW\Core\Seo\Schema` graph (`Schema::push(Schema::faqPage($items))`) from the same `$items` the accordion renders from, so the visible markup and the structured data can never drift out of sync. Use this as the reference example when pairing a new block's markup with its own schema.org node.

```php
'fields' => [
    ['id' => 'faq_heading', 'label' => 'Heading', 'type' => 'text', 'width' => '50'],
    [
        'id'           => 'faq_items',
        'label'        => 'Questions',
        'type'         => 'repeater',
        'button_label' => 'Add Question',
        'fields' => [
            ['id' => 'question', 'label' => 'Question', 'type' => 'text',     'required' => true],
            ['id' => 'answer',   'label' => 'Answer',   'type' => 'textarea', 'rows' => 4],
        ],
    ],
],
```

### Contact

**Type:** MetaBlock
**Purpose:** Section with contact info + a Form rendered inline.

Register the form in the block's `boot()` method; render it in `index.php` with `Form::display()`.

```php
// Blocks/Contact/Contact.php
use TAW\Core\Form\Form;

public static function boot(): void
{
    add_action('init', static function () {
        Form::register([
            'id'           => 'contact',
            'submit_label' => 'Send Message',
            'fields' => [
                ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true, 'width' => 50],
                ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true, 'width' => 50],
                ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
        ]);
    });
}

// Blocks/Contact/index.php
Form::display('contact');
```

### Stats / Numbers

**Type:** MetaBlock
**Purpose:** Row of key numbers/stats (value + label pairs).

```php
'fields' => [
    ['id' => 'stats_heading', 'label' => 'Heading', 'type' => 'text'],
    [
        'id'           => 'stats_items',
        'label'        => 'Stats',
        'type'         => 'repeater',
        'button_label' => 'Add Stat',
        'max'          => 6,
        'fields' => [
            ['id' => 'value',  'label' => 'Value',  'type' => 'text', 'placeholder' => '99%', 'width' => '50'],
            ['id' => 'label',  'label' => 'Label',  'type' => 'text', 'placeholder' => 'Satisfaction', 'width' => '50'],
        ],
    ],
],
```

### Logo Bar / Partners

**Type:** MetaBlock
**Purpose:** Horizontal row of partner/client logos.

```php
'fields' => [
    ['id' => 'logos_heading', 'label' => 'Eyebrow text', 'type' => 'text'],
    [
        'id'           => 'logos_items',
        'label'        => 'Logos',
        'type'         => 'repeater',
        'button_label' => 'Add Logo',
        'fields' => [
            ['id' => 'logo', 'label' => 'Logo',    'type' => 'image', 'width' => '50'],
            ['id' => 'url',  'label' => 'Link URL','type' => 'url',   'width' => '50'],
            ['id' => 'name', 'label' => 'Alt text','type' => 'text',  'width' => '50'],
        ],
    ],
],
```

---

## The `taw/core` Package

Framework internals are maintained as a standalone composer package at **`https://github.com/Relmaur/taw-core`** and installed at `vendor/taw/core/`.

This separation means:
- The core framework can be versioned and updated independently of any theme.
- Themes declare a version constraint in `composer.json` (`"taw/core": "^1.0"`).
- To pull a framework update: `composer update taw/core --with-dependencies` (without `-W`, a release that needs a newer dependency changes nothing; check the version moved), then read `vendor/taw/core/UPGRADING.md` for every release since the one you came from (what changes by default, and what to check).
- To change framework behaviour, work in the `taw-core` repo, tag a release, then update the constraint here.

**Gotcha — a `taw-core` change on its `main` branch does not exist here until tagged AND pulled.** `composer.lock` pins an exact tag; a commit sitting on `taw-core`'s `main` (even pushed) changes nothing in this repo until (1) `taw-core` cuts a new version tag and (2) `composer update taw/core` runs *here* and the updated `composer.lock` is committed. If a block here is written against a `taw-core` class/method that only exists on `main` and not yet in a tagged release, `composer run phpstan`/`composer run test` will fail with `class.notFound` — that's not a false positive, it means the dependency genuinely isn't resolvable yet. **Never "fix" this by hand-copying files into `vendor/taw/core/` to make a local check pass** — that bypasses Composer's real version resolution, so a check that passes that way will still fail in CI (which always does a real `composer install` against the committed lock file). The only real fix is the tag-then-update sequence above.

**Package structure:**
```
vendor/taw/core/
├── src/
│   ├── Core/
│   │   ├── Block/
│   │   │   ├── BaseBlock.php        # Abstract base — asset loading, template rendering
│   │   │   ├── MetaBlock.php        # Data-owning blocks (metaboxes + post_meta)
│   │   │   ├── Block.php            # Presentational blocks (receives props)
│   │   │   ├── BlockRegistry.php    # Static registry — queue, enqueue, render
│   │   │   └── BlockLoader.php      # Auto-discovers blocks by scanning Blocks/
│   │   ├── Metabox/
│   │   │   └── Metabox.php          # Config-driven metabox framework
│   │   ├── OptionsPage/
│   │   │   └── OptionsPage.php      # Config-driven admin options page (wp_options)
│   │   ├── Theme/
│   │   │   ├── Theme.php            # Theme bootstrap
│   │   │   └── ThemeUpdater.php     # GitHub Releases-based auto-updater
│   │   ├── Menu/
│   │   │   ├── Menu.php             # Nav menu tree factory
│   │   │   └── MenuItem.php         # Typed menu item with active-state helpers
│   │   ├── Rest/
│   │   │   ├── SearchEndpoints.php      # GET taw/v1/search-posts
│   │   │   ├── VisualEditorEndpoint.php # Visual Builder REST endpoint
│   │   │   └── IconsEndpoint.php        # GET taw/v1/icons — Lucide search (opt-in, see Icons/)
│   │   ├── Form/
│   │   │   ├── Form.php             # Config-driven frontend form (CSRF, honeypot, PRG, email)
│   │   │   └── SubmissionsHandler.php # CPT submission storage + webhook forwarding
│   │   ├── Mail/
│   │   │   ├── Mailer.php           # Fluent wp_mail() wrapper with template support
│   │   │   ├── MailTemplate.php     # MJML/HTML template compiler ({{variable}} syntax)
│   │   │   └── MailTester.php       # Admin page: Tools → Test Emails
│   │   ├── Editor/
│   │   │   └── VisualEditor.php     # Visual Builder engine — inline frontend editor
│   │   ├── Icons/
│   │   │   ├── Lucide.php           # Opt-in 'icon' field type + template render() helper
│   │   │   ├── picker.js/.css       # wp-admin icon picker modal
│   │   │   └── (resources/icons/lucide/*.svg + lucide-index.json — vendored icon set)
│   │   └── Media/
│   │       ├── MediaFolders.php     # Opt-in nestable Media Library folders (taxonomy-based)
│   │       └── folders.js/.css      # Media -> Folders screen + List-view integration
│   ├── CLI/                         # Symfony Console commands
│   │   ├── MakeBlockCommand.php
│   │   ├── ExportBlockCommand.php
│   │   └── ImportBlockCommand.php
│   ├── Helpers/
│   │   ├── Framework.php        # Path/URL resolver (Framework::path(), Framework::themePath() …)
│   │   ├── Image.php            # Performance-optimised <img> tag generator
│   │   ├── Svg.php              # SVG upload enablement, sanitization, inline/img rendering
│   │   ├── Dump.php             # Debug panel helper (WP_DEBUG only)
│   │   └── Editor.php           # Visual editor field annotation helpers
│   └── Support/
│       ├── utilities.php        # Global helpers: vite_asset_url(), dump(), taw_editable() … (autoloaded)
│       └── performance.php      # Resource hints, font preloads, WP bloat removal (autoloaded)
└── composer.json
```

---

## Architecture: The Block System

### Class Hierarchy

```
BaseBlock (abstract)
├── MetaBlock (abstract) — owns data via metaboxes, fetches from post_meta
│   └── Hero, Stats, Testimonials, etc.
└── Block (abstract) — presentational, receives data as props
    └── Button, Card, Badge, etc.
```

### Key Classes (all from `taw/core`)

| Class | Role |
|---|---|
| `TAW\Core\Block\BaseBlock` | Reflection-based auto-discovery of component directory, asset enqueuing (CSS/JS), template rendering via `extract()` |
| `TAW\Core\Block\MetaBlock` | Extends BaseBlock. Registers metaboxes via `registerMetaboxes()` (deferred to `init`), provides `getData(int|false $postId)` and `render(?int $postId)`. Override `static boot(): void` for early-request setup (e.g. form registration). |
| `TAW\Core\Block\Block` | Extends BaseBlock. Defines `defaults()` for props, provides `render(array $props)` |
| `TAW\Core\Block\BlockRegistry` | Static registry for MetaBlocks. Supports `register()`, `queue()`, `render()`, `enqueueQueuedAssets()` |
| `TAW\Core\Block\BlockLoader` | Auto-discovers all MetaBlock classes by scanning `Blocks/*/` directories |
| `TAW\Helpers\Framework` | Path/URL resolver. `Framework::path()` → package root; `Framework::themePath()` → active theme root |
| `TAW\Core\Metabox\Metabox` | Configuration-driven metabox framework. Field registration, rendering, saving, and static retrieval helpers |
| `TAW\Core\OptionsPage\OptionsPage` | Configuration-driven admin options page. Same field format as Metabox but stores to `wp_options` |
| `TAW\Core\Theme\ThemeUpdater` | GitHub Releases-based automatic theme updater |
| `TAW\Core\Menu\Menu` | Navigation menu object model — wraps WP nav menus into a typed tree |
| `TAW\Core\Menu\MenuItem` | Individual menu item with typed getters (url, title, children, active state) |
| `TAW\Core\Rest\SearchEndpoints` | REST API: `GET taw/v1/search-posts` — post search for authenticated users |
| `TAW\Core\Form\Form` | Configuration-driven frontend form — CSRF, honeypot, validation, PRG redirect, email delivery |
| `TAW\Core\Form\SubmissionsHandler` | Saves form submissions as `taw_submission` CPT; webhook forwarding (n8n, Zapier, Make) |
| `TAW\Core\Mail\Mailer` | Fluent `wp_mail()` wrapper with HTML template support |
| `TAW\Core\Mail\MailTemplate` | File-based email template compiler. Looks for `mails/html/{name}.html`; falls back to MJML at runtime |
| `TAW\Core\Mail\MailTester` | Admin page under Tools → Test Emails for testing compiled templates |
| `TAW\Core\Editor\VisualEditor` | Frontend inline editor — **opt-in**: call `VisualEditor::enable()` in `inc/customizations.php` (must run before `Theme::boot()`; `bootstrapFullSite()` already loads `customizations.php` first). `Theme::boot()`'s internal `VisualEditor::init()` silently no-ops without it. Once enabled, toggled via the **Edit Visually** admin bar button or `?taw_visual_edit=1`. |
| `TAW\Core\Rest\VisualEditorEndpoint` | REST endpoint: `POST /taw/v1/visual-editor/save` — persists inline edits using the same sanitization pipeline as metabox saves |
| `TAW\Support\ViteLoader` | OOP Vite bridge — dev-server detection (`isDevServerRunning()`), manifest parsing (`getManifest()`), asset URL resolution (`assetUrl()`), module preloading, critical CSS inlining (`inlineCriticalCss()`), and additional entry-point enqueuing (`enqueueAsset()`) |
| `TAW\Helpers\Image` | Performance-optimized `<img>` tag generator with above/below-fold attributes |
| `TAW\Helpers\Svg` | SVG upload enablement, sanitization on upload, and inline/img rendering |
| `TAW\Helpers\Dump` | Debug panel helper — `dump()` / `dd()` global functions, renders in `wp_footer` (WP_DEBUG only) |
| `TAW\Helpers\Editor` | Visual editor field annotation — `Editor::field()`, `Editor::attrs()`, `Editor::section()` |

### Naming Convention (CRITICAL)

Every block follows this exact convention — the folder name **must** match the class name:

```
Blocks/{Name}/{Name}.php    → class TAW\Blocks\{Name}\{Name}
Blocks/{Name}/index.php     → Template file
Blocks/{Name}/style.css     → Optional stylesheet (or style.scss)
Blocks/{Name}/script.js     → Optional JavaScript
```

Example:
```
Blocks/Hero/Hero.php        → class TAW\Blocks\Hero\Hero extends MetaBlock
Blocks/Hero/index.php       → Template receives extracted variables
Blocks/Hero/style.css       → Enqueued only when Hero renders
```

BlockLoader relies on this convention for auto-discovery. Breaking it will silently skip the block.

### Two Block Types

**MetaBlock** (data-owning sections):
- Registered in `BlockRegistry` via `BlockLoader::loadAll()`
- Owns metaboxes → appears in WP admin editor
- Fetches its own data from `post_meta` via `getData(int|false $postId)` — accepts `false` on 404 pages
- Rendered via `BlockRegistry::render('hero')`
- Override `static boot(): void` for early-request setup (form registration, early hooks). Called during block discovery at `after_setup_theme`. Wrap `__()` calls in `add_action('init', ...)` inside it.

**Block** (presentational UI components):
- NOT registered in the registry
- Receives data as props
- Instantiated directly where needed: `(new Button())->render(['text' => 'Click'])`

### Asset Loading Strategy — The Queue Pattern

Assets are loaded conditionally per page. The timing chain is:

```
functions.php        → BlockLoader::loadAll() registers all MetaBlocks
template file        → BlockRegistry::queue('hero', 'stats') ← BEFORE get_header()
get_header()         → wp_enqueue_scripts fires
                       → BlockRegistry::enqueueQueuedAssets() (only queued blocks' CSS/JS)
                     → wp_head() outputs <link>/<script> in <head>
template body        → BlockRegistry::render('hero') outputs HTML only
get_footer()         → wp_footer()
```

**Template pattern:**
```php
<?php
use TAW\Core\Block\BlockRegistry;

// 1. Queue blocks BEFORE get_header (assets land in <head>)
BlockRegistry::queue('hero', 'stats');

get_header();
?>

<?php BlockRegistry::render('hero'); ?>
<?php BlockRegistry::render('stats'); ?>

<?php get_footer(); ?>
```

**Safety fallback:** If `render()` is called without prior `queue()`, styles are printed inline in the body via `did_action('wp_head')` check. This works but is suboptimal (potential FOUC). Always prefer `queue()` first.

---


## Creating a New MetaBlock

### Step 1: Create the directory and class

```php
<?php
// Blocks/Features/Features.php

declare(strict_types=1);

namespace TAW\Blocks\Features;

use TAW\Core\Block\MetaBlock;
use TAW\Core\Metabox\Metabox;

class Features extends MetaBlock
{
    protected string $id = 'features';

    protected function registerMetaboxes(): void
    {
        new Metabox([
            'id'     => 'taw_features',
            'title'  => 'Features Section',
            'screens' => ['page'],
            'fields' => [
                [
                    'id'    => 'features_heading',
                    'label' => 'Heading',
                    'type'  => 'text',
                ],
                // Add more fields...
            ],
        ]);
    }

    protected function getData(int|false $postId): array
    {
        $fields = $this->fields($postId);   // typed reads (taw/core ≥ 1.57); getMeta() still works

        return [
            'heading' => $fields->field('features_heading')->text(),
        ];
    }
}
```

**Reading fields in `getData()`:** prefer `$this->fields($postId)` for new blocks, and return **plain values** from its typed accessors (`->text()`, `->bool()`, `->int()`, `->image()->url('large')`, `->link()->url()`, rows mapped to arrays) so the template keeps its own `esc_html()`/`esc_url()` and the block's unit test compares plain arrays. Never pass a field `Value` itself to `esc_html()`: echoing a `Value` already escapes it, so escaping again double-escapes (and turns a link's `<a>` into text). Missing posts and fields read as empty, never fatal.

### Step 2: Create the template

```php
<?php
// Blocks/Features/index.php

/** @var string $heading */

if (empty($heading)) return;
?>

<section class="features">
    <h2><?php echo esc_html($heading); ?></h2>
</section>
```

### Step 3: Optionally add style.css or style.scss

The block auto-discovers and enqueues these when rendered. SCSS is prioritized over CSS if both exist.

### Step 4: Write the test

Every block gets a matching `tests/Unit/Blocks/{Name}Test.php` testing `getData()`'s hydration logic — see § "Testing Blocks" for the pattern and rationale. Not optional.

### Step 5: That's it

`BlockLoader::loadAll()` auto-discovers the block. No changes to `functions.php` needed. Just `queue()` and `render()` it in a template.

> **Shortcut:** Use the CLI scaffolder instead of writing files manually:
> ```bash
> php bin/taw make:block Features --type=meta --with-style
> ```

---

## Creating a New UI Block

```php
<?php
// Blocks/Card/Card.php

declare(strict_types=1);

namespace TAW\Blocks\Card;

use TAW\Core\Block\Block;

class Card extends Block
{
    protected string $id = 'card';

    protected function defaults(): array
    {
        return [
            'title'       => '',
            'description' => '',
            'image_url'   => '',
        ];
    }
}
```

Usage in any template:
```php
<?php (new TAW\Blocks\Card\Card())->render([
    'title'       => 'My Card',
    'description' => 'Card content here',
]); ?>
```

---

## Testing Blocks

Every block's `getData(int|false $postId): array` gets a matching PHPUnit test at `tests/Unit/Blocks/{Name}Test.php` — required for every block created by `make-metablock` or `figma-to-block`, no exceptions. `getData()` is exactly the kind of logic that breaks silently in a future refactor (a renamed field id, a swapped `getMeta()`/`getRepeater()` call) without anything catching it until a page renders wrong in production.

**Harness:** `phpunit.xml` (bootstrap: `tests/bootstrap.php`), `tests/TestCase.php` (namespace `TAW\Theme\Tests`, PSR-4-mapped from `tests/` via `composer.json`'s `autoload-dev`), `composer run test` runs the suite. Mirrors `taw/core`'s own test suite (`vendor/taw/core/tests/`) — same stack (PHPUnit 11 + Brain Monkey), same reason: fast, isolated tests that stub individual WP functions instead of needing a real WordPress install.

If this project was created via `composer create-project` off the canonical scaffold, this harness ships from the first commit — nothing to scaffold. If it's an existing project that only ever received the harness's *documentation* via an `update-theme` sync (this section of `AGENTS.md` is Tier 2 and syncs on its own) without the harness files themselves — check for `phpunit.xml` before assuming it's there. If missing, run `update-theme`: `tests/bootstrap.php`/`tests/TestCase.php` are Tier 1 (apply automatically), `phpunit.xml` and the matching `composer.json` `require-dev`/`scripts`/`autoload-dev` entries are Tier 2 (review the diff, then apply).

**Scope — `getData()` hydration only, not rendering.** Does `getData()` ask for the right field IDs and assemble them into the right array? That's it. Full end-to-end rendering (a block's `index.php` actually producing correct HTML against a real WordPress + MySQL install) is a separate, already-existing concern owned by `bin/ci/smoke-test.php` — don't try to duplicate that here, and don't skip a `getData()` test because "the smoke test already covers it" (the smoke test only proves a page didn't fatal, it doesn't assert field-level correctness).

**Why mock `getMeta()`/`getImageUrl()`/`getRepeater()` instead of exercising them for real:** they're thin wrappers around `TAW\Core\Metabox\Metabox`'s own static methods, which are `taw/core`'s concern and already covered by its own test suite. Mocking them keeps a block's test scoped to what's actually that block's own logic. `TAW\Core\Block\BaseBlock`'s constructor calls `get_template_directory()`/`get_template_directory_uri()` unconditionally, and `MetaBlock`'s constructor also calls `add_action('init', ...)` — `TestCase::stubBlockConstructor()` stubs all three via Brain Monkey; call it in every block test's `setUp()` before instantiating the block under test.

**Pattern** (full worked example already in the repo: `tests/Unit/Blocks/FaqTest.php`):

```php
<?php

declare(strict_types=1);

namespace TAW\Theme\Tests\Unit\Blocks;

use TAW\Blocks\SectionName\SectionName;
use TAW\Theme\Tests\TestCase;

final class SectionNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->stubBlockConstructor();
    }

    public function test_getData_reads_the_expected_field_ids(): void
    {
        $block = $this->getMockBuilder(SectionName::class)
            ->onlyMethods(['getMeta']) // + 'getImageUrl'/'getRepeater' if getData() calls them
            ->getMock();

        $block->expects($this->once())
            ->method('getMeta')
            ->with(42, 'section_name_heading')   // exact field id from registerMetaboxes()
            ->willReturn('Example Heading');

        $data = $this->callMethod($block, 'getData', 42);

        $this->assertSame('Example Heading', $data['heading']);  // exact key getData() returns
    }
}
```

**Rules:**
- Mock only the getter methods this block's `getData()` actually calls — don't mock ones it doesn't use.
- Assert the exact field-id string passed to each mocked getter with `->with($postId, 'exact_field_id')`, not a bare `->willReturn(...)` with no argument check — an unchecked mock can't catch a mismatch between `registerMetaboxes()`'s field ids and `getData()`'s lookups, which is the whole point of the test.
- For a repeater field, add a second test asserting an empty array passes through untouched (see `FaqTest::test_getData_passes_through_an_empty_repeater_untouched`).
- Run `composer run test` before calling any block "done" — a test that exists but doesn't actually run (typo'd namespace, wrong mocked method name) provides false confidence, not coverage.

---

## The Metabox Framework

Provided by `taw/core` (namespace `TAW\Core\Metabox\Metabox`). Configuration-driven, supports:

**Field types:** `text`, `textarea`, `wysiwyg`, `url`, `number`, `range`, `select`, `image`, `files`, `group`, `checkbox`, `color`, `repeater`, `post_select`, `datepicker`, `gradient_text`, `hubspot_form`

**Features:**
- `screens` key (array) — accepts post type slugs, page template filenames, page slugs, or mixed
- `show_on` callback for conditional display (e.g., front page only)
- `tabs` for grouped field organization
- `width` property for side-by-side fields (e.g., `'width' => '50'`)
- `sanitize` => `'code'` for raw code snippet fields
- `readonly` => `true` on any field — renders as non-interactive text (with a lock icon next to its label) instead of an editable control, and its POSTed value is never written on save (enforced server-side, not just visually hidden). Use for fields an external process (e.g. a `.md`-file sync pipeline) authoritatively owns, so wp-admin stops implying they're editable there. Composes with `group` (propagates to every sub-field) and `repeater` (disables Add/Remove/reorder for the whole row list, not just the sub-field values) — see taw/core README § "Readonly Fields" for the full breakdown, including the not-yet-covered gap: `VisualEditorEndpoint` and `fields:set`/`seo:inject` don't consult this flag yet.
- `group` type for nested field groups (e.g., CTA with text + URL)
- `repeater` type for dynamic repeatable row groups (nested repeaters supported)
- `files` type — multi-file picker, drag-to-reorder, stores JSON array of attachment IDs
- `post_select` type for selecting related posts via a search-as-you-type UI (uses `taw/v1/search-posts`)
- `color` type renders a native color picker
- `checkbox` type renders a boolean toggle
- `datepicker` type renders a jQuery UI date picker; stored as a date string (default `YYYY-MM-DD`); supports `date_format`, `min_date`, `max_date`
- `gradient_text` type — ordered `{text, highlighted}` segments (JSON array); Alpine-only admin segment editor, no drag-reorder. Render with `Metabox::renderGradientText($segments, $highlightClass)`, passing the theme's own gradient utility classes.
- `hubspot_form` type — `{portal_id, form_id, region}` (JSON object). Render with `TAW\Core\Integrations\Hubspot::render($config)` (gate with `Hubspot::isConfigured($config)` to fall back to the site's native `Form` block when unconfigured).

→ Full reference: **[taw/core README — Metabox System](https://github.com/Relmaur/taw-core#metabox-system)**

**Meta key pattern:** `_taw_{field_id}` (prefix configurable, default `_taw_`)

**Static helpers:**
```php
Metabox::get(int $postId, string $fieldId, string $prefix = '_taw_'): mixed
Metabox::get_bool(int $postId, string $fieldId): bool
Metabox::get_image_url(int $postId, string $fieldId, string $size = 'full'): string
Metabox::get_color(int $postId, string $fieldId, string $fallback = ''): string
Metabox::get_posts(int $postId, string $fieldId): array   // post_select → array of IDs
Metabox::get_repeater(int $postId, string $fieldId): array // repeater → array of rows
```

**Default content goes in `'defaults'`, not in `getData()`** (taw/core ≥ v1.80.0). `'defaults' => ['field_id' => value]` on the metabox (or options page) is what the field reads and shows in the editor while nothing is stored, so write `$this->getMeta($postId, 'hero_heading')`, not `getMeta(...) ?: 'Welcome'`. A `?:` fallback is invisible to wp-admin and to content exports; a default can be saved as a record (Tools → TAW Data → *Defaults*, or `php bin/taw content:defaults --apply`). Repeater defaults are arrays of rows. Full detail: taw/core README § "Metabox System → Defaults".

### Locking Metabox Order

By default WordPress lets any user drag-and-drop reorder metaboxes, saved per-user, so the same edit screen can look different for every editor. `TAW\Core\Metabox\MetaboxOrder` forces a fixed order and disables dragging.

**`MetaboxOrder::lockFromTemplate()` runs automatically** — `Theme::bootstrapFullSite()` (called by `functions.php`) calls it unconditionally, nothing to add. It works via a static scan of each page's template file (never executed in wp-admin), so the edit screen's metabox order always matches the order blocks actually render in on the front end. Boxes not tied to a block on the page (e.g. core WordPress boxes) keep their relative position and render after the ordered ones.

To use an **explicit** order instead of the automatic template-derived one, add this to `inc/customizations.php` (never `functions.php` — that file is framework-owned and never touched by updates):

```php
use TAW\Core\Metabox\MetaboxOrder;

MetaboxOrder::lock('page', ['hero_settings', 'video_settings', 'faq_settings']);
```

Template resolution mirrors WordPress's own hierarchy, not just the raw Page Attributes selection. Candidates are tried highest-priority first:

- An explicitly-selected page template (`_wp_page_template`, the Page Attributes dropdown).
- `front-page.php` for the site's static front page (Settings → Reading) — no `Template Name:` header or Page Attributes selection required.
- `home.php` for the posts page (Settings → Reading → "Posts page") — same filename convention, no meta written.
- `page-{slug}.php` for a page whose slug is `{slug}` — applied automatically by the template hierarchy, no meta written.
- Pages matching none of these are left unordered.

---

## Form System

Provided by `taw/core` (namespace `TAW\Core\Form`).

### `Form`

Configuration-driven frontend form. Handles CSRF protection (nonces), honeypot spam guard, field sanitization & validation, AJAX submission (via `admin-ajax.php`), and email delivery (plain-text fallback or MJML templates via `Mailer`).

**Forms must be registered before templates load** — the AJAX handler registered inside a template doesn't exist when `admin-ajax.php` processes the submission. The correct place is the block's `boot()` method, wrapped in `add_action('init', ...)`:

```php
use TAW\Core\Form\Form;

// In your MetaBlock::boot()
public static function boot(): void
{
    add_action('init', static function () {
        Form::register([
            'id'                   => 'contact',
            'submit_label'         => 'Send Message',
            'submit_loading_label' => 'Sending...',
            'messages' => ['success' => "Thanks! We'll be in touch."],
            'email' => [
                'to_self'   => ['subject' => 'New contact',      'template' => 'contact-self'],
                'to_client' => ['subject' => 'Got your message', 'template' => 'contact-client'],
            ],
            'fields' => [
                ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true],
                ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
                ['id' => 'service', 'label' => 'Service', 'type' => 'select',
                 'options' => ['web' => 'Web Design', 'seo' => 'SEO']],
                ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
        ]);
    });
}
```

Then render in a template:
```php
Form::display('contact');
```

**Form field types:** `text`, `email`, `tel`, `url`, `textarea`, `select`, `checkbox`, `date`. Fields support `required`, `placeholder`, `width` (12-column grid %, e.g. `50`), `rows` (textarea), `conditions`, and validation rules (`min_length`, `max_length`, `pattern`, `min`/`max` on `number` fields — see Security below).

**`submit_icon`** — optionally renders an icon after the submit button's label: a Lucide icon name (`Lucide::render()` — no `enable()` needed, same as any direct template call to it) or raw `'<svg>...</svg>'`/HTML, printed as-is inside `<span class="taw-btn-icon" aria-hidden="true">`. Nothing renders when unset, empty, or an unrecognized icon name.

```php
Form::register([
    'id'           => 'contact',
    'submit_label' => 'Send message',
    'submit_icon'  => 'send',
    'fields'       => [...],
]);
```

**Per-form styling hooks** (`taw/core` ≥ v1.40.0): `'class' => 'contact-form'` appends to the `<form>`'s `taw-form`; `'button_class' => 'btn-black'` appends to the submit button's `taw-btn taw-btn-primary` (and Next on a multi-step form). Both *add to* the defaults — scope overrides as `.contact-form .taw-input { … }` instead of inventing an ancestor wrapper. A field's `width` is emitted as the `--taw-span` custom property (not an inline `grid-column`), so a theme can override `grid-column` on `.taw-form-field` with an ordinary selector — no `!important`.

```php
Form::register([
    'id'           => 'contact',
    'class'        => 'contact-form',
    'button_class' => 'btn-black',
    'fields'       => [
        ['id' => 'first', 'label' => 'First name', 'type' => 'text', 'width' => 50],
        ['id' => 'last',  'label' => 'Last name',  'type' => 'text', 'width' => 50],
    ],
]);
```

### Form security

Every form has CSRF (nonce) protection and honeypot spam filtering by default. Two more layers, both opt-in/configurable:

- **Rate limiting** — on by default (5 attempts/60s per IP+form, transient-backed, no external cache). Override with `'rate_limit' => ['max' => 3, 'window' => 120]`, or disable with `'rate_limit' => false`.
- **Cloudflare Turnstile** — opt in with `'turnstile' => true`, requires `TAW_TURNSTILE_SITE_KEY`/`TAW_TURNSTILE_SECRET_KEY` constants defined in `wp-config.php` (never as an OptionsPage field — that's REST-readable by anyone with `edit_posts`). Without keys configured, an opted-in form degrades gracefully (no widget, no check) rather than blocking submission, with a `WP_DEBUG`-only notice for developers.

```php
Form::register([
    'id'         => 'contact',
    'rate_limit' => ['max' => 3, 'window' => 120],
    'turnstile'  => true,
    'fields' => [
        ['id' => 'name',  'label' => 'Name',  'type' => 'text', 'required' => true, 'min_length' => 2, 'max_length' => 80],
        ['id' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'pattern' => '[0-9+ ()-]{7,20}', 'pattern_message' => 'Enter a valid phone number.'],
    ],
]);
```

Validation rules run server-side (the authoritative check) and also render as native HTML `minlength`/`maxlength`/`pattern`/`min`/`max` attributes for client-side UX — the HTML attributes are a convenience, not a security boundary. An empty, non-required field never fails these.

**Custom error messages per rule:** every rule (`required`, the built-in `email` format check, `min_length`, `max_length`, `pattern`, `min`, `max`) accepts a `{rule}_message` override — e.g. `'required_message' => 'Please tell us your name.'`, `'min_message' => 'You must be 18 or older.'` — falling back to a generic default (with the field's `label` interpolated) when not set.

**Form-level default messages:** to set validation copy once for an entire form (e.g. translating every rule for a non-English site) instead of repeating a `{rule}_message` on every field, pass a top-level `'messages' => ['required' => '...', 'email' => '...', 'min_length' => '...', ...]`. Precedence: field-level `{rule}_message` > form-level `messages.{rule}` > built-in English default. Templates take the same `sprintf()` placeholders as the built-in defaults (field label as `%s`/`%1$s`, the rule's bound as `%2$d`/`%2$s`); `email` takes no placeholders.

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

If `email.to_self.template` and `email.to_client.template` are both set, delivery uses `Mailer` + `MailTemplate`. Otherwise falls back to a plain-text `wp_mail()`.

**Multi-column layout:** Use `width` on fields (as a percentage) to control the 12-column grid span. All fields collapse to full width on mobile.

**Conditional fields:** Fields support a `conditions` array (same operators as Metabox: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`). Hidden fields are excluded from validation and submission data — enforced in both JS and on the server.

### `SubmissionsHandler`

Registers a `taw_submission` CPT for viewing submissions in WP Admin. Also provides a Settings → Form Webhook page for a webhook URL + HMAC secret.

**Auto-wired by `Theme::boot()`** — no manual instantiation needed. Submissions are saved automatically after a successful send.

**Per-form webhooks:** each form can target its own webhook instead of one shared site-wide URL — resolved in precedence order: (1) an admin-configured override in the settings page's **Per-Form Webhooks** table (one row per form), (2) a code-level default via the form's own `'webhook' => ['url' => ..., 'secret' => ...]` config key, (3) the page's **Default Webhook** as the site-wide fallback. A form with none of the three simply fires nothing (still saved to the CPT). Full detail: `taw/core` README § "Forms" → "Submission Persistence".

**Webhook payload:** always includes `page_url` — the full URL of the page the form was submitted from, captured server-side at render time — so a form embedded on several different pages (e.g. the same "Financiera" contact form on multiple pages) can still be routed differently downstream. A `taw_form_webhook_payload` filter (`add_filter('taw_form_webhook_payload', fn($payload, $formId, $postId, $data) => ..., 10, 4)` in `inc/customizations.php`) lets this specific site add or override anything else in the payload before it's sent — e.g. computing a `destination` key from `page_url` for an n8n workflow to route on.

---

## Mail System

Provided by `taw/core` (namespace `TAW\Core\Mail`).

### `Mailer`

Fluent wrapper around `wp_mail()`.

```php
use TAW\Core\Mail\Mailer;

(new Mailer())
    ->to('user@example.com')
    ->subject('Welcome!')
    ->template('welcome')                   // looks for mails/html/welcome.html
    ->setVariables(['name' => 'Jane', 'site_name' => 'MyBrand'])
    ->send();

// Optional extras
(new Mailer())
    ->to($email)
    ->subject($subject)
    ->template($template)
    ->setVariables($vars)
    ->from('noreply@mybrand.com', 'MyBrand')
    ->addHeader('Reply-To: support@mybrand.com')
    ->attach('/path/to/attachment.pdf')
    ->send();
```

### `MailTemplate`

Looks for templates in `mails/html/{name}.html` (pre-compiled HTML, used in production) or `mails/{name}.mjml` (compiled at runtime via `spatie/mjml-php` — dev only). The base directory can be overridden with the `taw_mail_templates_dir` filter.

Use `{{variable_name}}` placeholders in templates. `all_fields` is auto-populated with a labelled HTML list of all submitted form values.

### `MailTester`

WP Admin page (Tools → Test Emails) for sending test emails against compiled templates.

### Emailit transport (opt-in, per-client add-on)

`TAW\Support\EmailConfig::useEmailit()` operates one level below `Mailer` — it changes the underlying transport that **every** `wp_mail()` call goes through (including `Mailer`'s own calls), rather than composing the email itself. Not enabled by default; wire it up in `inc/customizations.php`, gated on a `wp-config.php` constant so it's a no-op everywhere except sites that pay for it:

```php
// In inc/customizations.php, before Theme::boot():
if (defined('EMAILIT_API_KEY')) {
    TAW\Support\EmailConfig::useEmailit(
        EMAILIT_API_KEY,
        defined('EMAILIT_FROM_EMAIL') ? EMAILIT_FROM_EMAIL : get_bloginfo('admin_email'),
        defined('EMAILIT_FROM_NAME') ? EMAILIT_FROM_NAME : ''
    );
}
```

Requires `composer require emailit/emailit-php` on that site (not a default dependency). `EMAILIT_API_KEY` / `EMAILIT_FROM_EMAIL` / `EMAILIT_FROM_NAME` are site-specific secrets — set them in that site's `wp-config.php`, never in this repo. Falls back to plain `wp_mail()` transparently if the SDK is missing, the key is empty, or the API call throws. Full detail: `taw/core` README § "Email".

```php
// In inc/customizations.php — never functions.php, which is framework-owned
(new \TAW\Core\Mail\MailTester())->register();
```

---

## Options Page

Provided by `taw/core` (namespace `TAW\Core\OptionsPage`).

A configuration-driven admin settings page. Uses the **same field format as Metabox** but stores values in `wp_options` instead of `post_meta`. Supports tabs, all standard field types, and validation.

**Usage:**
```php
new OptionsPage([
    'id'         => 'taw_settings',
    'title'      => 'TAW Settings',
    'menu_title' => 'TAW Settings',
    'icon'       => 'dashicons-screenoptions',
    'position'   => 2,
    'fields'     => [
        ['id' => 'company_name',  'label' => 'Company Name',      'type' => 'text'],
        ['id' => 'company_phone', 'label' => 'Phone Number',      'type' => 'text'],
        ['id' => 'footer_text',   'label' => 'Footer Copyright',  'type' => 'textarea'],
        ['id' => 'social_facebook', 'label' => 'Facebook URL',    'type' => 'url'],
    ],
    'tabs' => [
        ['id' => 'general', 'label' => 'General', 'fields' => ['company_name', 'company_phone']],
        ['id' => 'footer',  'label' => 'Footer',  'fields' => ['footer_text']],
        ['id' => 'social',  'label' => 'Social',  'fields' => ['social_facebook']],
    ],
]);
```

**Retrieval:**
```php
OptionsPage::get('company_name');                    // returns string
OptionsPage::get_image_url('company_logo', 'large'); // returns URL string
```

**`repeater`/`files` fields return the raw JSON string, not a decoded array** — same contract as Metabox (§ "Key Conventions": "Repeater and files field values are stored as JSON strings — callers must `json_decode()`"). This is an easy, silent trap: a `(array)` cast on a non-empty string doesn't decode it — it wraps the *whole string* as one bogus array element, so a badges/logos list renders one broken row instead of N, with no error anywhere.

```php
// Wrong — (array) on a non-empty JSON string wraps it as ONE element:
$badges = OptionsPage::get('footer_award_badges');
$badges = is_array($badges) ? $badges : (empty($badges) ? [] : (array) $badges);

// Right:
$badges = json_decode((string) OptionsPage::get('footer_award_badges'), true);
$badges = is_array($badges) ? $badges : [];
```

**Option key pattern:** `_taw_{field_id}` (same prefix convention as Metabox)

The theme's default options page is configured in `inc/options.php`, auto-loaded by `Theme::bootstrapFullSite()` if the file exists — nothing to require manually. To add a *second* options page, create a new file in `inc/` and require it from `inc/customizations.php` (not `functions.php`, which only auto-loads `options.php`/`performance.php`/`customizations.php` by convention, not arbitrary extra files).

---

## Navigation Menu System

Provided by `taw/core` (namespace `TAW\Core\Menu`).

Wraps WordPress's flat nav menu data into a typed tree structure. Eliminates `wp_nav_menu()` in favour of full control over markup.

**Classes:**
- `Menu` — the menu container. Factory method `Menu::get(string $location)` retrieves and builds the tree.
- `MenuItem` — a single item with typed getters.

**Usage in templates:**
```php
<?php
use TAW\Core\Menu\Menu;

$menu = Menu::get('primary');
if ($menu && $menu->hasItems()):
?>
<nav>
    <ul>
        <?php foreach ($menu->items() as $item): ?>
            <li class="<?php echo $item->isActive() ? 'active' : ''; ?>">
                <a href="<?php echo esc_url($item->url()); ?>"
                   target="<?php echo esc_attr($item->target()); ?>">
                    <?php echo esc_html($item->title()); ?>
                </a>
                <?php if ($item->hasChildren()): ?>
                    <ul>
                        <?php foreach ($item->children() as $child): ?>
                            <li><a href="<?php echo esc_url($child->url()); ?>"><?php echo esc_html($child->title()); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
```

**`MenuItem` API:**
| Method | Returns | Description |
|---|---|---|
| `title()` | `string` | Display label |
| `url()` | `string` | Destination URL |
| `target()` | `string` | `_self` or `_blank` |
| `openInNewTab()` | `bool` | True when target is `_blank` |
| `isActive()` | `bool` | Current page |
| `isActiveParent()` | `bool` | Direct parent of current page |
| `isActiveAncestor()` | `bool` | Ancestor of current page |
| `isInActiveTrail()` | `bool` | Any active state |
| `hasChildren()` | `bool` | Has sub-items |
| `children()` | `MenuItem[]` | Child items |
| `classes()` | `string[]` | Custom classes (WP defaults stripped) |

Menus are registered in `inc/customizations.php` via `register_nav_menus()` (not `functions.php`, which is framework-owned). The default scaffold registers `primary` and `footer` — edit that array directly to add or rename locations.

---

## REST API

Provided by `taw/core` (namespace `TAW\Core\Rest\SearchEndpoints`).

Registered automatically via `TAW\Core\Theme::boot()`.

### `GET taw/v1/search-posts`

Search for posts across post types. Powers the `post_selector` metabox field type.

**Permission:** Requires `edit_posts` capability (logged-in editors+).

**Parameters:**

| Param | Type | Default | Description |
|---|---|---|---|
| `s` | string | `''` | Search string (matches post titles) |
| `post_type` | string | `'post'` | Comma-separated post types (e.g. `post,page`) |
| `per_page` | int | `10` | Results to return (1–50) |
| `exclude` | string | `''` | Comma-separated post IDs to exclude |

**Response:** Array of post objects with `id`, `title`, `post_type`, `status`, `date`, `edit_url`, `permalink`, `thumbnail`.

---

## WP-CLI — live site data access

`bin/taw` (below) is for framework-specific scaffolding — blocks, live registry introspection. It knows nothing about actual site *content*. For that, use WordPress's own official CLI, **WP-CLI** (the `wp` command) — it's not bundled by this theme (it's a host-level tool, present on virtually every real WordPress host and every local dev environment: Local, Herd, DDEV), but it's the single highest-leverage tool an agent has for reading or mutating live site data directly: posts, options, users, terms, transients, arbitrary PHP via `wp eval`/`wp eval-file`, even an interactive `wp shell`.

**Prefer `php bin/taw wp <args>` over a bare `wp` command** — it's a thin passthrough to the real `wp` binary (every argument forwarded exactly as given) that automatically resolves `--path` and, under Local by Flywheel, the per-site MySQL socket (see the Warning below) — no manual configuration needed:

```bash
php bin/taw wp post list --post_type=page --fields=ID,post_title,post_status
php bin/taw wp option get siteurl
php bin/taw wp option get _taw_company_phone   # OptionsPage-backed option, prefix per Metabox field convention
php bin/taw wp user list --fields=ID,user_login,roles
php bin/taw wp eval 'echo home_url();'
php bin/taw wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_status='publish'"
php bin/taw wp shell   # interactive — TTY passthrough works too
```

A bare `wp` command (without the `bin/taw wp` wrapper) still works the same as always, and is still exactly what `wp-wpcli-and-ops` and other external WP-CLI documentation assumes — just without the automatic socket/path resolution below.

> **Warning — Local by Flywheel connection quirk:** `wp-config.php`'s `DB_HOST` is `localhost`, but Local runs a per-site MySQL instance on its own Unix socket, not the system default. A bare `wp` command fails with `Error establishing a database connection` even though the site works fine in the browser — `php bin/taw wp` (above) resolves this automatically and is the reason it's preferred over a bare `wp` command in this project. If you need to run the real `wp` binary directly anyway (a tool that shells out to it itself, for instance), the manual fix is the same thing `bin/taw wp` does internally:
>
> ```bash
> # Find the running mysqld's socket path for this site — Local names run
> # dirs by an opaque site ID, not the site's human name, so verify which
> # one is the right site if more than one Local site is running:
> ps aux | grep mysqld
> # → .../Local/run/<SITE_ID>/conf/mysql/my.cnf
>
> SOCK="$HOME/Library/Application Support/Local/run/<SITE_ID>/mysql/mysqld.sock"
> php -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
>   "$(which wp)" post list --path=/path/to/site/app/public
> ```
>
> If more than one Local site is running and it's unclear which socket belongs to which, query each candidate directly: `mysql --socket="$SOCK" -uroot -proot -e "SELECT option_value FROM wp_options WHERE option_name='siteurl';" local`.

Two things worth knowing before relying on this for troubleshooting:

- **`ViteLoader::isDevServerRunning()` is safe under WP-CLI** — the actual dev-server probe only runs on `wp_enqueue_scripts`, which doesn't fire for most `wp` commands (`option get`, `post list`, `eval`, etc.), so there's no wasted network probing per command.
- **`Theme::bootstrapFullSite()` defers textdomain loading and `inc/options.php`'s require to `after_setup_theme`** (fixed in `taw/core` v1.16.67) specifically because WordPress 6.7+ added a `WP_DEBUG`-only notice for translation calls that fire too early — this is exactly the kind of runtime-ordering bug that's invisible to PHPStan (it's about hook firing order, not types) and was only caught by actually running the site through `wp eval` and reading the debug output.

For general WP-CLI operations beyond this framework's scope, see the `wp-wpcli-and-ops` skill in [WordPress/agent-skills](https://github.com/WordPress/agent-skills).

## CLI Tools

Provided by `taw/core` (namespace `TAW\CLI`). Powered by Symfony Console. Entry point: `bin/taw`.

### `make:block`

Scaffold a new block with the correct folder structure, class, and template.

```bash
# Interactive (prompts for type)
php bin/taw make:block MyBlock

# Non-interactive
php bin/taw make:block MyBlock --type=meta --with-style --with-script

# UI block
php bin/taw make:block Badge --type=ui

# Overwrite existing
php bin/taw make:block Hero --type=meta --force
```

| Option | Description |
|---|---|
| `--type=meta\|ui` | Block type (prompted if omitted) |
| `--with-style` | Create a `style.scss` stub |
| `--with-script` | Create a `script.js` stub |
| `--force` / `-f` | Overwrite if block already exists |
| `--group=path` | Place the block inside a subgroup, e.g. `--group=sections` → `Blocks/sections/Name/`; nested: `--group=ui/cards` → `Blocks/ui/cards/Name/` |

After scaffolding, run `composer dump-autoload` to register the new class.

### `export:block`

Export a block as a portable ZIP archive with a `block.json` manifest.

```bash
php bin/taw export:block Hero
```

### `import:block`

Import a block from a ZIP archive exported by `export:block`.

```bash
php bin/taw import:block path/to/Hero.zip
```

### `inspect`

Live introspection of the *current* site state — the registered blocks and their actual metabox field schemas, registered forms, the installed `taw/core` version, and whether `MetaboxOrder` is locked. Unlike the other CLI commands, this one boots WordPress (it needs `after_setup_theme`/`init` to have fired for the registries to be populated), so it must be run from within the theme in an environment where WordPress can actually connect to its database.

```bash
php bin/taw inspect          # human-readable summary (table output)
php bin/taw inspect --json   # machine-readable, for AI agents / scripts
```

Prefer this over `grep`-ing `Blocks/*/*.php` by hand to answer "what blocks/fields/forms actually exist right now" — it reports live registry state, not static source, so it reflects reality even if a block's `getData()` doesn't match its `registerMetaboxes()`, or a form was conditionally registered.

### `fields:get` / `fields:set`

Read or write a single Metabox/OptionsPage field's value directly, without going through the wp-admin UI. This is the same read/write primitive `VisualEditorEndpoint` uses for its REST-driven saves — the field's type is resolved from the live `Metabox` registry (the same one `inspect` reports), then dispatched to the matching type-aware getter/sanitizer. A repeater is read back as a decoded array and written with the exact sanitization a real admin form save would apply (XSS-stripping via `wp_kses_post()`/`sanitize_text_field()`, attachment IDs coerced with `absint()`, JSON re-encoded clean) — not a hand-rolled approximation.

```bash
php bin/taw fields:get 42 hero_heading --json
php bin/taw fields:get 42 team_members --json                        # repeater comes back as a decoded array

php bin/taw fields:set 42 hero_heading "Welcome"
php bin/taw fields:set 42 team_members --file=/tmp/team.json         # --file avoids shell JSON-quoting for repeater/array-shaped values
php bin/taw fields:set 42 hero_heading "Welcome" --dry-run           # preview the sanitized result without writing

php bin/taw fields:set options company_phone "555-1234"              # 'options' targets a site-wide OptionsPage field, not a post
```

For a group sub-field, use the compound ID exactly as `inspect` reports it (e.g. `hero_cta_text` for the `cta_text` sub-field of a `hero_cta` group) — it's registered in the field registry under that compound key already.

Pass the literal `options` in place of the post ID to read/write a site-wide `OptionsPage` field instead — same commands, same `--dry-run`/`--file`/`--json` flags, resolved via `OptionsPage::getFieldConfig()`/`writeOption()` instead of the `Metabox` registry (taw/core README § "CLI" has the full detail).

**What this doesn't do:** it doesn't upload media (an `image`/`files` field value must already be a valid attachment ID — get one via `wp eval` or the Media Library first), and it doesn't check whether a field's owning block is actually attached to the target post's post type or template — same trust model as any other direct WordPress data write. If a field ID isn't found in the registry, the error message points at `inspect --json` to list what's actually registered.

This is the "populate the content" half of a design-to-page pipeline: `figma-to-block`/`make-metablock` scaffold the block and its empty fields, `fields:set` fills them in.

### Content-writing safety model

`fields:set` itself has no interactive confirmation — it's a raw primitive, usable from scripts, not just an AI agent, so it always does exactly what it's told. **All risk judgment belongs to whichever skill or agent is calling it**, the same way git-push confirmation is a project policy enforced by the agent, not by `git` itself. Any skill that writes content via `fields:set` (`populate-content`, and the population step in `make-metablock`/`figma-to-block`/`build-page`) follows this model:

1. **Always `--dry-run` first.** Never call `fields:set` for real without having already seen the sanitized preview. This also catches sanitizer surprises (stripped tags, coerced types) before they're written.
2. **Always confirm before overwriting a non-empty existing value.** Read the current value with `fields:get` first; if it's non-empty, show the user old vs. new side by side and get explicit confirmation before writing — never silently clobber existing content, even placeholder-looking content (it may be real).
3. **Always confirm before writing to a `publish`-status post**, regardless of whether the field is currently empty — a live, publicly-visible post is inherently higher-stakes than a draft. Check post status first (`wp post list --post__in=<id> --field=post_status`, or `wp eval`).
4. **Batch operations get one confirmation for the whole plan, not one per field.** Show every post/field/value that will change before writing any of them — don't ask N times for N fields, and don't start writing before the whole batch has been shown and approved.
5. **Flag raw-HTML-permitting fields specifically** (`wysiwyg`, or any field with `'sanitize' => 'code'`) when the source content comes from outside this conversation (a pasted document, a fetched file) — `wp_kses_post()` is deliberately permissive, so confirm the content is trusted before writing it, even if the field is currently empty.
6. **Low-risk, still worth a quick confirmation but without heavy ceremony:** a brand-new empty field on a draft/unpublished post, freshly scaffolded earlier in the same session, populated with content that matches the same source (design/document) the user already approved when asking for the block/page to be built.
7. **Never touches core post data.** `fields:set` only ever writes Metabox/OptionsPage meta — it never touches `post_title`, `post_content`, `post_status`, or anything else on the `wp_posts` row itself. If a request implies changing those too, say so explicitly and treat it as a separate, higher-risk action (e.g. `wp post update`) requiring its own confirmation — don't fold it into a `fields:set` plan silently.
8. **No wildcard/glob mass-edits.** Every post ID and field a batch operation will touch must be enumerated and shown to the user up front — never "every page matching X" without listing what that actually resolves to first.

---

## Image Helper

Provided by `taw/core` (namespace `TAW\Helpers\Image`).

Generates performance-optimised `<img>` tags with correct `loading`, `fetchpriority`, `decoding`, `srcset`, and `sizes` attributes based on whether the image is above or below the fold.

```php
use TAW\Helpers\Image;

// Below the fold (default — most images)
echo Image::render(get_post_thumbnail_id(), 'large');

// Above the fold (hero, banner) — eager + high priority
echo Image::render($image_id, 'full', [
    'above_fold' => true,
    'sizes'      => '100vw',
    'class'      => 'w-full object-cover',
]);

// With extra attributes
echo Image::render($id, 'large', [
    'class' => 'rounded-lg shadow-md',
    'attr'  => ['id' => 'site-logo'],
]);

// Generate a <link rel="preload"> tag for the hero image
echo Image::preloadTag($image_id, 'full');
```

| Option | Type | Default | Description |
|---|---|---|---|
| `above_fold` | `bool` | `false` | Sets `loading="eager"` + `fetchpriority="high"` |
| `sizes` | `string` | auto | Custom `sizes` attribute |
| `class` | `string` | — | CSS class(es) |
| `attr` | `array` | — | Any additional HTML attributes |

**`'thumbnail'` is hard-cropped to a square by WordPress before any custom position/crop transform ever sees it.** If a template applies its own crop/position transform on top of `Image::render()`'s output — most commonly a Figma-sourced `object-position`/absolute-positioning transform, see `figma-to-block` § Step 5 — it must request `'medium'`/`'large'`/`'full'` (WordPress's proportional, uncropped sizes), never `'thumbnail'`. The transform's math assumes the source's real, usually non-square, aspect ratio; `'thumbnail'` has already destroyed that by the time the transform runs.

---

## Performance Compliance

Every block build — via `make-metablock`, `figma-to-block`, or hand-authored — must comply with the framework's current performance mechanisms below before it's considered done, on the same footing as the § "Testing Blocks" unit-test requirement.

- **Images:** always render through `Image::render()` (§ "Image Helper" above), never a raw `<img>` tag, so `loading`, `fetchpriority`, `decoding`, and `srcset` are set correctly for the image's fold position. Set `above_fold => true` only for genuinely above-the-fold images (hero/banner); everything else defaults to lazy.
- **Reserve layout space for every image, regardless of loading strategy.** `width`/`height` attributes alone don't prevent a layout shift if CSS overrides the intrinsic box (e.g. `height: auto` on a flex/marquee track) — the browser still reflows once the real image loads, and because non-`above_fold` images load lazily/low-priority, that reflow lands at an unpredictable time relative to paint. This produces a Cumulative Layout Shift (CLS) that's *flaky* rather than consistently reproducible — same page, same code, a different CLS score on every run. Confirmed live: a partner-logo marquee band shipped with correct `width`/`height` attributes but no matching CSS `aspect-ratio`, and CLS on that page ranged from 0.05 to 0.87 across identical repeat PageSpeed Insights runs, purely from image-load timing. Give any image-bearing element (marquee tracks, card grids, repeaters of `image`/`files` fields) an explicit `aspect-ratio` or fixed-height container in its `style.scss` matching the image's real rendered ratio — don't rely on attribute-only sizing for anything that isn't going through `Image::render()`'s own sizing output.
- **Above-the-fold markup needs real critical CSS**, not the empty scaffold stub — see § "Vite Integration" → "`critical.scss` must contain real content" below. A new hero/banner-type block should get hand-authored layout rules (flex/grid direction, gap, min-height) added to `critical.scss` if it can appear above the fold on any page.
- **Queue block assets before `get_header()`** (`BlockRegistry::queue(...)`) — already listed in § "Do NOT"; repeated here because forgetting it falls back to inline assets, which is also a performance regression, not just a code-style miss.
- **Verify, don't assume a single good run proves it.** After building or editing a block that renders images, check PageSpeed Insights (or GTMetrix) against the page it's wired into at least twice — a single good CLS score doesn't rule out the load-timing flakiness described above.

---

## SVG Helper

Provided by `taw/core` (namespace `TAW\Helpers\Svg`).

Enables SVG uploads in WordPress (sanitized via `enshrined/svg-sanitize` on upload) and provides rendering utilities.

```php
use TAW\Helpers\Svg;

// Call once, e.g. in inc/customizations.php, to allow SVG uploads + auto-sanitize:
Svg::register();

// Render as <img> tag (safe; scripts inside SVG can't execute):
echo Svg::render($attachment_id, 'Company logo', ['class' => 'logo h-8']);

// Render inline (allows CSS targeting of SVG internals / animations):
echo Svg::inline($attachment_id, ['class' => 'icon w-5 h-5', 'title' => 'Menu']);

// Get URL only:
$url = Svg::url($attachment_id);
```

---

## Icon System

Provided by `taw/core` (namespace `TAW\Core\Icons\Lucide`). Full API/architecture: `taw/core` README § "Icon System".

Lucide's full icon set is vendored inside `taw/core` itself (no network call at runtime). **Opt-in** — the `icon` Metabox/OptionsPage field type and its wp-admin picker only work once enabled in `inc/customizations.php`, before `Theme::boot()` (this repo's scaffold does **not** call it by default — uncomment the line already present in `inc/customizations.php` to turn it on for a given site):

```php
TAW\Core\Icons\Lucide::enable();
```

```php
// Field config, same as any other Metabox/OptionsPage field:
['id' => 'feature_icon', 'label' => 'Icon', 'type' => 'icon']

// Template output needs no enable() call — same relationship as
// Svg::register() vs Svg::render()/Svg::inline() above:
echo TAW\Core\Icons\Lucide::render(Metabox::get($post_id, 'feature_icon'), ['class' => 'w-5 h-5']);
```

---

## TAW Media

Provided by `taw/core` (namespace `TAW\Core\Media\MediaFolders`). Full API/architecture: `taw/core` README § "TAW Media".

Nestable Media Library folders (a hierarchical `taw_media_folder` taxonomy on attachments) — a dedicated **Media → TAW Media** admin screen (a full Alpine.js app: folder tree with drag-and-drop, breadcrumb, folder cards for subfolder navigation, direct drag-and-drop file upload, multi-select with bulk move/delete), a filter/column/bulk-action on the classic Media Library list view, and a FileBird-style sidebar (Alpine.js) bolted onto the default Grid view of `upload.php` with live click-to-filter, the same folder CRUD, and independent sort controls for the folder tree (name/date) and the file grid (name/date/file size), each remembered per-browser. **Opt-in at the `taw/core` level, but this repo's `inc/customizations.php` scaffold calls it by default**, so every new taw-theme site has it active out of the box:

```php
TAW\Core\Media\MediaFolders::enable();
```

Remove that line from a site's `inc/customizations.php` if it doesn't need folder organization.

---

## Security Hardening

`inc/security.php` (site-owned — required from `inc/customizations.php`, never touched by `update-theme`, same status as `inc/performance.php`; a site created before it existed doesn't have it, and sync won't add it: copy the file from the starter and add the `require` by hand) locks down username / user-ID enumeration in two layers:

1. **REST users endpoint** — calls `TAW\Core\Security\Hardening::hideUsersEndpoint()` (provided by `taw/core`, also wired into `Theme::boot()` by default). Removes the public `/wp/v2/users` collection + single-user route for anonymous requests, across every routing form (`/wp-json/`, `?rest_route=`, `/batch/v1`) because it filters at `rest_endpoints` (REST dispatch). `/wp/v2/users/me` and all logged-in access stay intact. Opt a site out with `add_filter('taw_security_hide_users_endpoint', '__return_false')` — e.g. a headless front end that reads `/wp/v2/users` anonymously. Full detail: `taw/core` README § "Security / Hardening".

2. **`?author=N` probe** — a `parse_request` handler that 301-redirects logged-out `?author=<digits>` requests to the home page. Kept here rather than in the framework: it is site policy (it also kills author-archive query URLs) and overlaps with what security plugins like WP Defender's "Prevent User Enumeration" already do. Author archives stay reachable via their pretty permalink.

Trim either layer per site by editing `inc/security.php`.

---

## Schema — post types, taxonomies, fieldsets, options pages (taw/core ≥ v1.43)

Define the site's content model in one place instead of scattering `register_post_type()` calls and
`new Metabox([...])` arrays. There are two equivalent ways (authoritative reference: `taw/core`
README § "Schema"; decisions in taw-core ADR-0004):

- **PHP:** `add_action('taw_schema_register', fn (\TAW\Core\Schema\Registry $s) => $s->add(Schema::postType('book')->labels('Book', 'Books')));`
  with `Schema::taxonomy()`, `Schema::fieldset()->on('book')->fields([Field::text('book_author'), …])`
  and `Schema::optionsPage()`. Put it in `inc/customizations.php`, never `functions.php`.
- **JSON:** one entity per file in `taw-schema/` (subfolders one level deep are fine), with
  `"$schema": "../vendor/taw/core/resources/schema/taw-schema-1.0.json"` for editor autocomplete.
  Validate with `php bin/taw schema:validate` (no WordPress needed; run it before committing).

Fieldsets compile into regular Metaboxes (`_taw_{field}` meta, `Metabox::get()` reads, REST, content
export), so nothing else changes. Schema post types always get `custom-fields` support so their meta
reaches REST. Namespace field ids by entity (`book_author`): the field registry is keyed by bare id.
Existing MetaBlocks and `inc/options.php` keep working unchanged, so use the schema for *new* data
models.

`taw/core` is TAW's data layer, shared with the block theme **taw-gutenberg**, which boots only
`Boot::data()`. This theme boots the full toolkit through `bootstrapFullSite()`. Since v1.42.0 the
Performance optimizations register inside that boot rather than at Composer autoload. Behavior here
is identical (verified hook-for-hook).

## Content Interchange

Provided by `taw/core` (`TAW\Core\Content\*`, since v1.25.0; whole-site **state** migration since v1.26.0). A portable snapshot / change-set format for moving a site's state — posts + `_taw_*` fields, `_taw_*` options, terms, media, and (opt-in) authorship, users, comments, environment settings — between environments, or handing it to a code agent. Same **serialize → review → apply** shape as `seo:extract`/`seo:inject`. Schema `1.1` (importer also accepts `1.0`).

```bash
php bin/taw content:export --migrate --output=/tmp/site.json   # full state (users, settings, all media, drafts)
php bin/taw content:import /tmp/site.json                      # dry-run diff — writes nothing
php bin/taw content:import /tmp/site.json --yes --with-settings # apply (rollback snapshot written first)
php bin/taw content:diff a.json b.json --out=changes.json
php bin/taw content:defaults [--apply | --undo=<journal>]       # save fields' `defaults` into empty fields
```

- **Import is always a dry-run first.** Without `--yes` it prints a field-level diff and exits. `apply()` writes a full **maximal-scope** `Exporter` snapshot to `wp-content/uploads/taw-private/` before it touches the DB, in the order **users → terms → media → posts → comments → settings**.
- Records match by natural key (post `type`+`slug` or `type`+`match_key` for slug-less drafts, option key, term `taxonomy`+`slug`, user login→email, comment content-hash) — **never numeric ID**. Media matched by filename / sideloaded, IDs rewritten into content + `image`/`files` values.
- A clean `content:export --migrate` then `content:import --yes` against the **same** site = 0 created / 0 updated / 0 deleted.
- **Opt-in scope flags** (all default off): `--with-users` (`--with-user-passwords` for hashes), `--with-comments`, `--with-settings` (+ `taw_content_export_settings_options` filter; **import-gated too**), `--all-media`, `--include-drafts`, `--migrate` (= the first-listed four minus passwords/comments). Any CPT with a `Metabox` attached auto-exports — no `taw_content_export_post_types` filter needed for a `public => false` content CPT.
- wp-admin: **Tools → TAW Data** (Export with scope checkboxes + Import-with-review). `GET /wp-json/taw/v1/content/export` (cap `export`) is **content-only** — adds only `include_drafts`; no users/settings over REST.
- `Theme::boot()` also REST-registers every TAW field (`register_post_meta` + `register_rest_field`) for headless / integration reads over `wp/v2`. Opt out: `add_filter('taw_register_meta_in_rest', '__return_false')`.
- **Not** a full-DB byte-for-byte copy (still a separate SSH concern) and **not** a mobile editing UI (classic metaboxes stay desktop-only).

- **The `content-migrate` skill** (`.claude/skills/content-migrate/`, `owner: taw`) is the procedural wrapper: `pull` (remote → here), `push` (named records only, heavily gated), `migrate` (whole-site state over SSH), and an agent-transform variant (export both → `content:diff` → review → apply). It reads connection details from a gitignored `.sync/remote.env`. A site with its own source-of-truth policy authors an `owner: site` skill with a *different* name (`update-theme` overwrites `content-migrate` every sync).

Full detail: `taw/core` README § "Content Interchange" and the docs site's **[Content Interchange](https://taw.mlizardo.com/content-interchange)** page.

---

## Sovereign Hybrid-RAG Chatbot

Backend (SQLite knowledge bases, embeddings, LLM orchestration, REST) is entirely `taw/core` (`TAW\Core\Rag\*`, since v1.27.0; content-agnostic knowledge bases since v1.28.0) — see its README § "Sovereign Hybrid-RAG Chatbot" for the full picture, including `Settings → TAW Chatbot → Knowledge Bases` (a wp-admin screen for uploading any `.sqlite` file as a named, semantically-searchable knowledge base — no theme involvement). This repo owns exactly one piece: the presentational widget.

The widget is optional: new sites start with it, and `update-theme` never adds it to an existing site (nor its `marked`/`dompurify` packages). `Blocks/Chatbot` is a plain (non-`MetaBlock`) `Block`, mounted **site-wide** rather than per-page: `header.php` calls `(new Chatbot())->enqueueAssets()` before `wp_head()`, `footer.php` calls `(new Chatbot())->render()` right before `wp_footer()`. It talks only to `POST /wp-json/taw/v1/chat` — no LLM base URL or API key is ever present in `script.js`. Markdown replies go through `marked` then `DOMPurify.sanitize()` before any `x-html` binding, since assistant output is untrusted text.

```php
use TAW\Blocks\Chatbot\Chatbot;
(new Chatbot())->enqueueAssets();   // header.php, before wp_head()
(new Chatbot())->render();          // footer.php, before wp_footer()
```

Configuration (LLM base URL/model, indexed post types for the site's own content, whether anonymous visitors can chat) and knowledge-base management both live in wp-admin under **Settings → TAW Chatbot** — nothing here in the theme. `Chatbot::enqueueAssets()` localizes a nonce (`window.tawChatbot.nonce`) only when `RagSettings::publicChatEnabled()` is off, matching the endpoint's actual auth posture.

**Cost guardrails (taw/core ≥ v1.81.0, its ADR-0017).** Every message can spend a paid API key, so the endpoint gates it, and the widget has to cooperate:
- **Human check.** While **TAW Chatbot → Access → Human Check** is `turnstile` (the default), `script.js` solves Cloudflare Turnstile once per conversation. It renders into the `x-ref="turnstile"` mount in "interaction-only" mode, posts the token to `POST taw/v1/chat/session`, and sends the returned session as `X-TAW-Chat-Session`. On `401` it re-verifies once and retries.
- **Turnstile keys.** Define `TAW_TURNSTILE_SITE_KEY`/`TAW_TURNSTILE_SECRET_KEY` in wp-config.php, or the chat refuses every message (`503 not_protected`).
- **Refusals.** They carry a `code` (`rate_limited`, `budget_exhausted`, `paused`, `human_check_failed`), and their visitor copy is localized from `Chatbot.php` (`window.tawChatbot.copy`, translatable).
- **Kill switch.** When it's on (`TAW_RAG_CHAT_DISABLED` or the setting), `index.php` renders nothing.
- **Budgets, limits and spend.** These live in wp-admin (Budget / Limits tabs, **TAW Chatbot → Usage**) and in `php bin/taw rag:usage`.
- **Before giving a site a key:** set its budget, prices and Assistant Scope, use a provider key with a hard monthly limit, and check the Usage screen's "Resolved" IP (behind Cloudflare, set `TAW_TRUSTED_PROXIES`).

Manual re-ingestion commands (`content:reindex` for the site's own content, `content:reindex-kb <id>` for one uploaded knowledge base) are registered in `bin/taw` — see `taw/core` README for usage. Uploading a new knowledge base itself is a wp-admin action, not a CLI one.

---

## Debug Helper

Provided by `taw/core` (namespace `TAW\Helpers\Dump`). Global `dump()` / `dd()` functions are autoloaded from `utilities.php`.

**Only active when `WP_DEBUG` is `true`.** Renders a styled collapsible panel in `wp_footer` — no output in production.

```php
dump($someArray, 'My label');   // queues value for display in footer panel
dd($someValue);                 // dump + die
```

`dump()`/`dd()` are a dev-time inspection panel, **not** a log — for anything that should be recorded (a swallowed exception, a fallback that fired, a misconfiguration), use `Logger` (§ "Logging").

---

## Logging

Provided by `taw/core` (namespace `TAW\Core\Log`). Always on — no `enable()`. This is the structured replacement for hand-written `error_log('[TAW …] …')` — theme code (blocks, `inc/`, custom `on_submit` callbacks) should use it too.

```php
use TAW\Core\Log\Logger;

Logger::warning('hero.missing_background', 'Hero has no background image — falling back to the flat colour.', [
    'post_id' => $postId,
]);
// debug() info() notice() warning() error() critical()  — PSR-3 minus alert/emergency
```

Each entry has a human `message` **and** a machine-stable `code` (`subsystem.event`) plus a `context` array. Two sinks by default: PHP `error_log()` (one readable line) and `wp-content/taw-logs/taw.log.jsonl` (size-rotated JSON Lines). Read it back with `php bin/taw log:tail [--level=] [--code=] [--since=] [--json]`, or — on a production site — `taw-fleet live <site> --logs 20` (the companion's `/logs` route). Full API (sinks, the `taw_core_log_sinks` / `taw_core_log_entry` filters, `LogReader`): `taw/core` README § "Logging".

---

## Theme Updater

Provided by `taw/core` (namespace `TAW\Core\ThemeUpdater`).

Hooks into WordPress's theme update system to pull releases from a GitHub repository. When a new tag is published, WordPress shows the standard "Update Available" notice and one-click update UI.

> **⚠️ Do not wire this up on a real client site alongside `update-theme`.** `ThemeUpdater` does a **full theme directory replacement** from a ZIP when an admin clicks "Update Now" in wp-admin — exactly the destructive, all-or-nothing behavior `update-theme`'s manifest-based sync was built to avoid. On a client site with customized `Blocks/`, page templates, and `inc/` files, clicking that button would silently overwrite all of it. This class is dormant by default (not currently instantiated in this project's `functions.php`) — keep it that way for client sites. It may be appropriate for a genuinely zero-customization deployment that intentionally wants full-replacement updates, but that's the exception, not the default, and should be a deliberate choice, not something enabled by copy-pasting the example below.

Activated in `functions.php`:
```php
if (is_admin()) {
    new \TAW\Core\ThemeUpdater([
        'slug'       => 'taw-theme',
        'github_url' => 'https://api.github.com/repos/YOUR_USERNAME/taw-theme/releases/latest',
    ]);
}
```

- Caches the GitHub API response for **6 hours** to avoid rate limits.
- Prefers a built ZIP asset attached to the release; falls back to GitHub's auto-generated zipball.
- Tag names are normalised: `v1.2.0` → `1.2.0`.
- Only enabled in `is_admin()` context — no frontend overhead.

---

## Vite Integration

### How it works

`TAW\Support\ViteLoader` (from `taw/core`, PSR-4 autoloaded) is the OOP Vite bridge. It detects whether the Vite dev server is running by connecting to it (port 5173 by default, or whatever `vite.config.js`'s `hotFilePlugin` last wrote to `public/build/hot`, if the dev server is currently running) and verifying an actual Vite response (`GET /@vite/client` → HTTP 200) — a bare port-open check isn't sufficient, since any unrelated process (another dev server, a Docker container, anything) can end up bound to that port for reasons that have nothing to do with this project, producing a false positive that silently breaks asset loading in production. `vite.config.js` deliberately sets `strictPort: true` rather than letting Vite auto-move to a different port on conflict — the `server.origin` setting (needed for correct absolute font URLs) is hardcoded to port 5173, and an auto-moved port would go stale with no error, only fonts silently 404ing. If `npm run dev` fails to start, something else has port 5173 — find it with `lsof -i :5173` and stop it (or reconfigure that other project).

`Theme::boot()` calls `ViteLoader::init('resources/js/app.js')` automatically — no manual setup needed for the main bundle.

**Key static methods:**

| Method | Description |
|---|---|
| `ViteLoader::isDevServerRunning()` | Returns `true` when the Vite dev server is reachable (replaces `vite_is_dev()`) |
| `ViteLoader::assetUrl(string $path)` | Resolves a theme-relative path to the correct URL in dev and prod (replaces `vite_asset_url()`) |
| `ViteLoader::enqueueAsset(string $handle, string $entry, array $deps, bool $enqueue)` | Register/enqueue an additional Vite entry point |
| `ViteLoader::inlineCriticalCss(string $entry_key)` | Inline a compiled CSS entry into `<head>` (default: `resources/scss/critical.scss`) |
| `ViteLoader::inlineCssFile(string $css_path, string $id)` | Inline an arbitrary compiled CSS file, rewriting relative asset URLs |
| `ViteLoader::getManifest()` | Parse and return the Vite manifest (WP object-cache backed, 24 h TTL) |
| `ViteLoader::distDir()` | Detect whether Vite wrote to `dist/` or `public/build/` |
| `ViteLoader::distUrl(string $file)` | Build a full URL to a file inside the dist directory |
| `ViteLoader::init(string $entry_point)` | Wire up WP hooks + enqueue main bundle (called by `Theme::boot()`) |

- **Dev:** `app.js` (+ its CSS imports) served from `http://localhost:5173` with HMR
- **Prod:** Reads `public/build/manifest.json` for hashed filenames (manifest cached in WP object cache)

### CSS loading pipeline (production)

CSS is loaded in three layers to maximise paint speed:

```
1. critical.scss  → compiled → inlined as <style> in <head>   (zero network request)
2. app.js CSS     → <link rel="preload"> + async <link media="print" onload="...">
3. app.scss CSS   → same async pattern (deduped if same compiled file)
```

The async `media="print"` trick makes stylesheets non-render-blocking — the browser downloads them in the background and swaps them in when ready. A `<noscript>` fallback covers JS-disabled users.

### CSS entry points

| File | How loaded |
|---|---|
| `resources/js/app.js` | Vite JS entry. Imports `app.css` + `app.scss` — both compile into the JS entry's CSS output. In dev this is the only PHP-loaded script; Vite HMR injects styles automatically. |
| `resources/css/app.css` | Tailwind v4 (`@import "tailwindcss"`, `@source "../../**/*.php"`). Imported by `app.js`, **not** a standalone Vite entry. |
| `resources/scss/app.scss` | Custom SCSS (fonts, global rules). Imported by `app.js`. |
| `resources/scss/critical.scss` | Standalone Vite entry. Inlined into `<head>` by `vite_inline_critical_css()`. Must stay under ~14 KB. No `@font-face` here — inlined CSS resolves `url()` against the page origin, not a stylesheet location, causing 404s. |
| `Blocks/*/style.css` | Per-block styles. Auto-discovered by `vite.config.js`, separate Rollup entries. |

**`critical.scss` is unlayered, so it beats Tailwind utilities.** Tailwind v4 emits utilities inside `@layer utilities`; `critical.scss` is inlined as plain, unlayered CSS, and unlayered rules win over layered ones regardless of specificity or source order. Any element that `critical.scss` also styles (typically the Hero's inner column: `padding`, `max-width`) will **silently ignore** a conflicting utility (`pb-0`, `md:max-w-*`) in the template. To vary an above-the-fold block per page or per state (home vs inner-page hero, with vs without an image), define **modifier classes in `critical.scss`** (`.hero__inner--inner-page`, `.hero__inner--with-image`) and toggle them from the template — not utilities. Keep any rule shared between `critical.scss` and `app.css` (e.g. `.section-shell`) identical in both, and revisit `critical.scss` whenever the classes on the header/hero markup change. See the `figma-fidelity` skill.

### `critical.scss` must contain real content — this is not optional decoration

Since `app.css`'s CSS loading pipeline (above) is genuinely async (`media="print"` + `onload` swap,
not just documented as such — see the incident below), `critical.scss` is the **only** CSS
covering the page from first paint until `app.css` finishes loading. An empty/placeholder
`critical.scss` now means a real flash of totally unstyled HTML on every page load, not just a
slightly slower paint. At minimum, hand-author real layout rules (flex direction, gap, padding,
min-height — geometry that prevents CLS) for whatever markup renders above the fold: the header
and hero/first-section, typically. Full color/typography fidelity isn't required — a brief color
flash is an acceptable, standard critical-CSS tradeoff; a layout *jump* is not.

<details>
<summary>Why not just give critical.scss its own scoped Tailwind <code>@source</code> instead of hand-authoring?</summary>

Tried first, genuinely doesn't work in this build, for two independent reasons:

1. **`@source` is only honored on entries reachable from a real HTML/JS entry in Vite's module
   graph.** `critical.scss` is a bare secondary CSS Rollup input (see `vite.config.js`'s
   `rollupOptions.input`), never associated with an HTML page — Tailwind's content scanner never
   sees it as a root to scan, so nothing gets generated from `@source` directives placed there.
   They pass through as inert, unprocessed text.
2. **Even where `@source` does work, `@import "tailwindcss"` unconditionally emits Tailwind's
   entire default theme** (every stock color family, spacing scale, shadows, etc. — ~18 KB) into
   every independent Tailwind root it's imported into, regardless of how narrowly `@source` is
   scoped. Theme tokens aren't tree-shaken the way generated utility classes are — a
   critical-CSS file scoped to just the header/hero blew the 14 KB budget by 50%+ from the theme
   dump alone, before a single real utility class was counted.

Fixing this properly (e.g. making `critical.scss` reachable from a real Vite HTML entry, or
invoking Tailwind separately with explicit content globs outside `@tailwindcss/vite`) is a
build-tooling change, not a template-authoring one — out of scope for day-to-day block work.
Hand-authored, layout-only critical CSS is the practical answer until/unless that changes.
</details>

### Key Vite config decisions

```js
base: command === 'build' ? './' : '/'
```
Production uses a relative base so compiled CSS references fonts as `./Roboto-xxx.woff2` — resolves correctly relative to the CSS file regardless of WordPress install path.
Dev uses `'/'` because Vite's HMR and module resolution break with a relative base when scripts are served cross-origin.

```js
server: { origin: 'http://localhost:5173' }
```
Forces Vite to embed the full dev server URL in injected CSS (e.g. `url('http://localhost:5173/resources/fonts/...')`). Without this, Vite writes `/resources/fonts/...` which the browser resolves against the WordPress page origin, causing font 404s.

### Production incident: a host's JS-optimization plugin CDN-rehosting the entry bundle breaks it silently

**Confirmed on a real client site.** The host's JS optimization plugin (WPMUdev Hummingbird) rehosted the compiled Vite entry chunk (`public/build/assets/app-*.js`, loaded with `type="module"`) onto its own CDN. That CDN sends no `Access-Control-Allow-Origin` header. Module scripts (`type="module"`) always require CORS to execute, regardless of same-origin heuristics elsewhere in the page — so the browser hard-blocked the fetch (`net::ERR_FAILED` in the console), the entire bundle never executed, and with it Alpine.js and all core init logic (including the `--header-height` custom property some layout CSS depends on) silently never ran. **The rendered page showed no visible error at all** — no broken layout signal, no thrown exception surfaced to the user, just inert JS.

**This is not a `taw-core`/`ViteLoader`/`BaseBlock` bug.** Nothing in the framework's asset pipeline is wrong, and there's no code-level fix possible from this side — a framework can't force a third-party CDN to add response headers. This is purely a hosting-configuration gap, and the fix lives on the host, not in this codebase:

- Any host-level "combine/delay/serve JS from CDN" feature (Hummingbird, WP Rocket, Autoptimize, LiteSpeed Cache, etc.) **must exclude the theme's own build output** (`wp-content/themes/<theme>/public/build/assets/`) from that specific feature. Delaying *execution* of non-critical third-party scripts (analytics, chat widgets) is fine and expected; CDN-*offloading* the theme's own `type="module"` entry script is not — ESM module scripts can't survive being rehosted cross-origin without the new origin serving proper CORS headers.

`taw-core`'s `ViteLoader` now emits `data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"` on every module script, its preload/modulepreload tags, and every Vite-extracted stylesheet by default. Plugins that hook WordPress's normal `script_loader_tag`/`style_loader_tag` filters (WP Rocket, Autoptimize, Perfmatters, LiteSpeed Cache) respect these markers and will leave the tags alone automatically. **This does not resolve the Hummingbird incident above** — Hummingbird's CDN-rehosting feature rewrites the raw HTML output buffer directly rather than hooking those filters, so it never sees these attributes at all. Plugins in that category still require manual exclusion-list configuration in their own admin UI, regardless of what markers are on the tag.

**Fast diagnostic** — if a page looks visually broken with layout/interaction issues but zero application-level errors:
1. In the browser console, check `window.Alpine === undefined` after page load — if true, the entry bundle never executed.
2. Check `getComputedStyle(document.documentElement).getPropertyValue('--header-height')` — an empty string means the same thing (this custom property is set by app init logic, not CSS).
3. **Before assuming it's an application bug**, check the Network tab / console for a cross-origin CORS block on the module script request (`net::ERR_FAILED`, or a CORS error naming a CDN domain that isn't this site's own domain) — that points straight at a JS-optimization plugin having rehosted the entry chunk, not a code defect.
4. Watch for the **200-but-blocked variant**: the Network tab can show a `200 OK` on the module script request yet still flag it red/CORS-blocked — the CDN served the file fine, it just omitted `Access-Control-Allow-Origin`, so the browser blocks execution anyway. Don't let the 200 status rule out CORS as the cause.

**Second confirmed occurrence (2026-08):** same root cause, triggered by Hummingbird's **"Enable WPMU DEV CDN"** toggle (file-hosting/CDN feature) rather than the optimizer feature from the first incident. Confirms this isn't optimizer-specific — any Hummingbird feature that rehosts files cross-origin hits the same module-script CORS wall.

**The actual fix, this time:** Hummingbird's CDN settings has an "Exclude files from using CDN" picker that lists each enqueued script/style **by its WordPress handle**, not just by raw file path — including every `taw-block-*` handle `BaseBlock` registers per block (see `taw-core/src/Core/Block/BaseBlock.php`) alongside the main `theme-app` handle. Excluding `theme-app` from the CDN there fixed the entry bundle; **any block module handle actually used on a live page needs the same exclusion**, and this list does not auto-update when new blocks are scaffolded — revisit it after adding a block if the site uses Hummingbird's CDN. This is a real, working per-handle exclusion UI (not a dead end like the optimizer's raw-buffer rewrite) — so for Hummingbird's CDN feature specifically, the fix is "exclude every module-script handle from the CDN picker," not "there's no admin-side fix."

### Self-hosted fonts

- Place WOFF2 files in `resources/fonts/`
- Declare `@font-face` in `resources/scss/_fonts.scss` with `url('../fonts/Name.woff2')`
- `@use 'fonts'` in `app.scss` (linked CSS) — Vite rewrites the URL correctly
- **Never** `@use 'fonts'` in `critical.scss` — inlined styles can't resolve relative asset paths
- Register preloads via `vite_asset_url('resources/fonts/Name.woff2')` — returns dev server URL in dev and hashed build URL in prod

### Helper: `ViteLoader::assetUrl(string $path): string`

Resolves any theme asset to the correct URL in both modes:
```php
use TAW\Support\ViteLoader;

ViteLoader::assetUrl('resources/fonts/Roboto-Regular.woff2')
// Dev  → 'http://localhost:5173/resources/fonts/Roboto-Regular.woff2'
// Prod → 'https://example.com/.../public/build/assets/Roboto-Regular-B51t0g.woff2'
```

### Block assets in Vite

`vite.config.js` auto-discovers block assets:
```js
const componentAssets = readdirSync('Blocks', { recursive: true })
    .filter(f => f.endsWith('style.css') || f.endsWith('style.scss') || f.endsWith('script.js'))
    .map(f => `Blocks/${f}`);
```

These become separate Rollup entry points → separate cached files in production.

### Script type="module"

The `script_loader_tag` filter added by `ViteLoader::init()` applies `type="module"` to every handle registered via `ViteLoader::$moduleHandles`:
- All scripts from the Vite dev server (dev)
- `theme-app` and any handle passed to `enqueueAsset()` (prod)

---

## Block Variations

A single MetaBlock class can be registered as multiple distinct instances using variations. Override `static::variations()` to return an array of variation strings — an empty string means the default instance (no suffix).

```php
class Hero extends MetaBlock
{
    protected string $id = 'hero';

    public static function variations(): array
    {
        return ['', 'about'];  // registers 'hero' and 'hero--about'
    }

    protected function getData(int|false $postId): array
    {
        // $this->getVariation() returns '' or 'about'
        return ['heading' => $this->getMeta($postId, 'hero_heading')];
    }
}
```

Each variation gets its own separate metabox data (different `post_meta` rows) but shares the same class, assets, and template. Variations share the asset handle (based on `$this->baseId`) so only one CSS/JS file is loaded even when both are on the same page.

Rendered via `BlockRegistry::render('hero')` and `BlockRegistry::render('hero--about')` respectively.

---

## CSS Studio (Development)

CSS Studio is a visual editor that streams live-page edits to this agent. It is installed and configured in this project.

**Toggle:** WP Admin → TAW Settings → Developer Tools → Enable CSS Studio
**Requires:** Vite dev server running (`npm run dev`)
**Start session:** `/studio`

When a session is active, this agent applies incoming changes following the TAW-specific rules in `.claude/skills/studio/SKILL.md`:
- **Text/attr changes** — checks whether the element is already dynamic (PHP variable from metabox); if hardcoded, asks whether to keep it hardcoded or wire it to a new metabox field.
- **Style changes** — always asks the user to choose: Tailwind classes in `index.php`, Block SCSS (`Blocks/{Name}/style.scss`), or Global SCSS (`resources/scss/app.scss`).

---

## Tech Stack

| Technology | Version | Purpose |
|---|---|---|
| WordPress | 6.0+ | CMS |
| PHP | 8.2+ | Server-side |
| Tailwind CSS | v4 | Utility-first CSS (via `@tailwindcss/vite`) |
| Alpine.js | v3 | Lightweight JS reactivity |
| Vite | v7 | Build tool + HMR |
| SCSS | via `sass` | Optional per-block or global styles |
| Composer | v2 | PSR-4 autoloading + `taw/core` package management |
| Symfony Console | — | CLI scaffolding commands (`bin/taw`) |

---

## PSR-4 Autoloading

**Theme's `composer.json`** only registers theme-specific classes:
```json
{
    "autoload": {
        "psr-4": {
            "TAW\\Blocks\\": "Blocks/"
        }
    }
}
```

**`taw/core` package** registers everything else:
```json
{
    "autoload": {
        "psr-4": { "TAW\\": "src/" },
        "files": ["src/Support/utilities.php", "src/Support/performance.php"]
    }
}
```

`utilities.php` provides global helper functions: `dump()`, `dd()`, `taw_editable()`, `taw_editor_attrs()`, `taw_editor_attrs_array()`, `taw_editor_section()`.

> **Note:** `vite_asset_url()` and `vite_is_dev()` are **not** provided by `utilities.php`. Use `TAW\Support\ViteLoader::assetUrl()` and `TAW\Support\ViteLoader::isDevServerRunning()` instead. The legacy `vite-loader.php` procedural functions still exist in the package but are **not** in the composer `files` autoload — they will not be available unless explicitly included.

Effective namespace mapping (combined):
- `TAW\Core\Block\BaseBlock` → `vendor/taw/core/src/Core/Block/BaseBlock.php`
- `TAW\Core\Block\MetaBlock` → `vendor/taw/core/src/Core/Block/MetaBlock.php`
- `TAW\Core\Block\Block` → `vendor/taw/core/src/Core/Block/Block.php`
- `TAW\Core\Block\BlockRegistry` → `vendor/taw/core/src/Core/Block/BlockRegistry.php`
- `TAW\Core\Block\BlockLoader` → `vendor/taw/core/src/Core/Block/BlockLoader.php`
- `TAW\Core\Metabox\Metabox` → `vendor/taw/core/src/Core/Metabox/Metabox.php`
- `TAW\Core\OptionsPage\OptionsPage` → `vendor/taw/core/src/Core/OptionsPage/OptionsPage.php`
- `TAW\Core\Theme\Theme` → `vendor/taw/core/src/Core/Theme/Theme.php`
- `TAW\Core\Theme\ThemeUpdater` → `vendor/taw/core/src/Core/Theme/ThemeUpdater.php`
- `TAW\Core\Menu\Menu` → `vendor/taw/core/src/Core/Menu/Menu.php`
- `TAW\Core\Menu\MenuItem` → `vendor/taw/core/src/Core/Menu/MenuItem.php`
- `TAW\Core\Rest\SearchEndpoints` → `vendor/taw/core/src/Core/Rest/SearchEndpoints.php`
- `TAW\Core\Form\Form` → `vendor/taw/core/src/Core/Form/Form.php`
- `TAW\Core\Form\SubmissionsHandler` → `vendor/taw/core/src/Core/Form/SubmissionsHandler.php`
- `TAW\Core\Mail\Mailer` → `vendor/taw/core/src/Core/Mail/Mailer.php`
- `TAW\Core\Mail\MailTemplate` → `vendor/taw/core/src/Core/Mail/MailTemplate.php`
- `TAW\Core\Mail\MailTester` → `vendor/taw/core/src/Core/Mail/MailTester.php`
- `TAW\Helpers\Framework` → `vendor/taw/core/src/Helpers/Framework.php`
- `TAW\Helpers\Image` → `vendor/taw/core/src/Helpers/Image.php`
- `TAW\Helpers\Svg` → `vendor/taw/core/src/Helpers/Svg.php`
- `TAW\Helpers\Dump` → `vendor/taw/core/src/Helpers/Dump.php`
- `TAW\Helpers\Editor` → `vendor/taw/core/src/Helpers/Editor.php`
- `TAW\CLI\MakeBlockCommand` → `vendor/taw/core/src/CLI/MakeBlockCommand.php`
- `TAW\Blocks\Hero\Hero` → `Blocks/Hero/Hero.php`
- `TAW\Blocks\Menu\Menu` → `Blocks/Menu/Menu.php`

After adding new block classes, run `composer dump-autoload`.

---

## Commands

| Command | Description |
|---|---|
| `npm run dev` | Start Vite dev server (port 5173) with HMR |
| `npm run build` | Production build → `public/build/` |
| `composer install` | Install PHP dependencies (including `taw/core`) |
| `composer update taw/core --with-dependencies` | Pull the latest framework package update (and the dependencies it needs) |
| `composer dump-autoload` | Rebuild autoload classmap (after adding new blocks) |
| `php bin/taw make:block Name` | Scaffold a new block |
| `php bin/taw export:block Name` | Export a block as a ZIP |
| `php bin/taw import:block path.zip` | Import a block from a ZIP |
| `php bin/taw sync --json` | Check for drift: `taw/core` version + `taw-theme` Tier 1/Tier 2 scaffold paths |
| `php bin/taw sync --apply` | Also write Tier 1 scaffold changes (Tier 2 is report-only) |
| `php bin/taw sync --apply-manifests` | Also write the rule-based `composer.json`/`package.json` suggestions (additions and raised constraints, never removals; taw/core ≥ v1.78.0) |
| `php bin/taw log:tail --level=error` | Read back the structured log (`wp-content/taw-logs/`) — see § "Logging" |
| `composer run phpstan` | Static analysis (`Blocks/`, `inc/`) — also runs in CI |
| `composer run test` | Run the block unit test suite (`tests/Unit/`) — see § "Testing Blocks" |

---

## Production view: the companion and taw-fleet

[taw-fleet](https://github.com/Relmaur/taw-fleet) (the terminal dashboard for the TAW sites on a Mac) reads each production site through the **companion**, `taw/hub-companion` ([`Relmaur/taw-hub-companion`](https://github.com/Relmaur/taw-hub-companion)): a signed, read-mostly `wp-json/taw-hub/v1/` receiver (health, inventory, vulnerabilities, logs) that verifies every request against a trusted Ed25519 key. It replaces the taw-hub control app, retired 2026-10-08 (`hub:install` / `hub:enroll` are retired stubs since `taw/core` v1.77.0, and the `hub-connect` skill is gone).

**Not in this scaffold, by design:** the key a site trusts belongs to whoever runs the fleet, and `taw/theme` is public. Add it per client project:

- `composer.json`: the VCS repository `https://github.com/Relmaur/taw-hub-companion`, `"taw/hub-companion": "^0.3"`, and the fleet key in `"extra": {"taw-companion": {"keys": {"taw-fleet": "<taw-fleet live key show>"}}}`.
- The deploy copies `vendor/taw/hub-companion/mu-loader/taw-companion.php` to `wp-content/mu-plugins/` (see a client theme's `.github/workflows/deploy.yml`, step "Install the TAW companion loader"). The companion then runs as an mu-plugin and updates with `composer.lock`.
- Check with `taw-fleet live <site>`, then pin the site's key once with `taw-fleet live trust <site>`.

---

## Automated framework-drift detection

`php bin/taw sync` (shipped by `taw/core`, see its README for the full command reference) is the scriptable core of the `update-theme` skill — it checks whether the installed `taw/core` version is behind the latest GitHub tag, and whether this project's Tier 1/Tier 2 `taw-theme` scaffold paths (defined once in `taw/core`'s `resources/update-manifest.json`) differ from the canonical repo. It never boots WordPress and never touches `taw/core` itself.

`.github/workflows/framework-sync.yml` runs this unattended on a weekly schedule (`workflow_dispatch` also available for a manual run): bumps `taw/core`, applies Tier 1 changes, runs the exact same verification `ci.yml` runs on every push (`php -l`, the `getData()` signature check, PHPStan, and the dynamic smoke test below), and — only if something actually changed and verification passed — opens a pull request with Tier 2 diffs included in the body for human review. Tier 2 is never written automatically, by either this workflow or `sync --apply`; a human always reviews those diffs before they're applied, same as the interactive `update-theme` skill. Nothing happens (no PR, no noise) when the project is already current.

**A Tier 2 diff on `composer.json`/`package.json` is often permanent, not a problem to fix.** A real client project accumulating its own dependencies (an MJML compiler, a carousel library, whatever the project needs) is normal — those packages will never exist in the canonical `taw-theme` scaffold, so the diff never fully clears, and that's correct. The `update-theme` skill treats these two files specially: it never does a whole-file overwrite for them even when a Tier 2 change is approved, since that would silently delete every client-added dependency. It edits only the genuinely framework-relevant lines by hand instead. See the skill's own `SKILL.md` § "Step 3" for the full reasoning. Since taw/core v1.78.0, `sync --json` does that sorting by rule: each of the two files gets a `merge` entry (`add`, `bump`, `review`, `optional`, `site_only`; never a removal, and `extra`, such as the companion key, is never read), and `sync --apply-manifests` writes only `add` + `bump`.

**Updating several themes at once.** The `update-theme` skill has a non-interactive **batch mode** (its § "Batch mode") for an agent that can't ask questions, such as a subagent of taw-fleet's update-all. In short:
- it skips a theme with uncommitted changes or on an unexpected branch, with a reason;
- it updates taw/core before the sync (so the manifest suggestions exist), applies Tier 1 and the suggestions, and returns Tier 2 prose as proposals;
- it gives each UPGRADING check an outcome and runs the theme's tests, phpstan and build;
- it commits on `chore/taw-core-<version>`, never pushes, and ends with a JSON result the coordinator combines.

**Site-authored skills survive the sync.** `.claude/skills/` and `.agents/skills/` are Tier 1, but their manifest `type` is `skills-dir`, not `dir` — the sync does *not* `rsync --delete` them wholesale. It reconciles one skill folder at a time: every skill that exists in the canonical `taw-theme` repo is refreshed to the canonical copy; a skill folder that exists only in this project is kept or dropped based on its `SKILL.md` frontmatter — `owner: site` → preserved untouched (and named in the report), `owner: taw` → deleted (a framework skill retired upstream), no `owner:` key → preserved with a warning for a human to resolve. This is what lets a client site keep its own workflow skills (e.g. a parish site's `publish-news`, which drafts `news` CPT items in that site's editorial voice) in the same directory the framework skills live in. Every canonical framework skill carries `owner: taw`; a site-authored skill must declare `owner: site` to be safe across syncs — see the `update-theme` skill's `SKILL.md` § "Migrating an existing site-authored skill". The `skillsReconcile` block in `update-manifest.json` defines the marker; `TAW\CLI\SyncCommand` implements the reconcile.

This workflow file is itself Tier 1, so it propagates to every client project automatically via `update-theme` — a project only has to run that skill once to start self-checking forever after. Requires the repo setting **Settings → Actions → General → "Allow GitHub Actions to create and approve pull requests"** enabled (off by default on new repos) for the PR-opening step to succeed — the **`project-init`** skill checks this, enables it with confirmation if it's off, and triggers a real run to verify the whole pipeline actually works rather than assuming it does.

---

## Static Analysis

PHPStan (level 5, config in `phpstan.neon`) analyzes `Blocks/` and `inc/`, loading WordPress core stubs via `szepeviktor/phpstan-wordpress` so core functions/classes (`add_action`, `WP_Query`, etc.) resolve correctly instead of erroring as unknown. Runs in CI on every push/PR alongside the lint and `getData()` signature checks.

Prefer fixing types (accurate `@param`/`@return` PHPDoc for hook callbacks, REST request shapes, query results) over adding `ignoreErrors` entries. When an ignore is unavoidable, scope it narrowly and document why inline. Don't pre-emptively add a `phpstan-baseline.neon` — this repo has none because it's currently clean at level 5; only introduce one if adopting the check on a codebase with pre-existing legacy errors, and treat it as a migration aid to shrink over time, not a permanent suppression file.

---

## Dynamic Smoke Test

The static checks above (lint, the `getData()` signature check, PHPStan) all analyze source text — none of them actually execute a block's render path. `bin/ci/smoke-test.php` does: it boots a real, CI-provisioned WordPress + MySQL install with this theme active, creates one real post, and renders every registered `MetaBlock` and displays every registered `Form` against it, failing loudly on any `Throwable` — the class of bug a static check can't see (an undefined function call, a template referencing a variable `getData()` never returned, WP API misuse).

**Why a real post is required:** `MetaBlock::render(null)` falls back to `get_the_ID()`, which is `false` outside a real post/loop context — it silently no-ops instead of calling `getData()` at all. The smoke test script requires an explicit post ID for exactly this reason; a CI job that "passed" by rendering nothing would be worse than no test at all.

Runs as a separate job in `ci.yml` (and inside `framework-sync.yml`'s verification gate, against the post-sync codebase) — spins up a `mysql:8.0` service container, downloads WordPress core via `wp-cli`, installs it, copies the checked-out repo into `wp-content/themes/`, activates it, creates a test page, then runs:

```bash
php bin/ci/smoke-test.php /path/to/wp-load.php <post_id>
```

No `npm run build` is needed for this — `ViteLoader::getManifest()`/`assetUrl()` degrade gracefully when no manifest exists, and `render()` itself never touches asset enqueueing (that's a separate call path via `BlockRegistry::queue()`).

Sourced from the `wp-phpstan` skill in [WordPress/agent-skills](https://github.com/WordPress/agent-skills) — see that skill's `references/wordpress-annotations.md` and `references/third-party-classes.md` for WP-specific typing patterns and how to handle third-party plugin classes if this theme ever needs to integrate with one.

### Other external WordPress skill references

For general WP capabilities this framework doesn't already own an abstraction for, the same repo has a few more worth consulting on demand rather than vendoring wholesale: `wp-performance`, `wp-wpcli-and-ops`, `wp-playground`. **Do not** pull in `wp-block-development` or `wp-block-themes` — they teach native Gutenberg blocks and `theme.json`, both of which TAW replaces with its own MetaBlock/Block system and Vite pipeline; following them would actively fight this framework's conventions.

---

## Boilerplate Blocks

The theme ships with pre-built blocks that serve as ready-to-use starting points.

### `Menu` — Site Header with Live Search

**Location:** `Blocks/Menu/` — `class TAW\Blocks\Menu\Menu extends Block`

A two-row site header wired up with an Alpine.js live-search overlay. Drop it into `header.php` and customise the markup, colours, and labels.

**Files:**

| File | Purpose |
|---|---|
| `Menu.php` | UI Block class (no metaboxes — all markup is static in the template) |
| `index.php` | Two-row header: logo + search icon (top row), primary nav + contact links (bottom row) |
| `script.js` | Alpine.js `Menu` data component — search overlay state, debounced fetch, Escape key handler |
| `style.scss` | Search overlay styles (`.search-overlay`, `.search-overlay__*` BEM classes) |

**Features:**
- Renders the `primary` WordPress nav menu via `Menu::get('primary')` with dropdown support
- Shows `company_address`, `company_email`, `company_phone` from `OptionsPage` if set
- Search overlay: debounced (350 ms) fetch against the native WP REST endpoint `GET /wp-json/wp/v2/search`, results list, empty state, spinner, Escape to close, body scroll lock
- Minimum 2 characters before a search request fires

**Usage in `header.php`:**

```php
<?php
use TAW\Blocks\Menu\Menu;

(new Menu())->render();
?>
```

Asset queueing: because `Menu` is a UI Block (not a MetaBlock), call `queue()` manually if you want assets in `<head>`:

```php
// Before get_header() — assets land in <head>
use TAW\Core\Block\BlockRegistry;
BlockRegistry::queue('menu');
get_header();
```

Or simply instantiate and render inline — assets fall back to an inline `<link>` tag (acceptable for a persistent header).

**Customisation checklist:**
- Update label strings (`What are you looking for?`, `Search posts and pages…`) for the target language
- Adjust `subtype` in `script.js` to restrict search to specific post types (default: `post,page`)
- Replace hard-coded `bg-primary` / `bg-secondary` classes with your project's colour tokens

---

## Common Patterns

### Conditional block loading per template
```php
// front-page.php
BlockRegistry::queue('hero', 'features', 'testimonials', 'cta');

// single.php
BlockRegistry::queue('post-header', 'related-posts');

// archive.php — maybe no custom blocks needed
```

### Accessing meta in MetaBlock::getData()
```php
protected function getData(int|false $postId): array
{
    return [
        'heading'   => $this->getMeta($postId, 'my_heading'),
        'image_url' => $this->getImageUrl($postId, 'my_image', 'large'),
    ];
}
```

### Nesting UI blocks inside MetaBlocks
```php
<!-- Blocks/Hero/index.php -->
<section class="hero">
    <h1><?php echo esc_html($heading); ?></h1>
    <?php if ($cta_text): ?>
        <?php (new \TAW\Blocks\Button\Button())->render([
            'text' => $cta_text,
            'url'  => $cta_url,
        ]); ?>
    <?php endif; ?>
</section>
```

### Reading theme options in templates
```php
use TAW\Core\OptionsPage\OptionsPage;

$phone = OptionsPage::get('company_phone');
$logo  = OptionsPage::get_image_url('company_logo', 'medium');
```

### Rendering a performance-optimised image
```php
use TAW\Helpers\Image;

// Hero (above the fold)
echo Image::render($hero_image_id, 'full', ['above_fold' => true]);

// Card image (below the fold)
echo Image::render($card_image_id, 'large');
```

---

## Do NOT

- Put block logic in `functions.php` — it belongs in the block class
- Manually register blocks in `functions.php` — `BlockLoader::loadAll()` handles it
- Call `wp_enqueue_style/script` directly for blocks — the base class handles it
- Create blocks with mismatched folder/class names — auto-discovery will skip them
- Ship a new block without a matching `tests/Unit/Blocks/{Name}Test.php` — see § "Testing Blocks"
- Forget to `queue()` blocks before `get_header()` — assets will fall back to inline (suboptimal)
- Add `@font-face` / `@use 'fonts'` to `critical.scss` — inlined `<style>` tags resolve `url()` against the page origin, not the stylesheet, causing font 404s on any non-root install
- Add `resources/css/app.css` back as a standalone Vite entry — it is imported by `app.js` and must not be a separate entry or it will compile twice
- Set `base: './'` globally in `vite.config.js` — it must only apply to `build` (dev mode breaks with a relative base in cross-origin setups)
- Use `wp_nav_menu()` — use `Menu::get('location')` instead for full markup control
- Edit files inside `vendor/taw/core/` directly — changes will be overwritten by `composer update`. Work in the `taw-core` repo instead
- Look for `TAW\Core`, `TAW\Helpers`, or `TAW\CLI` classes in `inc/` — they live in `vendor/taw/core/src/`
- Reference `TAW\Core\Framework` — it moved to `TAW\Helpers\Framework`
- Reference `TAW\Core\BaseBlock` / `TAW\Core\MetaBlock` / `TAW\Core\Block` etc. without the `Block\` sub-namespace — e.g. use `TAW\Core\Block\MetaBlock`
- Install `wp-block-development` or `wp-block-themes` from WordPress/agent-skills — they teach native Gutenberg blocks/`theme.json`, which conflicts with TAW's own MetaBlock/Block and Vite conventions

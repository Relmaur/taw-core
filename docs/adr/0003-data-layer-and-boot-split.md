# ADR-0003: taw/core becomes a theme-agnostic data layer — `Boot::data()`, Composer-only distribution

## Status

Accepted (2026-09-23). Plan: umbrella `docs/plans/data-layer.md`, Phase 1 Step 2.

## Context

taw/core started as the engine behind one classic theme scaffold (taw-theme). Its data primitives
(Metabox field storage, `writeMeta()`, `FieldCodec`, `FieldMetaRegistrar`'s REST meta, content
interchange) are sound and mostly theme-independent. But they can only be reached through a boot
path that also turns on presentation: PHP blocks from the theme's `Blocks/`, Vite, Alpine,
performance tweaks, the visual editor, SEO output.

The goal is to make taw/core **the data layer for any WordPress project**: the classic taw-theme,
the new taw-gutenberg starter (a hybrid block theme), and other themes later. Owning this layer
is deliberate: third-party field frameworks get sunset (Carbon Fields is the cautionary example),
and a sunset data layer strands every site built on it.

Four things in the current code stop a non-classic consumer from simply running
`composer require taw/core`:

1. **Performance registers on autoload.** `src/Support/performance.php` is a Composer
   `autoload.files` entry (`composer.json:43-46`), and its last lines (473-476) call
   `Performance::register()` when the file loads. `removeBloat()` dequeues `wp-block-library`,
   `wp-block-library-theme`, `classic-theme-styles` and `global-styles` (lines 226-229). Any
   Gutenberg theme that installs taw/core loses its block CSS before it has called anything.
2. **There is no data-only boot.** `Theme::boot()` (`src/Core/Theme/Theme.php:88`) wires
   everything. The data parts are three lines at 178-180 (`ContentAdminScreen`,
   `ContentEndpoint`, `FieldMetaRegistrar::register()`).
3. **`Framework::url()` assumes the parent theme** (`src/Helpers/Framework.php:83-107`). It strips
   `realpath(get_template_directory())` from the package path. From a child theme's or a plugin's
   vendor folder nothing matches, and the result is the template URI with an absolute filesystem
   path appended. That breaks `admin.css` and Alpine for Metabox and OptionsPage.
4. **Global helpers without guards.** `dump()`/`dd()` in `src/Support/utilities.php:57-64` fatal
   if anything else on the site defines them.

The questions this ADR answers: how is taw/core distributed to non-classic consumers, what does
a data-only boot contain, and how are these four problems fixed without changing taw-theme's
behavior?

## Decision

1. **Composer-only distribution, one package.** Every consumer runs `composer require taw/core`
   and calls the boot it needs:
   - taw-theme: `Theme::bootstrapFullSite(get_template_directory())`, unchanged.
   - Data-only consumers (taw-gutenberg, any other theme or site plugin): `\TAW\Core\Boot::data()`.

   No companion WordPress plugin, no second package.
2. **`TAW\Core\Boot::data()`** is the new data-layer entry point. It is idempotent (a static flag,
   so a second call does nothing). It owns exactly the three content/REST lines from
   `Theme.php:178-180`, in the same order, plus (from ADR-0004) the schema registry hooks.
   `Theme::boot()` step 13 becomes a call to `Boot::data()`, so taw-theme registers the same
   callbacks in the same order.
3. **Performance registers only on boot.** The load-time `Performance::register()` call is
   removed, and `register()` becomes idempotent. `Theme::boot()` and `bootstrapFullSite()` call it
   as their first statement. For taw-theme this is the same moment as before, because
   `functions.php` requires the autoloader and calls `bootstrapFullSite()` on the very next line.
   `Boot::data()` never calls it. Escape hatch: `define('TAW_PERFORMANCE_AUTOLOAD', true)`
   restores the old load-time registration for any consumer that relied on it without booting.
4. **`Framework::url()` resolves by location.**
   - It finds the longest matching root among: stylesheet dir (child theme), template dir,
     `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `WP_CONTENT_DIR`, `ABSPATH`. Each is compared through
     `realpath()` with a trailing slash, so a child theme never matches its parent's prefix.
   - It maps that root to its URL.
   - The result passes through a new `taw_core_package_url` filter.
   - For a package vendored in the active parent theme, the output is byte-identical to today.
   - `themePath()` keeps its current meaning (the parent theme).
5. **Global helper guards.** `dump()`/`dd()` are wrapped in `function_exists()`.
6. **`ext-pdo_sqlite` moves from `require` to `suggest`.** Only the RAG and Corpus subsystems use
   it (ADR-0002 already added a MySQL fallback), and they get runtime checks. A theme that only
   wants the data layer shouldn't need SQLite.
7. **One taw/core copy per site.** The theme (or one site plugin) owns taw/core. Two consumers on
   the same site that each vendor their own copy is documented as unsupported. Composer's
   `files` dedupe prevents a fatal, but classes could load from either copy. No guard code is
   written for it.

## Trade-offs

- **Composer-only vs. a companion plugin.** *Rejected:* a thin "TAW Data" plugin requiring
  taw/core. It would have made the data layer survive theme switches. But it meant a second
  release pipeline (zip builds, a self-updater), a fifth repo, and a real multiple-copy problem:
  theme and plugin each vendoring taw/core, with classes loaded from mixed copies and version
  negotiation to design. For bespoke client sites where the theme *is* the product, surviving a
  theme switch is worth less than that complexity. The option stays open without new code: the
  resolver in Decision 4 already handles plugin and mu-plugin folders, so a site-specific
  mu-plugin can require taw/core and call `Boot::data()` if a project ever needs it.
- **Splitting a separate `taw/data` package.** *Rejected:* a cleaner boundary, but two packages to
  version and tag, and a large move of code out of taw/core. The boundary is drawn with a boot
  method instead of a package.
- **Performance: boot-only vs. autoload with block-theme detection.** *Rejected:* keep autoloading
  and disable `remove_bloat` when `wp_is_block_theme()`. taw-gutenberg is a hybrid theme (PHP
  shell, no `templates/`), which WordPress does not classify as a block theme, so it would still
  lose block CSS. Presentation code also shouldn't run for consumers that never asked for it. The
  cost is one documented behavior change for consumers that never call `boot()`, covered by the
  escape hatch.
- **No duplicate-copy guard.** It accepts a sharp edge (an unsupported two-copy setup) in exchange
  for not building and maintaining version negotiation that no current consumer needs.

## Consequences

- New: `src/Core/Boot.php`. Changed: `Theme.php` (step 13, and a Performance call at the top of
  `boot()` and `bootstrapFullSite()`), `performance.php`, `utilities.php`, `Framework.php`,
  `composer.json` (`suggest`), RAG/Corpus runtime SQLite checks.
- **Backward compatibility is proven, not asserted.** A golden hook snapshot (every TAW-owned
  callback on the taw Local site with its hook, priority and position, taken at v1.41.0) must be
  identical after this change. The existing `FrameworkTest` symlink cases must pass unchanged.
- Released as a semver **minor** (v1.42.0). The Performance change is called out in the
  changelog.
- taw-core's `CLAUDE.md` statement "`Theme::boot()` is the single entry point" becomes "the entry
  point for classic-theme consumers; `Boot::data()` is the data-only entry point".
- A Gutenberg consumer still ships symfony/console, symfony/process and enshrined/svg-sanitize in
  its vendor folder. They only load under `bin/taw` or when `Svg` is used, so there's no runtime
  cost; revisit only if a real conflict appears.
- Not done (YAGNI): moving `Performance` to PSR-4 and out of `autoload.files` (it still has to be
  a `files` entry today because the file defines the class); a guard for multiple copies.

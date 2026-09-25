# ADR-0006: A shared, theme-agnostic Vite adapter (`Assets\Vite`), separate from the classic `ViteLoader`

## Status

Accepted (2026-09-25). Plan: umbrella `docs/plans/vite-and-data-panel.md`, Track V. Related: taw-gutenberg
ADR-0003 (adopts it). The data panel (Track P, a later ADR) uses it for its own dev server.

## Context

taw-gutenberg needs a JS/CSS build for custom blocks and theme assets. The block theme it replaces,
ml-theme--custom-gutenberg, uses Vite with its own `ViteService`. taw/core already has
`TAW\Support\ViteLoader`, but that one is part of the classic-theme toolkit (ADR-0003):

- **What to keep:** its dev-server detection. It only probes the host:port written to the
  project's own **hot file**, after two real incidents where a bare port probe matched a stray
  process or another project's Vite server and pointed production at dead `localhost` URLs.
- **What doesn't fit a block theme:**
  - `init()` wires classic-only behaviour: the x-cloak style, a `theme-app` bundle with async CSS,
    and modulepreload tags.
  - Its paths are fixed to the active theme.
  - It adds `type="module"` by **rewriting the whole `<script>` tag** in `script_loader_tag`. That
    throws away the tag's id, inline scripts and translations; ml-theme ADR-0007 moved to
    `wp_script_attributes` for this reason.
- ml-theme's `ViteService` has the opposite profile. It's a good block-theme API (block.json
  blocks, attribute-based module type), but its dev detection is the bare port probe
  (`fsockopen(localhost:3000)`).
- The WordPress-externals Vite plugin (`@wordpress/*` → `window.wp.*`) needs a hand-kept list of
  export names. A missing name is `undefined` at runtime, with no error.
- taw/core serves TAW sites only (taw-theme, taw-gutenberg and their child themes).

## Decision

1. **New `TAW\Core\Assets\Vite`, one instance per project root:**
   - `Vite::theme()` for the active parent theme (the one that ships the build);
   - `new Vite($dir, $url)` for any other root, such as taw/core's own data panel.
   It isn't part of `Boot::data()` or `Theme::boot()`, and it adds no hooks until an instance
   registers an asset.
2. **Dev detection is shared, not duplicated.** The hot-file probe moves out of `ViteLoader` into
   one helper that both classes call. `ViteLoader`'s behaviour doesn't change.
3. **API:**
   - `script()` and `style()` register or enqueue an entry;
   - `block($dir)` registers a `block.json` block and maps `editorScript`, `viewScript`, `style` and
     `editorStyle` to Vite entries;
   - `url()` resolves a built asset.
   The manifest is `dist/.vite/manifest.json`, cached with the file's mtime in the cache key.
4. **ES modules through `wp_script_attributes`,** only for handles the instance registered, so ids,
   inline scripts and translations survive.
5. **With no build and no dev server, it degrades:** nothing is enqueued, one admin notice asks for
   `npm run build`, and the site still renders.
6. **Shared Vite config ships with the package** in `resources/vite/` (plain ESM, imported from
   `vendor/taw/core/resources/vite/`):
   - `wordpressExternals()` holds the single export-name list, checked by a test;
   - `hotFile()` writes the hot file;
   - `phpReload()` is opt-in.
   They're tested with Node's built-in runner (`tests/js/`), with no npm dependencies.
7. **`ViteLoader` stays as it is** for taw-theme. Moving it onto `Assets\Vite` is a separate,
   later decision.

## Trade-offs

- **Two Vite classes for a while** (`ViteLoader` for classic, `Assets\Vite` for everything else).
  Accepted: the classic toolkit's async-CSS/preload behaviour is tuned for taw-theme, and
  refactoring it risks live sites. The part that must never differ, dev detection, is shared.
- **Themes import JS from `vendor/`.** This ties a theme's `vite.config.js` to Composer having run.
  Accepted: TAW themes already need `composer install` to boot at all.
- **The externals list is still hand-kept.** Accepted: it's now one list with a test, instead of one
  per theme.

## Consequences

- taw-gutenberg can adopt Vite (its ADR-0003) without its own loader.
- taw-theme must be unaffected: its golden hook snapshot stays identical, and its tests pass.
- Any theme using `Assets\Vite` must write the hot file (`hotFile()` plugin), or dev mode is never
  detected. This is by design: there's no hardcoded-port fallback.

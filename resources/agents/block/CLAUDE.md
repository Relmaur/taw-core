# TAW block theme — Claude Code instructions (from taw/core)

This file ships with taw/core (`vendor/taw/core/resources/agents/block/CLAUDE.md`) and updates with
`composer update taw/core`; the theme's own `CLAUDE.md` imports it and adds the site's notes, which win.
Full reference: **`AGENTS.md` next to this file** (`vendor/taw/core/resources/agents/block/AGENTS.md`).

- FSE block theme; everything visual is `theme.json` + `templates/` + `parts/`.
- Data layer = taw/core via `\TAW\Core\Boot::data()` (never `Theme::boot()`); data is defined in
  `taw-schema/*.json`, validated with `php bin/taw schema:validate`.
- Editor lockdown = taw/core editing policies via `Boot::editing()` (`Setup\Editing`); policy in
  `taw-schema/editing.json` (ships `open`), per-install level via `TAW_EDITING_PRESET`. No lockdown code here.
- JS/CSS: Vite + TS (ADR-0003) through taw/core's `Assets\Vite`; blocks in `src/blocks/<name>/`
  (`taw-gutenberg/<name>`, classic JSX runtime, `block.json` with `file:` assets), built to `dist/`
  (not committed). `composer install` before `npm run dev`/`build`.
- Services implement `Bootable`: `register()` only adds hooks.
- Namespace field ids by entity (`book_author`).
- `composer run test` + `composer run phpstan` (level max) + `npm run check` before committing.

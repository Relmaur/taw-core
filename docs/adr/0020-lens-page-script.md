# ADR-0020: The TAW Lens page script is Preact and TypeScript, built and committed like the data panel

## Status

Accepted (2026-11-06). Under umbrella ADR-0006 (TAW Lens, accepted by the owner 2026-11-06), which decides
what Lens is; this ADR decides how its page script is built. Issue #221.

## Context

TAW Lens (umbrella ADR-0006) is a dev-only page script on a classic TAW site's frontend: a picker with a
selection ring, an inspect card, a structure tree, a chat panel that streams replies, approval cards with
diffs, and in-place text editing. It must not touch the site's look or layout, must not inherit the site's
styles, and must ship with taw/core so `taw update` brings it to every theme.

Facts that shape the build:

- **taw/core already builds one UI**: the data panel (ADR-0007), TypeScript in `resources/data-panel/`,
  built with Vite into a **committed** `assets/data-panel/` (Composer installs need no Node), checked by
  `npm run check` and a CI job that fails on a stale build. Developers and agents know that setup.
- **The frontend has no WordPress React.** The data panel uses the block editor's global React; the site
  frontend loads none, and a theme may load its own copy of anything.
- **The UI is stateful.** Streaming text, a tree with keyboard navigation, approvals, docking and editing
  states are a lot of state for hand-written DOM code.

## Decision

1. **`resources/lens/`**, a sibling of `resources/data-panel/` with the same shape: its own `package.json`
   (exact versions), TypeScript, Vite, Vitest with jsdom, ESLint and Prettier, `npm run check`.
2. **Preact** (bundled, about 4 KB) with TSX through Vite's automatic JSX runtime
   (`importSource: 'preact'`). Lens owns its copy, so it never meets a site's React, Alpine or anything else.
3. **Built into committed `assets/lens/`**, loaded by PHP through `TAW\Core\Assets\Vite`, only under the Lens
   gate (umbrella ADR-0006). `npm run dev` (port 5176) takes over through `assets/lens/hot`, like the data
   panel.
4. **Isolation:** the panel and the rings render inside a shadow root on one host element. Design tokens are
   CSS custom properties on `:host` (light, dark by `prefers-color-scheme`, a `data-theme` override), so
   nothing leaks in either direction.
5. **CI:** a `lens` job mirrors `data-panel`: `npm ci`, `npm run check`, then a fresh build must equal the
   committed one.

## Trade-offs

- Rejected: **React** (the data panel's choice). It's ten times the size for the same API, and the editor's
  global copy isn't on the frontend, so there's nothing to share.
- Rejected: **hand-written DOM** or a template library. Smaller, but the chat, tree and editing states
  become manual bookkeeping.
- Rejected: **Alpine**, which TAW themes already use. Lens would depend on the site's copy and its
  directives would share the site's scope; isolation is the point.
- Rejected: **loading from the theme's `app.js`** beside CSS Studio. Lens would need a change in every theme
  and its gate would live in client-side code; loading from taw/core keeps the gate on the server and the
  theme change to the toggle alone.
- Cost accepted: a second Node package in taw/core with its own lockfile and CI job. npm 10.9 crashed
  resolving Vite 8's optional devtools peers from scratch (`Cannot read properties of null (reading
  'edgesOut')`); the lockfile was started from the data panel's, which resolves the same Vite and Vitest.

## Consequences

- New: `resources/lens/`, `assets/lens/` (committed build), CI job `lens`; `TAW\Core\Lens\Lens` loads the build
  under the gate (build step 2).
- Any change under `resources/lens/src/` is rebuilt and committed with it, or CI fails.
- Accessibility checks live next to the code: the token tests assert WCAG 2.2 AA contrast for every text
  and UI pair in both themes.

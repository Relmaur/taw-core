# Playbook: style fix, layout change, bug (and hard-coded content)

Anything that needs a theme change. Work happens in the Local site's theme repo, ships as a PR;
merging the deploy branch deploys production.

What lands here (from real client requests):
- **Move/reorder sections** ("Pasar este texto abajo del subtítulo…", "Mover el botón arriba
  de…", "mover la sección de Noticias hasta abajo"): section order is the
  `BlockRegistry::render(...)` sequence in the page template (`page-<slug>.php`) → reorder there.
  Moving an element *within* a section may instead be a field edit — check
  [`content.md`](content.md) first.
- **New section/element** ("Poner un formulario de contacto hasta aquí como en…", "Poner un
  botón que lleve al formulario"): reuse an existing block/variation from a page that already has
  it (render call + its fields on this page); a CTA button whose block already has
  `*_cta_*` label/URL fields is a content edit, not code.
- **Remove a section** ("Quitar toda esta sección"): empty its heading/content fields if the
  block hides itself when empty (most TAW blocks `return` early), else drop the render call.
- **Bugs** (a logo marquee whose drag jumps back, carousel slides out of order, a 404 from a
  menu link):
  reproduce first; a 404 from a stale link is usually a menu item or a field URL (content), not
  code.

## 1. Reproduce on Local

- You're in the theme folder; the site's slug, repo and deploy branch come from
  `taw-fleet show <slug> --json` (SKILL § 0). `taw-fleet work <slug>` (or `start`) so the site and
  Vite are up. Read the theme's own `CLAUDE.md`/`AGENTS.md` first (build, conventions).
- Repo clean and current: `git -C <theme> status`, `git fetch`, on the deploy branch and up to
  date. Dirty or behind → stop and ask.
- Open the page (`page_url` with the Local domain) in Playwright at the comment's breakpoint:
  `device.viewport_width_range.min` (e.g. Desktop `L` = 1280; mobile ≈ 390 if no min). Compare
  with the comment's screenshot; locate the element via `dom.closest_parent_selector` /
  `dom.fallback_path`. Can't reproduce → say so, draft a question to the client.

## 2. Fix

- Branch `bugsmash/<comment_number>-<short-slug>` off the deploy branch.
- Smallest change that fixes it, in the theme's own styling system (Tailwind classes / SCSS /
  block `style.css` — whichever that component already uses). Don't touch `vendor/` or
  framework files that `bin/taw sync` owns.
- Build the way the theme says (usually `npm run build`) if built assets are committed.

## 3. Verify in the browser

Same page, same breakpoint **and** one other (desktop ↔ mobile) to catch regressions; screenshot
before/after into the scratchpad and show the owner. JS console clean.

## 4. Ship (ask first)

`AskUserQuestion`: who commits, pushes and opens the PR — the agent or the owner (SKILL rule 3). PR (`gh pr create --repo <repo> --base <deploy branch>`):
title `BugSmash #<n>: <summary>`, body = comment text, page URL, breakpoint, DOM path,
before/after screenshots, BugSmash comment link. Outcome **PR open** → private reply with the PR
link; comment stays Active.

## 5. After merge

Merging deploys (`taw-fleet merge <slug>` follows the deploy; ask who merges). Once deployed,
check the live page at the breakpoint (`?nocache=<ts>`), then close out and resolve.

# ADR-0019: Themes' CI runs taw/core's shared workflows (`@v1` branch)

## Status

Accepted (2026-10-09), as part of the platform plan's update work (umbrella
`docs/plans/taw-platform.md` § 5.0; owner's go-ahead).

## Context

Each theme carried full copies of `.github/workflows/ci.yml` (126 lines) and `framework-sync.yml`
(200 lines) as Tier 1 framework files, plus the CI scripts in `bin/ci/`. A fix to a check reached
sites only through a scaffold release and a sync. These were also "mixed" framework files living
in the theme, which keeps updates from being a plain `composer update`. taw-gutenberg had its own,
different CI.

## Decision

1. **Shared workflows.** taw-core holds `.github/workflows/theme-ci.yml` and
   `theme-framework-sync.yml` (`on: workflow_call`).
2. **Theme stubs.** Every theme's workflows become stubs of a few lines that call them. Inputs:
   - `smoke` (WordPress smoke test);
   - `build` (front-end check/build);
   - `php-versions`.
3. **Checks by presence.** Each check runs only when the theme has what it checks, so one workflow
   serves classic and block themes.
4. **CI scripts.** They move to `resources/ci/`. The shared workflows fall back to a theme's
   `bin/ci/` while its installed taw/core predates them.
5. **The `v1` ref.** Stubs call `@v1`, a **branch** that each 1.x release fast-forwards to its tag
   (`taw-release` § 4). It's a branch, not a moving tag, because moving a tag needs a force-push.
   A major version gets `v2`.

## Trade-offs

- **Shared ref risk:** a bad shared workflow breaks every theme's CI at once. That's mitigated by
  the release flow: taw-core's CI must pass before a tag, and a broken `v1` is fixed by moving it
  back.
- **Repo must stay public:** stubs need taw-core public (it is) or same-owner access.
- **Rejected: pin each stub to an exact tag.** The stubs would change every release, which is the
  copying this removes.

## Consequences

- Fixing a check is a taw-core release, and `v1` moves with it.
- `theme-ci.yml` ↔ the stubs is a contract: inputs are added, never renamed or removed, within `v1`.
- The workflows' headers carry the by-hand commands (umbrella ADR-0004).

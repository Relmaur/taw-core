# ADR-0018: The `taw` tool ships with taw/core (`vendor/bin/taw`); outside commands use a registry

## Status

Accepted (2026-10-09), owner-approved as the first step of the platform plan's update work
(umbrella `docs/plans/taw-platform.md` § 5.0, § 6 Phase 2).

## Context

Each theme carried its own `bin/taw`, listing ~28 commands by hand. `bin/` is a Tier 1 sync path
(`rsync -a --delete`), so:

- a site couldn't add a command, because anything extra in `bin/` was deleted on sync;
- a new taw/core command reached sites only after a scaffold release *and* a sync;
- `bin/taw` was one of the "mixed" framework files copied into themes, which keep updates from
  being a plain `composer update`.

## Decision

1. **Where the list lives.** taw/core owns the command list: `TAW\CLI\Application::coreCommands()`.
   A classic theme gets every command. A block theme (`theme.json` + `templates/`) gets
   `schema:validate` and `skills:sync`, the same split its own `bin/taw` had.
2. **How it's exposed.** taw/core declares `"bin": ["bin/taw"]`, so Composer installs
   `vendor/bin/taw` in every theme. The theme folder is the project that installed taw/core
   (Composer's `_composer_autoload_path`), or `$TAW_THEME_DIR`.
3. **The theme's own `bin/taw`.** In both scaffolds it becomes a hand-over shim, so `php bin/taw …`
   keeps working. On a taw/core older than 1.90 it rebuilds the old list from the classes that
   taw/core has, because the shim can arrive by sync before `composer update taw/core`.
4. **Commands from outside core.** Extensions and sites add commands through
   `TAW\CLI\CommandRegistry::add(fn (string $themeDir) => new MyCommand(...))`, from a file Composer
   autoloads. A registered command can't take a core command's name. The extension API (platform
   plan Phase 1) will register through the same call.

## Trade-offs

- **Two entry points for a while** (`bin/taw` shim and `vendor/bin/taw`). Acceptable: the shim is
  generic and removing it is a later, migration-driven step.
- **Rejected: keep the list in the scaffold.** It needs a scaffold release per command and can't
  take outside commands.
- **Rejected: auto-discover site commands from a folder.** It's implicit and harder to audit. One
  explicit `add()` in a file the site owns keeps it visible in that site's code.

## Consequences

- A new core command lands in `Application::coreCommands()`, and `ApplicationTest` pins the count.
- taw-fleet still runs `php bin/taw …`. It moves to `vendor/bin/taw` when the shim is retired.
- `UPGRADING.md` § v1.90.0 carries the by-hand steps and the undo (umbrella ADR-0004).

# ADR-0013: Conditions for values and blocks

## Status

Accepted (2026-09-26). Plan: umbrella `docs/plans/conditions.md`. Builds on ADR-0011 (dynamic tags) and
ADR-0012 (expressions and the TAW data popup); both stand unchanged.

## Context

Dynamic tags put live values into text, but always show them. Admins want to show a value, or a whole
block, **only when** another value says so: "First published @book_year" only when the year is set, a Buy
button only when `@buy_link` isn't empty, a badge only for recent posts. ADR-0012 deferred conditionals.

## Decision

1. **A condition is JSON, not syntax.** `{"match": "all"|"any", "rules": [...]}`. A rule is
   `{"value": "@name…", "op": "…", "to": …}` or a nested group, **one level deep**, at most **20 rules**.
   It is authored with a rule builder in the TAW data popup; nobody types it.
2. **`value` is an ADR-0012 expression token** (`@book_year`, `@post.date.format('Y')`), parsed by the
   existing parser and resolved by the existing resolvers, so names, functions and privacy are the same. An
   unreadable value is empty. **`to`** is a literal (text, number, list, pair) or another token (`"@x"`;
   `"@@"` escapes a literal `@`).
3. **Operators (fixed list):** presence (`empty`, `not_empty`), text (`equals`, `not_equals`, `contains`,
   `not_contains`, `starts_with`, `ends_with`; case-insensitive, trimmed), lists (`in`, `not_in`, `has`,
   `has_not`), numbers (`gt`, `gte`, `lt`, `lte`, `between`), dates (`before`, `after`, `on`,
   `between_dates`; absolute, `today`, `now` or relative dates in the site's time zone) and yes/no
   (`is_true`, `is_false`). **No regular expressions.** Unknown operators or bad shapes make the condition
   false and are reported in the editor.
4. **Three targets:**
   - a chip: `data-taw-tag` gains `if` and optional `else` (text shown when false);
   - block text: the `taw/field` binding's args gain `if` / `else`;
   - a whole block: `metadata.tawShowIf` on any block; when false, `render_block` returns an empty
     string. It uses the block's own context, so it works per item in a Query Loop.
5. **New values:** `viewer.logged_in`, `viewer.role`, `date.today`, `date.now`, usable in conditions and
   expressions.
6. **One evaluator, server side** (`Bindings\Condition\Evaluator`, pure). The editor asks the preview
   endpoint (`kind: condition` → `{shown, errors}`); a shared fixture `tests/fixtures/conditions.json`
   keeps the editor's shape validator and PHP in agreement.

## Consequences

- Chips, bindings and blocks without a condition render exactly as before.
- Each theme's hook list gains one `render_block` line (block visibility).
- **Display, not access control:** a hidden block's content is still in the post content, and in REST for
  users who can edit it.
- **Page caches:** conditions on `viewer.*` vary per visitor; a cache that serves logged-out visitors one
  copy is fine for `viewer.logged_in` only if it bypasses logged-in users (most do). Documented.
- If taw/core is removed, chips keep their last text and blocks with `tawShowIf` always show.
- Arithmetic in expressions and conditions over repeater rows stay out of scope; repeater rows come with
  Phase 5 (`taw/loop`).

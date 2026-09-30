# ADR-0015: Expressions v2 — formulas, functions and attribute expressions

## Status

Accepted (2026-09-30). Plan: umbrella `docs/plans/expressions-v2.md` (roadmap row 7, "later"). Extends
ADR-0012 (expressions); used by ADR-0013 (conditions) and ADR-0014 (loop).

## Context

Expressions (ADR-0012) are text with `@tokens` and five `.functions()`; every value is text. There is no
arithmetic, no inline if/else, and links and image IDs only take a plain field. The owner wants all of it:
arithmetic and number formatting, an `if()`, a function library (numbers, dates, text, lists), expressions in
link and image attributes, and custom functions that themes register in PHP.

## Decision

1. **Two new token forms, nothing else changes.** `@name(args)` calls a **known** function (built in or
   registered); `@( … )` is a formula. Inside either: values (`@…` names, numbers, quoted text, `true`/`false`),
   `+ - * / %`, comparisons, `and`/`or`/`not`, parentheses and nested calls. Outside them the v1 grammar is
   unchanged: every v1 fixture case parses and renders exactly as before. An unknown name before `(` is still
   the field followed by text.
2. **`if` is a function:** `if(test, then, else)`. No ternary operator.
3. **Typed values:** text, number, boolean, date and list, converted at the edges: text that reads as a number
   is a number in arithmetic and comparisons; a list shown as text is joined with ", ". Errors (division by
   zero, wrong kind, unknown function) make that token empty and are reported with their position.
4. **One grammar, two parsers.** PHP and TypeScript produce the same tree; the shared fixture
   `tests/fixtures/expressions.json` is the specification and gains a v2 section. Evaluation happens only on
   the server; the editor previews through the preview route.
5. **Functions are data, not code paths into PHP.** A fixed registry (logic, numbers, dates, text, lists) plus
   the `taw_expression_functions` filter: `name → {args, callback, label, description}`. Argument kinds are
   checked by both parsers (the editor receives the registry without callbacks). A failing callback yields an
   empty value and logs `expression.function_failed`. Every function is callable as `@fn(x, …)` and as a method
   `x.fn(…)`.
6. **Attribute expressions:** bindings accept `expr` for link attributes (through `esc_url()` and the allowed
   protocols; anything unsafe becomes empty), image ID/URL/alt (the result must be an image value or an
   attachment the visitor may see) and alt/title text. Condition values and the loop's Order by accept any
   expression.
7. **Limits:** 1,000 characters, 50 tokens and depth 10, which also bound the work a formula can do. Locale-aware output uses
   WordPress (`number_format_i18n`, `wp_date`, `human_time_diff`).

## Consequences

- Content that contains a literal `@(` or `@if(`-style text starts meaning something. This is very unlikely in
  practice; `@@` still escapes. Recorded here and in the changelog.
- The TS parser needs the function registry (including custom functions) to validate calls; it comes with the
  editor config.
- Custom functions are theme code and run on every render; documented as the theme's responsibility for
  speed. They never run in the browser.
- More surface in `Bindings`: attribute kinds beyond text now evaluate expressions, with per-kind checks.

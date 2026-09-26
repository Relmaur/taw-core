# ADR-0012: Expressions and the TAW data popup

## Status

Accepted (2026-09-26). Plan: umbrella `docs/plans/dynamic-tags.md` (revised). Amends ADR-0011: its storage
and rendering (decisions 1–5, 7) stand; **decision 6 (typing `@` in the canvas) is replaced** by the popup
below, and the "expression language" and "tags as binding args" it deferred are now in scope.

## Context

ADR-0011 planned an `@` completer in the canvas that inserts one value per chip. Two things changed:

- WordPress's post editor already owns `@` in the canvas: core user mentions. Only one completer can own
  a trigger.
- The owner wants **expressions**, not just single values: `Published on @book_date.format('Y') by
  @post.author`, written in a dedicated editor, with the result placed either inside the text or as the
  whole block's text.

## Decision

1. **One entry point, the TAW data popup.** It opens from the block toolbar's database button ("TAW
   field", v1.61.0). It has two tabs:
   - **Fields:** every value, grouped by fieldset (e.g. "Book details › Year"), plus Post, Site, Options,
     Term and Author values.
   - **Expression:** an editor with `@` autocomplete inside it, a live preview, and inline errors.

   Both tabs end in the same two actions: **Insert at cursor**, which adds an inline chip (ADR-0011
   storage), and **Use as block text**, which binds the block's text (a `taw/field` binding).
   The canvas `@` stays WordPress's.
2. **Expression syntax (v1):** plain text with tokens.

   ```
   Published on @book_date.format('F j, Y') by @post.author · @option.company_phone.default('—')
   ```

   - `@name`:
     - a field id of the context post (`@book_year`);
     - `@post.<property|field>`;
     - `@site.<property>`;
     - `@option.<field>`, `@term.<field>`, `@author.<field>`.
   - Chained functions, always with parentheses: `format('…')` (dates), `upper()`, `lower()`,
     `default('…')`, `truncate(n)`.
   - Arguments are quoted strings or numbers.
   - A `.` not followed by a name, or an `@` not followed by one, is literal text (sentence punctuation,
     e-mail addresses). `@@` is a literal `@`.
   - Limits: 500 characters and 20 tokens.
3. **Evaluation is a small parser plus the existing resolvers.** There is no `eval` and no PHP or JS code
   execution:
   - each token resolves through `TagResolver` or `FieldResolver` (same privacy, opt-outs and context);
   - then its functions run;
   - the whole result is one line of text, escaped once.

   An unknown name, function or bad argument gives an empty token, unless `default()` is used. The editor
   flags it as an error; the front end never fails.
4. **Storage:**
   - a chip is `{"expr": "…"}` in `data-taw-tag`. Single-value chips keep ADR-0011's `{"tag"}` /
     `{"field"}`.
   - "Use as block text" is a binding on a text attribute with `args: {"expr": "…"}`. Expressions bind
     text attributes only (paragraph/heading/list-item content, button text, image alt/caption).
5. **One grammar, two parsers.** PHP evaluates; TypeScript parses only to offer autocomplete and flag errors.
   Both are tested against **one shared fixture file** of expressions and their token trees, so they can't
   drift.
6. **Previews** come from the existing preview endpoint (`kind: "expr"`), which runs the PHP evaluator.

## Consequences

- Editors can write sentences mixing text and values without code, and choose inline or whole-block.
- There's one more syntax to document and keep stable. v1 is deliberately small (five functions), and
  adding functions is additive.
- Core's `@` user mentions keep working.
- The binding source gains `expr` args. Old bindings, and ADR-0011 chips, are unchanged.
- Not in this decision: arithmetic or conditionals, loops/repeater rows (Phase 5), expressions in URL or
  ID attributes, and user-defined functions.

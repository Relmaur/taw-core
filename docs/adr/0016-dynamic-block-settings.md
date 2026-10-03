# ADR-0016: Dynamic block settings — classes, colors and HTML attributes from expressions

## Status

Accepted (2026-10-03). Plan: umbrella `docs/plans/dynamic-block-settings.md` (Phase 7d). Extends ADR-0015
(expressions v2); follows the render-time pattern of ADR-0013 (block visibility).

## Context

Expressions can fill a block's text, a button's link and an image, all through Block Bindings. WordPress binds
a fixed list of attributes, and classes, colors and HTML attributes are serialized into the saved markup:
replacing the attribute at render time doesn't change the HTML. The owner wants expressions to set classes,
colors (text, background, border) and HTML attributes on any block, and the six remaining bindable attributes
(image caption/title, button link target/rel, navigation link URL, post date) offered in the editor.

## Decision

1. **Natively bindable attributes stay bindings.** The six join the existing expression targets.
   `Bindings::expressionValue()` gains the `target` (truthy → `_blank`, else removed), `rel` (rel words only)
   and `date` (ISO 8601) kinds. Nothing new on the front end.
2. **Everything else is `metadata.tawSettings`, applied at render time.** `classes`, `color`, `background`,
   `border` and `attributes` (name → expression). A `render_block` filter (`BlockSettings`, after
   `BlockVisibility`) evaluates them in the block's own context and edits only the outer tag with the HTML tag
   processor (the button's link for colors on `core/button`). Blocks without the key return at once.
3. **Values are cleaned, never trusted.**
   - Classes: split and cleaned like `sanitize_html_class()`.
   - Colors: a palette slug → `var(--wp--preset--color--slug)`, else a CSS color from a closed grammar
     (hex, numeric `rgb/rgba/hsl/hsla`, named colors, `transparent`, `currentColor`). `var()`, `url()` and
     anything else are dropped. Core's marker classes (`has-text-color`, `has-background`,
     `has-border-color`) are added.
   - Attributes: an allow-list (`id`, `title`, `aria-label`, `aria-description`, `data-*`); every other name is
     refused, whatever the value.
   - Invalid or failed results are skipped, never rendered. An empty result changes nothing.
4. **Editing policies win.** When `color.custom` is off in the global settings (the design layer, `guided` and
   up), dynamic colors accept palette slugs only.
5. **One rule set, two languages.** The pure `Settings\Normalizer` (PHP) and `settings.ts` pass the shared
   fixture `tests/fixtures/block-settings.json`. Evaluation stays on the server: the editor previews through
   `kind: "setting"` on the preview route and applies the cleaned classes and colors to the block in the
   canvas.

## Consequences

- Any block can take dynamic classes, colors and attributes, inside TAW Loop and Query Loop items too.
- taw-theme gains one `render_block` callback (recorded in the golden snapshot).
- The values are visible in the editor only through the TAW panel. The core color controls still show the
  block's saved colors, and a dynamic color overrides them on the front end.
- Not covered: gradients, font sizes, spacing and other style properties. Each would need its own grammar
  and safety rules; a later ADR can add them the same way.

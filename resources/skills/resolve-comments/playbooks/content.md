# Playbook: content fix

Wrong, outdated, missing or extra text/data on a page — by far the most common request (about
half of all comments in practice, mostly label/value edits in cards). Production
is the source of truth; the owner approves the exact change before anything is written.

On TAW sites almost every visible text is a **TAW field**, not post content and not theme code
(verified on a live TAW site 2026-10: credit cards, step headings, CTA labels, ally logos,
contact email, WhatsApp link). So the job is: find the right field, change its value, keep its markup.

## 1. Read the request relative to what was clicked

`location_metadata.text_content` is the text of the **clicked element only**; `dom.element_tag`
says what it was. Typical shapes (real examples):

| Comment on… | Means |
|---|---|
| `<li>` "Comisiones. De 3% a 5%" + "Cambiar texto a: Comisión por apertura. Hasta 4%" | replace the whole item (label `<strong>` + value) |
| `<strong>` "Montos." + "Cambiar el texto a: «Línea de crédito» en lugar de «Montos»" | replace the label only |
| `<strong>` "Plazo." + "Agregar texto: «Plazo de pago.»" | the label becomes "Plazo de pago." |
| `<li>` "Comisión por apertura. Hasta 4%" + "Quitar «Hasta»" | remove that word only |
| `<ul>` + "Agregar un recuadro que diga: «Buró de Crédito No determinante.»" | **add** an item to that list (same markup as its siblings, at the end unless told) |
| `<li>` "Aprobación desde 24 horas…" + "Resaltar «Aprobación desde 24 horas»" | wrap that phrase in `<strong>` |
| `<p>` + "Quitar este párrafo" | remove the element |
| long text with `[Subtítulo 2]:` `[Texto]:` `[Texto tipo frase]:` `[Botón de contacto]:` | structured rewrite of a section: map each marker to the section's heading / content / CTA fields |

Keep the client's wording, accents and punctuation exactly (`Buró`, `I.V.A.`, `(m.n.)`). Labels in these
cards end with a period inside `<strong>` (`<strong>Plazo.</strong> Hasta 48 meses.`) — keep the
sibling pattern unless the client clearly writes otherwise; mention any typo in their text to the owner
instead of fixing it. Wanted text not stated exactly → clarifying question (SKILL § 4).

## 2. Find the field

1. **Which post.** `page_url` path → page by slug on production:
   `taw-fleet wp-remote <slug> GET "/wp/v2/pages?slug=<last segment>&context=edit"`. Home page
   `/` → the Local site's `taw-fleet wp <slug> option get page_on_front` (Editors can't read
   `/wp/v2/settings`), then the same slug on production. Other post types by their REST base
   (`posts` and the theme's own types: `taw-fleet wp-remote <slug> GET /wp/v2/types`).
2. **Which blocks render on that page.** Template: `page-<slug>.php`, else the page's
   `template` field, else `page.php`; home: `front-page.php`. In it,
   `BlockRegistry::render('<block_id>')` / `'<block_id>--<variation>'` lists the sections in page
   order. A variation's fields use the prefix `_taw_<variation>_` (e.g.
   `content_block--characteristics_pyme` → `_taw_characteristics_pyme_content`).
3. **Which field.** `taw-fleet inspect <slug> --json` → `blocks[].fields[]` (`meta_key`, `type`,
   `label`) for those blocks. Then, in the page's `meta` (REST, `context=edit`), the field whose
   value contains the clicked text. Use `dom.closest_parent_selector` (e.g.
   `section.bg-lightgray > div.section-container`) and section order to pick between candidates.
   **Only fields of blocks the template renders count** — pages carry stale values for
   variations they don't render (credito-pyme has `_taw_characteristics_content` *and*
   `_taw_characteristics_pyme_content` with the same text; only `_pyme` is on screen).
   Quick locator across everything (Local, after `taw-fleet pull <slug>` if it may be stale):
   `taw-fleet wp <slug> db query "SELECT p.post_name, pm.meta_key FROM wp_postmeta pm JOIN wp_posts p ON p.ID=pm.post_id WHERE pm.meta_value LIKE '%<text>%' AND p.post_type<>'revision'"`
   (and `wp_options.option_value`, `wp_posts.post_content`).
4. **Where it can't be written by the bot** → outcome **prepared** (give the owner the exact
   screen, field label and new value):
   - `_taw_*` **options** (Theme Options: contact email, WhatsApp, footer, legal links) —
     `/wp/v2/settings` needs `manage_options`, the bot is an Editor.
   - TAW fields on a **custom post type registered without `custom-fields` support**: the type
     is in REST but its `_taw_*` meta is not (a page's `meta` lacks the keys `inspect` lists).
     Follow-up for the theme: register the type with `custom-fields` support so taw/core's
     `FieldMetaRegistrar` exposes them.
   - Text found only in theme source (`grep -rnF` in the theme, excluding `vendor/`,
     `node_modules/`, `public/build/`, `.sync/`) → it's code: [`style.md`](style.md).

## 3. Change the value

- **wysiwyg / HTML fields** (`*_content`): edit the HTML minimally — same tags, classes and
  item order; for "add an item" copy a sibling `<li>` and change its text.
- **text / textarea**: plain replacement.
- **repeater** fields (`*_items`, `*_logos`): JSON array; add/remove/reorder rows keeping every
  key a row has. Image fields hold attachment IDs.

## 4. Approve, apply, verify

1. **Batch per page.** Clients leave bursts on one page (ten comments on one credit page is
   normal). Work them in `comment_number` order on one working copy of the field values (later
   comments build on earlier ones: one sets "Hasta 4%", a later one removes "Hasta"), then
   show the owner one before → after per field, listing which comment each change answers.
   `AskUserQuestion`: Apply all / Apply some / Edit / Skip.
2. Apply with one write per page: put `{"meta": {"<key>": <value>, …}}` in a scratchpad file and
   `taw-fleet wp-remote <slug> POST /wp/v2/pages/<id> --data @<file>` (post content: `content`
   raw, every block comment intact). WordPress keeps a revision; note the
   old values in the private reply so they can be restored.
3. Verify on the live page: `curl -s --http1.1 --retry 2 "https://<site><path>?nocache=$(date +%s)"`
   → each new text present, each old one gone. Outcome **applied** → close out (SKILL § 4).

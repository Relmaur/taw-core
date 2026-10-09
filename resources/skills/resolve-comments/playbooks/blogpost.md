# Playbook: blogpost

"Añadir el siguiente artículo al blog…". Result is always a **draft** on the site the comment
was left on (never the sibling site, never published).

## 1. Parse the request

Formats vary (`[Título]:` vs `Título:`; sometimes `Categoría:`; a Drive folder link) — read it,
don't regex it. Extract: title, subtítulo(s), body text, category, images (comment
`attachment_file_url` and/or Drive folder), extra carousel instructions. Missing title or body →
clarifying question (SKILL § 4).

## 2. Match the site's existing posts

Before composing, fetch the site's 3 latest posts
(`taw-fleet wp-remote <slug> GET "/wp/v2/posts?per_page=3&context=edit"`) and mirror them: block
markup, heading levels, featured-image filename pattern, how numbered points are styled. A post
with a carousel is the best reference when the request has one.

## 3. Body → blocks

- Title → post `title`. `Subtítulo` → `core/heading` H2.
- Numbered points ("1. Separación patrimonial.") → H2 with the text wrapped in `<strong>`, unless
  the site's latest posts do it differently.
- Every other paragraph → `core/paragraph`. Keep the client's wording, punctuation and accents
  exactly; fix nothing silently (typos → mention to the owner, don't change).
- Category from `Categoría:` matched by name against
  `taw-fleet wp-remote <slug> GET "/wp/v2/categories?per_page=100"`. Never fall back to
  "Uncategorized" silently. Create a category only when the comment asks for it ("hacer una nueva
  sección aquí que diga «Tesorería»") **and** the owner approves — Editors can create
  terms; no match otherwise → ask the owner.
- Older requests put the numbered points inline in `[Texto]` ("…tres ideas fundamentales: 1.
  Diversidad de contribuyentes. No todos…") — split them out into the H2 + paragraph pattern.
- SEO: `meta._taw_seo_meta_title` (≤60 chars) and `_taw_seo_meta_description` (≤155 chars),
  Spanish, from the article.
- `slug` = WordPress default from the title.

## 4. Images

Work in the scratchpad (`img/` subfolder per comment). Spec (owner): **WebP, max width 1200px,
~100 KB**; never upscale.

```bash
w=$(sips -g pixelWidth "$in" | awk '/pixelWidth/{print $2}')
resize=(); [ "$w" -gt 1200 ] && resize=(-resize 1200 0)
cwebp -quiet -metadata none "${resize[@]}" -size 100000 -pass 6 "$in" -o "$out"
```

Check the result with `sips -g pixelWidth -g pixelHeight` and its size. If an image needs a
human eye (text cut off by a crop, wrong colours, huge transparent margins), say so and offer the
owner's Affinity MCP instead of guessing.

- **Featured image** (the comment attachment, or carousel slide 0): filename per the site's
  pattern (read it off the latest posts' featured images, e.g. `<prefix>-blog-image-DD-MM-YY.webp`,
  date = today). Alt text: one Spanish
  sentence describing the image in the article's context.
- **Carousel** (Drive folder; shared with the owner's Gmail → Google Drive MCP
  `search_files` `parentId = '<folder id>'`, then `download_file_content`): files
  `imagen-blog-carrusel-{N}-{Label}.png`, ordered by **the number N parsed as an integer** —
  never by filename or Drive order (`…-10-…` sorts before `…-2-…` as text). A real "las imágenes
  del carrusel están revueltas" bug was exactly this. Check the block's `images` order
  against N before showing the owner.
  - Slide **0 ("Portada") = featured image only** (not in the carousel).
  - Slides 1…N → uploaded as `imagen-blog-{post-slug}-carrusel-{N}-{Label-ascii}.webp`
    (accents stripped, spaces → `-`), alt text each.
  - The carousel is the **first block** (directly under the post hero):
    `<!-- wp:<carousel block> {"images":[{"id":<media id>,"url":"<source_url>","alt":"…"},…],"autoplay":true} /-->`
    The block is the theme's own carousel: `grep -l carousel Blocks/*/block.json` (or the block
    the reference post uses). No carousel block → ask the owner. More carousels only where the
    text says.

Upload each: `taw-fleet wp-remote <slug> POST /wp/v2/media --file <path> --type image/webp`
(the filename is the file's), then `taw-fleet wp-remote <slug> POST /wp/v2/media/<id> --data
'{"alt_text":"…"}'`.

## 5. Approve, then create the draft

Show the owner: title, category, SEO title/description, block outline (headings + first words of
each paragraph), image list (name, px, KB). `AskUserQuestion`: Create draft / Edit / Skip.
Upload images only after approval. Then `taw-fleet wp-remote <slug> POST /wp/v2/posts --data
@<file>` with `status: "draft"`, `title`, `content`, `categories`, `featured_media`, `meta`.

Preview: `https://<site>/?p=<id>&preview=true` (owner must be logged in). Outcome **draft
created** → reply to the client ("ya está en borrador…"), private reply with the preview + edit
links; comment stays **Active** until the owner publishes, then resolve.

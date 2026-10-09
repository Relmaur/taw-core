# Playbook: media (replace or add images, PDFs, logos, galleries, videos)

Real requests: swap an infographic for the attached one, replace the privacy PDF "sent by
WhatsApp", add ally logos from a Drive folder, photo albums per event from Drive folders, add a
webinar with a YouTube link + thumbnail, change a video link.

## 1. Get the file

- **Attached to the comment** (`attachment_file_url`): download to the scratchpad.
- **Drive folder/file link**: Google Drive MCP (`search_files` `parentId = '<id>'`,
  `download_file_content`). Folders are shared with the owner's Gmail; no access → ask the owner.
- **"Te lo envié por WhatsApp / correo"**: the file isn't in BugSmash → **blocked**: ask the owner
  to drop it into the scratchpad (or attach it to the comment) and continue when they do.

## 2. Prepare it

- Images: WebP, max width 1200px, ~100 KB, never upscale (recipe in
  [`blogpost.md`](blogpost.md) § 4). Infographics with small text: check legibility after
  compression; if it suffers, use `-size 200000` or keep PNG and say so. Logos: keep transparency
  (WebP keeps alpha; never flatten onto white).
- PDFs: upload as-is; name like the file it replaces (`aviso-de-privacidad-…`).
- Filenames: lowercase ASCII, hyphens, descriptive (match the site's existing pattern for the same
  kind of file). Alt text: one Spanish sentence (logos: the company name).

## 3. Place it

Find what currently shows the old file (the clicked `<img>`/`<a>`): its `src`/`href` →
attachment (`taw-fleet wp-remote <slug> GET "/wp/v2/media?search=<filename>"`), then the field holding that attachment
ID (same locator as [`content.md`](content.md) § 2, searching for the ID or URL).

- Upload: `taw-fleet wp-remote <slug> POST /wp/v2/media --file <path> --type <mime>`, then
  `taw-fleet wp-remote <slug> POST /wp/v2/media/<id> --data '{"alt_text":"…"}'`.
- Point the field at the new ID (page meta via REST). **Never delete the old attachment** — leave
  it in the library; mention it to the owner.
- Logos into a repeater (e.g. `_taw_allies_logos`): append rows, same keys as existing rows.
- Fields on a post type whose TAW meta isn't in REST (content.md § 2.4) → **prepared**: upload the
  images (thumbnail, gallery photos) so they're in the library, then give the owner the post,
  field and values to set.

## 4. Approve and verify

Show the owner the new file(s) (path, px, KB) next to the old one and where it goes;
`AskUserQuestion` before uploading. After applying, load the live page (`?nocache=<ts>`) and check
the new `src`/`href` is served. Outcome **applied** / **prepared** / **blocked**.

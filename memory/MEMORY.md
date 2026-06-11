# taw-core Memory Index

- [form-registration-timing.md](form-registration-timing.md) — Forms must register in `boot()` not templates; `admin-ajax.php` never runs theme templates so handlers won't exist on submission
- [repeater-json-storage.md](repeater-json-storage.md) — Repeater/files store JSON strings (not serialize); `wp_unslash()` corrupts PHP serialized backslashes
- [vite-manifest-cache.md](vite-manifest-cache.md) — Vite manifest cached 24h in-process; stale prod assets after deploy = restart PHP-FPM, not a ViteLoader bug
- [404-false-post-id.md](404-false-post-id.md) — `getData(int|false $postId)`: `false` is a real value on 404/archive pages; all meta helpers must handle it
- [metabox-screens-resolution.md](metabox-screens-resolution.md) — Three screen-matching modes; `front-page.php`/`home.php` never write template meta and need special-case handling; post type name beats slug
- [visual-editor-repeater-limitation.md](visual-editor-repeater-limitation.md) — Repeater sub-fields intentionally excluded from visual editor field registry; requires row-index-aware UI not yet built
- [form-email-before-save.md](form-email-before-save.md) — Email sent before submission saved; failed email = no CPT record; fix by reordering if guaranteed persistence is needed

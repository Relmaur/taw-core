---
name: metabox-screens-resolution
description: How Metabox resolves screens — three distinct matching modes, two of which bypass _wp_page_template meta entirely
metadata:
  type: project
---

`Metabox::parseScreens()` splits the `screens` array into three buckets with different matching logic:

1. **Post types** — anything where `post_type_exists()` is true. Registered via `add_meta_box()` normally.
2. **Template files** — strings ending in `.php`. Matched against `_wp_page_template` post meta — but with two special cases:
   - `front-page.php` — WordPress never writes `_wp_page_template` for the static front page. Matched by comparing `$post->ID === get_option('page_on_front')`.
   - `home.php` — Same: no meta written. Matched via `get_option('page_for_posts')`.
   - `page-{slug}.php` — WordPress auto-applies these via template hierarchy with no meta. Matched by regex against `$post->post_name`.
3. **Slugs** — everything else, matched against `$post->post_name`.

**Why:** WordPress's template hierarchy applies `front-page.php`, `home.php`, and `page-{slug}.php` without calling `update_post_meta`, so there is nothing to read from `_wp_page_template` for these templates.

**How to apply:** If adding a new screen-matching mode (e.g., by taxonomy, by parent post), do not assume all template matching goes through `_wp_page_template`. Any WP hierarchy template that auto-applies without user selection needs its own explicit check. Also: a slug that matches a registered post type name is categorised as a post type, not a slug — `post_type_exists()` wins.

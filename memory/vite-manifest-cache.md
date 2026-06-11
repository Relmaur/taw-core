---
name: vite-manifest-cache
description: ViteLoader caches the Vite manifest for 24 hours in-process — relevant when debugging stale prod assets
metadata:
  type: project
---

`ViteLoader` reads `dist/.vite/manifest.json` once per process and caches the result for 24 hours. Dev mode is detected via a socket connection to `localhost:5173` on each request (cheap; no manifest read).

**Why:** Manifest parsing on every request would be an avoidable filesystem hit in production. The 24-hour TTL matches typical deploy cadence and keeps the cache warm across requests.

**How to apply:** If someone reports that updated assets aren't loading in production after a deploy, the cached manifest is the likely cause. The fix is to restart PHP-FPM/the web server (which clears the in-process cache), not to change ViteLoader code. This is not a bug.

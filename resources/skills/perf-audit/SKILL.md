---
name: perf-audit
owner: taw
description: Use when iterating on a live site's performance/SEO health using GTMetrix + PageSpeed Insights — running a baseline check, applying a fix, and re-checking to see if it moved the numbers. Triggers on: "check GTMetrix", "run a perf audit", "how's the site performing", "PageSpeed score", "optimization report", "post-launch report", "Core Web Vitals".
---

# Perf Audit — GTMetrix + PageSpeed iteration loop, for this TAW site

A dev-workflow tool, not a client-facing feature: it changes nothing on the site by itself.
The point is a fast loop: baseline → change something → re-check → see if the number actually
moved, grounded in what this framework can actually influence (not generic "optimize images"
advice with no path back to a real fix).

## 0. Prerequisites (the user's to manage, not this skill's)

- **GTMetrix**: a GTMetrix MCP server must already be configured (`gtmetrix.com/mcp`). Confirm
  with `ToolSearch` (query `"gtmetrix"`) before assuming it's available — if nothing comes back,
  say so and stop; don't fall back to scraping gtmetrix.com or guessing at an API shape.
- **PageSpeed Insights**: no MCP for this — call Google's REST API directly. Check for a
  `GOOGLE_PSI_API_KEY` environment variable first (`echo $GOOGLE_PSI_API_KEY`). A key is
  effectively required now — confirmed empirically (2026-07-31): calling unauthenticated returns
  HTTP 429, `defaultPerDayPerProject` quota is `0` for unauthenticated callers, not just "lower."
  If no key is set, tell the user to get a free one (Cloud Console → enable "PageSpeed Insights
  API" → Credentials → API key) and set it as `GOOGLE_PSI_API_KEY` in their shell profile — don't
  attempt the unauthenticated call and expect it to work.
  - Gotcha: a freshly-`export`ed var in `~/.zshrc` won't necessarily show up in your own next
    shell invocation — non-interactive shells may only source `~/.zshenv`. If `echo
    $GOOGLE_PSI_API_KEY` comes back empty right after the user says they set it, try
    `source ~/.zshrc` inline in the same command before concluding it's missing.
- Never hardcode either credential in this file, in a script, or in any repo. They're
  session/environment-level, not project config.

## 1. Get the target URL

This site's URLs come from taw-fleet (`taw-fleet list --json` → the site whose theme
`real_path` is this folder; `taw-fleet show <slug> --json` → `production.url` and the Local
`url`). When taw-fleet started you, the first message has them. Confirm which one to audit
(via `AskUserQuestion` if not already given in the invocation) — this tool runs
against Local/staging/production alike, and GTMetrix/PSI both need a publicly reachable URL
(a bare `.local` Local by Flywheel site won't work; use its public tunnel or a staging/prod
domain).

**Exception**: if the target really is a bare `.local` site with no tunnel, Chrome DevTools MCP's
own `performance_start_trace` + `performance_analyze_insight` and `lighthouse_audit` tools can
run directly against it — no public-URL requirement, since it's driving a local Chrome instance
rather than calling a remote scanning service. This is a smaller, local-only substitute for
GTMetrix/PSI's step 2 (no cross-site grade, no CrUX field data), useful for pre-launch iteration
before a public URL exists. Ask before driving an automated browser against a site.

## 2. Run the baseline

**GTMetrix** (via its MCP tool — inspect whatever `ToolSearch` surfaces for the exact call
shape, since this skill was written without being able to load `gtmetrix.com/mcp`'s own docs
directly): kick off a test for the URL and pull the report. Capture:
- Overall GTMetrix grade/score, Performance score, Structure score
- Largest Contentful Paint (LCP), Total Blocking Time (TBT), Cumulative Layout Shift (CLS)
- Fully loaded time, total page size, total requests

**PageSpeed Insights** — run for **both** `strategy=mobile` and `strategy=desktop`, each with
`category=performance&category=accessibility&category=best-practices&category=seo`:

```
https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url={url}&strategy=mobile&category=performance&category=accessibility&category=best-practices&category=seo[&key={GOOGLE_PSI_API_KEY}]
```

Capture per strategy: Lighthouse category scores, Core Web Vitals **field data** (CrUX — real
Chrome-user data, only present if the URL gets enough traffic; a brand-new site will only have
**lab data**, which is fine, just say so rather than treating a missing field-data block as an
error) and **lab data**, plus the top 3–5 "opportunities"/diagnostics by potential savings.

## 3. Ground findings in what this framework can actually fix

This is the actual value of doing this through you instead of pasting a report link into an
email — don't just relay Lighthouse's generic copy. For each flagged opportunity, check whether
it maps to something in this codebase:

- **Unoptimized/oversized images** → is `TAW\Helpers\Image` (WebP conversion, lazy-loading)
  actually wired up in the templates rendering this page? If not, that's a concrete, in-framework
  fix, not a vague "compress your images."
- **Render-blocking JS/CSS** → check `ViteLoader`'s mode (dev vs. built/production manifest) and
  whether `Support/performance.php`'s asset hints are in play.
- **SEO category findings** → cross-check against `TAW\Core\Seo\SeoMeta`/`Schema`'s actual
  output on the live page (fetch the page's rendered `<head>` and confirm meta/OG tags and
  JSON-LD are really there) rather than treating Lighthouse's SEO score as a black box. This
  covers the "SEO" leg without needing a paid rank-tracker — real keyword rank tracking is a
  separate, ongoing-subscription tool this skill deliberately doesn't attempt.
- **Caching/TTFB/server response time** → usually hosting-level, not framework-level; say so
  plainly rather than implying taw-core can fix it.

## 4. Report the baseline

Chat/terminal output, not an HTML artifact — this is mid-iteration, terse and scannable beats
polished. Format: GTMetrix score, PSI mobile + desktop scores, Core Web Vitals against Google's
pass/fail thresholds (LCP ≤2.5s good, CLS ≤0.1 good, INP ≤200ms good), then a ranked list of
concrete next actions from step 3, each tied to a real file/mechanism in this codebase.

## 5. Offer a hand-off prompt for the implementing agent

The findings from step 3 often need to be fixed in a different repo/session than this one —
the client's own theme repo, not necessarily this session. Right after reporting the baseline,
ask via `AskUserQuestion` whether the user wants a ready-to-paste, self-contained prompt for
whichever agent will actually implement the fixes (their own Claude Code session against the
client repo, a teammate, etc.) — don't assume yes, but don't wait to be asked either.

If yes, produce a single prompt block (not a to-do list for yourself — literal text the user
copies elsewhere) that stands on its own with zero shared context from this conversation:

- Every finding restated with everything already gathered so the implementing agent doesn't
  re-run the audit or re-derive root cause: exact file path + line number where found, the exact
  selector/CSS values/contrast ratio/wastedBytes-wastedMs numbers, the exact broken URL — data,
  not a paraphrase.
- Explicit scope: fix only what's listed, nothing else — no drive-by refactors, no "while I'm in
  here" cleanup.
- Framework context the implementing agent needs but has no way to already know — e.g. "other
  images on this page use `TAW\Helpers\Image`'s responsive srcset; this one doesn't, check why"
  — phrased as something to verify, not asserted as fact, since the hand-off agent is working in
  a different repo/session and may find the actual cause differs.
- An instruction to verify each fix before calling it done (re-check the contrast ratio actually
  clears 4.5:1, confirm the URL now resolves, etc.) rather than assuming the obvious edit worked.

## 6. Iterate

After a fix is applied, re-run steps 2–3 against the same URL and diff against the numbers from
this same conversation (no need to persist across sessions — this is a within-session loop).
Don't over-index on small deltas run-to-run — server load, CDN cache state, and network jitter
all move these numbers a little even with no code change.

## 7. Optional: client hand-off report

Only when the user explicitly asks to wrap up (e.g. "give me something for the client") —
compile the session's before/after numbers into a polished, self-contained HTML page via the
`Artifact` tool (load the `artifact-design` skill first, per its own rules). This is the
original "post-launch report" idea, now the closing step of an iteration loop rather than the
whole point of the skill.

## Gotchas

- GTMetrix test runs are metered by plan tier — don't re-run tests casually in a tight loop;
  ask before burning another test credit if you've already run one recently for the same URL.
- A missing CrUX field-data block isn't a bug — it means the URL doesn't have enough real-user
  Chrome traffic yet, common for new/low-traffic sites.
- If the GTMetrix MCP tools aren't found via `ToolSearch`, stop and say so — don't silently
  degrade to PSI-only and present it as a full audit.
- **A wildly high desktop CLS from PSI's API that GTMetrix and mobile both disagree with may be
  a lab-measurement artifact, not a real bug — don't chase it with more code fixes past the
  second attempt.** Signature to watch for: the same element/bounding-box shifts every PSI
  desktop run regardless of what was fixed, but Lighthouse's attributed "cause" (which resource
  it blames) changes between runs in a way that doesn't track what was actually changed —
  confirmed once via a Puppeteer+CDP trace with `PerformanceObserver('layout-shift')`: heavy
  CPU throttling can starve an element's first paint until very late (e.g. ~5.9s in), and the
  Layout Instability API scores that first paint as a jump from an empty rect to its real one —
  a "shift from nothing" even though nothing already-visible ever moved. Real network throttling
  reproduces this too (`lighthouse --throttling-method=devtools`), so "simulated vs. real
  throttling" isn't the distinguishing factor — heavy-throttle-driven late compositing is.
  Diagnostic: if two fix rounds haven't moved the PSI-desktop number at all despite confirmed
  correct code changes (verify by diffing the *raw*, unrounded CLS value across runs — a
  bit-for-bit identical score across runs with real changes deployed in between is a strong
  tell), stop iterating on code and instead verify with a real trace
  (`PerformanceObserver('layout-shift')` + timestamped screenshots, or DevTools' "Layout Shift
  Regions" rendering flag) before proposing another fix. If the real trace shows the element's
  computed geometry was correct and stable the whole time and nothing visible actually moved,
  report the PSI number as an artifact rather than continuing to chase it.

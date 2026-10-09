# ADR-0017: Hard spend ceiling, human check and request caps for the public chat endpoint

## Status

Accepted (2026-10-09).

## Context

`POST taw/v1/chat` (v1.27+, opt-in since v1.30) is public and anonymous by default, and every message
can call a paid LLM API several times (tool rounds). Before the fsspx-taw site put a paid key behind
it, an audit found nothing that bounded the bill:

- **Spoofable rate limiting.** The only defence was 20 messages per 10 minutes per IP, and
  `SubmissionsHandler::getUserIp()` read `X-Forwarded-For` first. A bot that sends a new value on
  every request is a new visitor every time. Forms, PagePassword and the corpus endpoints share the
  helper, so they share the hole.
- **No metering.** `LlmClient` discarded the `usage` block of every response, so no budget was
  possible.
- **No output cap.** No `max_tokens` was sent, and history (10 turns) and tool results were
  unbounded, so one request's cost had no ceiling.
- **Off-topic use.** The system prompt ended "Answer normally for anything else". That made a parish
  website widget a free general-purpose assistant on the owner's bill.
- **No human check**, though Turnstile already existed for forms.

Constraints: managed hosting without `pdo_sqlite` (ADR-0002), so any new storage must use MySQL;
page caching (Hummingbird) serves stale WordPress nonces; the only site that enables the chatbot is
fsspx-taw, whose widget is not rendered yet.

## Decision

1. **Trustworthy client IP.** `Security\ClientIp::get()` returns `REMOTE_ADDR`. Only when that peer
   is a trusted proxy does it read `CF-Connecting-IP`, then `X-Forwarded-For` from the right (the
   first hop that isn't itself trusted), then `X-Real-IP`. Private and loopback ranges are trusted
   by default, since such a peer can only be the site's own infrastructure. Public proxies are
   declared with `TAW_TRUSTED_PROXIES` or the `taw_trusted_proxies` filter.
   `SubmissionsHandler::getUserIp()` keeps its signature and delegates, so all nine callers are fixed.
2. **Metering at the one choke point.** `LlmClient::request()` records every successful call's
   `usage` with `Rag\Usage\UsageMeter::record()`: an atomic `INSERT … ON DUPLICATE KEY UPDATE` into
   `{prefix}taw_rag_usage`, one row per (site-local day, kind, model). Cost is priced from settings
   (USD per 1M tokens) and stored in integer micro-dollars, where 1 token costs exactly `price` micro-dollars.
3. **Hard budget, enforced before spending.** Daily and monthly budgets (defaults $1 and $10). A
   request is refused (`503 budget_exhausted`) when spend plus the request's *worst-case* cost would
   cross either limit. The worst case is computed by `Guard\ChatLimits::worstCaseCostMicros()` from
   the same constants that enforce each cap, so the reserve can't drift from the caps. An unreadable
   ledger fails closed. Alert emails are sent once per period: 80% of the month, and "paused" when
   a limit blocks.
4. **Per-request caps.** Output tokens (600; `max_completion_tokens` for api.openai.com, else
   `max_tokens`), message length (1000 chars), history (6 turns × 1500 chars, server side), tool
   results (6000 chars), tool calls per model reply (3), tool rounds (3).
5. **Throttles.** Per visitor 10 per 10 min and 60 per day, then site-wide 30 per minute. Per-visitor
   checks run first, so one visitor over their limit can't exhaust the site-wide capacity.
6. **Human check once per conversation.** `POST taw/v1/chat/session` trades a Turnstile token for a
   signed session (`Guard\ChatSession`: HMAC-SHA256 with `wp_salt('auth')`, 30 minutes, 30 messages,
   counter in a transient). `/chat` requires it in `X-TAW-Chat-Session` while the mode is
   `turnstile` (the default). Turnstile mode without keys **fails closed** (`503 not_protected`).
   Switching protection off is an explicit admin choice.
7. **Scoped assistant.** The default prompt declines unrelated tasks. Sites describe their subject
   in a setting, and `taw_rag_system_prompt` replaces the prompt wholesale.
8. **Kill switch.** The `TAW_RAG_CHAT_DISABLED` constant, needing no database, or a setting →
   `503 paused`.
9. **Visibility.** A read-only TAW Chatbot → Usage screen (spend, the worst case, active protections,
   the IP as the server resolves it), admin notices, `bin/taw rag:usage`, and one `rag.chat` log line
   per answer. The log line holds tool names and counts only, never the message, the answer or the IP.
10. Refusals carry a stable `code` (`paused`, `not_protected`, `session_required`,
    `human_check_failed`, `rate_limited` + `retry_after`/`Retry-After`, `budget_exhausted`) for
    widgets to localize.

## Trade-offs

- **Concurrency overshoot.** Simultaneous requests each pass the budget pre-check before either
  records its usage, so the ceiling can be exceeded by (in-flight requests × worst case). A lock per
  request would serialize the chat. The site-wide throttle bounds in-flight requests, so the
  overshoot is cents, not dollars. The same applies to the session message counter.
- **Estimated, not billed, cost.** Prices are typed in by the admin and tokens come from the
  provider's report, so a price change upstream makes the meter drift. The provider's dashboard
  stays the bill of record, as the Usage screen says, and a provider-side budget is still
  recommended.
- **The worst-case reserve is conservative**, at about 3 characters per token and every cap maxed
  together: about $0.01 per message at the defaults. The last cent of a budget may go unspent;
  overspending is impossible.
- **A REST contract change in a minor release** (v1.81.0). `/chat` now needs a session by default
  and caps messages at 1000 characters. Accepted by the owner: the route is opt-in and its only
  consumer (fsspx-taw) is updated in lockstep. UPGRADING.md lists it.
- Rejected: **WordPress nonces** (stale behind page caching); **binding sessions to IP** (breaks
  mobile visitors, and per-IP limits already apply per message); **a token-count budget instead of
  dollars** (owners think in dollars); **blocking embeddings on budget** (indexing is admin- or
  publish-triggered and costs fractions of a cent).

## Consequences

- New: `Security\ClientIp`, `Rag\Usage\{UsageMeter, UsageSchema, UsageAdminScreen}`,
  `Rag\Guard\{ChatLimits, ChatSession}`, `CLI\RagUsageCommand` (the theme's `bin/taw` must register
  it), `POST taw/v1/chat/session`, the `{prefix}taw_rag_usage` table (rollback:
  `bin/taw rag:usage --uninstall`), and new settings tabs (Budget, Limits, Access).
- New filters: `taw_trusted_proxies`, `taw_rag_budget_alert_recipients`, `taw_rag_system_prompt`.
  New constants: `TAW_TRUSTED_PROXIES`, `TAW_RAG_CHAT_DISABLED`.
- `window.TAWTurnstile.render(el, options)` forwards callbacks and appearance options and gains
  `reset(el)`; existing form calls are unchanged.
- Sites behind a public proxy (Cloudflare) must declare it, or every visitor shares one rate-limit
  bucket. The Usage screen's "Your address" table is the check.
- Widgets must handle `401 session_required` (run Turnstile, then retry) and map refusal codes to
  their own copy.
- Not done (YAGNI): per-model price tables, budget alerts at other thresholds, a
  provider-reconciliation job, and a persistent per-session store.

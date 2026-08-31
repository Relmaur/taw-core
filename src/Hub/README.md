# TAW\Hub — Management Hub integration

`taw-core` acting as a **headless, authenticated client** of a central Laravel/TALL
"Management Hub" that orchestrates many TAW sites (telemetry, asset deployment, block
config sync, cache invalidation). This namespace is the receiver side only.

**Status: Phase 1 in progress.** `Security/` is implemented and unit-tested. Nothing is
wired into `Theme::boot()` yet — the integration is inert until that lands (Phase 4).

---

## Design decisions (do not silently reverse these)

1. **No arbitrary command execution over REST.** The brief asked for the Hub to have
   "parity with a terminal user" via a WP-CLI bridge. A literal reading (an endpoint that
   shells out to `wp …`) is a signed webshell — one Hub-key compromise = RCE on every
   managed site, and `TAW\CLI\WpCliCommand` forwards its args *unparsed*. Instead: both the
   REST API and the new CLI commands are thin adapters over a **fixed `Orchestration\ActionRegistry`**
   of named, typed actions (`sync-blocks`, `deploy-assets`, `flush-caches`, …). No
   `symfony/process`, no shell string, ever, on the request path. Parity means "same TAW
   operations", not "same shell".

2. **Verifier is WordPress-free.** `HmacRequestVerifier` takes an `InboundRequest` DTO, a
   `KeyRing`, a `NonceStore`, and an injected clock — no `WP_REST_Request`, no `time()`,
   no globals. `InboundRequest::fromRestRequest()` is the only WP-coupled seam and it only
   copies fields. This keeps the crypto fully unit-testable with the Brain Monkey suite
   (no real WP install), consistent with how `Turnstile` splits network from logic.

3. **Keys live in `wp-config.php`, not the database.** `KeyRing::fromEnvironment()` reads
   the `TAW_HUB_KEYS` constant (JSON). Secrets never touch `wp_options`. A single malformed
   key entry is skipped, never fatal.

4. **Opt-in, fails closed.** Mirrors `Lucide` / `MediaFolders` / headless `Cors`. Until a
   `TAW_HUB_ENABLED` constant (or `HubIntegration::enable()`) is set, routes won't even be
   registered. Every verification failure throws and yields no identity.

5. **Reason codes are for logs, not clients.** `VerificationException::reason()` is a stable
   slug for the audit log / metrics. The REST middleware (Phase 3) collapses every reason
   into one generic `401` so a prober can't learn which check it tripped.

---

## The signature scheme — `v1` (wire contract with the Hub)

Request headers:

| Header | Value |
|---|---|
| `X-TAW-Hub-Scheme` | `hmac-sha256` (optional; `ed25519` reserved, not yet implemented) |
| `X-TAW-Hub-Key-Id` | key id, must exist in `TAW_HUB_KEYS` |
| `X-TAW-Hub-Timestamp` | unix seconds; `\|now − ts\|` must be ≤ 60s |
| `X-TAW-Hub-Nonce` | `[A-Za-z0-9_-]{16,128}`, single-use within the window |
| `X-TAW-Hub-Signature` | lower/upper-hex HMAC-SHA256 (64 chars) of the canonical string |

Canonical string (`CanonicalRequest::bytes()`) — **frozen**, changing it = a scheme-version bump:

```
v1\n
{UPPERCASE METHOD}\n
{REST route, e.g. /taw-hub/v1/assets/deploy}\n
{lowercase hex sha256 of the raw request body}\n
{unix timestamp}\n
{nonce}
```

Only the route path is signed — not the query string. Every mutating endpoint takes its
input in the signed body.

Replay window: acceptance window is 2× drift = 120s wide, so `TransientNonceStore` TTL
defaults to 150s (must stay ≥ 120).

`wp-config.php` example:

```php
define('TAW_HUB_KEYS', json_encode([
    'hub-prod' => [
        'secret'       => '…64+ random hex chars…',
        'capabilities' => ['hub:read', 'hub:deploy', 'hub:config', 'hub:maintenance'],
    ],
]));
```

Capabilities are coarse action groups, not WP caps. `*` = break-glass, grants everything.

---

## Roadmap

| Phase | Scope | State |
|---|---|---|
| 1 | `Security/` — HMAC verify, canonical string, key ring, nonce store | **done, tested** |
| 2 | `Security/Ed25519Verifier` + `EnrolmentService` (asymmetric handshake / TOFU) | not started |
| 2 | `Security/HubAuthMiddleware` — `permission_callback` factory, capability gating, audit hook | not started |
| 3 | `Api/` — `taw-hub/v1` routes; `Telemetry/` collectors; `Assets/` (zip-slip-safe extract + atomic swap + rollback); `Orchestration/` action registry + audit log | not started |
| 4 | `HubIntegration::enable()/init()`, wired into `Theme::boot()` as subsystem #12; CLI commands (`wp taw sync-blocks`, `wp taw deploy-assets`, `taw hub:enrol`) | not started |

Full endpoint map and module tree: see the Phase 1 architecture notes (also mirrored into
taw-docs when Phase 3 lands).

---

## Files in `Security/`

- `Contracts/RequestVerifier`, `Contracts/NonceStore` — the two seams
- `InboundRequest` — framework-agnostic request DTO (`::fromRestRequest()` adapter)
- `CanonicalRequest` — the frozen signing-string builder
- `HmacRequestVerifier` — checks run cheapest-first, nonce spent only after signature passes
- `KeyRing` / `HubKey` — trusted credentials from `TAW_HUB_KEYS`
- `HubIdentity` — resolved caller + `can(capability)`
- `TransientNonceStore` — replay cache over WP transients
- `VerificationException` — carries a stable `reason()` slug

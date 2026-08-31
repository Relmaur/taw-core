# TAW\Hub — Management Hub integration

`taw-core` acting as a **headless, authenticated client** of a central Laravel/TALL
"Management Hub" that orchestrates many TAW sites (telemetry, asset deployment, block
config sync, cache invalidation). This namespace is the receiver side only.

**Status: Phase 3 complete.** The whole receiver — `Security/`, `Telemetry/`, `Assets/`,
`Orchestration/`, and every `taw-hub/v1` route — is implemented and unit-tested (247
tests). Nothing is wired into `Theme::boot()` yet: `HubRoutes::register()` and
`HubServices::boot()` exist but aren't called. Phase 4 flips the switch.

### Routes (`wp-json/taw-hub/v1/`)

| Route | Method | Route guard | Notes |
|---|---|---|---|
| `/health` | GET | `hub:read` | environment report |
| `/telemetry/{environment\|blocks\|assets\|full}` | GET | `hub:read` | |
| `/handshake` | POST | — (enrolment token in body) | single-use pairing |
| `/command/actions` | GET | `hub:read` | the allow-list + each action's capability |
| `/command` `{action,args}` | POST | `hub:read` route guard, **then the action's own capability** | the one dispatch path |
| `/cache/flush` `{scopes[]}` | POST | `hub:maintenance` | → `flush-caches` action |
| `/config/blocks` `{config,mode,expected_version}` | PUT | `hub:config` | → `sync-blocks` action |
| `/audit` `?limit=&since=` | GET | `hub:read` | |

Asset deploy/rollback go through `/command` (`deploy-assets` / `rollback-assets`) so
there is exactly one dispatch + audit path. **There is no arbitrary-exec action** —
`ActionRegistry` is the complete list (`report-telemetry`, `flush-caches`, `sync-blocks`,
`deploy-assets`, `rollback-assets`), each gated by its own `hub:*` capability, re-checked
in `CommandDispatcher` against the specific action even after the route guard passed.

### Asset deployment safety (`Assets/`)

`PayloadExtractor` never calls `ZipArchive::extractTo()`. Every entry is checked: no `..`
/ absolute / backslash / NUL, resolved path must stay inside the destination, no symlink
entries, extension must be on the allow-list (`js css map json svg png … woff2 …` — **no
`php`**), and per-file / total / compression-ratio limits ({@see Assets\ExtractionLimits},
defaults 25 MB / 150 MB / 200×). `DeploymentTransaction` stages into a dot-prefixed sibling
of the build dir (same filesystem → `rename()` is atomic), validates the Vite manifest
against the extracted file set, then swaps: live build → `.taw-hub-rollback-<id>`, staging
→ live. One rollback generation is kept.

### Enrolment / `wp-config.php`

```php
define('TAW_HUB_ENABLED', true);
define('TAW_HUB_ENROLMENT_TOKEN', '…one-time random string…');  // burned after first handshake
define('TAW_HUB_SECRET', '…32+ random bytes…');                 // seals the site's own key (else SECURE_AUTH_KEY)
// TAW_HUB_KEYS is optional once enrolment is used — enrolled keys live in the
// taw_hub_enrolled_keys option (Ed25519 public keys only, no secrets).
```

`POST /handshake` `{ enrolment_token, hub_public_key (base64), requested_capabilities[] }`
→ `{ key_id, site_public_key, accepted_capabilities[] }`. Single-use; capabilities are
intersected with `hub:read|deploy|config|maintenance` (`*` is never grantable by handshake).

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
| 2 | `Security/` — Ed25519 verifier, scheme router, auth middleware + audit sink | **done, tested** |
| 3a | `HubConfig` (opt-in flag + drift tunable); `Telemetry/` — `EnvironmentReport`, `BlockInventory`, `AssetInventory`, `TelemetrySnapshot` | **done, tested** |
| 3b | `Security/EnrolmentService` + KeyStore/SiteSigner/EnrolmentLedger seams; `Api/` — `RestRequestAdapter`, `HubRoutes` (`/health`, `/telemetry/*`, `/handshake`) | **done, tested** |
| 3c | `Assets/` — `PayloadExtractor` (per-entry: traversal / symlink / ext-allowlist / size + zip-bomb limits), `ViteManifestValidator`, `DeploymentTransaction` (stage → validate → atomic rename swap → keep 1 rollback) | **done, tested** |
| 3d | `Orchestration/` — `Contracts/Action`, `ActionRegistry` (the allow-list), `Actions/*`, `AuditLog` + `AuditStore` (wpdb / array) + `AuditSchema`; `Api/CommandDispatcher`; routes `/command`, `/command/actions`, `/cache/flush`, `/config/blocks`, `/audit` | **done, tested** |
| 4 | `HubIntegration::enable()/init()` + `HubServices::boot()` wired into `Theme::boot()` as subsystem #12; activation hook → `AuditSchema::ensureTable()`; CLI commands (`wp taw sync-blocks`, `wp taw deploy-assets`, `taw hub:enrol`) | not started |

Full endpoint map and module tree: see the Phase 1 architecture notes (also mirrored into
taw-docs when Phase 3 lands).

### Signature encoding by scheme

| Scheme (`X-TAW-Hub-Scheme`) | `X-TAW-Hub-Signature` | Enrolled material (`TAW_HUB_KEYS`) |
|---|---|---|
| `hmac-sha256` (default) | 64-char hex of HMAC-SHA256 | `secret` |
| `ed25519` | base64 (standard or URL-safe) of the 64-byte detached signature | `public_key` (base64, 32 bytes) |

A key may carry `secret`, `public_key`, or both. `SchemeRouter` picks the verifier from the
header; each verifier also re-checks the scheme (defense in depth).

---

## Files in `Security/`

- `Contracts/RequestVerifier`, `Contracts/NonceStore`, `Contracts/AuditSink` — the seams
- `InboundRequest` — framework-agnostic request DTO (`::fromRestRequest()` adapter)
- `CanonicalRequest` — the frozen signing-string builder
- `SignaturePreflight` — every non-crypto check, shared by both verifiers; nonce spent via `spend()`
- `HmacRequestVerifier` / `Ed25519Verifier` — the crypto step + nonce burn only
- `SchemeRouter` — dispatches to a verifier by `X-TAW-Hub-Scheme` (`::standard()` wires both)
- `KeyRing` / `HubKey` — trusted credentials from `TAW_HUB_KEYS` (`secret` and/or `public_key`)
- `HubIdentity` — resolved caller + `can(capability)`
- `HubAuthMiddleware` — enabled-check → verify → capability gate → audit; returns `AuthOutcome`
- `AuthOutcome` — maps to 200 / 404 (disabled) / 401 / 403 for the REST layer
- `ErrorLogAuditSink` — default `[TAW Hub]` error-log audit; Phase 3 adds a persistent one
- `TransientNonceStore` — replay cache over WP transients
- `VerificationException` — carries a stable `reason()` slug

# 11 — API & Mobile Readiness

**Verified:** 2026-08-25

The stated goal is *"heavily for API… proper API support for mobile as well."*
This file scores the current API surface against what a production mobile back end needs.

---

## What is already right

Do not rebuild these — they are better than most hand-rolled PHP APIs.

| Capability | Where |
|---|---|
| Two separate API surfaces: token-only external, cookie+CSRF internal | `framework.middleware_groups.api.external.auth` vs `api.app` |
| Versioned prefix resolved from config, never hardcoded | `app/config/api.php` → `/api/v1`, consumed by `app/routes/api.php` |
| CORS defaults **fail-secure** — empty origin list, `allow_credentials=false`, wildcard-with-auth explicitly rejected | `app/config/api.php` |
| `api.auth.methods` defaults to `['token']` only | least privilege by default |
| 8 auth mechanisms available (session, token, JWT, api_key, oauth, oauth2, basic, digest) | `Components\Auth` |
| Token abilities (scopes) with `ability:` middleware | `RequireAbility` |
| Named rate limiters, three-tier atomic backend (APCu → Redis → file) | `framework.rate_limiters`, `App\Http\Middleware\RateLimit` |
| Request hardening: URI/body/header/input-var/JSON-field/multipart caps | `security.request_hardening` |
| Content-type enforcement per profile | `EnforceContentType` + `framework.content_type_profiles` |
| Structured API request logging | `ApiRequestLogger` |
| Login timing normalisation (`timing.normalize:100`) | blocks timing-based user enumeration |
| `X-RateLimit-Limit` / `X-RateLimit-Remaining` / `Retry-After` + `retry_after` in the 429 body | `RateLimit.php:183-193` |
| IP blocklist, trusted-proxy CIDR resolution, request fingerprinting | `BlocklistIp`, `ValidateTrustedProxies`, `AttachRequestFingerprint` |
| Consistent envelope `{code, message, data|errors}` | `Core\Http\Controller` + `custom_api_helper.php` |
| Cursor/keyset pagination and streaming for large result sets | `HasStreaming`, `chunkById` |

---

## Gap analysis

### Blocking for mobile

| # | Gap | Detail |
|---|---|---|
| M-1 | **No refresh-token flow** ([T-04](10-audit-findings.md#t-04)) | Access tokens are long-lived opaque strings. Either they expire and users re-login constantly, or they never expire and a stolen token is permanent. |
| M-2 | **No device / installation identity** | You cannot show a user "iPhone 15, last used 2 h ago" or revoke one device. `auth()->sessions()` does this for *web* sessions only. |
| M-3 | **No global revocation** | No `token_version` on the user row, so "log out everywhere" after a password change means deleting rows and hoping none were missed. |
| M-4 | **Write on every request** ([T-01](10-audit-findings.md#t-01)) | `last_used_at` update per authenticated call. Defeats read replicas, generates constant write load. |
| M-5 | **No push-token storage** | Standard mobile back-end table + endpoints (register/unregister, per-device, with platform). |
| M-6 | **No API problem-details format** | Errors are `{code, message, errors}` — fine, but not RFC 9457, and `errors` shape varies between validation failures and other 4xx. Mobile clients need one parse path. |

### Blocking for API quality generally

| # | Gap | Detail |
|---|---|---|
| M-7 | **No OpenAPI / schema output** | No `php myth api:docs`. Mobile and web clients are written against source-reading. |
| M-8 | **No response transformers / resources** | Controllers return raw DB rows filtered by `safeOutput()`. Column renames leak straight into the API contract. Laravel's `JsonResource` layer is the missing piece. |
| M-9 | **No idempotency keys** | A mobile client retrying a `POST` on flaky signal double-creates. |
| M-10 | **No ETag / conditional GET on API routes** | `Response::etag()` and `withCacheHeaders()` exist but are not wired into the API path. Mobile clients on cellular benefit disproportionately. |
| M-11 | **No cursor pagination in the HTTP layer** | `chunkById` exists in the query builder; the HTTP pagination shape is DataTables-style (`draw`/`recordsTotal`/`recordsFiltered`), which is wrong for a mobile list. |
| M-12 | **No `unique:`/`exists:` validation** ([V-01](10-audit-findings.md#v-01)) | Every write endpoint hand-rolls existence checks. |
| M-13 | **No health / readiness endpoint** | Needed for load balancers and for a mobile "is the service reachable" probe. |

---

## Proposed token model

Additive — the existing `users_access_tokens` table stays as the access-token store.

```sql
-- new
CREATE TABLE user_devices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  device_uuid     CHAR(36)     NOT NULL,   -- client-generated, stable per install
  platform        VARCHAR(16)  NOT NULL,   -- ios | android | web
  app_version     VARCHAR(32)  NULL,
  os_version      VARCHAR(32)  NULL,
  model           VARCHAR(64)  NULL,
  push_token      VARCHAR(255) NULL,
  last_seen_at    DATETIME     NULL,
  revoked_at      DATETIME     NULL,
  created_at      DATETIME, updated_at DATETIME,
  UNIQUE KEY uq_user_device (user_id, device_uuid),
  KEY idx_push (push_token),
  CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_refresh_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  device_id       BIGINT UNSIGNED NOT NULL,
  token_hash      CHAR(64)     NOT NULL,   -- sha256(secret)
  parent_id       BIGINT UNSIGNED NULL,    -- rotation chain, for reuse detection
  expires_at      DATETIME     NOT NULL,
  consumed_at     DATETIME     NULL,
  revoked_at      DATETIME     NULL,
  created_at      DATETIME,
  UNIQUE KEY uq_refresh_hash (token_hash),
  KEY idx_user_device (user_id, device_id),
  CONSTRAINT fk_refresh_user   FOREIGN KEY (user_id)   REFERENCES users(id)        ON DELETE CASCADE,
  CONSTRAINT fk_refresh_device FOREIGN KEY (device_id) REFERENCES user_devices(id) ON DELETE CASCADE
);

-- altered
ALTER TABLE users               ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users_access_tokens ADD COLUMN device_id     BIGINT UNSIGNED NULL,
                                ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0;
```

**Rules**

- Access token TTL 15 min; refresh token TTL 30 days, **rotated on every use**.
- On refresh: mark the old row `consumed_at`, issue a child with `parent_id` set.
- **Reuse detection:** presenting an already-consumed refresh token revokes the entire chain
  and the device. That is the standard defence against refresh-token theft.
- `token_version` is stamped into the access token at issue and compared on every request.
  Bumping `users.token_version` invalidates every access token for that user instantly, with
  no writes to the token table.
- `last_seen_at` on `user_devices` is written at most once per `auth.device.seen_precision`
  seconds (default 300), which also fixes **M-4/T-01**.

## Proposed endpoints

```
POST   /api/v1/auth/login          {email, password, device:{uuid,platform,app_version,...}}
                                   → {access_token, expires_in, refresh_token, user}
POST   /api/v1/auth/refresh        {refresh_token}   → new pair (old one consumed)
POST   /api/v1/auth/logout                           → revoke this device's chain
POST   /api/v1/auth/logout-all                       → bump users.token_version
GET    /api/v1/auth/me
GET    /api/v1/auth/devices                          → list, with last_seen_at
DELETE /api/v1/auth/devices/{uuid}                   → revoke one device
POST   /api/v1/auth/push-token     {token, platform}
DELETE /api/v1/auth/push-token
GET    /api/v1/health                                → {status, version, time} — unauthenticated
```

Throttling: `throttle:auth` (10/min per IP+route) on `login` and `refresh`; a tighter
per-device limiter on `refresh` to make chain-hammering visible.

## Proposed error envelope

Keep the existing `code`/`message` keys for backwards compatibility, add the fields a mobile
client actually needs:

```json
{
  "code": 422,
  "message": "The given data was invalid.",
  "error": "validation_failed",
  "errors": { "email": ["The email field is required."] },
  "request_id": "01J8Z…",
  "retry_after": null
}
```

`error` is a **stable machine-readable slug** — mobile clients must never branch on `message`,
which is human-facing and translatable. `request_id` comes from `AttachRequestFingerprint` and
lets support correlate a user report with a server log line. `retry_after` is populated on 429
and 503.

---

## Sequencing

These map onto the priorities in [30-roadmap.md](30-roadmap.md).

1. **Fix first** — the API path is unusable at scale until these land:
   [T-01](10-audit-findings.md#t-01) (write per request),
   [T-02](10-audit-findings.md#t-02) (DDL per login),
   [R-01](10-audit-findings.md#r-01) (`exit`),
   [C-01](10-audit-findings.md#c-01) (file cache),
   [SE-01](10-audit-findings.md#se-01) (session lock on `api.app`).
2. **Then build** — M-1 through M-5 (device + refresh model), M-6 (error envelope),
   M-13 (health).
3. **Then polish** — M-7 (OpenAPI), M-8 (resources), M-9 (idempotency), M-10 (ETag),
   M-11 (cursor pagination).

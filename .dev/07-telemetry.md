# 07 — Telemetry and the debug bar

**Added:** 2026-09-17 · `Core\Telemetry` · 56 tests

Records what a request did — HTTP in, queries out, mail, queue jobs, exceptions,
logs — and shows it in a bar on any page. AJAX and API calls are recorded the
same way, so calls a page makes appear in that page's bar.

---

## Turning it on

```dotenv
TELEMETRY_ENABLED=true
TELEMETRY_MODE=all          # or: users
TELEMETRY_USER_IDS=1,42     # only with MODE=users
```

Nothing else is required. The middleware is already in the global stack and is a
no-op while the gate says no.

---

## The gate

`Core\Telemetry\Gate` is the only thing that decides whether a request is
recorded, and it is written to say no by default. Three independent conditions
must all pass:

| Condition | Why |
|---|---|
| `enabled` is true | One switch, off by default |
| Not production, **or** `allow_in_production` is also true | Recording captures query text and request bodies. One misplaced `.env` value should not start writing those to disk on a live site |
| `mode` admits this actor | `all`, or `users` + a matching id |

`mode: users` refuses anonymous visitors outright — "record these user ids"
cannot include somebody who is not signed in.

The decision is cached per request, because the gate is consulted on every
recorded event and resolving the user means an auth lookup. `identify()` clears
it, which is how a login part-way through a request is picked up.

---

## What is recorded

| Type | Source | Notable payload |
|---|---|---|
| `request` | `RecordTelemetry` middleware | method, path, status, ajax, input, peak memory |
| `query` | `PerformanceMonitor::observe()` | sql, bindings, duration, first non-framework caller |
| `mail` | `Core\Mail\Mailer` | driver, recipients, subject, body, sent/failed |
| `queue` | `Recorder::recordQueue()` | job class, status |
| `exception` | middleware + `recordException()` | class, message, file:line, bounded trace |
| `log` | `Recorder::recordLog()` | level, message, context |

Adding a source is one call:

```php
telemetry()->record(Entry::TYPE_HTTP, ['url' => $url, 'status' => $status], $ms);
```

### Queries

`PerformanceMonitor` gained a single observer slot. The middleware subscribes
the recorder to it and turns the monitor on, because `endQuery()` — the only
place timing exists — does nothing while it is off.

`observe(null)` is called in the middleware's `finally`, and `reset()` clears the
slot too: under a worker SAPI an observer bound to a finished request would
otherwise keep recording into a dead buffer.

---

## Redaction

Payload keys are matched against a substring list, case-insensitively, with
`-`, `.` and spaces normalised to `_`. That single rule covers `password`,
`password_confirmation`, `X-API-KEY` and `apiKey`.

The default list is `Recorder::defaultRedactedKeys()`. Setting `redact` in config
**replaces** it, so include what you still want covered.

A recorded login request contains whatever was posted to the login form. This is
not optional hardening.

---

## Bounds

Telemetry runs inside every request, so everything is capped:

| Limit | Default | Config |
|---|---|---|
| Entries per request | 300 | `max_entries_per_request` |
| String length in a payload | 2,000 bytes | `max_value_length` |
| Trace frames | 20 | `max_trace_frames` |
| Payload nesting | 6 levels | — |
| Keys per array | 200 | — |
| Bytes per day on disk | 64 MB | `storage.max_bytes_per_day` |
| Days retained | 3 | `storage.retention_days` |

Overflow is counted, not silently lost: `dropped_entries` rides along on the
`request` entry.

**The recorder never throws.** Every public method is wrapped. An observer that
can break the thing it observes is worse than no observer — `RecorderTest`
asserts that recording to an unwritable store returns false rather than raising.

---

## Storage

`FileStore`, one JSON-lines file per day under `storage/telemetry/`. A file
rather than a table so the bar works before anyone runs a migration, and because
append-many/read-the-tail is what an append-only file is best at. One line per
entry means a truncated write costs one entry.

Reads walk the file backwards in 8 KB chunks, so showing the last 200 entries out
of a 60 MB file does not load 60 MB.

> One trap worth knowing: `filesize()` is served from PHP's stat cache, and
> `write()` calls it on the same path to check the daily cap. Without
> `clearstatcache()` the reader sizes the file as it was *before* the last
> append and never sees the newest entries — exactly the ones the bar is for.
> Covered by `FileStoreTest::testEntriesComeBackNewestFirst`.

---

## The bar

`Core\Telemetry\Bar` renders a self-contained panel — no build step, no CDN — and
`RecordTelemetry` injects it before the last `</body>`.

It is **not** injected into JSON, downloads, or AJAX partials. A partial with a
debug bar stitched into it is a bug. Those requests are still recorded; they
show up in the bar on the page that made them, which polls `/_telemetry/entries`
every two seconds.

Everything the bar renders is escaped as text. The payloads are SQL and request
bodies — precisely the content an attacker controls.

### Endpoint

| Route | Purpose |
|---|---|
| `GET /_telemetry/entries` | Feed, with `after`, `type`, `request_id`, `search`, `limit` |
| `DELETE /_telemetry/entries` | Drop everything on disk |

Both return **404** when the gate refuses, so on a site with telemetry off the
endpoint does not advertise itself.

In `users` mode the endpoint filters to the caller's own entries. Being on the
allowlist lets you read your own traffic, not everyone else's request bodies.

---

## Tests

| File | Covers |
|---|---|
| `tests/Unit/Telemetry/GateTest.php` | Every way the gate must say no |
| `tests/Unit/Telemetry/RecorderTest.php` | Caps, redaction, tagging, flush semantics |
| `tests/Unit/Telemetry/FileStoreTest.php` | Reverse reads across chunk boundaries, filters, retention, corrupt lines |
| `tests/Unit/Telemetry/BarTest.php` | Injection points, escaping, idempotence |

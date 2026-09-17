# 07 — Telemetry and the debug bar

**Added:** 2026-09-17 · `Core\Telemetry` · 96 tests

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
| `dump` | `dbg()` | label, value, calling file:line |
| `timer` | `dbg_start`/`dbg_stop`/`dbg_count` | name, kind (span / counter / unclosed) |
| `cache` | `Core\Cache\CacheManager` | operation (hit / miss / put / forget), key, store |
| `http` | `Core\Support\HttpClient` | method, redacted url, status, bytes, resolved ip |

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

### Cache

`CacheManager` records `hit`, `miss`, `put`, `forget` with the key, the store
name and the duration. The bar's **cache** tab shows the **hit rate** rather
than a count, because the count on its own says nothing — and turns amber below
50%.

`remember()` shows as a miss followed by a put, which is what you want to see:
it tells you the closure actually ran.

Two deliberate choices:

- **The cached value is never recorded.** A cache holds rendered pages and query
  results; writing those to a telemetry file on every read would dwarf
  everything else in it.
- **`get()` uses an internal sentinel as the store default**, so a stored `null`
  registers as a hit rather than a miss. Both shipped stores already
  distinguished the two, so nothing about the return value changed — the
  difference just became visible.

### Outbound HTTP

`HttpClient` records method, URL, status, duration, response size and the
resolved IP, so third-party latency sits in the same timeline as your own
queries. It is recorded once after `curl_exec`, before the error paths, so the
entry exists for all three outcomes: success, a cURL failure, and a 4xx/5xx.

The response body is not recorded — it can be megabytes, and it is the one part
of an outbound call you can usually get elsewhere.

URLs are redacted before storage. An outbound URL routinely carries an
`api_key` or a signed token as a query parameter, and the recorder's key-based
redaction cannot reach inside a string, so `redactUrl()` rewrites the query
string first. Non-secret parameters survive, so `?api_key=…&page=7` keeps its
`page=7`.

---

## Debugging helpers

`var_dump()` into a JSON endpoint corrupts the payload; into a page it shifts
the layout. Either way the act of debugging changes what is being debugged.
These record instead, leaving the response byte-identical.

```php
$total = dbg($this->calculateTotal(), 'total');   // returns its argument
dbg_start('parse'); … dbg_stop('parse');          // a measured span
$rows = dbg_measure('query', fn() => $db->get()); // times a callable
dbg_count('processed');                           // counts in a hot path
```

| Helper | Records | Notes |
|---|---|---|
| `dbg($value, $label)` | `dump` | Returns `$value`, so it wraps an expression without restructuring code. Captures the calling `file:line`. |
| `dbg_start` / `dbg_stop` | `timer` | `dbg_stop` returns elapsed ms, or `null` if the timer was never started — a stop without a start is a caller bug, and recording 0 ms would hide it. |
| `dbg_measure($name, $fn)` | `timer` | Closes the span in a `finally`, so a throwing callable still records how long it ran before it threw. |
| `dbg_count($name)` | `timer` | Only the total is recorded, at flush. Counting a million iterations costs an array increment, not a million entries. |

A timer started and never stopped is recorded as `unclosed` rather than
vanishing — that usually means the path which would have stopped it threw.

Objects are recorded as their class plus public state, or `toArray()` if they
have one, rather than as a full object graph: dumping one is how a debug tool
turns into a memory problem. Dumps go through the same redaction as everything
else, so `dbg($request->all())` will not write a password to disk.

**With telemetry off** these fall back to the log when `APP_DEBUG` is on, and are
otherwise a no-op. A silent debugger is worse than none — you conclude the code
never ran.

---

## Finding an N+1

The bar's **duplicates** tab groups queries by shape: literals are replaced, so
`where id = 1` and `where id = 2` collapse to one row with a count. A count in
the dozens against a single shape is what an N+1 looks like. Each row carries
the call sites, so you know where the eager load belongs.

The same summary rides on the `request` entry — `query_count`, `query_time_ms`,
`query_shapes`, and the top ten `repeated_queries` — so it is available without
opening the bar, including for API responses.

`Recorder::fingerprint()` is the normalisation: whitespace collapsed, quoted
strings and numbers replaced, `IN (?, ?, ?)` folded to `IN (?)`.

`PerformanceMonitor::getQueryFingerprints()` returns every shape with its count;
`getN1Suspects()` filters to those past the warn threshold (default 30), which
is the right answer for a warning and too blunt for a debug bar.

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
| `tests/Unit/Telemetry/DebugHelpersTest.php` | dbg() value handling and redaction, timer and counter semantics, query fingerprinting |
| `tests/Unit/Telemetry/CollectorsTest.php` | Cache behaviour is unchanged by instrumentation, hit/miss recording, URL redaction |

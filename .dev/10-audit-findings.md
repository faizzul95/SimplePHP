# 10 — Audit Findings

**Verified:** 2026-09-17 · branch `database-update` · PHP 8.3.8 · **1,920 tests**
(file order and two random orders) · PHPStan level 2 clean · SQLite engine tests
run with `-d extension=pdo_sqlite`

<a id="fixed-on-2026-09-17-twelfth"></a>
### Fixed on 2026-09-17 (twelfth pass — driving the builder against a real engine)

Suite **1,894 → 1,920 tests**. PHPStan level 2 clean.

Method: 211 public builder methods existed; 26 had ever been executed against a
database. A harness drove the rest against SQLite. Everything below passed every
string-level assertion already in the suite.

| Finding | What changed | Test |
|---|---|---|
| **`orderBy()` defaulted to DESC** | `orderBy('name')` returned reverse-alphabetical. SQL defaults to ascending, every comparable builder defaults to ascending, and `BuilderStatementInterface` **declared ASC** — the implementation said DESC. The only two one-argument call sites were docblock examples, both plainly meaning ascending, so the default is now ASC and matches the interface. | `BuilderDefectsTest` (3) |
| **`toJson()` and `toObject()` did nothing** | Both set `$returnType`, and `reset()` — which runs inside the select pipeline before `_returnResult()` reads it — put it back to `array`. `paginate()` worked around this by saving and restoring the value; `get()` and `fetch()` did not, so two documented `ResultInterface` methods silently returned plain arrays. The requested format now lives in a field `reset()` does not touch, consumed once when the result is formatted. | `BuilderDefectsTest` (5) |
| **`inRandomOrder()` emitted MySQL's `RAND()`** | Hard-coded, while the grammar already had `compileRandomOrder()` and was not asked. `RAND()` does not exist outside MySQL. | `BuilderDefectsTest` (2) |
| **`truncate()` emitted `TRUNCATE` everywhere** | SQLite has no such statement — it is a syntax error, not a slower path. Added `compileTruncate()` to the grammar; SQLite returns `DELETE FROM`. | `BuilderDefectsTest` (2) |
| **`analyze()` emitted `ANALYZE TABLE`** | MySQL's spelling, and it read `Msg_text` off the result — so on an engine where ANALYZE returns no rows, a successful analyze reported **false**. Added `compileAnalyze()` and `analyzeReturnsStatusRows()`. | `BuilderDefectsTest` (2) |
| **`whereAny` / `whereAll` / `whereNone` refused `LIKE`** | They validated against the bare comparison set while `where()` and `orWhere()` each carried their own widened copy — so searching a term across several columns, the case these methods exist for, was refused by the helper that then delegates to a `where()` which accepts it. One shared `EXTENDED_OPERATORS` constant. `whereColumn()` and the temporal helper keep the narrow list: they build a single binary clause, where `BETWEEN` would emit `a BETWEEN ?`. | `BuilderDefectsTest` (4) |
| **`skip()->take()` produced unparseable SQL** | `offset()` writes SQLite's no-limit sentinel and `limit()` appended after it: `LIMIT 2 LIMIT -1 OFFSET 1`. Only that call order was affected, which is why `take()->skip()` worked. A real limit now supersedes the sentinel. | `BuilderDefectsTest` (3) |
| **A backup could truncate silently** | `Backup::createDump()` ignored every `fwrite()` return and `fclose()`'s. On a full volume the dump truncates mid-statement and the method returns the path as though it succeeded — you find out when you try to restore. Writes are checked, a partial dump is deleted rather than left looking usable, and the close error does not mask the write error that caused it. | `BackupWriteFailureTest` (4) |
| `grantPermissionsToRole()` was an N+1 | A SELECT and an INSERT per ability: granting fifty abilities issued a hundred queries. One read, one batch write. It also narrows a check-then-insert race. | — |
| `count()` re-evaluated per iteration | `Request::processFiles()` called `count($file['name'])` in the loop condition, once per uploaded file. Hoisted. | — |

**Flagged, not changed:**

- **`having()` takes `(column, value, operator)` — the opposite order from
  `where($column, $operator, $value)`** in the same builder. Both the interface
  and the implementation agree, so it is a deliberate signature rather than
  drift, but two adjacent methods taking their arguments in opposite orders is
  a standing trap. Changing it breaks callers, so it needs a deprecation cycle.
- **No unique index on `system_permission (role_id, abilities_id)`.** Duplicate
  rows are reachable under concurrent grants, and revoke deletes by value — a
  leftover duplicate leaves a permission granted after it was revoked. Existing
  installs may already hold duplicates, so the migration needs a dedupe step
  and is not added blind.
- **MariaDB connections silently use `MySQLGrammar`** (from the eleventh pass):
  `BaseDatabase::$driver` never tracks the connection config. Unverifiable here.

---

<a id="fixed-on-2026-09-17-eleventh"></a>
### Fixed on 2026-09-17 (eleventh pass — SQLite driver, and the stale-read bug it found)

Suite **1,860 → 1,894 tests**. PHPStan level 2 clean. The database layer now has
a second engine it can actually be run against.

| Finding | What changed | Test |
|---|---|---|
| **A write never invalidated cached reads** | `QueryCache` is enabled by default and caches SELECT results. Three things had to work for a write to drop them, and none did: `invalidateTable()` had **no callers anywhere**; `generateKey()` takes a `$tables` argument that all three call sites left defaulted to `[]`, so the per-table version never entered a key; and without APCu `tableVersion()` returned a constant `1`. The effects: `update()` then the same read returned the **pre-update value**, and `delete()` then a read still returned the **deleted row**. Because the file cache outlives the request, on a host without APCu — most shared hosting — this persisted across requests and users until the TTL lapsed. All three repaired. | `SqliteEngineTest` read-after-write cases (3) |
| **The grammar seam silently resolved to MySQL for every engine** | `BaseDatabase::$driver` is initialised to `'mysql'` and nothing populates it from the connection config, so `DriverRegistry::queryGrammar($this->driver ?: 'mysql')` always returned the MySQL grammar. On SQLite `whereYear()` emitted `YEAR(created_at)` — no such function. The new driver sets its own engine. **Still open:** a MariaDB connection has the same gap and quietly uses `MySQLGrammar` instead of `MariaDBGrammar`; not changed here because there is no MariaDB server to verify against. | `SqliteEngineTest::testTemporalPredicates` (4) |
| **`BuilderStatementInterface` declared `where()`'s parameters in the wrong order** | The interface said `where($column, $value, $operator)`; every implementation and call site uses `(column, operator, value)`. PHP enforces arity and types but never parameter *names*, so the two never had to agree and nothing could detect the lie. Anyone who trusted the interface got "Invalid operator", and a named argument bound to a parameter that does not exist. Same for `orWhere()`. Interface corrected to match. | — |
| Dead code in the pagination path | — | — |

**New: `Core\Database\Drivers\SqliteDriver` + `Query\Grammars\SqliteGrammar`.**

Why it matters more than "one more engine": every other database test in the
suite asserts on generated SQL strings, which proves the builder emits what its
author expected and nothing about whether an engine accepts it. 33 of the new
tests execute real statements. All three bugs above were invisible to string
assertions and surfaced within minutes of running against a real database.

Dialect differences the grammar encodes, each verified against SQLite 3.40:

| | SQLite | Why it needed an override |
|---|---|---|
| Temporal | `strftime()`, cast to INTEGER | No `EXTRACT`; `strftime` returns `'03'`, which would not equal `3` |
| `OFFSET` | `LIMIT -1 OFFSET n` | A bare `OFFSET` is a **syntax error** |
| Locking | nothing emitted | `FOR UPDATE` does not parse; SQLite locks the database, not rows |
| Column listing | `pragma_table_info(?)` | No `information_schema`; this form takes a bound parameter |
| Case-insensitive LIKE | `LIKE` | `ILIKE` does not exist; `LIKE` is already ASCII-insensitive |
| `RETURNING` | declined | Exists from 3.35, but `lastInsertId()` answers the same question on every build |

`connect()` also switches `foreign_keys` on — **off by default in SQLite**, which
makes a declared schema look enforced when it is not — and sets a busy timeout.

**What this does and does not prove.** SQLite accepts backticks, brackets *and*
double quotes, so the ~35 hard-coded backtick sites in the write paths run fine
here. Those remain a real blocker for PostgreSQL. This second engine validates
the grammar seam for row limiting, temporal expressions, locking, upserts and
introspection — not identifier quoting.

**Running them.** The engine tests skip unless `pdo_sqlite` is loaded; the DLL
ships with the bundled PHP but is commented out in `php.ini`. Enable
`extension=pdo_sqlite` there, or pass `-d extension=pdo_sqlite`. Without it the
suite is 1,894 tests with 50 skipped; with it, 17 skipped.

---

<a id="fixed-on-2026-09-17-tenth"></a>
### Fixed on 2026-09-17 (tenth pass — mailer, menu, telemetry)

Suite **1,693 → 1,800 tests** (file order and two random orders). PHPStan level 2
clean. Two new subsystems: `Core\Mail` and `Core\Telemetry`.

| Finding | What changed | Test |
|---|---|---|
| **A menu item marked `maintenance` was visible to everyone** | `MenuManager::resolveDynamicValue()` treated any `is_callable()` value as a closure to invoke, and the framework defines a global `maintenance()` helper — so `'state' => 'maintenance'`, the documented way to restrict a module to superadmin, *called that function*. It returns a manager object, which is not scalar, so the state normalised to the default: `release`. Any state or badge string colliding with a global function name had the same problem (`'badge' => 'time'` would have rendered a timestamp). Plain strings are no longer treated as callables; closures, `[$obj, 'method']` and invokables still resolve. | `MenuAccessControlTest` (23) |
| **A role whitelist that parsed to nothing admitted everyone** | `normalizeRoleIds()` dropped entries that were not positive ints, so `'role_ids' => ['admin']` — a slug where an id belongs — emptied the list, and an empty list means "no whitelist". A restriction became a page open to every user. Unparseable entries now normalise to `0`, which matches no real role. | `MenuAccessControlTest` |
| **Password reset could lock a user out of their own account** | `AuthController::resetPassword()` committed the new password and *then* sent the email. A dead SMTP relay left the account holding a password nobody would ever read, and the response said only "Failed to send email". The write and the send are now one transaction: a mail failure rolls the password back. Deliberately not queued — the caller is telling the user whether the mail went out, and it cannot know that from a queue. | — |
| **The mailer had no timeout** | PHPMailer defaults to 300 seconds. One unreachable relay held a web request open for five minutes, and the password-reset route was one `sendEmail()` away from it. Configurable, default 10s, hard-capped at 120. | `MailerTest` (13) |
| **SMTP internals were returned to the caller** | The old helper's `catch` returned `$mail->ErrorInfo` — the SMTP conversation, including hostnames — as the user-facing message. Detail now goes to the log; the caller gets a sentence it can display. | `MailerTest` |
| `SMTPAuth` was forced on | A local Mailpit or MailHog accepts no auth, so there was no way to exercise an email path locally. Authentication now happens only when a username is configured, and `log` / `array` / `null` drivers were added. | `MailerTest` |
| Header injection in mail | A newline in a subject or recipient name starts a new header, which is how a `Bcc` gets added to somebody else's mail. `Message` strips CR/LF/NUL from every header value as it is set. | `MessageTest` (11) |
| A repeated recipient read as a send failure | PHPMailer returns false for a duplicate address. `Message` lowercases and de-duplicates. | `MessageTest` |
| **RBAC: a role could be shown as having every permission** | `PermissionController` used `in_array($allAccessID, $currentAbilitiesID)` loosely, and `$allAccessID` is `null` when no wildcard ability exists — so `null == 0` and `null == ""` made a single zero-ish row report the role as having all access. Ids are normalised to int and compared strictly. The wildcard lookup also ran once per row inside `array_map`; hoisted, which takes the build from O(n²) to O(n). | — |
| **The debug bar silently missed the newest entries** | `FileStore` reads the day's file backwards from `filesize()`, and `write()` had just called `filesize()` on the same path for its cap check — so PHP's stat cache served a size from before the last append and the reader truncated the newest line. `clearstatcache()` on both paths. | `FileStoreTest` (17) |
| `PerformanceMonitor::reset()` leaked its observer | The new observer slot was not cleared with the rest of the static state, so under a worker SAPI an observer bound to a finished request would keep recording into its dead buffer — the same shape as W-01. | — |

**New: `Core\Mail`** — `Message` (fluent builder, validates and de-duplicates
addresses, strips header injection, derives a text part), `Mailer` (smtp / log /
array / null, bounded timeout, attachment path and size guards, generic errors),
`SendMailJob` (queued sends with a retry budget). `sendEmail()` keeps its exact
signature and return shape; `queueEmail()` is new.

**New: `Core\Telemetry`** — a Telescope-style recorder and debug bar. Records
requests, queries, mail, queue jobs, exceptions and logs; shows them on any page;
AJAX and API calls included. Enabled per user id or for everyone, off by default,
gated twice in production. Full notes in [07-telemetry.md](07-telemetry.md).

**Checked and found sound** (recorded so the next pass does not re-derive them):
path normalisation agrees between `Request::resolvePath()` and
`Router::normalizeUri()`, so there is no menu-gate bypass by encoding or casing;
the `md5()` calls in `AccessCredentialService` are RFC 2617 Digest and required;
`applySessionVariable()` interpolates framework constants, not user input; the
`while (true)` loops in `HasStreaming` all have reachable breaks.

---

<a id="fixed-on-2026-09-17-ninth"></a>
### Fixed on 2026-09-17 (ninth pass — CSRF binding, front-end XSS, comment cleanup)

Suite **1,679 → 1,693 tests**, file order and `--order-by=random`. PHPStan level 2
clean. **~2,400 comment lines and 736 lines of dead code removed.**

| Finding | What changed | Test |
|---|---|---|
| **[S-01] CSRF was forgeable from any sibling subdomain** | `validateToken()` compared the submitted token against a **cookie**, so both halves were writable by anything that could set a cookie on the parent domain — a vendor status page, a compromised staging host. The expected value now comes from the session, which nothing outside the process can write; the cookie stays only as the transport the JS client reads. A cookie that outlived its session (login calls `session_regenerate_id()`) is adopted once so the token already rendered into an open page keeps working. | `CsrfSessionBindingTest` (14) |
| **Reflected XSS in the modal error path** | `ModalManager.createContentLoadErrorHtml()` interpolated `message` straight into `innerHTML`, and `message` comes from `requestError.message` or a server response body — so an API that echoed user input into an error string turned a failed modal load into script execution. `shellId` landed inside a single-quoted `onclick`, where an apostrophe ends the string. Both escaped; the class had no escape helper at all before this. | — |
| **`notification.js` `destroy()` threw every time it was called** | `cleanup()` removed a `handleEscKey` that was never declared — the keydown listener was an inline arrow — and a `themeChangeHandler` that was `const` inside an `if` block and therefore invisible from the outer scope. Both `ReferenceError`s fired before `panel.remove()`, so the public teardown method could not tear anything down and the keydown listener outlived every panel. | scripted lifecycle check, 9 cases |
| Two notification panels overwrote each other | `panel.id`, `notiToggleBtn` and `notiContent` were hard-coded, and the content lookup was `document.getElementById`. A second call bound its listeners to the first panel's button and wrote its content into the first panel's body. Per-instance ids, panel-scoped queries. | as above |
| A panel title carrying markup executed | `<h5>${finalConfig.title}</h5>` via `innerHTML`; titles are routinely built from record data. Escaped, and `setText()`/`setTitle()` added for callers that want text semantics. | as above |
| A colour value could break out of the `<style>` block | `colors.light`, `width`, `height` and `top` were interpolated into a generated stylesheet, where one `}` closes the rule and the rest is caller-authored CSS. Values are now pattern-checked and fall back to the default. | as above |
| A partial `colors` or `icon` object dropped its sibling key | `{...defaultConfig, ...config}` is shallow, so `{colors: {light: '#fff'}}` deleted the dark colour. Nested objects merged key by key. | as above |
| **`helper.js` had no escape helper in 3,760 lines** | `previewFiles()` wrote `downloadFile('${url}', '${filename}')` into a quoted `onclick`: an apostrophe in a filename ends the JS string and everything after it is script. The same filename went into markup unescaped at four more sites, and the error view reflected `message`, `details` and `url`. `escapeHtml()` and `escapeJsAttr()` added and applied at all eight. Not currently reachable through the framework's own uploads — `FileUploadGuard` stores files under `bin2hex(random_bytes(16))` — but the helper takes any path a caller hands it. | round-trip check on five payloads |
| Dead code: `Core\Database\Interface\UtilsInterface` | 76 lines, implemented by nothing, and one of its eight methods (`completeTrans`) does not exist anywhere in the codebase. | — |
| Comment cleanup | 338 `@param`/`@return` tags that only restated a native type declaration; 673 more through the same rule plus formulaic descriptions; 373 lines of step-by-step narration (`// Execute the prepared statement` above `$stmt->execute()`); 114 PEAR-era header tags (`@category`, `@package`, an empty `@author`, `@version 0.0.1`); the interface docblocks, where `BuilderStatementInterface` was 79% comment and several `@param` types **contradicted** the signature they described. Rationale comments, banners and every richer-than-native type (`string[]`, `array{...}`, `@template`) were left alone — verified by PHPStan, which caught the one generic the first pass broke. | full suite + PHPStan |

**Flagged, not changed:** thirty files carry
`@license http://opensource.org/licenses/gpl-3.0.html GNU Public License` while
`composer.json` declares `"license": "MIT"`. Which one governs is not a cleanup
decision — the tags were left in place.

**Front-end review status:** `validation.js`, `notification.js`, `ModalManager.js`
and `helper.js` reviewed. `BootstrapDataTable.js` already had an `escapeHtml()` and
used it; its two `innerHTML` writes in `refreshRowNode()` and
`refreshResponsiveChildRow()` mirror DataTables' own render semantics, where a
column `render` function returns HTML — changing them to `textContent` would break
every action-button column, so they were left as they are.

---

<a id="fixed-on-2026-09-17"></a>
### Fixed on 2026-09-17 (eighth pass — cleanup, validation, front-end parity)

Suite **1,662 → 1,679 tests**. PHPStan level 2 clean. **660 lines of dead code
removed.**

| Finding | What changed | Test |
|---|---|---|
| **`deep_array` was defeated by its own implementation** | `getArrayDepth()` recursed once per level, so the guard against over-nested input walked the whole structure before it could report the depth. A 50,000-level array — a few hundred bytes of JSON — exhausted the PHP stack first, and a stack overflow is fatal rather than an `Exception`, so the surrounding `catch` never saw it. Now an explicit stack with a ceiling. | `ValidationLargeInputTest` (17) |
| `array_keys` was O(keys × allowed) with loose comparison | A thousand-key payload against fifty allowed keys did fifty thousand comparisons, and `in_array`'s loose default matched a numeric array key against a non-numeric allowed key. Hash lookup, string comparison. | `ValidationLargeInputTest` |
| `json` allocated the document to validate it | `json_decode()` built the whole tree and discarded it. `json_validate()` on 8.3+, decode fallback on 8.2. | `ValidationLargeInputTest` |
| **An empty file input passed every conditional required rule** | `required_if`, `required_unless` and `required_with` delegated to `validateRequired` passing `[]` as the element. `[].type` is undefined, so the file branch never ran and `String(FileList)` is the non-empty `"[object FileList]"`. `required_with` passed *nothing*, so `element.type` threw and the rule failed whenever its condition was met. | front-end; see [06-validation.md](06-validation.md) |
| **`dimensions` reported every image as invalid** | It returned a Promise into a synchronous caller, which then evaluated `!undefined`. Measures into a `WeakMap` now. | front-end |
| `regex:` truncated in the browser | The same comma-splitting bug the PHP side had, so a bounded quantifier made the rule unpassable. Both parsers special-case `regex` and `not_regex`. | front-end |
| **26 rules existed server-side only** | A rule string valid on the server silently did nothing in the browser. 94 rules now, full parity in that direction; the genuinely server-only ones return `{valid: true}` with a comment rather than falling through to the default, so the boundary is visible. | — |
| Dead code: `Components\TaskRunner` | 509 lines, zero references anywhere including `preload.php` and `composer.json`. A `proc_open` process pool duplicating `Core\Queue\Supervisor`, which is wired and used by `queue:supervise`. | — |
| Dead code: `Core\Http\SecureResourceAccess` | Both methods referenced only from the trait's own docblock example. | — |
| Dead code: `Core\Database\Traits\ValidationTrait` | Three static helpers, zero callers, mixed into a 4,800-line class. | — |
| Dead code: eight `Controller` methods | `paginateResponse`, `decodeIdOrFail`, `findByEncodedIdOrFail`, `authorizeOrFail`, `softDeleteByEncodedId`, `restoreByEncodedId`, `redirectTo`, `redirectRoute` — zero references. Several were one-line wrappers over `redirect()` and `Abort::denied()`, which is the "two ways to do the same thing" problem. The encoded-id cluster was self-referential: nothing called `encodeId()`. | — |

**Still open and worth knowing:** 13 rules run in the browser and are *not*
re-checked on the server — the dangerous direction, because anything skipping the
browser is unchecked. Listed in [06-validation.md](06-validation.md#the-parity-table).

---

**Seventh pass verified:** 2026-08-26 · branch `database-update` · PHP 8.3.8

<a id="fixed-on-2026-08-26-seventh"></a>
### Fixed on 2026-08-26 (seventh pass)

Suite **1,611 → 1,662 tests**, passing in file order and in random order;
PHPStan clean at level 2.

| Finding | What changed | Test |
|---|---|---|
| **[D-02] `select()` passed expressions straight through** | Any entry containing a dot, an `" as "`, or a pair of parentheses was returned verbatim — the three shapes hardest to validate and the three that matter most. A `fields=` parameter reaching `select()` could carry `(SELECT password FROM users LIMIT 1) AS x` into the query: arbitrary reads through a select list, and a `SLEEP()` away from a blind timing oracle. Each shape is now parsed and rebuilt from validated parts, with an allow-list of single-argument functions; anything else is refused with a message naming `selectRaw()`. **This was the last identifier path in the builder that trusted its input.** | `SelectColumnSafetyTest` (32) |
| **`AUTH_LOGIN_POLICY_FAIL_OPEN` was dead config** | Present in `app/config/auth.php` and `.env.example`, read by nothing. An operator setting it to false believed they had closed a door that did not exist. It now governs a real decision. | `LoginPolicyAvailabilityTest` (10) |
| **An unreadable attempt log silently disabled brute-force protection** | `recentLoginAttemptTimestampsForScope()` caught every `Throwable` and returned `[]`, which reads as "no recent failures", which reads as "not locked out". Any fault on the attempts table removed the lockout entirely and logged nothing. The failure is now logged, and the setting above decides whether to allow the attempt or refuse it with a 503. | `LoginPolicyAvailabilityTest` |
| `ok()` and `fail()` helpers | `reply('…')->code(422)` puts the status last, as an afterthought, at exactly the moment it is the point. `fail('Failed to delete role')` defaults to 422 — a rejected write is understood-and-not-carried-out, not malformed. 56 controller call sites converted. | `ReplyTest` (32) |
| Datatable endpoints were wrapped for nothing | Every route those controllers serve is under `/api/v1/`, where `Request::isApi()` forces `expectsJson()` true — so `Reply`'s negotiation could never fire there. The four datatable actions now return the array directly, which `Emitter` already turns into JSON. | existing suite |

**Correction to the sixth pass.** That pass reported `AUTH_LOGIN_POLICY_FAIL_OPEN`
as "a cache outage disables login rate limiting". The mechanism was wrong: the
lockout is backed by the `system_login_attempt` table, not the cache, so a cache
outage does not affect it. The setting was simply never read at all. The real
exposure was the unlogged catch above, which is now closed.

---

**Sixth pass verified:** 2026-08-26 · branch `database-update` · PHP 8.3.8

<a id="fixed-on-2026-08-26-sixth"></a>
### Fixed on 2026-08-26 (sixth pass — scheduler, migrations, RBAC, feature flags)

Suite **1,560 → 1,611 tests**, all passing in file order and under two randomised
runs; PHPStan clean at level 2. Level 5 was run as a one-off review sweep: 292
findings, almost all defensive `??` on non-nullable values and always-true
comparisons. Two were real and are below.

| Finding | What changed | Test |
|---|---|---|
| **A backgrounded task released its overlap lock at launch** | `releaseLock()` sat in the `finally`, which runs the instant the child process is *started* — so `withoutOverlapping()` let the next minute's scheduler run start a second copy while the first was still working. That is the one thing it exists to prevent. The lock is now left to expire on its own for a backgrounded task, and still released immediately when the launch itself fails. | `SchedulerLifecycleTest` (12) |
| **The scheduler's failure path stole an output buffer** | `flushOutput()` was guarded on `ob_get_level()` alone, so a task that threw before `startOutputCapture()` ran cleaned whatever buffer happened to be open — the console's own — and wrote it to that task's log file. It now records the depth it opened at and unwinds only its own. | `SchedulerLifecycleTest` |
| **The migration ledger was rewritten in place** | `deploy.json` is the only record of what has been applied. An interrupted write — killed deploy, full disk, container stopped mid-run — truncated it, and the next `migrate` then believed nothing had ever run and replayed everything against a populated schema. Now temp-file plus rename. | `MigrationLedgerTest` (12) |
| **Nothing guarded a migration run against a concurrent one** | Reading the pending list, applying it and recording it is a read-modify-write across the whole run. Two deploys starting together saw the same pending list and both applied it; the per-write `LOCK_EX` made each write atomic, not the sequence. All four mutating operations now take a non-blocking ledger lock — a second deploy is told the first is running rather than queueing up to repeat it. | `MigrationLedgerTest` |
| **A `Reply` negotiated against the wrong request** | Resolution is lazy, so a Reply built in one request and emitted later — in a worker, or anywhere it outlives the moment — negotiated against whatever request was current *then*. The decision is now taken when the Reply is created, which is when the controller is running inside the request it is answering. | `ReplyNegotiationTest` (11) |
| **`Reply` answered JSON to browsers when nothing had touched `request()`** | `Request::current()` is populated lazily, so a Reply resolving before anything else read the request saw null and fell back to JSON. | `ReplyNegotiationTest` |
| **Security headers were sent with raw `header()`** | The last middleware still writing outside the response object, so the response cache stored bodies with no security headers, a test could not observe them, and a worker SAPI building its own response never saw them. Now attached via `WithHeaders`, with the direct call kept for output written before a response object exists. | `ReplyNegotiationTest` |
| **Feature tests inherited each other's config** | `bootstrapTestFrameworkServices()` merges into `$GLOBALS['config']` rather than replacing it, and a merge cannot remove a key. The harness now snapshots and restores it. | `FeatureTestCase` |
| Temporal grammar match was not provably exhaustive | `normalizeType(): string` meant each grammar's `match` could not be proven to cover every case — adding a sixth type and forgetting one grammar was an `UnhandledMatchError` in production rather than a static failure. The return type is now the literal union. | `QueryGrammarTest` |
| The remaining controllers still answered only in JSON | 52 `jsonResponse()` call sites across five controllers converted to `reply()`, so a browser form post redirects with a flash message. | `ReplyNegotiationTest`, `UploadControllerSecurityTest` |

**Reviewed and found sound:** `FeatureManager` (fail-closed on every branch —
missing actor, empty intersection, unknown flag), `AuthorizationService`,
`Backup`, and the RBAC middleware, which are now covered end to end by
`AuthorizationGateTest` (16).

**Noted, not changed:** `AUTH_LOGIN_POLICY_FAIL_OPEN` defaults to true, so a
cache outage disables login rate limiting and lockout entirely. That is a
deliberate, documented, configurable choice, but failing open on brute-force
protection for a credential endpoint is the wrong default — worth revisiting
with the DB-backed attempt recording as the fallback.

---

**Fifth pass verified:** 2026-08-26 · branch `database-update` · PHP 8.3.8

<a id="fixed-on-2026-08-26"></a>
### Fixed on 2026-08-26 (fifth pass)

Suite **1,437 → 1,560 tests**, all passing; PHPStan clean at level 2.

| Finding | What changed | Test |
|---|---|---|
| **X-Forwarded-For was read left-to-right** | The leftmost entry is the one the *caller* writes. Sending `X-Forwarded-For: 1.2.3.4` made every IP-keyed control — rate limit, blocklist, audit log — attribute the request to an address of the attacker's choosing, and rotating it per request bypassed all of them. The chain is now walked from the right, skipping our own proxies. | `ClientAddressTest` (16) |
| **The rate limiter resolved the user before rejecting** | `buildSignature()` called `auth()->id()` on every request including the ones about to be refused, so a flood of unauthenticated requests bought a token lookup each. The IP-only scopes no longer pay for it. | `RateLimitTest` (17) |
| **No coarse limiter** | The per-route key means an attacker walking distinct URLs gets a fresh budget for each — 120/minute became 120,000/minute across a thousand paths. A per-IP burst ceiling now runs first, costing one counter. | `RateLimitTest` |
| **The file limiter never pruned** | One file per key and nothing deleted: with a path in the key, walking random URLs exhausts inodes. The limiter was itself a denial of service. Sampled pruning added. | `RateLimitTest` |
| **`increment()` created keys that never expire** | On all four stores. On Redis that is unbounded memory growth; for a rate-limit counter recreated after expiry it pinned the caller at the limit permanently. `increment()` now takes a TTL applied only on creation. | `CacheStampedeTest`, `RateLimitTest` |
| **`whereColumn('a.b', 'c.d')` produced `` `a.b` ``** | The whole dotted name was wrapped as one identifier, so the only shape the method exists for asked the server for a column that cannot exist. Same in `orWhereColumn` and `whereBetweenColumns`. | `BuilderSubQueryTest` (25) |
| **Two status normalisers disagreed** | `Emitter::normalizeStatus()` clamped an invalid status to **200**, `ExceptionHandler`'s private copy to **500**. Reporting a bug as OK makes the client treat the failure as a success. One normaliser now, clamping to 500. | `ResponseEmissionTest`, `AbortNegotiationTest` |
| **`cache()` and `dispatch()` held function statics** | Same class as the `csrf()` leak: unreachable by the worker flush, so the request-scoped array store stopped being request-scoped. Both are framework services now. | existing worker suite |
| **The flash bag never rotated under a worker** | `initializeFlashSessionState()` tracked "already rotated" in a function static, so the second request a worker served kept the first one's flash keys readable. | `WorkerState::flush()` |
| **The DSN had one shape** | `driver:host=…;dbname=…` is right for MySQL and PostgreSQL and connects to nothing else. SQL Server separates its port with a comma; Oracle takes an Easy Connect descriptor. The identifier pattern also rejected the ordinary Oracle service name `orclpdb1.localdomain`. | `DsnTest` (29) |
| **No replay protection for token clients** | CSRF does not apply to bearer auth, so nothing defended against a captured request being sent again. `Core\Security\RequestSignature` plus the `signed` middleware: timestamp, one-time nonce, HMAC over method/path/body, keyed by the caller's own token. | `RequestSignatureTest` (30) |
| **Every controller answered in JSON** | A browser form post got a JSON body instead of a redirect, so no flash message ever reached a page. `Core\Http\Reply` negotiates once; the validation failure path in `Router` now uses it too rather than duplicating the negotiation. | `ReplyTest` (23) |

---

**Previous pass verified:** 2026-08-25 · branch `database-update` · PHP 8.3.8
**Method:** direct source reading + targeted reproduction. Every finding cites `file:line`.
Where I reproduced the defect, the reproduction is shown.

Baseline before any of this: **693 tests pass, PHPStan level 2 clean.** These findings are
things the existing tests do not cover, not regressions.

---

## Severity key

| | Meaning |
|---|---|
| **P0** | Exploitable or data-corrupting in a realistic deployment. Fix before production. |
| **P1** | Breaks correctness or blocks a stated goal (API-first, mobile, performance, ease of use). |
| **P2** | Real cost — maintainability, performance headroom, or defence-in-depth. |
| **P3** | Worth doing, low urgency. |

## Status key

| | Meaning |
|---|---|
| **FIXED** | Change landed with a regression test. |
| **MITIGATED** | Risk removed by disabling the affected path; the underlying defect remains. |
| open | Not yet addressed. |

<a id="fixed-on-2026-08-25"></a>
### Fixed on 2026-08-25

Suite went from **693 → 1,204 tests** (1,182 unit + 22 end-to-end), all passing; PHPStan clean at level 2.

> Run PHPStan as `composer stan` (or with `--memory-limit=2G`). Cold-cache analysis of
> ~110k LOC exceeds the 512M default and crashes mid-run, printing dozens of phantom
> "return statement is missing" errors that have nothing to do with the code.

| Finding | What changed | Test |
|---|---|---|
| [W-01](#w-01) | Full state flush: service instances, `Request::$current`, link headers, emit flag, session. Worker still gated behind `MYTH_EXPERIMENTAL_WORKER=1` until load-proven | `WorkerIsolationTest` (11), `SessionCycleTest` (8) |
| [W-02](#w-02) | `exit` removed from the whole HTTP path — `Core\Http\Emitter` is the single emission point | `ResponseEmissionTest` (41) |
| [W-03](#w-03) | `PsrRequestBridge::files()` maps PSR-7 uploads to the native `$_FILES` shape | `PsrRequestBridgeTest` (15) |
| [W-04](#w-04) | `$_SERVER` restored to a boot-time baseline each cycle; `http_response_code()` reset | `WorkerIsolationTest` |
| [R-01](#r-01) | `Response::json()`/`redirect()`, all 4 response classes and 16 middleware throw `ResponseEmitted` instead of exiting | `ResponseEmissionTest` |
| [R-02](#r-02) | Route-model-binding 404 negotiates content instead of forcing JSON | `ResponseEmissionTest` |
| [R-07](#r-07) | `HEAD` sends headers only | `Kernel::emit()` |
| [D-01](#d-01) | `parseIdentifier()` / `quoteIdentifier()` added; all five JOIN builders quote the foreign key | `JoinIdentifierInjectionTest` (39) |
| [D-03](#d-03) | `_escapeJoinColumn()` re-derives quoting instead of trusting pre-backticked input | `JoinIdentifierInjectionTest` |
| [B-01](#b-01) | ~25 directives moved onto the balanced-parenthesis scanner; bare `@break`/`@continue` added | `BladeNestedExpressionTest` (25) |
| [V-01](#v-01) | `unique:`, `unique_with:`, `exists:` rules added | `ValidationDatabaseRulesTest` (13) |
| [T-01](#t-01) | `last_used_at` written only when stale beyond `auth.token.last_used_precision` | `TokenServiceTest` (+5) |
| [T-02](#t-02) | Runtime DDL gated by `auth.token.auto_migrate`; per-process guards on the queue and credential tables | `TokenServiceTest` (+1) |
| [D-07](#d-07) | `retryOnDeadlock()`, 8 retryable codes + SQLSTATE 40001, cause-chain walk, full-jitter backoff, key-ordered batch locking, `SKIP LOCKED` capability detection | `DeadlockResilienceTest` (25), `LockOrderingTest` (24), `ServerCapabilitiesTest` (19) |
| [D-08](#d-08) | MariaDB batch writes were stubs that read as success; both drivers now share `HasBatchWrites` | `BatchWriteParityTest` |
| [Q-01](#q-01) partial | `queue:restart` (graceful reload), unique jobs (`uniqueId()`), `queue:supervise` (parallel workers with respawn) | `BackgroundProcessingTest` (20) |
| [R-05](#r-05) | `route:cache` now names each dropped closure route and exits non-zero; the one closure route became a controller | verified via `myth route:cache` |
| [H-01](#h-01) | The 21 hand-rolled content negotiations collapsed onto `Abort::problem()` / `denied()` / `unauthenticated()` | `AbortNegotiationTest` (26) |
| [H-02](#h-02) | `CompressResponse` compressed nothing once responses became objects | `CompressResponseTest` (17) |
| [H-03](#h-03) | `ApiRequestLogger` logged every request as HTTP 200; `AttachRequestFingerprint` stopped stamping API bodies | `ResponseAwareMiddlewareTest` (10) |
| [H-04](#h-04) | `logger()` inside failure handlers could itself throw; replaced with `Core\Support\SafeLog` | covered by `WorkerIsolationTest`, `AbortNegotiationTest` |
| [H-05](#h-05) | An abort from *middleware* skipped every outer middleware's post-processing; the Pipeline now converts it at each layer | `HttpLifecycleTest` |
| [H-06](#h-06) | Rate-limit budget headers were sent with raw `header()`, outside the response object | `SecurityMiddlewareTest` |
| [D-09](#d-09) | MariaDB had **no statement timeout at all** — `max_execution_time` does not exist there | `HasProfiling::applyStatementTimeout()` |
| [D-10](#d-10) | `gc_collect_cycles()` ran per chunk: 33% slower, zero memory benefit | `StreamingGarbageCollectionTest` (15) |
| [T-06](#t-06) | API tokens had no device cap, so "single-device login" held for the web only | `TokenDeviceLimitTest` (15) |
| [U-01](#u-01) | Upload guard: no size ceiling, no image-decode proof, no pixel-bomb guard, and it rejected worker-spooled uploads | `FileUploadGuardTest` (24) |
| [QA-02](#qa-02) | A `Feature` suite that drives the real kernel through the real middleware stack | 22 end-to-end tests |

---

## Index

| ID | Sev | Area | Finding |
|---|---|---|---|
| [W-01](#w-01) | **P0** | Worker | Auth/CSRF/session singletons not reset — identity can leak between requests — **FIXED** (gate retained) |
| [W-02](#w-02) | **P0** | Worker | ~45 `exit`/`die` calls in the HTTP path kill the worker process — **FIXED** |
| [W-03](#w-03) | P1 | Worker | `$_FILES` populated with PSR-7 objects — uploads broken — **FIXED** |
| [W-04](#w-04) | P2 | Worker | Superglobals and `http_response_code()` not cleared between requests — **FIXED** |
| [D-01](#d-01) | **P1** | Database | `validateColumn()` is a non-empty-string check; JOIN foreign keys interpolated raw — **FIXED** |
| [D-02](#d-02) | P2 | Database | `select()` passes through anything containing `.`, ` as `, or `fn(...)` |
| [D-03](#d-03) | P2 | Database | `_escapeJoinColumn()` returns input unchanged when it already contains a backtick — **FIXED** |
| [D-04](#d-04) | P2 | Database | `BaseDatabase` is 4,415 LOC in one class |
| [D-05](#d-05) | P2 | Database | No SQLite driver — no in-memory DB for tests |
| [D-06](#d-06) | P3 | Database | `paginate()` clones builder state field-by-field |
| [D-07](#d-07) | **P1** | Database | Deadlock retry too narrow: 2 codes, no cause chain, fixed backoff, queue claim unprotected — **FIXED** |
| [D-08](#d-08) | **P0** | Database | MariaDB `batchInsert`/`batchUpdate` were stubs returning `$this`, read as success — silent data loss — **FIXED** |
| [R-01](#r-01) | **P1** | HTTP | `Response::json()`/`redirect()` call `exit` — middleware post-processing skipped — **FIXED** |
| [R-02](#r-02) | P2 | HTTP | Route-model-binding 404 emits JSON + `exit` even for HTML requests — **FIXED** |
| [R-03](#r-03) | P2 | Routing | Reflection on every action invocation, uncached |
| [R-04](#r-04) | P2 | Routing | Dynamic routes matched by linear `preg_match` scan |
| [R-05](#r-05) | P2 | Routing | `route:cache` silently drops closure routes — **FIXED** |
| [R-06](#r-06) | P3 | Routing | `middlewareCache` is per-request and therefore dead |
| [R-07](#r-07) | P3 | HTTP | `HEAD` requests still emit a response body — **FIXED** |
| [T-01](#t-01) | **P1** | Auth | Every authenticated API request performs a write (`last_used_at`) — **FIXED** |
| [T-02](#t-02) | P1 | Auth | `CREATE TABLE IF NOT EXISTS` on every `createToken()` — **FIXED** |
| [T-03](#t-03) | P3 | Auth | Token comparison relies on SQL equality, not `hash_equals` |
| [T-04](#t-04) | **P1** | Auth | No refresh tokens, device binding, or global revocation |
| [T-05](#t-05) | P2 | Auth | `Components\Auth` is 3,998 LOC with a half-finished extraction |
| [S-01](#s-01) | P1 | Security | CSRF is double-submit cookie, not session-bound |
| [S-02](#s-02) | P2 | Security | CSRF cookie is `HttpOnly`, forcing a header-relay workaround |
| [S-03](#s-03) | P1 | Security | CSP ships `'unsafe-inline'` by default; nonces off by default |
| [S-04](#s-04) | P2 | Security | Input XSS filter is log-only by default |
| [S-05](#s-05) | P2 | Security | `Encryptor` hard-fails without ext-sodium + AES-NI; no XChaCha20 fallback |
| [V-01](#v-01) | **P1** | Validation | No `unique:` or `exists:` rules — **FIXED** |
| [V-02](#v-02) | P2 | Validation | `Components\Validation` is 2,939 LOC |
| [B-01](#b-01) | **P1** | Blade | Nested parentheses truncate ~25 directives — reproduced — **FIXED** |
| [B-02](#b-02) | P3 | Blade | No directive-registration API |
| [B-03](#b-03) | P3 | Blade | Regex HTML minifier unsafe inside `<pre>`/`<textarea>` |
| [B-04](#b-04) | P2 | Blade | Filesystem directives (`@file @mkdir @unlink @rename`) exposed to templates |
| [B-05](#b-05) | P2 | Blade | `extract($vars, EXTR_SKIP)` silently drops view variables named `data`, `view`, `shared`, … |
| [H-01](#h-01) | P2 | HTTP | 21 middleware hand-rolled the same content negotiation — **FIXED** |
| [H-02](#h-02) | **P1** | HTTP | `CompressResponse` silently stopped compressing once responses became objects — **FIXED** |
| [H-03](#h-03) | **P1** | HTTP | `ApiRequestLogger` logged every status as 200; fingerprint stopped reaching API bodies — **FIXED** |
| [H-04](#h-04) | P2 | Core | `logger()` throws when unregistered, so failure handlers could fail — **FIXED** |
| [C-01](#c-01) | P1 | Cache | Default store is `file`; no tiered store |
| [C-02](#c-02) | P2 | Cache | `FileStore` has no GC and no directory sharding |
| [C-03](#c-03) | P3 | Cache | Two independent cache systems |
| [D-09](#d-09) | **P1** | Database | MariaDB statement timeout silently never applied — `max_execution_time` is MySQL-only — **FIXED** |
| [D-10](#d-10) | P2 | Database | Per-chunk `gc_collect_cycles()` cost 33% with no memory benefit — **FIXED** |
| [T-06](#t-06) | P1 | Auth | No device cap on API tokens — **FIXED** |
| [U-01](#u-01) | **P1** | Uploads | No size ceiling, no image-decode proof, no pixel-bomb guard; rejected worker uploads — **FIXED** |
| [H-05](#h-05) | **P1** | HTTP | A middleware abort skipped all outer middleware post-processing — **FIXED** |
| [H-06](#h-06) | P2 | HTTP | Rate-limit headers set outside the response object — **FIXED** |
| [SE-01](#se-01) | P1 | Session | `session_write_close()` is never called — lock held for the whole request |
| [BK-01](#bk-01) | P2 | Backup | Archives are neither encrypted nor checksummed |
| [BK-02](#bk-02) | P2 | Backup | No restore command |
| [Q-01](#q-01) | P2 | Queue | No batching, chaining, unique jobs, or `queue:restart` — **PARTIAL**: restart, unique jobs and a parallel supervisor landed; batching and chaining still open |
| [CO-01](#co-01) | P2 | Console | `Commands.php` is a 2,602-LOC monolith |
| [A-01](#a-01) | P2 | Arch | `app/` ↔ `systems/` boundary leaks (Kernel + 38 middleware in `app/`) |
| [A-02](#a-02) | P2 | Arch | `Components\` legacy layer duplicates `Core\` |
| [A-03](#a-03) | P3 | Arch | Runtime classification keys off a client-controlled header |
| [QA-01](#qa-01) | P2 | Quality | PHPStan pinned at level 2 of 10 |
| [QA-02](#qa-02) | P2 | Quality | Unit tests only — no HTTP/integration suite — **FIXED** |
| [QA-03](#qa-03) | P2 | Quality | `docs/` and `.github/skills/` are stale and self-contradictory |

**Totals: 60 findings** — 3 × P0, 19 × P1, 30 × P2, 8 × P3.
**27 FIXED**, 1 partial, 32 open. All three P0s are closed.

---

## Worker mode

<a id="w-01"></a>
### W-01 — Auth, CSRF and session state leak between requests in worker mode · **P0**

> **MITIGATED 2026-08-25.** Worker mode is switched off, not fixed. `.rr.yaml` is deleted,
> the `Caddyfile` worker block is commented out, and both entry points
> (`public/index.php`, `RoadRunnerWorker.php`) now require `MYTH_EXPERIMENTAL_WORKER=1`.
> Every gap below is still present behind that flag.

`systems/Core/Server/WorkerState.php:26-33` resets exactly six classes plus the
`database.runtime` service:

```php
private const CORE_STATEFUL_CLASSES = [
    CspNonce::class, AuditLogger::class, ConnectionPool::class,
    PerformanceMonitor::class, QueryCache::class, BladeEngine::class,
];
```

`framework_service()` (`systems/hooks.php:286`) memoises every service in a static store.
The services **not** reset include `auth`, `csrf`, `security`, `logger`, `feature`,
`response`, `blade_engine`, `maintenance`, `route.provider`.

`Components\Auth` caches the resolved user and the ACL result set per instance
(`resetResolvedAuthCaches()`, `aclCacheKey()`, `invalidateAclCache()` at `Auth.php:2658-2680`).
Because the instance survives the request, **request N+1 can be served request N's
authenticated user and permission set**.

Additionally, `bootstrap.php` is required once outside the worker loop, so `session_start()`
runs exactly once per worker. `$_SESSION` is then shared by every request that worker handles.

`Core\Http\Request::$current` (`Request.php:66`) and `Response::$pendingLinkHeaders`
(`Response.php:272`) are also static and unreset.

**Impact:** authenticated identity, permissions, CSRF tokens and session contents cross
request boundaries. This is a cross-user data disclosure.

**Fix (choose one):**
- **Now:** delete `.rr.yaml`, the `Caddyfile` worker block, and `RoadRunnerWorker.php`, or move
  them behind a loud `MYTH_EXPERIMENTAL_WORKER=1` guard. Document worker mode as unsupported.
- **Properly:** make `WorkerState::flush()` call `reset_framework_service()` (no argument →
  resets everything), add `Request::setCurrent(null)` and `Response::resetLinkHeaders()`, and
  move session start/`session_write_close()` inside the loop.

---

<a id="w-02"></a>
### W-02 — `exit`/`die` in the HTTP path terminates the worker · **P0**

> **MITIGATED 2026-08-25** by the same gate as W-01 — the worker can no longer be
> started accidentally. The `exit` calls themselves remain and still skip middleware
> post-processing under php-fpm; that is [R-01](#r-01), still open.

45 `exit`/`die` calls exist in non-console request-path code across 30 files, including
`Response::json()` (`Response.php:219`), `Response::redirect()` (`Response.php:225`), and
every short-circuiting middleware (`VerifyCsrfToken`, `XssProtection`, `RateLimit`,
`BlocklistIp`, `EnforceContentType`, `EnforceOriginPolicy`, `ValidateTrustedHosts`,
`ValidateTrustedProxies`, `ValidatePayloadLimits`, `ValidateRequestSafety`,
`ValidateUploadGuard`, `EnforceMenuAccess`, `RequirePermission`, `RequireAnyPermission`,
`RequireRole`, `EnsureGuest`, `DetectIdor`, …).

Under RoadRunner each of these ends the worker process. Every 404, 405, CSRF mismatch,
rate-limit rejection and unauthenticated request costs a worker respawn — which under load is
a self-inflicted denial of service.

`Core\Http\ResponseEmitted` already exists and is the correct mechanism; it is used by
`jsonResponse()` but not by `Response::json()` or by any middleware.

**Fix:** convert `Response::json()`/`redirect()` to throw `ResponseEmitted`, add a single
emission point in `Kernel::handle()`, and convert the middleware short-circuits. This is
mechanical and testable. Depends on / enables **R-01**.

---

<a id="w-03"></a>
### W-03 — `$_FILES` receives PSR-7 objects · P1

`RoadRunnerWorker.php:80`:
```php
$_FILES = $psrRequest->getUploadedFiles();
```
`getUploadedFiles()` returns `UploadedFileInterface` objects. `Components\Files`,
`FileUploadGuard` and `custom_upload_helper.php` all expect the native
`['name','type','tmp_name','error','size']` array shape. Uploads silently fail in worker mode.

**Fix:** map each `UploadedFileInterface` into the native array shape, writing the stream to a
temp file, or reject multipart requests in worker mode until implemented.

---

<a id="w-04"></a>
### W-04 — Superglobal and response-code residue between requests · P2

The loop overwrites `$_SERVER['REQUEST_METHOD']`, `REQUEST_URI`, `QUERY_STRING`, `HTTP_HOST`,
etc., but never *removes* keys. A request carrying `HTTP_X_FORWARDED_FOR` leaves that key set
for the next request on the same worker, which then resolves the wrong client IP — affecting
rate limiting, IP blocklisting and audit logs. `http_response_code()` is likewise sticky.

**Fix:** snapshot a clean `$_SERVER` baseline at worker boot and restore it each iteration;
call `http_response_code(200)` at the top of the loop.

---

## Database

<a id="d-01"></a>
### D-01 — `validateColumn()` validates nothing; JOIN foreign keys reach SQL unescaped · **P1**

> **FIXED 2026-08-25.** `DatabaseHelper::parseIdentifier()` and `quoteIdentifier()` added;
> all five JOIN builders now quote the foreign key through them, and `crossJoin()` quotes
> its table the same way. `validateColumn()` is unchanged but its docblock now states
> plainly that it is not an injection guard. 39 tests in `JoinIdentifierInjectionTest`.

`systems/Core/Database/DatabaseHelper.php:353`:

```php
protected function validateColumn($column, $default = 'Column')
{
    if (!is_string($column)) { throw ...; }
    if (empty($column))      { throw ...; }
    // ...and that is the entire validation
}
```

It reads like an identifier validator. It is a non-empty-string assertion. Compare
`validateTableName()` at `DatabaseHelper.php:237`, which does apply
`/^[a-zA-Z_][a-zA-Z0-9_.\-]*$/` — table names are protected, column names are not.

All five JOIN builders then interpolate the foreign key **raw** between backticks
(`HasJoins.php:46, 73, 102, 131, 160`):

```php
$this->joins .= " $joinType JOIN $safeTable ON $safeTable.`$foreignKey` = $safeLocalKey";
```

`$table` is escaped with `str_replace('`', '``', …)`. `$foreignKey` is not. A backtick in
`$foreignKey` closes the identifier quote and everything after it is SQL.

`quoteSortColumn()` (`HasAggregates.php:348`) shows the team already understands this class of
bug and fixed it for ORDER BY — the same treatment has not reached JOIN.

**Impact:** SQL injection wherever a column name is derived from configuration, a mapping
table, or request input. Today's call sites appear to use literals, so this is a latent
framework-level hole rather than a live exploit — but a framework must not permit it.

**Fix:** give `validateColumn()` the same regex treatment as `validateTableName()`
(`/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/`), and backtick-escape `$foreignKey`
through `_escapeJoinColumn()` at all five sites. Add a test asserting a backtick-bearing
column throws.

---

<a id="d-02"></a>
### D-02 — `select()` passes through function-shaped and dotted columns verbatim · P2

`BaseDatabase.php:1139-1160`:

```php
if (strpos($column, '.') !== false ||
    stripos($column, ' as ') !== false ||
    preg_match('/\w+\s*\(.*\)/i', $column)) {
    return $column;    // no escaping at all
}
return "`{$this->table}`.`{$column}`";
```

Any string matching `\w+\s*\(.*\)` — for example `x((SELECT password FROM users))` — is
concatenated straight into the SELECT list. This is intentional (aggregates and correlated
subqueries must be expressible), but it means `select()` is a raw-SQL surface wearing the
name of a safe one.

**Fix:** split the API. `select()` accepts identifiers only, escaped unconditionally;
`selectRaw()` (which already has null-byte, stacked-query and comment guards at
`BaseDatabase.php:1173`) is the documented path for expressions. Deprecate the passthrough
behind a config flag for one release.

---

<a id="d-03"></a>
### D-03 — `_escapeJoinColumn()` trusts pre-backticked input · P2

> **FIXED 2026-08-25.** It now strips, validates and re-quotes instead of returning
> pre-backticked input unchanged. Covered by `JoinIdentifierInjectionTest`.

`HasJoins.php:190`:
```php
if (strpos($column, '`') !== false) {
    return $column;      // "already escaped" — assumed, not verified
}
```
A caller passing `` `id` = 1 OR `1 `` gets it back untouched and spliced into the ON clause.
Combined with **D-01** (no validation upstream) there is no layer that catches this.

**Fix:** never trust pre-quoted input. Strip backticks, validate against the identifier regex,
re-quote.

---

<a id="d-04"></a>
### D-04 — `BaseDatabase` is 4,415 LOC in one class · P2

Six traits are already extracted (`HasWhereConditions`, `HasJoins`, `HasAggregates`,
`HasStreaming`, `HasEagerLoading`, `HasProfiling`, `HasPaginateCountCache`,
`HasDebugHelpers`) but the core still carries connection management, state save/restore,
query building, all terminals, all writes, batching, sanitising, profiling and error logging.

**Impact:** every change risks unrelated behaviour; the `_saveQueryState()`/`_restoreQueryState()`
pair and `paginate()`'s manual state clone (see **D-06**) exist because state is spread across
~40 properties on one object.

**Fix:** continue trait extraction — `HasTransactions`, `HasBatchWrites`, `HasSafeOutput`,
`HasConnectionRouting`. Target < 1,500 LOC in the base class. No behaviour change; the 31
existing DB tests are the safety net.

---

<a id="d-05"></a>
### D-05 — MySQL/MariaDB only; no SQLite · P2

`DriverRegistry.php` exists but only `MySQLDriver` and `MariaDBDriver` are implemented, so
`php myth perf:benchmark` reports `DB unavailable` on any machine without a MySQL instance,
and the test suite cannot exercise real SQL against an in-memory database. That is why the
693 tests contain no end-to-end query assertions.

**Fix:** add a `SqliteDriver` + `SqliteGrammar` scoped to what tests need. This unlocks
integration testing (**QA-02**) more than it serves production.

---

<a id="d-06"></a>
### D-06 — `paginate()` clones builder state field-by-field · P3

`BaseDatabase.php:2296-2312` copies 11 named properties onto a sub-builder. Any state field
added later (a new hint, a new lock mode, a new soft-delete flag) will be silently dropped from
the COUNT query, producing a count that disagrees with the page.

**Fix:** add an explicit `copyQueryStateTo(self $other)` next to the property declarations, so
adding a property forces you to look at one list.

---

<a id="d-07"></a>
### D-07 — Deadlock handling was too narrow to be useful · **P1**

> **FIXED 2026-08-25.** `retryOnDeadlock()` added (config-driven attempts, full-jitter
> exponential backoff, capped at 2s); the retryable set widened from 2 codes to 8 plus
> SQLSTATE 40001; the whole `getPrevious()` chain is now walked; a failing rollback no
> longer masks the original error; the queue's job claim passes an explicit retry budget;
> and batch writes order their keys so the commonest deadlock cannot form at all.
> 25 tests in `DeadlockResilienceTest`, 24 in `LockOrderingTest`, 19 in
> `ServerCapabilitiesTest`.

A deadlock is not a fault in InnoDB — it is how the engine breaks a wait cycle. It picks a
victim, rolls that transaction back completely, and expects a replay. Four things stopped
that happening:

1. **`Worker::pop()` ran with retries disabled.** It called `db()->transaction($cb)` and the
   default was 1 attempt, so the single most contended statement in the framework treated a
   routine deadlock as a lost job.
2. **Only two driver codes were recognised** (1213, 1205). Galera/Group Replication
   certification conflicts (3058), XA branch rollbacks (1479/1614) and dropped connections
   (2006/2013) all fell through as permanent failures.
3. **Only the outermost exception was inspected.** The builder wraps PDO failures in its own
   exceptions in several paths, so the `PDOException` carrying the deadlock was never seen.
4. **The backoff was `random_int(20_000, 60_000) * $attempt`** — a narrow band around a fixed
   value. With N workers deadlocking on the same rows they all wake up together and deadlock
   again. Full jitter (a uniform draw from `[0, cap]`) is what actually decorrelates them.

**Note on the default:** `transaction()` still defaults to a **single** attempt. An existing
test asserts this deliberately — a retry re-executes the callback, and while the database
writes are rolled back, anything it did outside the database (mail, HTTP, file writes) happens
again. Retrying is opt-in via `retryOnDeadlock()`, which is documented as requiring an
idempotent callback.

---

<a id="d-08"></a>
### D-08 — MariaDB bulk writes silently discarded every row · **P0**

> **FIXED 2026-08-25.** The real implementations moved from `MySQLDriver` into
> `Core\Database\Concerns\HasBatchWrites`, now used by both drivers. 24 tests in
> `BatchWriteParityTest` and `LockOrderingTest`.

`MariaDBDriver::batchInsert()` and `batchUpdate()` were stubs:

```php
public function batchInsert($data)
{
    return $this;
}
```

`iterableWriteBatchSucceeded()` (`BaseDatabase.php:1287`) returns `false` only for `false` or
an object/array carrying `code >= 400`. A query builder has no `code` property, so returning
`$this` **reads as success**.

On any connection with `driver: mariadb`, every one of these reported success and wrote
nothing:

- `insertInBatches()`, `updateInBatches()`
- `Model::bulkInsert()`, `Model::bulkUpdate()`, `Model::importInBatches()`, `Model::updateInBatches()`

No exception, no log line, an affected-row count derived from the input array rather than the
database. Silent data loss on the exact code path built for large imports.

The SQL in the MySQL implementations is plain enough that MariaDB accepts it unchanged, so
sharing one implementation is both the fix and the guarantee the two cannot drift apart again.

**Still worth doing:** `iterableWriteBatchSucceeded()` is too permissive. It should require a
recognised success shape rather than treating "not obviously a failure" as success — that
permissiveness is what made this silent rather than loud.

---

<a id="h-01"></a>
### H-01 — Twenty-one middleware hand-rolled the same content negotiation · P2

> **FIXED 2026-08-25.** `Abort::problem()`, `Abort::denied()` and
> `Abort::unauthenticated()` cover all three shapes. Zero `Response::json(`,
> `http_response_code(<arg>)` or `echo` remain in `app/http/middleware/`, asserted
> per-file by `AbortNegotiationTest`.

Every middleware ended with a variant of:

```php
if ($request->expectsJson()) {
    Response::json(['code' => $status, 'message' => $message], $status);
}
Abort::text($message, $status);
```

Three shapes, differing only in the non-JSON branch: plain text, the 403 page, or a
redirect to login. Copying the decision twenty-one times meant the JSON payload drifted
between middleware — some included the offending permission, some did not; some used
`code`, some relied on the HTTP status alone — so a client could not rely on the shape.

It also made every future cross-cutting change (an `error` slug, a `request_id`, RFC 9457
problem details) a twenty-one-file edit.

---

<a id="h-02"></a>
### H-02 — Compression silently stopped working · **P1**

> **FIXED 2026-08-25.** `CompressResponse` now compresses the returned
> `Responsable`, keeping the legacy echo path as a fallback. `StreamedResponse` and
> `BinaryFileResponse` pass through untouched. 17 tests.

A regression introduced by the response-emission work, and a good illustration of why
that work needed tests either side of it.

The middleware buffered whatever the route echoed:

```php
ob_start();
$response = $next($request);
$buffer = ob_get_clean();     // ← empty once routes return objects
```

Once controllers returned `JsonResponse`/`HtmlResponse` instead of echoing, the buffer was
always empty. The middleware compressed an empty string, echoed it, and handed the real
response on **uncompressed**. Nothing errored. Responses were simply 60–80 % larger than
they should have been, on every route, with `compress` still listed in the middleware
group and looking active.

Two smaller bugs were fixed in the same pass: `Vary` was overwritten rather than merged
(which lets a cache serve a compressed body to a client that cannot read it), and a
compressed result larger than the input was still sent.

---

<a id="h-03"></a>
### H-03 — The API log recorded every request as 200 · **P1**

> **FIXED 2026-08-25.** `ApiRequestLogger::describeResponse()` reads the status from
> the response object; `AttachRequestFingerprint` stamps `JsonResponse` bodies as well
> as arrays. 10 tests.

Two more consequences of responses becoming objects, both silent.

`ApiRequestLogger` took the status from `http_response_code()`, which is read *before* the
response is emitted. With the status now living on the response object and not yet sent,
every log line recorded **200** — including 401s, 404s and 500s. The one thing an API
request log exists for is spotting errors, and it had stopped being able to.

`AttachRequestFingerprint` injected `request_id` and `trace_id` into the response only
`if (is_array($response))`. Once controllers returned `JsonResponse`, the ids stopped
reaching API bodies, so a user reporting a problem had no id to quote.

Neither failed a test, because no test asserted on either.

---

<a id="h-04"></a>
### H-04 — Failure handlers could fail · P2

> **FIXED 2026-08-25.** `Core\Support\SafeLog` degrades service → `Logger::instance()`
> → `error_log()` and never throws. Used by `WorkerState`, `Abort::view()`,
> `Router::renderErrorView()` and the transaction retry loop.

`logger()` resolves a service, and `framework_service()` throws when nothing is registered
under that name. Fine in a normal request; wrong inside a failure handler, because the
places that most need to log are the places where the container may be half-built, already
flushed, or never bootstrapped.

This bit twice, both times caught by a test running without a logger registered:

- `WorkerState::flush()` — a logging failure aborted the rest of the flush, leaving the
  process in exactly the dirty state the class exists to prevent.
- `Abort::view()` — the catch block written to survive a broken error page threw instead.

Both were bugs in code written earlier the same day. The pattern is the point: a handler
that can throw is not a handler.

---

## HTTP & routing

<a id="r-01"></a>
### R-01 — `Response::json()` / `redirect()` call `exit` · **P1**

`Response.php:213-226`. Consequences even in classic php-fpm:

- Every middleware's post-`next()` code is skipped. `CompressResponse`, `SetResponseCache`,
  `CacheResponse`, `ApiRequestLogger`, `NormalizeResponseTime` and `MemoryProfiler::end()`
  all do work after `$next()` and are bypassed on any error path.
- There is no single point where the response is emitted, so cross-cutting concerns (ETag,
  compression, timing headers) cannot be applied uniformly.
- Under a worker SAPI it kills the process (**W-02**).

The codebase already contains the right answer — `ResponseEmitted` — with a docblock
explaining exactly this. It was applied to `jsonResponse()` and stopped there.

**Fix:** throw `ResponseEmitted` from `Response::json()`/`redirect()`; make
`App\Http\Kernel::handle()` the one emitter; convert the 30 middleware short-circuits.
Keep a deprecated `Response::sendJson()` for anything that genuinely must terminate.

---

<a id="r-02"></a>
### R-02 — Route-model-binding 404 always emits JSON · P2

`Router.php:801-815`:
```php
$model = $typeName::findById($params[$name]);
if ($model === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['code' => 404, 'message' => 'Resource not found.']);
    exit;
}
```
A browser hitting `/reports/{report}` with a bad id gets raw JSON instead of the 404 view, and
`$request->expectsJson()` is never consulted. It also `exit`s (see R-01).

**Fix:** throw a `NotFoundException` (or `ResponseEmitted`) and let `dispatch()`'s existing
content-negotiation handle it.

---

<a id="r-03"></a>
### R-03 — Reflection on every action invocation · P2

`Router.php:775` builds a `ReflectionMethod`/`ReflectionFunction` and walks every parameter
on each request. For a hot API endpoint this is pure repeated work — the signature never
changes between requests.

**Fix:** cache the resolved parameter plan (`[kind, name, typeName, default]`) keyed by
`Class::method`, and persist it into the route cache so it survives process restarts.
Expected saving is small per request but it is on the critical path of every request.

---

<a id="r-04"></a>
### R-04 — Dynamic routes matched by linear scan · P2

`Router.php:688-716`. Static routes are an O(1) hash lookup — good. Parameterised routes are
iterated one `preg_match` at a time. With the current ~40 routes this is invisible; the
measured 0.0517 ms/op at 200 routes is fine. At 500+ API routes, worst case (a miss, or the
last-registered route) is 500 regex executions.

**Fix (cheap):** bucket dynamic routes by first static path segment and by segment count
before scanning. **Fix (thorough):** compile chunks of ~30 routes into a single alternation
regex with numbered groups, the way Laravel and FastRoute do.

---

<a id="r-05"></a>
### R-05 — `route:cache` silently drops closure routes · P2

`Router::getCompiledRoutes()` (`Router.php:38-87`) increments a `skipped` counter for closure
actions. `app/routes/web.php` defines `/modal/content` as a closure, and both
`Router::redirect()` and `Router::view()` generate closures internally. After `php myth deploy`
(which runs `route:cache`), those routes **404 in production and work in development**.

**Fix:** make `route:cache` fail loudly — print each skipped route and exit non-zero unless
`--allow-closures` is passed. Then convert the three known closures to controller methods.

---

<a id="r-06"></a>
### R-06 — `middlewareCache` never gets a second hit · P3

`Router.php:653` caches resolved middleware instances on the `Router` object, but the router is
constructed fresh in `Kernel::handle()` for each request and `dispatch()` resolves exactly one
stack. The cache is written once and read never.

**Fix:** make it `static` (safe: it caches instances, so it must then be cleared by
`WorkerState`), or delete it. Deleting is honest; making it static only pays off in worker mode.

---

<a id="r-07"></a>
### R-07 — `HEAD` responses include a body · P3

`findRoute()` folds `HEAD` to `GET` (`Router.php:689`) and the action then echoes its body.
Apache/nginx usually strip it, but the framework has computed and buffered it, and under the
built-in server or a worker SAPI it is sent.

**Fix:** flag HEAD on the request and discard the output buffer at the emission point.

---

## Auth & tokens

<a id="t-01"></a>
### T-01 — Every authenticated API request performs a write · **P1**

> **FIXED 2026-08-25.** `TokenService::touchLastUsedAt()` writes only when the stored
> value is staler than `auth.token.last_used_precision` (default 60s; 0 restores the old
> behaviour). Future timestamps are treated as stale so clock skew cannot pin the column.
> `last_used_at` is now part of the token SELECT so the comparison costs nothing extra.

`TokenService.php:88`:
```php
$this->touchTokenRecord($tokenTable, $tokenIdColumn, $tokenRecord[$tokenIdColumn] ?? null, [
    $tokenLastUsedAtColumn => $this->now(),
    $tokenUpdatedAtColumn  => $this->now(),
]);
```
This runs on **every** token-authenticated request, before the controller.

Consequences for a mobile API:
- A read-only `GET` becomes a read-write transaction. Read-replica routing
  (`default.read[]`, `sticky`) is defeated — `markStickyWrite()` pins the connection to the
  primary for the rest of the request.
- One row-lock per active token per request. A chatty mobile client polling every few seconds
  produces sustained write load proportional to active users.
- Replication lag grows for no product benefit.

Laravel Sanctum has the same design and it is a known scaling complaint there too.

**Fix:** only write when the stored `last_used_at` is older than a threshold
(`auth.token.last_used_precision`, default 60 s). One comparison, ~99 % of the writes gone.
Optionally batch through the queue.

---

<a id="t-02"></a>
### T-02 — DDL on every token creation · P1

> **FIXED 2026-08-25.** `ensureTokenTable()` is gated by `auth.token.auto_migrate`
> (development-only default). `Auth::ensureApiKeyTable()`/`ensureOAuth2Table()` and
> `QueueDispatcher::ensureTable()` gained per-process guards — the queue path was the
> worst offender at roughly seven round trips per dispatched job. `Dispatcher::reset()`
> is registered with `WorkerState`.
>
> **Follow-up:** there is no migration creating `system_jobs`/`system_failed_jobs`; they
> exist only via `ensureTable()`. Add one before gating the queue DDL by config too.

`TokenService.php:110` calls `ensureTokenTable()` inside `createToken()`;
`TokenService.php:331` issues `CREATE TABLE IF NOT EXISTS …`. Every login therefore pays a
DDL round-trip. On MySQL this also takes a metadata lock.

The same pattern exists at `Auth.php:2581` (`ensureApiKeyTable()`) and `Auth.php:2610`
(`ensureOAuth2Table()`), and in `Queue\Dispatcher::ensureTable()` (`Dispatcher.php:133`).

Migration `20260308_005_create_users_access_tokens_table.php` already creates all three tables
properly, so the runtime DDL is redundant.

**Fix:** remove the runtime `ensureTable()` calls from the request path. If a safety net is
wanted, run it once per process behind a static flag, or only when `ENVIRONMENT === 'development'`.

---

<a id="t-03"></a>
### T-03 — Token comparison happens in SQL, not `hash_equals` · P3

`TokenService.php:387-407` looks the token up with
`->where($tokenIdColumn, $id)->where($tokenColumn, $hash)`. The secret comparison is therefore
a database string comparison, not a constant-time one. `Core\Security\Hasher::equals()` exists
and is unused here.

The practical leak is tiny (an indexed lookup on a SHA-256 hash), but the fix is one line:
select by id, then `Hasher::equals($stored, $computed)` in PHP — which is what Sanctum does.

---

<a id="t-04"></a>
### T-04 — No refresh tokens, device binding, or global revocation · **P1**

For the stated goal ("proper API support for mobile") the token model is missing the pieces a
mobile client needs:

| Missing | Why a mobile app needs it |
|---|---|
| Refresh tokens + rotation | Access tokens must be short-lived; without refresh, either tokens live for months or users re-login constantly. |
| Device / installation binding | Identifying and revoking "this phone" independently of other sessions. |
| `token_version` on the user row | Logging out every device after a password change or breach, without deleting rows. |
| Push-notification token storage | Standard part of a mobile back end. |
| Reuse detection | Detecting a stolen refresh token when the old one is replayed. |

What exists: creation, per-token revocation, `revokeAllTokens($userId)`, rotation of a
*given* token, abilities, and expiry.

**Fix:** see [11-api-mobile-readiness.md](11-api-mobile-readiness.md) for the concrete schema
and endpoint design.

---

<a id="t-05"></a>
### T-05 — `Components\Auth` is 3,998 LOC with a half-finished extraction · P2

`TokenService`, `AuthorizationService`, `AccessCredentialService` and `LoginPolicy` were
extracted into `systems/Core/Auth/`, but they are called with **callables passed in for
`safeColumn`, `safeTable`, `isFutureOrNull`, `isUserStatusAllowed`** rather than owning that
logic — e.g. `TokenService::tokenUser(callable $bearerToken, callable $safeColumn, callable
$safeTable, callable $isFutureOrNull, callable $isUserStatusAllowed)`.

That signature is the shape of an extraction that stopped halfway: the services depend on the
god-object they were supposed to replace. Reading the auth flow requires holding both files in
your head.

**Fix:** move `safeColumn`/`safeTable`/`isFutureOrNull`/`isUserStatusAllowed` into a shared
`Core\Auth\SchemaMap` value object injected once at construction. Remove the callable
parameters. Purely mechanical, well covered by the auth tests.

---

## Security

<a id="s-01"></a>
### S-01 — CSRF is double-submit cookie, not session-bound · P1

`CSRF.php:341-370` compares the `csrf_cookie` value against the submitted token with
`hash_equals`. Both halves are attacker-writable if the attacker controls **any** origin that
can set a cookie on the parent domain — a compromised or user-content subdomain, or a MITM on
a plain-HTTP sibling host. There is no server-side binding to the session.

Mitigating controls that are present and do help: `validateOrigin()` (`CSRF.php:377`) with
`csrf_allow_missing_origin` defaulting to **false**, and `SameSite=Lax`. Those are the reason
this is P1 and not P0.

**Fix:** store the token (or an HMAC of it keyed by `APP_KEY`) in `$_SESSION` and compare the
submitted value against that. Keep the cookie purely as the transport for JS. This is the
Laravel model and is a contained change to `CSRF::init/getToken/validateToken`.

---

<a id="s-02"></a>
### S-02 — `HttpOnly` CSRF cookie forces a header-relay workaround · P2

`security.csrf.csrf_httponly => true` means JavaScript cannot read the cookie, so
`VerifyCsrfToken` sends the value back in an `X-CSRF-TOKEN` **response header**
(`VerifyCsrfToken.php:51`) for the front end to pick up. That works, but:
- the token is now in a response header, which proxies and logs may retain;
- any client that misses the header gets a 419 loop;
- it is a non-standard contract a mobile client has to be told about.

**Fix:** if S-01 is implemented (session-bound token), the cookie can be dropped entirely and
the token delivered once via a `/csrf-token` endpoint or a meta tag. Alternatively follow the
`XSRF-TOKEN` convention: a non-HttpOnly cookie holding a token that is *also* session-bound,
so reading it grants nothing.

---

<a id="s-03"></a>
### S-03 — CSP ships `'unsafe-inline'` by default · P1

`app/config/security.php:5-6`:
```php
$cspNonceEnabled     = (bool) env('CSP_NONCE_ENABLED', false);
$cspAllowUnsafeInline = (bool) env('CSP_ALLOW_UNSAFE_INLINE', true);
```
So out of the box `script-src` contains `'unsafe-inline'` and nonces are off. A CSP with
`'unsafe-inline'` in `script-src` provides **no XSS protection** — that is the one directive
value that defeats the whole mechanism.

The infrastructure to do it right is already built: `Core\Security\CspNonce`, the `@nonce`
Blade directive, `report-uri` handling, `CspViolationLogger`, a `csp_violations` table, and a
`csp:report` command. The blocker is the bundled Sneat views, which use inline `onclick`
handlers and inline `<script>` blocks.

**Fix:** treat this as a migration, not a config flip. (1) Turn on `CSP_MODE=report` with
nonces enabled in staging. (2) Use `csp:report` to enumerate violations. (3) Move inline
handlers to `addEventListener` in external files. (4) Flip
`CSP_ALLOW_UNSAFE_INLINE=false`, `CSP_NONCE_ENABLED=true`, `CSP_MODE=enforce`.
Note the API surface can flip immediately — it renders no HTML.

---

<a id="s-04"></a>
### S-04 — Input XSS filter is log-only by default · P2

`security.xss_input_blocking` defaults to `false` and `XssProtection::shouldBlock()`
(`XssProtection.php:79`) honours it. **The source comment explaining why is correct** — a
blocklist rejects legitimate text like `C++ template <vector>` and misses evasions like
`jav&#x09;ascript:` — and output escaping is the real defence.

This is listed not as a defect but because it is routinely misread as active protection. It
should be stated in the API documentation, and the middleware's log output should be wired to
the audit log so the signal is not lost.

---

<a id="s-05"></a>
### S-05 — `Encryptor` hard-fails without ext-sodium + AES-NI · P2

`Encryptor::assertSupported()` (`Encryptor.php:120`) throws when
`sodium_crypto_aead_aes256gcm_is_available()` is false, and the error message itself suggests
the fix: *"Switch to `sodium_crypto_aead_xchacha20poly1305_ietf_*`, which has no hardware
requirement."*

The current dev machine has no `sodium` at all, so **every call throws today**. Any shared host
or ARM VM without AES-NI is in the same position, and this framework explicitly targets shared
hosting.

**Fix:** implement the fallback the error message already describes. Prefix the stored bundle
with a one-byte algorithm tag so existing AES-GCM ciphertexts keep decrypting. Add
`php myth security:crypto-check`.

---

## Validation

<a id="v-01"></a>
### V-01 — No `unique:` or `exists:` rules · **P1**

> **FIXED 2026-08-25.** `unique:table[,column[,ignoreValue[,ignoreColumn]]]`,
> `unique_with:table,column,otherField...[,ignore:N[,ignore_column:col]]` and
> `exists:table[,column]` added. Soft-deleted rows are counted on purpose, to match what
> a plain UNIQUE index does. Empty values skip the query. A failed lookup fails closed.
> 13 tests in `ValidationDatabaseRulesTest`.

`Components\Validation` implements 70 rules. Grepping for `unique`/`exists` returns nothing;
no `app/http/requests/*.php` uses them. These are two of the three most-used rules in any
Laravel-style application.

The cost is visible in the codebase: `UserController` hand-rolls a SELECT-then-INSERT
uniqueness check, and migration `20260824_017_add_users_unique_constraints.php` had to be
written to close the resulting race — its own docblock says so:

> *"Uniqueness was enforced only by a SELECT-then-INSERT check in UserController, which is a
> check-then-act race."*

Every future model with a unique field repeats that work.

**Fix:** add `unique:table,column[,ignoreId[,idColumn]]` and `exists:table,column`, both
resolving through `db()` with the connection taken from the FormRequest. Document that the
rule is a UX affordance and a **DB unique index is still required** — the race is real and only
the index closes it. Add `unique_with:` for composite keys.

---

<a id="v-02"></a>
### V-02 — `Components\Validation` is 2,939 LOC · P2

70 rules, a batch validator, deep-array handling and message formatting in one class with
loose typing. Adding a rule means editing the god-class.

**Fix:** a `Core\Validation\Rule` interface plus one class per rule, discovered from a map;
keep the existing façade so no call site changes. Do this at the same time as V-01 so the new
DB rules land in the new structure.

---

## Views

<a id="b-01"></a>
### B-01 — Nested parentheses truncate ~25 Blade directives · **P1** (reproduced)

> **FIXED 2026-08-25.** Every affected directive now uses `replaceDirectiveCalls()`, which
> also gained a word boundary (so `@for` cannot eat `@foreach`) and tolerates whitespace
> before the parenthesis. Bare `@break`/`@continue` were never compiled at all and now are,
> which is what made `@switch`/`@case` usable. 25 tests in `BladeNestedExpressionTest`;
> all 17 bundled views recompile to valid PHP.

The compiler has two tiers. `replaceDirectiveCalls()` + `extractBalancedExpression()`
(`BladeEngine.php:914`) is a correct quote- and depth-aware scanner, used for
`@sri @section @include @includeIf @includeWhen @includeUnless @yield @component @slot @each
@forelse`.

The most-used directives are **not** on that path. `BladeEngine.php:699`:
```php
$content = preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $content);
```

Reproduced:
```
IN : @if(count($users) > 0)YES@endif
OUT: <?php if (count($users): ?> > 0)YES@endif        ← PHP parse error

IN : @foreach(array_keys($a) as $k)x@endforeach
OUT: <?php foreach (array_keys($a): ?> as $k)x@endforeach
```

Affected: `@if @elseif @unless @isset @empty @foreach @for @while @switch @case @can @cannot
@class @style @json @error @env @session @checked @selected @disabled @readonly @required
@dd @dump @method`.

The bundled views happen to contain no nested parentheses, which is why 693 tests pass. Any
developer writing normal Blade — `@if(count($x))`, `@if(!empty($y))`, `@can('edit', $post)`
with a call inside — hits it immediately. This is the single biggest obstacle to the stated
"easy to use" goal.

**Fix:** route every remaining directive through `replaceDirectiveCalls()`. The helper already
exists and handles quotes and escapes; this is a mechanical substitution of ~25 `preg_replace`
calls. Add a compiler test per directive with a nested-call expression.

---

<a id="b-02"></a>
### B-02 — No directive-registration API · P3

Adding a directive means editing `compileString()`. A `BladeEngine::directive(string $name,
callable $compiler)` registry (routed through `replaceDirectiveCalls`) would let applications
extend the engine without patching the framework — and would give B-01's fix a natural home.

---

<a id="b-03"></a>
### B-03 — Regex HTML minifier · P3

`minifyRenderedHtml()` (`BladeEngine.php:386`) collapses whitespace in rendered output.
Content inside `<pre>`, `<textarea>` and `<script>` blocks is whitespace-significant.
`view_minify_output` defaults to **true** in `framework.php`.

**Fix:** protect `pre|textarea|script|style` spans before collapsing (the same
extract-placeholder-restore trick `@verbatim` already uses), or default the flag to false.

---

<a id="b-04"></a>
### B-04 — Filesystem directives exposed to templates · P2

`@file @mkdir @rename @unlink @stat` are compiled directives. A template layer that can delete
files turns any template-injection bug — or any careless `{!! !!}` of user content into a
template path — into arbitrary file deletion.

**Fix:** remove them. If they serve a real internal use, move that logic to a helper called
from a controller.

---

<a id="b-05"></a>
### B-05 — `EXTR_SKIP` silently drops common view variable names · P2

`BladeEngine::render()` (`BladeEngine.php:38-62`):

```php
$vars = array_merge($this->sharedViewData(), $shared, $data);
extract($vars, EXTR_SKIP);
```

`EXTR_SKIP` refuses to overwrite a variable that already exists — and the method's own locals
are in scope by then: `$view`, `$data`, `$shared`, `$isTopLevel`, `$viewFile`, `$compiled`,
`$vars`, `$content`, `$extends`, `$__blade`.

So `render('users', ['data' => $rows])` leaves `$data` bound to the **whole** payload array
`['data' => $rows]` rather than to `$rows`. The view renders the wrong thing with no error
and no warning, and `data` is one of the most natural names for a view payload.

Found while writing `BladeNestedExpressionTest` — a case passing `['data' => …]` failed for
exactly this reason.

**Fix:** prefix every local in `render()` and `includeView()` (`$__bladeView`, `$__bladeData`,
…). `$__blade` itself must keep its name because compiled templates reference it, so document
that one as reserved. Add a test asserting a `data` key reaches the view intact.

---

<a id="h-05"></a>
### H-05 — A middleware abort skipped every outer middleware · **P1**

> **FIXED 2026-08-25.** `Pipeline::process()` wraps each layer so a
> `ResponseEmitted` thrown further in is converted to a return value at that
> boundary. Found by the new end-to-end suite on its first run.

The Router caught `ResponseEmitted` around the *destination*, so an abort from a
controller unwound correctly and every middleware's post-`$next()` code ran.

An abort from a **middleware** did not. The exception propagated straight past every
outer layer:

```php
Tracing->handle()        // 'before' logged
  Blocking->handle()     // throws ResponseEmitted
// 'after' never runs — the exception skipped it
```

That is most aborts: rate limit, CSRF failure, permission denial, blocked IP,
payload too large. So on exactly the responses where observability matters most,
compression, response caching, timing normalisation and API request logging were
all silently skipped.

The previous session's claim that "middleware post-`next()` code now runs" was
therefore only half true, and this is the half that was wrong.

---

<a id="h-06"></a>
### H-06 — Rate-limit headers were set outside the response · P2

> **FIXED 2026-08-25.** `Core\Http\WithHeaders` decorates any `Responsable` with
> extra headers; `RateLimit` uses it for the success path.

`X-RateLimit-Limit` and `X-RateLimit-Remaining` were sent with raw `header()`. That
works under php-fpm because PHP accumulates headers globally, but it puts them
outside the response object, so they are invisible to the emitter, to
`CacheResponse`'s stored payload, and to any test that inspects a response. A
worker calling `header_remove()` between requests loses them entirely.

---

<a id="d-09"></a>
### D-09 — MariaDB had no statement timeout at all · **P1**

> **FIXED 2026-08-25.** `applyStatementTimeout()` picks the right variable per
> engine, each `SET SESSION` gets its own try/catch, and a failure is logged
> rather than swallowed.

`applySessionPerformanceRules()` ran:

```php
$pdo->exec('SET SESSION max_execution_time = ' . $statementTimeoutMs);
```

`max_execution_time` is **MySQL only**. MariaDB calls it `max_statement_time` and
measures it in **seconds**, not milliseconds. On MariaDB the statement raised
"Unknown system variable", which a single catch-all around the whole block
swallowed.

The result: a MariaDB deployment reading `DB_STATEMENT_TIMEOUT_MS=15000` in its
config had **no statement timeout whatsoever**. The one control you would rely on
to stop a runaway query was configured, believed, and absent.

The shared try/catch made it worse — a failure on the first `SET` also skipped the
second, silently.

**Related, and the answer to "why does it error after a few seconds":** on MySQL the
timeout *was* working, and a query exceeding it dies with driver code 3024
(MariaDB: 1969) as a bare PDO exception. `logDatabaseError()` now names the setting
and suggests `db:slow`, `chunkById()` or a higher limit.

---

<a id="d-10"></a>
### D-10 — Per-chunk garbage collection cost 33% for nothing · P2

> **FIXED 2026-08-25.** `collectStreamingGarbage()` sweeps every 50 chunks;
> `runGarbageCollector()` is split out so the cadence is testable. 15 tests.

`chunk()`, `cursor()`, `chunkById()` and the lazy variants called
`gc_collect_cycles()` on **every chunk**. Measured on a simulated 1,000,000-row
stream in 1,000-row chunks:

| GC strategy | Time | Peak memory delta |
|---|---|---|
| every chunk | 610 ms | 0.00 MB |
| every 50 chunks | 460 ms | 0.00 MB |
| never | 429 ms | 0.00 MB |

Identical peak memory. A chunk of plain rows is freed by refcounting the moment it
leaves scope; the cycle collector exists for *reference cycles*, which result
arrays do not contain. The call was pure overhead — on a 1M-row export, 1,000 full
heap sweeps.

Kept periodically rather than removed, because a consumer callback can legitimately
build cycles.

---

<a id="t-06"></a>
### T-06 — API tokens had no device cap · **P1**

> **FIXED 2026-08-25.** `auth.token.max_active_per_user` and `auth.token.on_limit`,
> enforced in `TokenService::createToken()`. 15 tests.

`auth.session_concurrency` capped how many browsers a user could be signed in from
— `max_devices = 1` for single-device, `N` for N, `0` for unlimited, with a choice
of revoking the oldest or denying the new login. Complete and correct.

It governed **web sessions only**. `createToken()` had no equivalent, so a user
limited to one browser could still accumulate unlimited tokens from a mobile app —
the surface where a device limit is normally the point.

Oldest is measured by **last use**, not creation: the device someone actually
stopped using is the one to retire.

---

<a id="u-01"></a>
### U-01 — Upload guard gaps · **P1**

> **FIXED 2026-08-25.** Size ceiling, image-decode verification, pixel-bomb guard,
> explained upload errors, and worker-compatible source acceptance. 24 tests.

`FileUploadGuard::store()` had good extension and MIME handling — a blocklist that
catches double extensions, an allowlist keyed on finfo-detected MIME, SVG banned,
random stored names, out-of-webroot storage, `.htaccess` deny-all. Four gaps:

1. **No size ceiling.** It relied entirely on `upload_max_filesize`, a server-wide
   backstop rather than a per-route policy.
2. **No proof the bytes are an image.** finfo reads the magic bytes at the head of
   a file, which a polyglot satisfies while carrying a payload in its tail.
   `getimagesize()` has to parse real dimensions out of the container.
3. **No decompression-bomb guard.** A 50,000 x 50,000 PNG is a few KB on disk and
   roughly 10 GB once decoded — the process dies before any file-size check fires.
4. **`is_uploaded_file()` was required unconditionally**, which a PSR-7 worker can
   never satisfy: `PsrRequestBridge` spools each upload to a temp file the SAPI
   never registered. Fixing W-03 had therefore re-broken worker uploads for a
   different reason.

**A bug in the fix, caught by its own test:** the replacement accepted any file
inside a temp directory, resolving candidates with `realpath()`. `realpath('')`
returns the **current working directory**, so an unset `upload_tmp_dir` made the
project root an accepted upload source — a provenance check that accepted anything.

---

## Cache & sessions

<a id="c-01"></a>
### C-01 — Default cache store is `file` · P1

`app/config/cache.php` sets `'default' => 'file'` — and unlike the other entries it is a
literal, not `env('CACHE_DRIVER', …)`, so it cannot be changed from `.env` at all.

Every `cache()->get()` is `stat` + `file_get_contents` + `unserialize`. `CacheManager::resolve()`
already supports `apcu` (with graceful degradation) and `redis`, and `RateLimit` already
prefers APCu — the cache layer just does not.

**Fix:** `'default' => env('CACHE_DRIVER', 'file')`, and add a `tiered` store that reads
APCu → Redis → file and writes through. APCu alone typically removes most cache syscalls on a
single node, and it works on the shared hosting this framework targets.

---

<a id="c-02"></a>
### C-02 — `FileStore` has no GC and no sharding · P2

`FileStore::path()` (`FileStore.php:272`) maps a key to one flat file in
`storage/cache/app`. Expired entries are only removed when read (`get()`) or when
`cache:clear` runs. A cache with high key churn accumulates files until the directory has
hundreds of thousands of entries, at which point `readdir`/`unlink` on flush becomes
pathological on ext4 and unusable on NTFS.

**Fix:** two-level sharding (`ab/cd/hash`) plus a probabilistic GC sweep (1-in-N writes prunes
one shard), or a `cache:prune` command wired into the scheduler.

---

<a id="c-03"></a>
### C-03 — Two independent cache systems · P3

`Core\Cache\CacheManager` (instance, prefix `MythPHP_`, driver-based) and
`Core\Database\QueryCache` (static, own file+APCu tier, table-version invalidation) do not
share configuration, storage, or a flush path. `cache:clear` has to know about both.

**Fix:** make `QueryCache` a consumer of `CacheManager` with a `query:` prefix, keeping its
table-version invalidation logic on top.

---

<a id="se-01"></a>
### SE-01 — `session_write_close()` is never called · P1

Zero occurrences in `systems/` or `app/`. PHP's session handler — both the native file handler
and `RedisSessionHandler` (which implements explicit locking with `lock_ttl` /
`lock_wait_ms` / `lock_retry_us`) — holds an exclusive lock from `session_start()` until the
script ends.

The bundled front end is DataTables-driven and fires several concurrent AJAX calls per page.
Every one of them serialises behind the session lock, so page load is the **sum** of the
request times rather than the max.

**Fix:** call `session_write_close()` as soon as the request stops writing to the session —
practically, at the end of `StartStatefulSession` after `$next()`, or immediately before the
controller runs for read-only routes. Add a `session.readonly` middleware for GET API routes.
For the token API path sessions are already off, so this only affects the web and `api.app`
surfaces — which is exactly where the AJAX load is.

---

## Backup & queue & console

<a id="bk-01"></a>
### BK-01 — Backups are neither encrypted nor checksummed · P2

`Components\Backup::run()` produces a zip containing a full database dump — every user record,
password hash and PII column — with no encryption and no recorded digest. `listBackups()`
reports name, size and date only.

**Fix:** encrypt the archive with `Core\Security\Encryptor` (once **S-05** gives it a portable
cipher) or a streamed AES-256-CTR + HMAC, and record `sha256` alongside each entry so
tampering and truncation are detectable.

---

<a id="bk-02"></a>
### BK-02 — No restore path · P2

`backup:run` and `backup:clean` exist; nothing reads a backup back. An untested backup is a
hypothesis.

**Fix:** `php myth backup:restore <file> [--db-only] [--files-only] [--dry-run]`, plus a
`--verify` mode that restores into a scratch database and reports row counts. Add it to the
scheduler as a monthly canary.

---

<a id="q-01"></a>
### Q-01 — Queue lacks batching, chaining, unique jobs and `queue:restart` · P2

`Core\Queue\Dispatcher` supports sync, database (with priority) and Redis drivers, retries and
a failed-jobs table. It has no `Bus::batch()`, no job chaining, no `ShouldBeUnique`, no
per-job middleware or rate limiting, and no `queue:restart` — so deploying new code requires
manually killing workers.

**Fix, in value order:** `queue:restart` (a cache flag workers poll) → unique jobs (a lock key
in cache) → batching → chaining.

---

<a id="co-01"></a>
### CO-01 — `Commands.php` is a 2,602-LOC monolith · P2

Every built-in command lives in one class, while `app/console/commands/` already demonstrates
the one-class-per-command pattern that `Kernel::discoverClassCommands()` supports, and
`systems/Core/Console/Commands/` already holds five properly extracted commands.

**Fix:** finish the migration already started — move each built-in into
`systems/Core/Console/Commands/`. Mechanical, and `tests/Unit/Console` (12 files) covers it.

---

## Architecture & quality

<a id="a-01"></a>
### A-01 — `app/` ↔ `systems/` boundary leaks · P2

`app/` is supposed to be the replaceable application. But `App\Http\Kernel`, all 38
middleware, `App\Support\DatabaseRuntime` (the thing `db()` resolves through),
`App\Support\EventDispatcher` (the thing `dispatch_event()` resolves through) and all 14
service providers live there. `systems/hooks.php` type-hints
`\App\Support\EventDispatcher` directly.

So `systems/` depends on `app/`. You cannot start a new project by deleting `app/`.

**Fix:** move the generic middleware, `Kernel`, `EventDispatcher` and `DatabaseRuntime` into
`Core\`; leave only genuinely app-specific middleware (`EnforceMenuAccess`, `RequireFeature`,
the upload guards) in `app/`. Then `app/` becomes a real skeleton and the framework becomes
extractable as a package — which is the prerequisite for versioning it at all.

---

<a id="a-02"></a>
### A-02 — `Components\` duplicates `Core\` · P2

`Components\Request` (1,167 LOC) and `Core\Http\Request` (676 LOC) are unrelated
implementations of the same concept; `request()` returns the former, middleware and actions
receive the latter. `Components\Security` overlaps `Core\Security\*`.
`Components\Auth` overlaps `Core\Auth\*` (see T-05).

**Fix:** pick `Core\` as the target for each pair, make the `Components\` class a thin
deprecated forwarder, then delete after one release. Start with `Request` — it is the smallest
and the most confusing.

---

<a id="a-03"></a>
### A-03 — Runtime classification keys off a client-controlled header · P3

`bootstrapHasApiCredential()` returns true if `HTTP_AUTHORIZATION`, `PHP_AUTH_USER`,
`PHP_AUTH_DIGEST` or `HTTP_X_API_KEY` is present, and `bootstrapRuntime()` then classifies the
request as `api` — which changes the session bootstrap policy.

Impact is limited because `shouldBootstrapSession()` checks for an existing session cookie
*first*, so an attacker adding a header to a victim's cookie-bearing request changes nothing.
But basing a runtime decision on an unauthenticated header is the wrong shape.

**Fix:** classify on the route prefix alone (or on the matched route's middleware group, once
routing has happened). The header check adds no capability the path check does not.

---

<a id="qa-01"></a>
### QA-01 — PHPStan pinned at level 2 of 10 · P2

`phpstan.neon` sets `level: 2` and reports zero errors — but level 2 only checks unknown
methods/properties and basic types. Levels 3–6 add nullability flow, return-type correctness,
and `iterable` value types, which is where a loosely typed 100k-LOC codebase actually has bugs.

`phpstan/phpstan` is also pinned to `^1.12`, and the tool itself warns it is 404 days behind.

**Fix:** upgrade to PHPStan 2.x, then ratchet: raise to level 3, generate a baseline, fix
`Core\` to level 6 file-group by file-group, leave `Components\` on the baseline until A-02
retires it.

---

<a id="qa-02"></a>
### QA-02 — Unit tests only · P2

`phpunit.xml.dist` declares one suite, `tests/Unit`. 693 tests, all in isolation. There is no
test that boots the kernel, dispatches an HTTP request through the real middleware stack, and
asserts on status/headers/body. Consequently:

- **B-01** (Blade nested parens) shipped undetected.
- **R-05** (closure routes dropped from the route cache) would not be caught.
- **W-01** (worker state leakage) has no possible unit test.

**Fix:** add a `Feature` suite with a test kernel that calls `Kernel::handle()` against a
synthetic `Request` and captures output. Requires **R-01** (no `exit`) to be useful, and
**D-05** (SQLite) for anything touching the database. Those three findings unblock each other.

---

<a id="qa-03"></a>
### QA-03 — `docs/` and `.github/skills/` are stale and contradict the code · P2

- `.github/skills/myth-framework/SKILL.md` states *"No model classes — always use
  `db()->table()`"*. `Core\Database\Model` is a 1,868-LOC ORM wired into route-model binding,
  and `app/models/User.php` uses it. An agent following this skill will write the wrong code.
- `docs/framework-security-performance-comparison.md` cites `app/support/Auth/LoginPolicy.php`
  and `app/support/Auth/AccessCredentialService.php`; both now live in `systems/Core/Auth/`.
- `docs/framework_knowledge/` predates the `app/console/Commands` → `commands` and
  `app/Models` → `models` renames still uncommitted in `git status`.
- `README.md` is 114 KB and overlaps both.

Three parallel knowledge bases is how the model-vs-no-model contradiction survived.

**Fix:** make `.dev/` the single source. Reduce `.github/skills/myth-*` to thin pointers into
`.dev/`, delete `docs/framework_knowledge/`, and cut `README.md` down to a real README
(install, quick start, links).

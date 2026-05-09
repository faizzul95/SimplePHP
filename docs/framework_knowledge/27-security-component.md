# 27. Security Component

## Component

- Class: `Components\Security`
- File: `systems/Components/Security.php`

## Purpose

Centralize application-level security helpers so upload flows, request hardening, helper functions, and validation logic can share one maintained implementation.

This component handles framework-level protections such as path normalization, blocked upload extensions, header sanitization, suspicious content detection, and streaming inspection of text-like documents. It does not guarantee protection from every future CVE by itself; runtime patching, dependency updates, PHP extension updates, server hardening, and safe deployment settings are still required.

## Current Responsibilities

### Database Mass-Assignment Guard (separate from this component)

Although not part of `Components\Security`, the SQL injection defence includes a two-layer column filter built into `Core\Database\BaseDatabase::sanitizeColumn()`:

1. **Schema guard** — always strips columns not present in the real table schema.
2. **`$fillable` allowlist** — when declared, only listed columns survive `insert()`/`update()`.
3. **`$guarded` denylist** — always blocks listed columns regardless of `$fillable`.

See [12-database-query-builder.md](12-database-query-builder.md) for full documentation, subclass patterns, and runtime setter methods (`setFillable`, `setGuarded`, etc.).

### Filesystem Permission Checks

- `canReadPath(string $path): bool`
- `canWritePath(string $path): bool`
- `assertWritablePath(string $path, string $label = 'Path'): void`
  - Centralize runtime filesystem permission validation for upload directories and file reads.

### Path and Storage Safety

- `normalizeRelativeProjectPath(string $path): string`
  - Rejects absolute paths, null bytes, traversal segments, and invalid path characters.
  - Returns a normalized project-relative path using `/` separators.

- `sanitizeStorageSegment(string $value): string`
  - Sanitizes a single folder/file-name segment for legacy helper compatibility.
  - Removes control characters and unsafe filesystem characters.
  - Collapses repeated underscores and applies a bounded segment length.

### Header and Request Safety

- `normalizeHostHeader(string $host): string`
  - Removes control characters and port suffixes.
  - Supports IPv4, IPv6, and hostnames.
  - Rejects malformed host headers.

- `sanitizeUserAgent(string $userAgent, int $maxLength = 1000): string`
  - Trims user-agent values.
  - Removes control characters and CR/LF/TAB characters.
  - Caps length to prevent oversized header abuse and log pollution.

- `exceedsMaxLength(string $value, int $maxLength): bool`
  - Shared length guard used by request hardening.

### Upload Safety

- `isBlockedUploadExtension(?string $extension): bool`
  - Checks common executable or dangerous upload extensions.
  - Used by upload and validation layers to prevent direct executable upload classes.

- `isBlockedUploadMimeType(?string $mimeType): bool`
  - Blocks active browser-executable MIME types such as HTML, JavaScript, CSS, SVG, Flash, and shell-script style content types.
  - Used as a second layer after extension checks so permissive MIME configuration cannot accidentally allow public active-content uploads.

### Content Inspection

- `containsSqlInjection($input, bool $sanitizeValue = true): array`
- `containsNoSqlInjection($input, bool $sanitizeValue = true): array`
- `containsInjection($input, bool $sanitizeValue = true): array`
  - Centralize common SQL, NoSQL, and operator-based injection detection for validation and request hardening.
  - These checks reduce obvious attack payloads but do not replace prepared statements, query parameter binding, or query-builder identifier validation.

- `containsMalicious($input, bool $sanitizeValue = true): array`
  - Detects suspicious active content patterns such as script tags, executable protocols, event-handler attributes, encoded script payloads, template-style executable markers, PHP/stream-wrapper references, and common obfuscation helpers.
  - Uses a fast-path needle check before running deeper regex checks to reduce unnecessary CPU cost on normal safe input.
  - Supports optional caller-provided whitelist controls for known-safe false positives via `whitelist_patterns` and `whitelist_contains`.

- `containsXss($input, int $depth = 0): bool`
  - Shared XSS detection helper used by request and validation layers for plain-text style input.
  - Intended for untrusted text fields, not trusted rich HTML storage.

- `inspectDocument(string $path, string $mime, array $options = []): array`
  - Streams supported text-like documents instead of loading the whole file into memory.
  - Supports CSV row-by-row scanning and text, JSON, XML, Markdown, and YAML-style line-by-line scanning.
  - Returns structured issue details with bounded issue counts.

### Detection Coverage Notes

- Fast-path scanning checks for high-signal markers before heavier regex evaluation to reduce CPU cost on ordinary input.
- Current high-signal detections include `srcdoc`, `xlink:href`, `foreignObject`, executable wrapper schemes such as `php://`, `phar://`, `data://`, `zip://`, `expect://`, and common obfuscation helpers such as `base64_decode`, `gzinflate`, `gzuncompress`, and `str_rot13`.
- The component also flags template and lookup payload indicators such as `${jndi:...}` and classic executable delimiters like `<?php`, `<?=`, and `<%`.
- These detections are intentionally application-layer heuristics. They improve early rejection and logging quality, but still do not replace safe rendering, prepared statements, or runtime patching.
- Built-in safe-content allowances still exist for math-style expressions, certain URL/query-like strings, and educational programming phrases.
- Custom whitelists are intended only for narrow false-positive handling. They should not be used to permit actual script tags, wrapper payloads, or obvious code-execution markers.

## Supported Streaming Document Types

- `text/plain`
- `text/csv`
- `application/json`
- `application/x-ndjson`
- `application/ld+json`
- `application/xml`
- `text/xml`
- `text/markdown`
- `application/x-yaml`
- `text/yaml`

Unsupported binary formats are not deep-parsed here. They still rely on MIME validation, extension blocking, upload directory controls, and any format-specific handling implemented elsewhere.

## Current Consumers

- `systems/Components/Files.php`
  - Uses `Security` for blocked-extension checks, upload path normalization, and document-content scanning.

- `app/helpers/custom_upload_helper.php`
  - Uses `Security` for `replaceFolderName()` segment sanitizing and `containsMalicious()` delegation.
  - `extractSafeCSVContent(...)` now forwards optional custom whitelist rules into `Security::containsMalicious(...)`.

- `app/http/middleware/ValidateRequestSafety.php`
  - Uses `Security` for host normalization and max-length request guards.

- `systems/Components/Request.php`
  - Uses `Security` for user-agent sanitization.

- `systems/Components/Validation.php`
  - Uses `Security` for dangerous upload-extension checks and shared injection detection during file validation.

## Design Notes

- The class is application-layer focused. It reduces common RCE/XSS/upload-abuse paths by normalizing dangerous inputs before they spread across multiple components.
- Streaming document inspection is intentionally bounded by line length and issue count to avoid unbounded memory growth.
- Fast-path input screening reduces the cost of expensive deep regex checks for ordinary safe values.
- The class is stateless at runtime, which keeps reuse simple across helpers, middleware, and components.
- Middleware alias override behavior can now be configured through `framework.middleware_override_aliases`, allowing default group middleware such as `xss` to be replaced by a route-specific variant like `xss:email_body`.
- Path normalization must validate absolute-path input before trimming separators, otherwise leading-slash absolute paths can be normalized incorrectly.
- Authorization remains owned by `Components\Auth`; `Security` should not become a second permission system.
- Trusted HTML storage should be implemented through dedicated validation rules such as `safe_html`, not by weakening global request or upload protections.
- This applies beyond email bodies: receipt templates, invoice layouts, print fragments, and other trusted config-backed or database-backed HTML should use an explicit trusted-template path.

### Middleware Override Notes

The router now supports configurable last-one-wins override semantics for selected middleware aliases.

Current intended use:

- Keep `xss` enabled by default in the API middleware group.
- Override it on a specific route with `xss:email_body` or `xss:email_body,email_footer`.
- Configure overridable aliases in `framework.middleware_override_aliases`.

This behavior should be enabled only for aliases where override semantics are desirable. Middleware such as throttles or permissions may intentionally need to stack instead of override.

## What It Does Not Replace

- PHP runtime updates
- Web server hardening
- OS patching
- Dependency patching
- Extension patching for GD, OpenSSL, fileinfo, etc.
- Safe server ownership, ACLs, mount options, and non-executable upload directories on the server
- Endpoint-level authorization and rate limiting
- Prepared statements, parameter binding, and safe query construction in database code

## Maintenance Guidance

When adding new framework-level security behavior:

1. Prefer adding the reusable primitive to `Components\Security` first.
2. Update consumers to call the shared method instead of copying logic.
3. Keep the method stateless unless shared cached state is clearly justified.
4. Update this knowledge file and any feature-specific docs that depend on the change.

## Evidence

- `systems/Components/Security.php`
- `systems/Components/Files.php`
- `systems/Components/Request.php`
- `systems/Components/Validation.php`
- `app/helpers/custom_upload_helper.php`
- `app/http/middleware/ValidateRequestSafety.php`
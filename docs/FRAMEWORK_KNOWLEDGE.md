# MythPHP Framework Knowledge (Verified)

Use the verified knowledge base as the canonical source.

## Canonical Entry

- [docs/framework_knowledge/README.md](framework_knowledge/README.md)

## Folder Policy

- Use a single folder: `docs/framework_knowledge/`.
- No separate verified folder is used anymore.

## Guarantee

- Verified docs include only capabilities confirmed from current code.
- If a feature is not listed in verified docs, treat it as unsupported until validated.
- Current verified additions include constrained direct-path Blade view resolution, request-cached Blade compile lookup, Blade `@can` / `@cannot` directives, redirector / redirect response support with same-origin redirect hardening, trusted-proxy-aware auth IP binding, config-driven auth/middleware defaults, configurable auth/API hardening defaults, explicit phased bootstrap helpers, explicit bootstrap runtime detection (`web` / `api` / `cli`), conditional bootstrap session startup for CLI and stateless API/mobile requests, lazy database manager creation with failure-aware application bootstrap, database concern traits, persistent PDO connection reuse, APCu-backed database metadata/warmth registries, limit-preserving keyset streaming delegation for eligible scans, expanded Laravel-style database helpers (`find*`, `firstOrNew`, `updateOrCreate`, `forceDelete`, `restore`), safer empty-IN query handling, immediate named-route registration for route helpers, route-aware `menu_manager()` rendering/landing resolution, and the shared `Components\\Security` security primitive.
- Additional verified database capabilities include model observers with quiet lifecycle helpers, hydration-preserving model streaming/keyset iteration, iterable batch writes for plain builders and models, adaptive chunk sizing for wide read and eager-load paths, unified `toDebugSnapshot()` inspection, CLI/debug-only SQL inspection helpers, guard-consistent model update and force-create persistence, and redacted NDJSON slow-query logging.

## Frontend CRUD Standard

- For maintainable frontend CRUD code, use the canonical standard documented in [docs/framework_knowledge/23-javascript-api-helpers.md](framework_knowledge/23-javascript-api-helpers.md).

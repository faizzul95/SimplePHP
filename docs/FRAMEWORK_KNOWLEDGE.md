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
- Current verified additions include constrained direct-path Blade view resolution, request-cached Blade compile lookup, Blade `@can` / `@cannot` directives, redirector / redirect response support with same-origin redirect hardening, trusted-proxy-aware auth IP binding, config-driven auth/middleware defaults, configurable auth/API hardening defaults, explicit phased bootstrap helpers, explicit bootstrap runtime detection (`web` / `api` / `cli`), conditional bootstrap session startup for CLI and stateless API/mobile requests, lazy database manager creation with failure-aware application bootstrap, immediate named-route registration for route helpers, route-aware `menu_manager()` rendering/landing resolution, and the shared `Components\\Security` security primitive.

## Frontend CRUD Standard

- For maintainable frontend CRUD code, use the canonical standard documented in [docs/framework_knowledge/23-javascript-api-helpers.md](framework_knowledge/23-javascript-api-helpers.md).

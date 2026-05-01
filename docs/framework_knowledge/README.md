# Framework Knowledge (Verified, AI-Friendly)

This knowledge set documents only capabilities verified from the current codebase.

## How This Docs Set Is Structured

- `01`–`10`: core runtime and architecture (request flow, router, security, config, limits)
- `11`–`18`: implementation surfaces (controller base, DB layer, auth/api, files, task runner)
- `19`–`27`: operational and developer tooling (console, scheduler, collections, JS helpers, capability map, schema builder, security component)

Each file is intentionally topic-scoped so AI agents and junior developers can load only what they need.

## Scope Rules

- Included: behavior directly implemented in code.
- Excluded: roadmap ideas, assumptions, or Laravel features not present here.
- If a feature is not listed, treat it as unsupported until verified.

## Sections

- [00. How To Use This Docs Set](00-how-to-use-this-docs-set.md)

- [01. Runtime & Architecture](01-runtime-architecture.md)
- [02. Routing & HTTP Flow](02-routing-http-flow.md)
- [03. Auth, Tokens, and API](03-auth-tokens-api.md)
- [04. Validation & FormRequest](04-validation-formrequest.md)
- [05. Views & Blade Engine](05-views-blade-engine.md)
- [06. Middleware & Security](06-middleware-security.md)
- [07. Cache, Queue, and Console](07-cache-queue-console.md)
- [08. Backup System](08-backup-system.md)
- [09. Framework Config Reference](09-framework-config-reference.md)
- [10. Known Limits & Non-Features](10-known-limits.md)
- [11. Controller Base Pattern](11-controller-base-pattern.md)
- [12. Database Query Builder](12-database-query-builder.md)
- [13. Database Scopes & Macros](13-database-scopes-macros.md)
- [14. Request & Response Details](14-request-response-details.md)
- [15. Auth Component Reference](15-auth-component-reference.md)
- [16. API Component Reference](16-api-component-reference.md)
- [17. File Upload System](17-file-upload-system.md)
- [18. TaskRunner Component](18-task-runner-component.md)
- [19. Console Built-in Commands](19-console-built-in-commands.md)
- [20. Scheduler / Cron System](20-scheduler-cron-system.md)
- [21. Collection (`Core\\Collection`)](21-collection-core-collection.md)
- [22. LazyCollection (`Core\\LazyCollection`)](22-lazy-collection-core-lazycollection.md)
- [23. JavaScript API Helpers](23-javascript-api-helpers.md)
- [24. Global Helpers & Hooks](24-global-helpers-hooks.md)
- [25. Framework Capability Map](25-framework-capability-map.md)
- [26. Schema Builder & Migration](26-schema-builder-migration.md)
- [27. Security Component](27-security-component.md)
- [28. Startup Lifecycle Maintenance](28-startup-lifecycle-maintenance.md)

## How To Use This Knowledge Base

### For AI Agents

1. Start from `25-framework-capability-map.md` for a high-level capability scan.
2. Open `10-known-limits.md` before proposing architecture to avoid unsupported features.
3. Open only the matching section file for the task (routing, validation, queue, etc.).
4. Confirm behavior against the Evidence paths listed in that section.

### For Junior Developers

1. Read in order: `01` → `02` → `11` → `14` → `04` → `06`.
2. Then move to feature-specific docs (`12`, `15`, `17`, `19`, `20`).
3. Use `09-framework-config-reference.md` whenever changing app behavior.

### For Feature Implementation

1. Pick target area (example: API endpoint).
2. Check related docs (`02`, `03`, `04`, `06`, `16`).
3. Implement in code.
4. Re-check `10-known-limits.md` for edge constraints.
5. Update the affected docs file in the same change.

## What To Avoid

- Do not document Laravel features that are not implemented in this codebase.
- Do not infer capabilities from naming conventions alone; verify via source file + method.
- Do not duplicate the same rule in multiple files with conflicting wording.
- Do not skip the “Known Limits” file when designing new modules.

## Benefits Of This Structure

- Lower context size for AI prompts (topic-based loading).
- Faster onboarding for junior devs (ordered reading path).
- Safer implementation decisions (explicit limits + evidence).
- Easier maintenance (small, focused markdown files).

## Primary Source Files

- `index.php`
- `app/http/Kernel.php`
- `systems/Core/Routing/Router.php`
- `systems/Core/Routing/RouteServiceProvider.php`
- `systems/Core/Http/Request.php`
- `systems/Core/Http/FormRequest.php`
- `systems/Core/View/BladeEngine.php`
- `systems/Components/Auth.php`
- `systems/Components/Api.php`
- `systems/Components/Validation.php`
- `systems/Core/Cache/*.php`
- `systems/Core/Queue/*.php`
- `systems/Core/Console/Commands.php`
- `systems/Components/Backup.php`
- `app/config/framework.php`, `app/config/security.php`, `app/config/auth.php`, `app/config/api.php`, `app/config/cache.php`, `app/config/queue.php`

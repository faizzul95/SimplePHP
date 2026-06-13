---
name: myth-framework
description: >
  Domain knowledge for the MythPHP custom framework (this codebase). Use when:
  creating controllers, routes, middleware, FormRequest validation, auth,
  API tokens, database queries, migrations, schema builder, file uploads, cache,
  queue, jobs, console commands, scheduler, views/Blade, service providers, helpers,
  collections, security, startup lifecycle, maintenance mode, or any feature of this
  framework. Load before implementing any feature in this project. Uses direct
  db()->table() query builder — no Eloquent-style model classes.
argument-hint: 'Topic area, e.g. "routing", "auth", "database", "upload"'
---

# MythPHP Framework Knowledge

## When to Use This Skill

Load this skill before implementing any feature in the MythPHP codebase. All
framework behaviour documented here is verified from the actual source — do not
assume Laravel-compatible APIs unless confirmed in the relevant reference file.

**No model classes** — always use `db()->table('table_name')` directly.

## Focused Sub-Skills (load for deeper topic coverage)

| Skill | Invoke | Coverage |
|-------|--------|----------|
| Security review & audit | `/myth-security` | OWASP Top 10, auth hardening, upload safety, XSS/SQL injection, CSRF, rate limiting, `security:audit` |
| Feature development | `/myth-develop` | Controllers, routes, FormRequests, Blade views, middleware, cache, file uploads, dev checklist |
| Database layer | `/myth-database` | Query builder (db()->table()), schema/migrations, soft deletes, transactions, eager loading, cursor pagination, N+1 |
| Auth & API | `/myth-auth-api` | Session, token, JWT, OAuth2, API keys, RBAC, token issuance, API route patterns, response shapes |
| Views & Frontend | `/myth-views` | Blade engine, layout patterns, directives, partials, JS API helpers, DataTable, BootstrapDataTable |
| Helpers & Collections | `/myth-helpers` | Global helpers, Collection/LazyCollection, feature flags, event dispatcher, redirect, cache facade |
| Testing | `/myth-testing` | PHPUnit setup, middleware tests, FormRequest tests, auth tests, DB tests, bootstrap patterns |
| Console / Queue / Scheduler | `/myth-console` | Custom commands, queue jobs, scheduler/cron, TaskRunner, all 39 built-in `myth` commands |

### Auth & API
| Topic | Reference |
|-------|-----------|
| Auth, Tokens & API (overview) | [03-auth-tokens-api.md](../../../docs/framework_knowledge/03-auth-tokens-api.md) |
| Auth Component Reference | [15-auth-component-reference.md](../../../docs/framework_knowledge/15-auth-component-reference.md) |
| API Component Reference | [16-api-component-reference.md](../../../docs/framework_knowledge/16-api-component-reference.md) |

### Controllers & Validation
| Topic | Reference |
|-------|-----------|
| Controller Base Pattern | [11-controller-base-pattern.md](../../../docs/framework_knowledge/11-controller-base-pattern.md) |
| Validation & FormRequest | [04-validation-formrequest.md](../../../docs/framework_knowledge/04-validation-formrequest.md) |

### Database
| Topic | Reference |
|-------|-----------|
| Database Query Builder | [12-database-query-builder.md](../../../docs/framework_knowledge/12-database-query-builder.md) |
| Database Scopes & Macros | [13-database-scopes-macros.md](../../../docs/framework_knowledge/13-database-scopes-macros.md) |
| Schema Builder & Migration | [26-schema-builder-migration.md](../../../docs/framework_knowledge/26-schema-builder-migration.md) |
| Cursor Pagination / N+1 / CSP | [29-cursor-pagination-n1-csp.md](../../../docs/framework_knowledge/29-cursor-pagination-n1-csp.md) |

### Storage & Files
| Topic | Reference |
|-------|-----------|
| File Upload System | [17-file-upload-system.md](../../../docs/framework_knowledge/17-file-upload-system.md) |
| Backup System | [08-backup-system.md](../../../docs/framework_knowledge/08-backup-system.md) |

### Views
| Topic | Reference |
|-------|-----------|
| Views & Blade Engine | [05-views-blade-engine.md](../../../docs/framework_knowledge/05-views-blade-engine.md) |

### Async / Background Work
| Topic | Reference |
|-------|-----------|
| Cache, Queue & Console | [07-cache-queue-console.md](../../../docs/framework_knowledge/07-cache-queue-console.md) |
| Console Built-in Commands | [19-console-built-in-commands.md](../../../docs/framework_knowledge/19-console-built-in-commands.md) |
| Scheduler / Cron System | [20-scheduler-cron-system.md](../../../docs/framework_knowledge/20-scheduler-cron-system.md) |
| TaskRunner Component | [18-task-runner-component.md](../../../docs/framework_knowledge/18-task-runner-component.md) |

### Utilities & Helpers
| Topic | Reference |
|-------|-----------|
| Collection (`Core\Collection`) | [21-collection-core-collection.md](../../../docs/framework_knowledge/21-collection-core-collection.md) |
| LazyCollection (`Core\LazyCollection`) | [22-lazy-collection-core-lazycollection.md](../../../docs/framework_knowledge/22-lazy-collection-core-lazycollection.md) |
| Global Helpers & Hooks | [24-global-helpers-hooks.md](../../../docs/framework_knowledge/24-global-helpers-hooks.md) |
| JavaScript API Helpers | [23-javascript-api-helpers.md](../../../docs/framework_knowledge/23-javascript-api-helpers.md) |

### Security
| Topic | Reference |
|-------|-----------|
| Security Component | [27-security-component.md](../../../docs/framework_knowledge/27-security-component.md) |

## Learning Path (Junior / Onboarding)

Work through files in this order to understand the framework end-to-end:

1. [01-runtime-architecture.md](../../../docs/framework_knowledge/01-runtime-architecture.md)
2. [02-routing-http-flow.md](../../../docs/framework_knowledge/02-routing-http-flow.md)
3. [11-controller-base-pattern.md](../../../docs/framework_knowledge/11-controller-base-pattern.md)
4. [14-request-response-details.md](../../../docs/framework_knowledge/14-request-response-details.md)
5. [04-validation-formrequest.md](../../../docs/framework_knowledge/04-validation-formrequest.md)
6. [06-middleware-security.md](../../../docs/framework_knowledge/06-middleware-security.md)
7. Then domain files: `12`, `15`, `17`, `19`, `20`, `23`

## How to Use This Skill for Implementation

1. Identify the layer being changed (route / controller / validation / query / middleware / queue / etc.).
2. Open the corresponding reference file from the Topic Map above.
3. Confirm actual behaviour from the listed Evidence source paths in that file.
4. Implement using existing patterns shown in the docs.
5. If the feature is not listed, treat it as **unsupported** until verified in code.

## Documentation Rules

- Only trust behaviour documented in these files.
- Do NOT assume Laravel-compatible behaviour unless confirmed here.
- If uncertain, check the Evidence source path in the relevant doc, not assumptions.

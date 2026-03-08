# 00. How To Use This Docs Set

## Purpose

This file explains how to use the framework knowledge docs effectively for implementation, review, and onboarding.

## Reading Paths

### Fast Path (AI / Senior)

- `25-framework-capability-map.md`
- `10-known-limits.md`
- target topic file(s)

### Learning Path (Junior)

- `01-runtime-architecture.md`
- `02-routing-http-flow.md`
- `11-controller-base-pattern.md`
- `14-request-response-details.md`
- `04-validation-formrequest.md`
- `06-middleware-security.md`
- then domain files (`12`, `15`, `17`, `19`, `20`, `23`)

## How To Work With A Feature

1. Identify which layer is changing (route, controller, validation, query, middleware, queue, etc.).
2. Open the corresponding docs file(s).
3. Confirm the behavior in the listed Evidence source paths.
4. Implement code using existing patterns.
5. Update docs if behavior changed.

## Documentation Rules

- Only include behavior proven in current source code.
- Every section should include at least one Evidence path.
- If uncertain, write “not verified yet” instead of guessing.

## What To Avoid

- Avoid documenting aspirational features.
- Avoid mixing implementation details from old architecture versions.
- Avoid broad claims like “supports everything Laravel does.”

## Why This Helps

- Reduces implementation mistakes.
- Makes code reviews faster.
- Keeps AI outputs grounded in real framework behavior.
- Improves onboarding consistency across developers.

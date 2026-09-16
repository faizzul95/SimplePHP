# 06 — Validation, front and back

**Verified:** 2026-09-17 · 1,679 tests passing · PHPStan level 2 clean

Two validators run against the same rule strings: `systems/Components/Validation.php`
(3,100 lines, 73 rules) and `public/general/js/validation.js` (2,750 lines, 94 rules).
A rule string is meant to mean the same thing in both. Where it does not, the
browser is giving the user an answer the server will contradict — or worse,
accepting something the server never re-checks.

---

## How a rule is dispatched

**PHP.** `validateField()` splits on `|`, then on the first `:`, then builds a
method name: `'validate' . ucfirst(str_replace('_', '', $ruleName))`. So
`max_length` reaches `validateMaxlength()`. Every rule is a `private function
validate*` on the class — there is no registry to update, and a static analyser
will report all 73 as unreferenced. They are not.

```php
$method = 'validate' . ucfirst(str_replace('_', '', $ruleName));   // line ~848
if (method_exists($this, $method)) { … }
```

**JavaScript.** `parseRules()` produces `{name, parameters}` and `validateRule()`
switches on the name. Adding a rule means a `case` *and* a function.

**Parameters are split on commas in both** — except `regex` and `not_regex`,
which are special-cased on both sides. A pattern like `/^[A-Z]{2,3}$/` contains
a comma; splitting it truncates the pattern to `/^[A-Z]{2`, the invalid-pattern
guard fires, and **the rule can then never pass**. Laravel special-cases this for
the same reason. If you add another rule whose argument can contain a comma, it
needs the same treatment in both files.

---

## The parity table

Full parity in the PHP → JS direction as of this date. The reverse direction has
**13 rules the browser enforces and the server does not**, which is the more
dangerous asymmetry: the user is stopped by the browser, and anything that skips
the browser — a mobile client, curl, a replayed request — is not checked at all.

| Only in the browser | Consequence |
|---|---|
| `after_or_equal`, `before_or_equal`, `date_equals`¹ | Date ordering unchecked server-side |
| `weekend`, `time` | Unchecked |
| `ipv4`, `ipv6` | `ip` exists server-side; the narrower forms do not |
| `currency`, `decimal` | Numeric format unchecked |
| `lowercase`, `uppercase` | Unchecked |
| `dimensions` | Pixel dimensions unchecked — note `FileUploadGuard` does enforce a pixel ceiling |
| `contains`, `doesnt_contain` | Unchecked |

¹ `date_equals` exists on both as of this pass.

**Rules that are deliberately server-only**, and now say so explicitly in the JS
switch rather than falling through to `default: {valid: true}`:

| Rule | Why the browser cannot do it |
|---|---|
| `unique`, `unique_with`, `exists`, `current_password` | Need a database round trip |
| `xss`, `safe_html`, `secure_value`, `secure_filename`, `no_sql_injection` | Sanitisation; a client-side pass is bypassable and gives false confidence |
| `exclude_if`, `exclude_unless` | Remove the field from the validated payload — a server concept |

---

## Bugs fixed on 2026-09-17

### PHP

| What | Why it mattered |
|---|---|
| `getArrayDepth()` recursed once per level | `deep_array` exists to reject over-nested input, and measured depth by walking the whole structure first. A 50,000-level array — a few hundred bytes of JSON — exhausted the PHP stack before the guard could fire. A stack overflow is fatal, not an `Exception`, so the surrounding `catch` never saw it either: **the guard was defeated by its own implementation.** Now an explicit stack with a ceiling that stops as soon as the limit is passed. |
| `array_keys` was O(keys × allowed) | A thousand-key payload against fifty allowed keys did fifty thousand comparisons. Now one `array_flip` and a hash lookup. It also used loose `in_array`, so a numeric array key matched a non-numeric allowed key — keys are compared as strings now. |
| `json` decoded to validate | `json_decode()` allocated the entire tree only to discard it, so validating a large document cost its full decoded size in memory. `json_validate()` (PHP 8.3+) answers without building it; the 8.2 fallback still decodes. |

### JavaScript

| What | Why it mattered |
|---|---|
| `validateRequired(value, [])` | `required_if`, `required_unless` and `required_with` all delegated to `validateRequired` passing `[]` — or nothing — as the element. `[].type` is `undefined`, so the file branch never ran and `String(FileList)` is the non-empty `"[object FileList]"`: **an empty file input passed every conditional required rule.** With no element at all, `element.type` threw, the `catch` turned it into invalid, and `required_with` **failed whenever its condition was met.** |
| `validateDimensions` returned a Promise | `validateRule()` is synchronous. The caller evaluated `!result.valid` against `undefined`, so `dimensions` reported **every** image as invalid, including conforming ones. Now measures into a `WeakMap` and reads the cached result on the next pass; the server is the enforcing check either way. |
| `parseRules` split every argument on `,` | The regex truncation described above. |

---

## Adding a rule

1. Write `private function validateYourrule(string $field, $value, array $params = []): bool`
   in `Validation.php`. The name is `ucfirst` of the rule with underscores
   removed — `your_rule` → `validateYourrule`.
2. Add a `case 'your_rule':` and a function in `validation.js`. If it cannot be
   checked in a browser, still add the case and return `{ valid: true }` with a
   comment saying why — silence there reads as "checked" to the next person.
3. If the argument can contain a comma, special-case it in **both** parsers.
4. Add it to the parity table above.

`tests/Unit/Support/ValidationLargeInputTest.php` is the pattern for testing a
rule against input designed to break it rather than input designed to pass.

---

## Where validation sits in a request

```
FormRequest::validateResolved()
  ├─ authorize()              → ValidationException(403) → error page
  ├─ applyMaxInputLength()    → truncates oversized strings before any rule runs
  ├─ applySanitize()          → per-field sanitisers
  ├─ prepareForValidation()   → your normalisation hook
  ├─ validator(...)->validate()
  │     └─ fails → ValidationException(422)
  │                  → Router catches it → Core\Http\Reply
  │                       ├─ API caller  → {code, message, errors}
  │                       └─ browser     → 302 back, errors + old input flashed
  └─ whitelist to rules() keys, including dotted and wildcard paths
```

The 422 path goes through `Reply` rather than a second hand-written negotiation,
so a rejected write has one shape whichever client made it. See
[03-request-lifecycle.md](03-request-lifecycle.md).

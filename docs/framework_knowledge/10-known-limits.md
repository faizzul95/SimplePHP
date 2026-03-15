# 10. Known Limits & Non-Features (Verified)

This section lists boundaries observed from current implementation.

## HTTP Method Spoofing Caveat

- Blade supports `@method('PUT')` hidden input generation.
- `Core\Http\Request` method is taken from `REQUEST_METHOD` only.
- No framework-level `_method` override handling was found.

## API Component Method Coverage

- `Components\Api` route helpers include GET/POST/PUT/PATCH/DELETE/OPTIONS.
- No HEAD registration helper in `Components\Api`.

## View Directive Extensibility

- No runtime custom directive registration API exists in `BladeEngine`.
- Supported directives are hardcoded in compile logic.

## Cache Drivers

- Cache manager supports `file` and `array` only.
- No Redis/Memcached driver is implemented in current core cache manager.

## Queue Backends

- Queue supports `database` and `sync` only.
- No Redis/SQS/etc backend implementation in current queue core.

## Examples (How Limits Affect Design)

### Method spoofing

```blade
@method('PUT')
```

The hidden field is rendered, but request method parsing still depends on server `REQUEST_METHOD`.

### Router any()

`any()` registers `OPTIONS` as well, but API preflight handling should still be validated at CORS layer.

## How To Work Safely Around Limits

1. Read this file before proposing new module architecture.
2. Use explicit route methods for endpoints that need special HTTP behavior.
3. Design queue workloads for `database`/`sync` only unless backend code is added.
4. Keep unsupported features in “future work” notes, not active docs claims.

## Benefits of Documented Limits

- Prevents over-promising framework capabilities.
- Reduces rework caused by invalid assumptions.
- Gives juniors and AI clear boundaries for implementation.

## Evidence

- `systems/Core/View/BladeEngine.php`
- `systems/Core/Http/Request.php`
- `systems/Core/Routing/Router.php`
- `systems/Components/Api.php`
- `systems/Core/Cache/CacheManager.php`
- `systems/Core/Queue/Dispatcher.php`

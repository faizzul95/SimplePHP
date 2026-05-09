<?php

// OPcache Preload Script — loaded once per FPM startup into shared memory.
//
// Activate by adding to php.ini or pool config:
//   opcache.preload = /path/to/systems/Core/Server/preload.php
//   opcache.preload_user = www-data   (or whichever user PHP-FPM runs as)
//
// DO NOT require bootstrap.php here — preload runs as root/privileged user
// before any request, with no superglobals and no database connections.
//

declare(strict_types=1);

$hotFiles = [
    // HTTP layer
    __DIR__ . '/../Http/Request.php',
    __DIR__ . '/../Http/Response.php',
    __DIR__ . '/../Http/JsonResponse.php',

    // Router
    __DIR__ . '/../Routing/Router.php',

    // Database layer — base + all concern traits
    __DIR__ . '/../Database/BaseDatabase.php',
    __DIR__ . '/../Database/ConnectionPool.php',
    __DIR__ . '/../Database/StatementCache.php',
    __DIR__ . '/../Database/QueryCache.php',
    __DIR__ . '/../Database/PerformanceMonitor.php',
    __DIR__ . '/../Database/Concerns/HasWhereConditions.php',
    __DIR__ . '/../Database/Concerns/HasJoins.php',
    __DIR__ . '/../Database/Concerns/HasAggregates.php',
    __DIR__ . '/../Database/Concerns/HasEagerLoading.php',
    __DIR__ . '/../Database/Concerns/HasStreaming.php',
    __DIR__ . '/../Database/Concerns/HasProfiling.php',
    __DIR__ . '/../Database/Concerns/HasDebugHelpers.php',

    // Security layer
    __DIR__ . '/../Security/Hasher.php',
    __DIR__ . '/../Security/CspNonce.php',
    __DIR__ . '/../Security/RateLimiter.php',
    __DIR__ . '/../Security/SignedUrl.php',
    __DIR__ . '/../Security/FileUploadGuard.php',
    __DIR__ . '/../Security/Encryptor.php',
    __DIR__ . '/../Security/AuditLogger.php',

    // Cache layer
    __DIR__ . '/../Cache/CacheManager.php',
    __DIR__ . '/../Cache/FileStore.php',
    __DIR__ . '/../Cache/ArrayStore.php',
    __DIR__ . '/../Cache/ApcuStore.php',

    // Components — all hot-path ones
    __DIR__ . '/../../Components/Request.php',
    __DIR__ . '/../../Components/Auth.php',
    __DIR__ . '/../../Components/CSRF.php',
    __DIR__ . '/../../Components/Security.php',
    __DIR__ . '/../../Components/Validation.php',
    __DIR__ . '/../../Components/FeatureManager.php',
    __DIR__ . '/../../Components/Input.php',

    // Middleware traits
    __DIR__ . '/../../Middleware/Traits/SecurityHeadersTrait.php',
    __DIR__ . '/../../Middleware/Traits/XssProtectionTrait.php',
    __DIR__ . '/../../Middleware/Traits/RateLimitingThrottleTrait.php',
    __DIR__ . '/../../Middleware/Traits/PermissionAbilitiesTrait.php',
];

$compiled = 0;
$skipped  = 0;

foreach ($hotFiles as $file) {
    if (is_file($file)) {
        opcache_compile_file($file);
        $compiled++;
    } else {
        $skipped++;
    }
}

// Optional: log preload result (only visible in FPM error log)
if ($skipped > 0) {
    error_log("[MythPHP Preload] Compiled {$compiled} files, skipped {$skipped} missing files.");
}

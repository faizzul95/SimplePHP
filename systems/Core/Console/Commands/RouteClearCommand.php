<?php

declare(strict_types=1);

namespace Core\Console\Commands;

/**
 * Delete the compiled route cache file.
 *
 * Usage: php myth route:clear
 *
 */
final class RouteClearCommand
{
    public function handle(): void
    {
        $cacheFile = ROOT_DIR . 'storage/cache/routes.cache.php';

        if (!file_exists($cacheFile)) {
            echo "Route cache file not found — nothing to clear.\n";
            return;
        }

        if (unlink($cacheFile)) {
            echo "Route cache cleared.\n";
        } else {
            throw new \RuntimeException("Failed to delete route cache: {$cacheFile}");
        }
    }
}

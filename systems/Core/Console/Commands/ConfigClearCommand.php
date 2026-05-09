<?php

declare(strict_types=1);

namespace Core\Console\Commands;

/**
 * Delete the compiled config cache file.
 *
 * Usage: php myth config:clear
 *
 */
final class ConfigClearCommand
{
    public function handle(): void
    {
        $cacheFile = ROOT_DIR . 'storage/cache/config.cache.php';

        if (!file_exists($cacheFile)) {
            echo "Config cache file not found — nothing to clear.\n";
            return;
        }

        if (unlink($cacheFile)) {
            echo "Config cache cleared.\n";
        } else {
            throw new \RuntimeException("Failed to delete config cache: {$cacheFile}");
        }
    }
}

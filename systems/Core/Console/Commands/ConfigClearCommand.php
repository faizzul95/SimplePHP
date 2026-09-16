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
        $files = [
            ROOT_DIR . 'storage/cache/config.php', // legacy name
            ROOT_DIR . 'storage/cache/config.cache.php',
            helperCacheFile(),
        ];

        $cleared = 0;

        foreach ($files as $cacheFile) {
            if (!file_exists($cacheFile)) {
                continue;
            }

            if (!unlink($cacheFile)) {
                throw new \RuntimeException("Failed to delete config cache: {$cacheFile}");
            }

            $cleared++;
        }

        echo $cleared > 0
            ? "Config cache cleared ({$cleared} file(s)).\n"
            : "Config cache file not found — nothing to clear.\n";
    }
}

<?php

declare(strict_types=1);

namespace Core\Console\Commands;

/**
 * Pre-compile all Blade templates into the view cache directory.
 *
 * Usage: php myth view:cache
 *
 * Walks app/views (configurable via framework.view_path) recursively and
 * compiles every *.php and *.blade.php view so that no compilation overhead
 * occurs on the first production request.
 *
 * Clear the compiled cache with: php myth view:clear
 */
final class ViewCacheCommand
{
    public function handle(): void
    {
        echo "Compiling view templates...\n";

        // Resolve paths using the same logic as ViewServiceProvider so the
        // paths are always consistent between the CLI command and web requests.
        $viewPath  = rtrim(ROOT_DIR . (config('framework.view_path')  ?? 'app/views'),          '/\\');
        $cachePath = rtrim(ROOT_DIR . (config('framework.view_cache_path') ?? 'storage/cache/views'), '/\\');

        if (!is_dir($viewPath)) {
            throw new \RuntimeException("View path does not exist: {$viewPath}");
        }

        $engine = new \Core\View\BladeEngine($viewPath, $cachePath);
        $result = $engine->compileAll();

        $compiled = $result['compiled'];
        $errors   = $result['errors'];

        echo "  Compiled: {$compiled} template(s)\n";

        if (!empty($errors)) {
            echo "\n  ERRORS (" . count($errors) . "):\n";
            foreach ($errors as $error) {
                echo "    - {$error}\n";
            }
            echo "\nView cache partially compiled (with errors).\n";
        } else {
            echo "\nView cache built successfully → {$cachePath}\n";
        }
    }
}

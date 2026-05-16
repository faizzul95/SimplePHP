<?php

declare(strict_types=1);

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class QueryAllowlistAudit
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function run(array $security): array
    {
        $config = (array) ($security['query_allowlist'] ?? []);
        $enabled = array_key_exists('enabled', $config) ? (bool) $config['enabled'] : true;

        if (!$enabled) {
            return [[
                'id' => 'query_allowlist.config',
                'status' => 'warn',
                'severity' => 'medium',
                'message' => 'Query allowlist audit is disabled in configuration.',
            ]];
        }

        $controllerFiles = $this->collectPhpFiles((array) ($config['controller_paths'] ?? ['app/http/controllers']));
        $modelFiles = $this->collectPhpFiles((array) ($config['model_paths'] ?? ['app/Models']));

        $checks = [];
        $checks[] = $this->buildDynamicPatternCheck(
            'query_allowlist.dynamic_order_by',
            $controllerFiles,
            '/->orderBy\s*\(\s*(?:request\s*\(|\$_(?:GET|POST|REQUEST)|\$[A-Za-z_][A-Za-z0-9_]*->(?:input|query|get|post)\s*\()/i',
            'Dynamic orderBy() calls sourced from request data were found. Replace them with orderBySafe().'
        );
        $checks[] = $this->buildDynamicPatternCheck(
            'query_allowlist.dynamic_where',
            $controllerFiles,
            '/->where\s*\(\s*(?:request\s*\(|\$_(?:GET|POST|REQUEST)|\$[A-Za-z_][A-Za-z0-9_]*->(?:input|query|get|post)\s*\()/i',
            'Dynamic where() calls sourced from request data were found. Replace them with whereSafe().'
        );
        $checks[] = $this->buildModelMetadataCheck($modelFiles);

        return $checks;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function collectPhpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $resolved = $this->resolvePath($path);
            if (is_file($resolved) && str_ends_with(strtolower($resolved), '.php')) {
                $files[] = $resolved;
                continue;
            }

            if (!is_dir($resolved)) {
                continue;
            }

            $iterator = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved)),
                '/^.+\.php$/i'
            );

            foreach ($iterator as $fileInfo) {
                $files[] = (string) $fileInfo->getPathname();
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }

        if (preg_match('/^[A-Za-z]:\\\\|^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return $path;
        }

        $root = defined('ROOT_DIR') ? rtrim((string) ROOT_DIR, "\\/") : dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @param string[] $files
     * @return array<string, mixed>
     */
    private function buildDynamicPatternCheck(string $id, array $files, string $pattern, string $failureMessage): array
    {
        $matches = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }

            if (preg_match($pattern, $content) !== 1) {
                continue;
            }

            $matches[] = $this->relativePath($file);
        }

        if ($matches === []) {
            return [
                'id' => $id,
                'status' => 'pass',
                'severity' => 'medium',
                'message' => 'No unsafe dynamic query-builder calls were detected in scanned controllers.',
            ];
        }

        return [
            'id' => $id,
            'status' => 'fail',
            'severity' => 'high',
            'message' => $failureMessage . ' Offending files: ' . implode(', ', $matches),
        ];
    }

    /**
     * @param string[] $modelFiles
     * @return array<string, mixed>
     */
    private function buildModelMetadataCheck(array $modelFiles): array
    {
        if ($modelFiles === []) {
            return [
                'id' => 'query_allowlist.model_metadata',
                'status' => 'warn',
                'severity' => 'low',
                'message' => 'No model files were found for query allowlist metadata scanning.',
            ];
        }

        $annotated = 0;
        foreach ($modelFiles as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $hasSortable = preg_match('/protected\s+array\s+\$sortable\s*=\s*\[(?!\s*\])/s', $content) === 1;
            $hasFilterable = preg_match('/protected\s+array\s+\$filterable\s*=\s*\[(?!\s*\])/s', $content) === 1;
            if ($hasSortable || $hasFilterable) {
                $annotated++;
            }
        }

        return [
            'id' => 'query_allowlist.model_metadata',
            'status' => $annotated > 0 ? 'pass' : 'warn',
            'severity' => $annotated > 0 ? 'low' : 'medium',
            'message' => $annotated > 0
                ? 'Query allowlist model metadata is present on ' . $annotated . ' model file(s).'
                : 'No model declares sortable or filterable allowlist metadata yet.',
        ];
    }

    private function relativePath(string $path): string
    {
        $root = defined('ROOT_DIR') ? rtrim((string) ROOT_DIR, "\\/") . DIRECTORY_SEPARATOR : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return str_replace('\\', '/', substr($path, strlen($root)));
        }

        return str_replace('\\', '/', $path);
    }
}
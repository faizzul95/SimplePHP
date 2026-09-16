<?php

/*
|--------------------------------------------------------------------------
| DEBUG OUTPUT
|--------------------------------------------------------------------------
|
| dump(), dd() and d() used to print_r() straight into the response inside a
| <pre>. Three problems, all of which have bitten someone:
|
|   - No environment guard. A dd() left in a controller dumps connection
|     strings, session contents and hashed passwords to whoever loads the page.
|   - No content negotiation. Echoing HTML into a JSON response gives the mobile
|     client a parse error instead of the value you were trying to see.
|   - No escaping. A dumped string containing </script> or a tag escaped the
|     <pre> and rendered as markup.
|
| These keep the same names and the same one-argument habit, and fix all three.
*/

if (!function_exists('myth_debug_allowed')) {
    /**
     * Debug output is a development tool. Anywhere else it is a disclosure bug,
     * so the dump goes to the log and the response gets nothing.
     */
    function myth_debug_allowed(): bool
    {
        $environment = defined('ENVIRONMENT') ? strtolower((string) ENVIRONMENT) : 'production';

        return in_array($environment, ['development', 'local', 'testing'], true);
    }
}

if (!function_exists('myth_debug_origin')) {
    /** Where the dump was called from — the first thing you want when you find a stray one. */
    function myth_debug_origin(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $frame = $frames[1] ?? $frames[0] ?? [];
        $file = (string) ($frame['file'] ?? '');

        if ($file === '') {
            return 'unknown';
        }

        $root = str_replace('\\', '/', defined('ROOT_DIR') ? ROOT_DIR : '');
        $file = str_replace('\\', '/', $file);

        if ($root !== '' && str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }

        return $file . ':' . (int) ($frame['line'] ?? 0);
    }
}

if (!function_exists('myth_debug_format')) {
    /** The form the caller can actually read: text on a console, JSON for an API client, HTML otherwise. */
    function myth_debug_format(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'text';
        }

        return function_exists('request') && request()->expectsJson() ? 'json' : 'html';
    }
}

if (!function_exists('myth_debug_render')) {
    /**
     * @param array<int, mixed> $values
     * @param string|null       $format Overrides detection; 'text', 'json' or 'html'
     */
    function myth_debug_render(array $values, string $origin, ?string $format = null): void
    {
        switch ($format ?? myth_debug_format()) {
            case 'text':
                echo "── dump @ {$origin} ──\n";
                foreach ($values as $value) {
                    echo print_r($value, true) . "\n";
                }

                return;

            case 'json':
                echo json_encode(
                    ['_dump' => ['origin' => $origin, 'values' => $values]],
                    JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );

                return;

            default:
                echo '<pre style="background:#1e1e1e;color:#e6e6e6;padding:12px;overflow:auto">';
                echo '<strong>' . htmlspecialchars($origin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong>\n\n";
                foreach ($values as $value) {
                    echo htmlspecialchars(print_r($value, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                }
                echo '</pre>';
        }
    }
}

if (!function_exists('myth_debug_suppressed')) {
    /** Outside development the values still go somewhere — just not to the client. */
    function myth_debug_suppressed(array $values, string $origin): void
    {
        try {
            \Core\Support\SafeLog::warning(sprintf(
                'Debug dump suppressed outside development at %s | %s',
                $origin,
                json_encode($values, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ));
        } catch (\Throwable) {
            // A dump that cannot be logged must not become the error it was
            // meant to help diagnose.
        }
    }
}

/**
 * Dump variables without stopping the request.
 *
 * dump($var1, $var2);
 */
if (!function_exists('dump')) {
    function dump(...$params)
    {
        $origin = myth_debug_origin();

        myth_debug_allowed()
            ? myth_debug_render($params, $origin)
            : myth_debug_suppressed($params, $origin);
    }
}

/**
 * Dump variables and stop.
 *
 * dd($var1, $var2);
 */
if (!function_exists('dd')) {
    function dd(...$params)
    {
        $origin = myth_debug_origin();

        if (!myth_debug_allowed()) {
            myth_debug_suppressed($params, $origin);

            if (!headers_sent()) {
                http_response_code(500);
            }

            exit;
        }

        myth_debug_render($params, $origin);
        exit;
    }
}

/**
 * Dump a single variable to the browser console or the page.
 *
 * d($var, true);  // console
 * d($var);        // page
 */
if (!function_exists('d')) {
    function d($var, $jsconsole = false)
    {
        $origin = myth_debug_origin();

        if (!myth_debug_allowed()) {
            myth_debug_suppressed([$var], $origin);
            return;
        }

        if (!$jsconsole) {
            myth_debug_render([$var], $origin);
            return;
        }

        // JSON_HEX_TAG is what stops a dumped "</script>" from closing the tag
        // and turning a debug call into stored XSS.
        echo '<script>console.log('
            . json_encode(
                ['origin' => $origin, 'value' => $var],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR
            )
            . ');</script>';
    }
}


/**
 * Check if the provided data contains non-empty values for the specified key.
 *
 * @param mixed       $data          The data to be checked (array or string).
 * @param string|null $arrKey        The key to check within the data.
 * @param bool        $returnData    If true, returns the data value if found.
 * @param mixed       $defaultValue  The default value to return if data is not found.
 *
 * @return bool|string|null Returns true if data exists, data value if $returnData is true and data exists, otherwise null or $defaultValue.
 */
if (!function_exists('hasData')) {
    function hasData($data = NULL, $arrKey = NULL, $returnData = false, $defaultValue = NULL)
    {
        // Base case 1: Check if data is not set, empty, or null
        if (!isset($data) || empty($data) || is_null($data)) {
            return $returnData ? ($defaultValue ?? $data) : false;
        }

        // Base case 2: If arrKey is not provided, consider data itself as having data
        if (is_null($arrKey)) {
            return $returnData ? ($defaultValue ?? $data) : true;
        }

        $arrKey = str_replace(['[', ']'], ['.', ''], $arrKey);

        // Split the keys into an array
        $keys = explode('.', $arrKey);

        // Helper function to recursively traverse the data
        $traverse = function ($keys, $currentData) use (&$traverse, $returnData, $defaultValue) {
            if (empty($keys)) {
                return $returnData ? $currentData : true;
            }

            $key = array_shift($keys);

            if (is_array($currentData) && array_key_exists($key, $currentData)) {
                return $traverse($keys, $currentData[$key]);
            } elseif (is_object($currentData) && isset($currentData->$key)) {
                return $traverse($keys, $currentData->$key);
            } else {
                // If the key doesn't exist, return the default value or false
                return $returnData ? $defaultValue : false;
            }
        };

        return $traverse($keys, $data);
    }
}

/**
 * Get database performance statistics for debugging
 * 
 * @param bool $logReport Whether to log the report to error.log (default: false)
 * @param string $context Optional context label for the log (e.g., "Cart Datatable", "User Query")
 * @return array Performance statistics array
 * 
 * Example use:
 * 
 * // Add to response without logging
 * $result['_debug'] = getPerformanceStats();
 * 
 * // Add to response with logging
 * $result['_debug'] = getPerformanceStats(true, 'Cart Datatable');
 * 
 * // Just log without adding to response
 * getPerformanceStats(true, 'Heavy Query');
 */
if (!function_exists('getPerformanceStats')) {
    function getPerformanceStats($logReport = false, $context = 'Performance')
    {
        $perfReport = \Core\Database\PerformanceMonitor::generateReport();
        
        if ($logReport) {
            logger()->log_info("{$context}: " . json_encode($perfReport, JSON_PRETTY_PRINT));
        }
        
        return [
            'summary' => $perfReport['summary'] ?? [],
            'statement_cache' => $perfReport['statement_cache_stats'] ?? [],
            'query_cache' => $perfReport['query_cache_stats'] ?? [],
            'optimizer' => $perfReport['optimizer_stats'] ?? [],
            'connection_pool' => $perfReport['connection_stats'] ?? []
        ];
    }
}

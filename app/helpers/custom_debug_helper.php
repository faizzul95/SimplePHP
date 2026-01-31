<?php

/**
 * Dump variables with formatting.
 * 
 * @param mixed ...$params The variables to dump
 * @return void
 * 
 * Example use:
 * 
 * dump($var1, $var2, $var3);
 */
// Check if the function dump() does not exist
if (!function_exists('dump')) {
    function dump(...$params)
    {
        array_map(function ($param) {
            echo '<pre>';
            print_r($param);
            echo '</pre>';
        }, $params);
    }
}

/**
 * Dump variables and end the script.
 * 
 * @param mixed ...$params The variables to dump
 * @return void
 * 
 * Example use:
 * 
 * dd($var1, $var2, $var3);
 */
// Check if the function dd() does not exist
if (!function_exists('dd')) {
    function dd(...$params)
    {
        array_map(function ($param) {
            echo '<pre>';
            print_r($param);
            echo '</pre>';
        }, $params);
        die;
    }
}

/**
 * Dump variable to console or HTML.
 * 
 * @param mixed $var The variable to dump
 * @param bool $jsconsole Whether to output to JavaScript console
 * @return void
 * 
 * Example use:
 * 
 * d($var, true); // Output to JavaScript console
 * d($var); // Output to HTML
 */
// Check if the function d() does not exist
if (!function_exists('d')) {
    function d($var, $jsconsole = false)
    {
        if (!$jsconsole) {
            echo '<pre>';
            print_r($var);
            echo '</pre>';
        } else {
            echo '<script>console.log(' . \json_encode($var) . ')</script>';
        }
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

        // Replace square brackets with dots in arrKey
        $arrKey = str_replace(['[', ']'], ['.', ''], $arrKey);

        // Split the keys into an array
        $keys = explode('.', $arrKey);

        // Helper function to recursively traverse the data
        $traverse = function ($keys, $currentData) use (&$traverse, $returnData, $defaultValue) {
            if (empty($keys)) {
                return $returnData ? $currentData : true;
            }

            $key = array_shift($keys);

            // Check if $currentData is an array or an object
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

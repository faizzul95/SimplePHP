<?php

if (!function_exists('db')) {
    function db($conn = 'default')
    {
        try {
            $connectionName = strtolower(trim((string) $conn));
            if ($connectionName === '') {
                $connectionName = 'default';
            }

            return framework_service('database.runtime')->connection($connectionName);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->logException($e);
            }

            throw $e;
        }
    }
}

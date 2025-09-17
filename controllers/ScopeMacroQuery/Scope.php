<?php

/**
 * Scope functions for database queries for general system function
 * File: controllers/ScopeMacroQuery/Scope.php
 */

// Single Scope Registration

if (!function_exists('scopeWithTrashed')) {
    function scopeWithTrashed($query)
    {
        $query->scope(function ($column = 'deleted_at') {
            return $this->whereNull($column);
        });
    }
}

if (!function_exists('scopeOnlyTrashed')) {
    function scopeOnlyTrashed($query)
    {
        $query->scope(function ($column = 'deleted_at') {
            return $this->whereNotNull($column);
        });
    }
}

// Multiple Scope Registration

if (!function_exists('scopeSystemQuery')) {
    function scopeSystemQuery($db)
    {
        try {
            $listOfScope = [
                'latest' => function ($column = 'created_at') {
                    return $this->orderBy($column, 'DESC');
                },
                'oldest' => function ($column = 'created_at') {
                    return $this->orderBy($column, 'ASC');
                },
                'recent' => function (int $days = 7, $column = 'created_at') {
                    if ($days < 1) {
                        throw new InvalidArgumentException('Days must be positive integer');
                    }

                    $date = date('Y-m-d', strtotime("-{$days} days"));
                    return $this->whereDate($column, '>=', $date);
                },
            ];

            // Register multiple scopes at once
            if (!empty($listOfScope)) {
                $db->scopes($listOfScope);
            }
        } catch (Exception $e) {
            logger()->logException($e);
        }
    }
}
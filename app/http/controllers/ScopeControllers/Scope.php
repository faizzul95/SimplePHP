<?php

/**
 * Scope functions for database queries for general system function
 * File: app/http/controllers/ScopeControllers/Scope.php
 */

use Core\Database\BaseDatabase;

// Single Scope Registration

if (!function_exists('scopeWithTrashed')) {
    function scopeWithTrashed($query)
    {
        $query->scope(function () {
            // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
            $this->softDelete = false;
            // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
            return $this;
        });
    }
}

if (!function_exists('scopeOnlyTrashed')) {
    function scopeOnlyTrashed($query)
    {
        $query->scope(function ($column = 'deleted_at') {
            // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
            $this->softDelete = true;
            // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
            $this->softDeleteColumn = $column;
            // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
            return $this;
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
                    // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
                    return $this->orderBy($column, 'DESC');
                },
                'oldest' => function ($column = 'created_at') {
                    // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
                    return $this->orderBy($column, 'ASC');
                },
                'recent' => function (int $days = 7, $column = 'created_at') {
                    if ($days < 1) {
                        throw new InvalidArgumentException('Days must be positive integer');
                    }

                    $date = date('Y-m-d', strtotime("-{$days} days"));
                    // @phpstan-ignore-next-line Bound to the active query builder by Scopeable::callScope().
                    return $this->whereDate($column, '>=', $date);
                },
            ];

            // Register multiple scopes at once
            if (!empty($listOfScope)) {
                $db->scopes($listOfScope);
            }
        } catch (Exception $e) {
            if (function_exists('logger')) {
                logger()->logException($e);
            }
        }
    }
}
<?php

function scopeQuery($db, $includeOnly = ['*'])
{
    try {
        // All available scopes
        $listOfScope = [
            // Soft Delete Scopes
            'withTrashed' => function ($column = 'deleted_at') {
                return $this->whereNull($column);
            },
            'onlyTrashed' => function ($column = 'deleted_at') {
                return $this->whereNotNull($column);
            },

            // Date-based Scopes
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

        // Filter scopes if needed
        if (!in_array('*', $includeOnly) && !empty($includeOnly)) {
            $tempScopeArr = [];
            foreach ($includeOnly as $scope) {
                if (isset($listOfScope[$scope])) {
                    $tempScopeArr[$scope] = $listOfScope[$scope];
                }
            }
            $listOfScope = $tempScopeArr;
        }

        // Register multiple scopes at once
        if (!empty($listOfScope)) {
            $db->scopes($listOfScope);
        }
    } catch (Exception $e) {
        logger()->logException($e);
    }
}

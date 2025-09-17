<?php

function macroQuery($db, $includeOnly = ['*'])
{
    try {
        $listOfMacros = [
            'whereLike' => function ($column, $value) {
                if (empty($column) || empty($value)) {
                    throw new InvalidArgumentException('Column and value cannot be empty');
                }

                if (is_array($column) || is_object($column)) {
                    throw new InvalidArgumentException('Column cannot be array or object');
                }

                if (is_callable($column) || $column instanceof Closure) {
                    throw new InvalidArgumentException('whereLike not support for callback/closure');
                }

                return $this->where($column, 'LIKE', "%{$value}%");
            }
        ];

        // Filter macro if needed
        if (!in_array('*', $includeOnly) && !empty($includeOnly)) {
            $tempMacroArr = [];
            foreach ($includeOnly as $macro) {
                if (isset($listOfMacros[$macro])) {
                    $tempMacroArr[$macro] = $listOfMacros[$macro];
                }
            }
            $listOfMacros = $tempMacroArr;
        }

        // Register multiple macro using macros() function. use macro() for single registration
        if (!empty($listOfMacros)) {
            $db->macros($listOfMacros);
        }
    } catch (Exception $e) {
        logger()->logException($e);
    }
}

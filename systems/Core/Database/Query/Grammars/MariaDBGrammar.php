<?php

namespace Core\Database\Query\Grammars;

class MariaDBGrammar extends QueryGrammar
{
    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "DATE_FORMAT($column, '%Y-%m-%d')",
            'day' => "DAY($column)",
            'month' => "MONTH($column)",
            'year' => "YEAR($column)",
            'time' => "DATE_FORMAT($column, '%H:%i:%s')",
        };
    }
}
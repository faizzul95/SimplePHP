<?php

namespace Core\Database\Query\Grammars;

class MySQLGrammar extends QueryGrammar
{
    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "DATE($column)",
            'day' => "DAY($column)",
            'month' => "MONTH($column)",
            'year' => "YEAR($column)",
            'time' => "TIME($column)",
        };
    }
}
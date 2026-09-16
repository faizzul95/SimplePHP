<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

/**
 * MariaDB is MySQL-compatible in almost everything the builder emits; the
 * differences that matter live in TimeoutDialect, not here.
 */
class MariaDBGrammar extends MySQLGrammar
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

    /** RETURNING landed in MariaDB 10.5 for INSERT; MySQL still has nothing. */
    public function supportsReturning(): bool
    {
        return true;
    }
}

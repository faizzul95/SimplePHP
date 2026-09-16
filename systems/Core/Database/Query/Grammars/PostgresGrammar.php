<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

class PostgresGrammar extends QueryGrammar
{
    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "{$column}::date",
            'day' => "EXTRACT(DAY FROM {$column})",
            'month' => "EXTRACT(MONTH FROM {$column})",
            'year' => "EXTRACT(YEAR FROM {$column})",
            'time' => "{$column}::time",
        };
    }

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $clauses = [];

        if ($limit !== null && $limit >= 0) {
            $clauses[] = 'LIMIT ' . $limit;
        }

        if ($offset !== null && $offset > 0) {
            $clauses[] = 'OFFSET ' . $offset;
        }

        return implode(' ', $clauses);
    }

    public function offsetRequiresOrderBy(): bool
    {
        return false;
    }

    public function compileRandomOrder(string $seed = ''): string
    {
        return 'RANDOM()';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsSkipLocked(): bool
    {
        return true;
    }

    public function compileFullTextPredicate(array $columns, string $mode = 'natural'): ?string
    {
        if ($columns === []) {
            return null;
        }

        // coalesce() because to_tsvector(NULL) is NULL, and a NULL match silently
        // drops the row rather than failing to match it.
        $vector = implode(" || ' ' || ", array_map(
            fn(string $c): string => "coalesce(" . $this->wrap($c) . ", '')",
            $columns
        ));

        $query = $mode === 'boolean' ? 'websearch_to_tsquery' : 'plainto_tsquery';

        return "to_tsvector({$vector}) @@ {$query}(?)";
    }
}

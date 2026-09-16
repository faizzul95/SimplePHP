<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

class SqlServerGrammar extends QueryGrammar
{
    protected string $openingIdentifier = '[';
    protected string $closingIdentifier = ']';

    /**
     * Bracket quoting is not symmetrical, so the base class's
     * "double the closing character" escape does not apply.
     */
    public function wrapValue(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            return $value;
        }

        return '[' . str_replace(']', ']]', $value) . ']';
    }

    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "CAST({$column} AS DATE)",
            'day' => "DAY({$column})",
            'month' => "MONTH({$column})",
            'year' => "YEAR({$column})",
            'time' => "CAST({$column} AS TIME)",
        };
    }

    /**
     * OFFSET … FETCH, which SQL Server rejects without an ORDER BY. The builder
     * has to supply a deterministic ordering before paginating — see
     * offsetRequiresOrderBy().
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && ($offset === null || $offset <= 0)) {
            return '';
        }

        $clause = 'OFFSET ' . max(0, (int) $offset) . ' ROWS';

        if ($limit !== null && $limit >= 0) {
            $clause .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        return $clause;
    }

    public function offsetRequiresOrderBy(): bool
    {
        return true;
    }

    public function compileRandomOrder(string $seed = ''): string
    {
        return 'NEWID()';
    }

    /** MERGE is a statement, not a trailing clause; the builder must emit it whole. */
    public function compileUpsertClause(array $updateColumns, array $conflictColumns): ?string
    {
        return null;
    }

    public function compileInsertIgnoreClause(): ?string
    {
        return null;
    }

    public function supportsReturning(): bool
    {
        return true; // OUTPUT INSERTED.*
    }

    public function supportsSkipLocked(): bool
    {
        return true; // WITH (READPAST) — a table hint rather than a trailing clause.
    }

    public function compileLock(bool $exclusive, bool $skipLocked = false, bool $noWait = false): string
    {
        // SQL Server locks through table hints placed after FROM, not through a
        // clause at the end of the statement. Returning '' keeps the builder from
        // appending FOR UPDATE, which SQL Server cannot parse.
        return '';
    }

    /** Collations are case-insensitive by default, so LIKE already matches. */
    public function compileCaseInsensitiveLike(bool $negated = false): string
    {
        return $negated ? 'NOT LIKE' : 'LIKE';
    }

    public function compileFullTextPredicate(array $columns, string $mode = 'natural'): ?string
    {
        if ($columns === []) {
            return null;
        }

        $wrapped = implode(', ', array_map(fn(string $c): string => $this->wrap($c), $columns));

        return "CONTAINS(({$wrapped}), ?)";
    }
}

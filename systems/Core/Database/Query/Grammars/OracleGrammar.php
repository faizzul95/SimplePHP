<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

/**
 * Oracle folds unquoted identifiers to upper case and preserves quoted ones
 * exactly, so `"users"` and `USERS` are different objects. Anything created
 * outside this framework is almost certainly upper case.
 */
class OracleGrammar extends QueryGrammar
{
    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "TRUNC({$column})",
            'day' => "EXTRACT(DAY FROM {$column})",
            'month' => "EXTRACT(MONTH FROM {$column})",
            'year' => "EXTRACT(YEAR FROM {$column})",
            'time' => "TO_CHAR({$column}, 'HH24:MI:SS')",
        };
    }

    /** OFFSET … FETCH arrived in 12c. Earlier releases need a ROWNUM subquery. */
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
        return 'DBMS_RANDOM.VALUE';
    }

    /** MERGE only; there is no trailing conflict clause to append. */
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
        return true; // RETURNING … INTO, which needs an out-bound bind.
    }

    public function supportsSkipLocked(): bool
    {
        return true;
    }

    /** Oracle is case-sensitive; matching without regard to case needs UPPER(). */
    public function compileCaseInsensitiveLike(bool $negated = false): string
    {
        return $negated ? 'NOT LIKE' : 'LIKE';
    }

    public function compileFullTextPredicate(array $columns, string $mode = 'natural'): ?string
    {
        // Oracle Text needs a CONTEXT index per column and scores through
        // CONTAINS(col, ?, 1) > 0. One column only — there is no multi-column
        // form — so the builder falls back to LIKE for anything else.
        if (count($columns) !== 1) {
            return null;
        }

        return 'CONTAINS(' . $this->wrap($columns[0]) . ', ?, 1) > 0';
    }

    public function compileColumnListing(string $table, string $schema = ''): array
    {
        $sql = 'SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE TABLE_NAME = ?';
        $bindings = [strtoupper($table)];

        if ($schema !== '') {
            $sql .= ' AND OWNER = ?';
            $bindings[] = strtoupper($schema);
        }

        return ['sql' => $sql, 'bindings' => $bindings, 'column' => 'COLUMN_NAME'];
    }
}

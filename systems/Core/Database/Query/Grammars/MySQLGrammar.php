<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

class MySQLGrammar extends QueryGrammar
{
    protected string $openingIdentifier = '`';
    protected string $closingIdentifier = '`';

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

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && ($offset === null || $offset <= 0)) {
            return '';
        }

        // MySQL cannot express OFFSET without LIMIT, so a bare offset needs the
        // documented sentinel rather than an omitted clause.
        $limitClause = 'LIMIT ' . ($limit ?? '18446744073709551615');

        return ($offset !== null && $offset > 0)
            ? $limitClause . ' OFFSET ' . $offset
            : $limitClause;
    }

    public function offsetRequiresOrderBy(): bool
    {
        return false;
    }

    public function compileRandomOrder(string $seed = ''): string
    {
        return $seed === '' ? 'RAND()' : 'RAND(' . (int) $seed . ')';
    }

    /**
     * MySQL keys the conflict off whichever unique index is violated rather than
     * a named column list, so $conflictColumns is informational here.
     */
    public function compileUpsertClause(array $updateColumns, array $conflictColumns): ?string
    {
        if ($updateColumns === []) {
            return null;
        }

        $assignments = array_map(
            fn(string $column): string => $this->wrap($column) . ' = VALUES(' . $this->wrap($column) . ')',
            $updateColumns
        );

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }

    /**
     * INSERT IGNORE is a statement modifier, not a trailing clause, so the
     * builder has to place it after INSERT rather than append it.
     */
    public function compileInsertIgnoreClause(): ?string
    {
        return null;
    }

    public function insertIgnoreModifier(): string
    {
        return 'IGNORE';
    }

    /** 8.0+ only; MariaDB got it in 10.6. ServerCapabilities does the version probe. */
    public function supportsSkipLocked(): bool
    {
        return true;
    }

    /** MySQL's default collations are case-insensitive, so ILIKE has no purpose. */
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

        $against = match (strtolower($mode)) {
            'boolean' => 'IN BOOLEAN MODE',
            'expansion' => 'WITH QUERY EXPANSION',
            default => 'IN NATURAL LANGUAGE MODE',
        };

        return "MATCH ({$wrapped}) AGAINST (? {$against})";
    }

    public function compileColumnListing(string $table, string $schema = ''): array
    {
        $sql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = ?';
        $bindings = [$table];

        // Without the schema filter this returns columns from every database on
        // the server that happens to have a table by the same name.
        $sql .= $schema !== '' ? ' AND TABLE_SCHEMA = ?' : ' AND TABLE_SCHEMA = DATABASE()';

        if ($schema !== '') {
            $bindings[] = $schema;
        }

        return ['sql' => $sql, 'bindings' => $bindings, 'column' => 'COLUMN_NAME'];
    }
    /** MySQL returns a status row from ANALYZE; nothing else does. */
    public function compileAnalyze(string $wrappedTable): string
    {
        return 'ANALYZE TABLE ' . $wrappedTable;
    }

    public function analyzeReturnsStatusRows(): bool
    {
        return true;
    }
}
<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

/**
 * SQLite.
 *
 * Closer to the ANSI defaults than MySQL is — double-quoted identifiers,
 * LIMIT/OFFSET, ON CONFLICT — so most of this class is the handful of places
 * SQLite genuinely goes its own way:
 *
 *   - no EXTRACT(); date parts come from strftime(), which returns text
 *   - OFFSET is a syntax error without a LIMIT
 *   - no row-level locking at all, so FOR UPDATE does not parse
 *   - no information_schema; pragma_table_info() is the equivalent
 */
class SqliteGrammar extends QueryGrammar
{
    /**
     * strftime() returns a zero-padded string, so a day part compares as '03'
     * rather than 3. Casting to INTEGER makes `whereDay('col', 3)` behave the
     * way it does on every other engine.
     */
    public function compileTemporalExpression(string $type, string $column): string
    {
        return match ($this->normalizeType($type)) {
            'date' => "date({$column})",
            'day' => "CAST(strftime('%d', {$column}) AS INTEGER)",
            'month' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            'year' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
            'time' => "time({$column})",
        };
    }

    /**
     * `SELECT … OFFSET 10` is a syntax error in SQLite: OFFSET only exists as a
     * suffix to LIMIT. `LIMIT -1` is the documented "no limit" sentinel, which
     * is what makes an offset-only query expressible at all.
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $hasOffset = $offset !== null && $offset > 0;
        $hasLimit = $limit !== null && $limit >= 0;

        if (!$hasOffset) {
            return $hasLimit ? 'LIMIT ' . $limit : '';
        }

        return 'LIMIT ' . ($hasLimit ? $limit : -1) . ' OFFSET ' . $offset;
    }

    public function offsetRequiresOrderBy(): bool
    {
        return false;
    }

    public function compileRandomOrder(string $seed = ''): string
    {
        return 'RANDOM()';
    }

    /** ON CONFLICT … DO UPDATE, as the ANSI default already compiles. Since 3.24. */
    public function supportsReturning(): bool
    {
        /*
        | RETURNING exists from 3.35, but the grammar has no connection and so
        | cannot see the version. lastInsertId() works on every SQLite build and
        | gives the builder the same answer, so declining here costs nothing and
        | avoids emitting a statement an older library cannot parse.
        */
        return false;
    }

    /**
     * SQLite locks the database, not rows, and rejects FOR UPDATE outright.
     * Emitting nothing is correct: a transaction already gives the isolation
     * the clause would have asked for.
     */
    public function supportsSkipLocked(): bool
    {
        return false;
    }

    public function compileLock(bool $exclusive, bool $skipLocked = false, bool $noWait = false): string
    {
        return '';
    }

    /**
     * No information_schema. pragma_table_info() is the table-valued form of
     * `PRAGMA table_info`, and unlike the PRAGMA statement it accepts a bound
     * parameter — so the table name still never reaches the SQL as text.
     *
     * Available since 3.16.
     */
    public function compileColumnListing(string $table, string $schema = ''): array
    {
        return [
            'sql' => 'SELECT name FROM pragma_table_info(?)',
            'bindings' => [$table],
            'column' => 'name',
        ];
    }

    /**
     * SQLite's LIKE is already case-insensitive for ASCII, and ILIKE does not
     * exist. Emitting ILIKE would be a syntax error.
     */
    public function compileCaseInsensitiveLike(bool $negated = false): string
    {
        return $negated ? 'NOT LIKE' : 'LIKE';
    }

    /**
     * FTS5 exists but only against a virtual table declared for it, which the
     * builder cannot know about. Null tells the builder to fall back to LIKE
     * rather than emit MATCH against an ordinary table.
     */
    public function compileFullTextPredicate(array $columns, string $mode = 'natural'): ?string
    {
        return null;
    }
}

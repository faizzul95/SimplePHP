<?php

declare(strict_types=1);

namespace Core\Database\Query\Grammars;

/**
 * The SQL each engine spells differently.
 *
 * This class existed with exactly one method on it — temporal expressions — while
 * identifier quoting, LIMIT/OFFSET, upserts, random ordering and column listing
 * stayed hard-coded to MySQL throughout the builder. The seam was real but it
 * carried almost nothing through, so "add PostgreSQL later" still meant editing
 * the builder rather than adding a grammar.
 *
 * The defaults here are ANSI SQL, which is what a new engine should start from.
 * A subclass overrides only where its engine actually differs.
 */
abstract class QueryGrammar
{
    abstract public function compileTemporalExpression(string $type, string $column): string;

    // ─── Identifiers ─────────────────────────────────────────────────

    /** The characters that quote an identifier. ANSI uses double quotes. */
    protected string $openingIdentifier = '"';
    protected string $closingIdentifier = '"';

    /**
     * Quote one identifier segment, escaping any embedded quote character.
     *
     * `*` is passed through: it is a wildcard, not a column name, and quoting it
     * turns `SELECT *` into a lookup for a column literally named "*".
     */
    public function wrapValue(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            return $value;
        }

        return $this->openingIdentifier
            . str_replace($this->closingIdentifier, $this->closingIdentifier . $this->closingIdentifier, $value)
            . $this->closingIdentifier;
    }

    /**
     * Quote a possibly-qualified identifier: `users.email`, `schema.users.email`.
     *
     * Idempotent, because callers pass values that are sometimes already wrapped
     * and double-wrapping produces SQL no engine accepts. But "already wrapped"
     * has to mean *correctly* wrapped: a check of `str_contains($value, '`')`
     * treats the column name `na``me` as finished and emits it raw, which is an
     * injection point wearing an escaping function's name. Only a segment that
     * opens, closes, and has no stray quote inside is passed through; everything
     * else is escaped.
     */
    public function wrap(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            return $value;
        }

        return implode('.', array_map(
            fn(string $segment): string => $this->isWrappedSegment($segment)
                ? $segment
                : $this->wrapValue($segment),
            $this->splitQualified($value)
        ));
    }

    /**
     * Split on the dots that separate identifier segments, ignoring dots inside
     * a quoted segment — `"a.b"."c"` is two segments, not three.
     *
     * @return list<string>
     */
    protected function splitQualified(string $value): array
    {
        $segments = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === $this->openingIdentifier && $depth === 0) {
                $depth++;
            } elseif ($char === $this->closingIdentifier && $depth > 0) {
                // A doubled closing character is an escaped literal, not the end.
                if (($value[$i + 1] ?? '') === $this->closingIdentifier) {
                    $current .= $char;
                    $i++;
                } else {
                    $depth--;
                }
            } elseif ($char === '.' && $depth === 0) {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return $segments;
    }

    /** Whether a segment is already a correctly quoted identifier. */
    protected function isWrappedSegment(string $segment): bool
    {
        $open = $this->openingIdentifier;
        $close = $this->closingIdentifier;

        if (strlen($segment) < strlen($open) + strlen($close)
            || !str_starts_with($segment, $open)
            || !str_ends_with($segment, $close)) {
            return false;
        }

        $inner = substr($segment, strlen($open), -strlen($close));

        // Once escaped pairs are removed, a remaining closing character means the
        // quoting terminates early — the value is not safely wrapped.
        return !str_contains(str_replace($close . $close, '', $inner), $close);
    }

    /** Quote a table, optionally schema-qualified. */
    public function wrapTable(string $table, string $schema = ''): string
    {
        $wrapped = $this->wrap($table);

        return $schema === '' ? $wrapped : $this->wrap($schema) . '.' . $wrapped;
    }

    // ─── Row limiting ────────────────────────────────────────────────

    /**
     * ANSI: OFFSET … FETCH. MySQL, PostgreSQL and SQLite use LIMIT … OFFSET;
     * SQL Server before 2012 and Oracle before 12c have neither.
     *
     * Returns '' when there is nothing to limit, so the caller appends nothing.
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $clauses = [];

        if ($offset !== null && $offset > 0) {
            $clauses[] = 'OFFSET ' . $offset . ' ROWS';
        }

        if ($limit !== null && $limit >= 0) {
            $clauses[] = 'FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        return implode(' ', $clauses);
    }

    /**
     * Whether OFFSET is legal without an ORDER BY.
     *
     * SQL Server and Oracle reject it, so the builder has to supply a
     * deterministic ordering before it can paginate.
     */
    public function offsetRequiresOrderBy(): bool
    {
        return true;
    }

    // ─── Ordering ────────────────────────────────────────────────────

    /** RAND() on MySQL, RANDOM() on PostgreSQL/SQLite, NEWID() on SQL Server. */
    public function compileRandomOrder(string $seed = ''): string
    {
        return 'RANDOM()';
    }

    // ─── Writes ──────────────────────────────────────────────────────

    /**
     * Whether the engine can express "insert, and update these columns on
     * conflict" in one statement, and how.
     *
     * MySQL has ON DUPLICATE KEY UPDATE; PostgreSQL and SQLite have
     * ON CONFLICT DO UPDATE; SQL Server and Oracle need MERGE. Returning null
     * tells the builder to fall back to a select-then-write inside a
     * transaction, which is correct everywhere and slower.
     *
     * @param list<string> $updateColumns
     * @param list<string> $conflictColumns
     */
    public function compileUpsertClause(array $updateColumns, array $conflictColumns): ?string
    {
        if ($updateColumns === [] || $conflictColumns === []) {
            return null;
        }

        $assignments = array_map(
            fn(string $column): string => $this->wrap($column) . ' = EXCLUDED.' . $this->wrap($column),
            $updateColumns
        );

        return 'ON CONFLICT (' . implode(', ', array_map(fn(string $c): string => $this->wrap($c), $conflictColumns))
            . ') DO UPDATE SET ' . implode(', ', $assignments);
    }

    /** "Insert unless it collides", without an update. Null means unsupported. */
    public function compileInsertIgnoreClause(): ?string
    {
        return 'ON CONFLICT DO NOTHING';
    }

    /**
     * Whether INSERT … RETURNING id works.
     *
     * PostgreSQL and modern SQLite/MariaDB have it; MySQL does not and needs
     * lastInsertId(), which behaves differently again on Oracle sequences.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * Empty a table.
     *
     * `TRUNCATE` is the fast path where it exists, but SQLite has no such
     * statement at all — it parses as a syntax error, not as a slower DELETE.
     * Overriding this is how an engine says which it has.
     */
    public function compileTruncate(string $wrappedTable): string
    {
        return 'TRUNCATE ' . $wrappedTable;
    }

    /**
     * Refresh the planner's statistics for a table.
     *
     * MySQL spells it `ANALYZE TABLE x` and returns a result set; SQLite and
     * PostgreSQL spell it `ANALYZE x` and return nothing.
     */
    public function compileAnalyze(string $wrappedTable): string
    {
        return 'ANALYZE ' . $wrappedTable;
    }

    /**
     * Whether ANALYZE reports its outcome as rows.
     *
     * Only MySQL does. Everywhere else, "no rows" means success, and treating
     * an empty result as failure is how analyze() came to report false on an
     * analyze that worked.
     */
    public function analyzeReturnsStatusRows(): bool
    {
        return false;
    }

    // ─── Introspection ───────────────────────────────────────────────

    /**
     * SQL listing a table's columns. MySQL's SHOW COLUMNS exists nowhere else;
     * information_schema is the portable answer.
     *
     * The result is a prepared statement with positional parameters, so callers
     * never interpolate the table name.
     *
     * @return array{sql: string, bindings: list<string>, column: string}
     */
    public function compileColumnListing(string $table, string $schema = ''): array
    {
        $sql = 'SELECT column_name FROM information_schema.columns WHERE table_name = ?';
        $bindings = [$table];

        if ($schema !== '') {
            $sql .= ' AND table_schema = ?';
            $bindings[] = $schema;
        }

        return ['sql' => $sql, 'bindings' => $bindings, 'column' => 'column_name'];
    }

    // ─── Locking ─────────────────────────────────────────────────────

    /** Whether SELECT … FOR UPDATE SKIP LOCKED is available — queue claims need it. */
    public function supportsSkipLocked(): bool
    {
        return false;
    }

    public function compileLock(bool $exclusive, bool $skipLocked = false, bool $noWait = false): string
    {
        $clause = $exclusive ? 'FOR UPDATE' : 'FOR SHARE';

        if ($skipLocked && $this->supportsSkipLocked()) {
            return $clause . ' SKIP LOCKED';
        }

        if ($noWait) {
            return $clause . ' NOWAIT';
        }

        return $clause;
    }

    // ─── Text search ─────────────────────────────────────────────────

    /**
     * Case-insensitive LIKE. PostgreSQL has ILIKE; MySQL's default collation is
     * already case-insensitive, so plain LIKE is correct there.
     */
    public function compileCaseInsensitiveLike(bool $negated = false): string
    {
        return $negated ? 'NOT ILIKE' : 'ILIKE';
    }

    /**
     * Full-text predicate. MySQL uses MATCH … AGAINST, PostgreSQL tsvector
     * operators, SQL Server CONTAINS. Null means the builder must fall back to
     * LIKE rather than emit SQL the engine cannot parse.
     *
     * @param list<string> $columns
     */
    public function compileFullTextPredicate(array $columns, string $mode = 'natural'): ?string
    {
        return null;
    }

    /**
     * The return type is the literal union rather than `string` so each grammar's
     * match is provably exhaustive. Typed loosely, adding a sixth temporal type
     * here and forgetting one grammar was an UnhandledMatchError in production
     * instead of a static-analysis failure.
     */
    protected function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'date' => 'date',
            'day' => 'day',
            'month' => 'month',
            'year' => 'year',
            'time' => 'time',
            default => throw new \InvalidArgumentException('Unsupported temporal expression type: ' . $type),
        };
    }
}

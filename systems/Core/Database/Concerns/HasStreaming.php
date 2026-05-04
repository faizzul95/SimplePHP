<?php

namespace Core\Database\Concerns;

use Core\LazyCollection;

/**
 * HasStreaming Trait
 *
 * Provides memory-efficient iterators over large result sets:
 * chunk(), cursor(), lazy(), chunkById(), and lazyById().
 *
 * For eligible query shapes the trait prefers keyset pagination over OFFSET
 * scans so iteration cost stays stable as row counts grow.
 *
 * Extracted from BaseDatabase to keep the monolith manageable.
 * All methods rely on protected properties and helpers defined in BaseDatabase /
 * DatabaseHelper, which are accessible at runtime via $this.
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasStreaming
{
    /**
     * Iterate through the result set in batches and invoke a callback per chunk.
     *
     * @param int $size
     * @param callable $callback
     * @return $this
     */
    public function chunk($size, callable $callback)
    {
        $size = $this->normalizeStreamingChunkSize($size, __FUNCTION__);
        $originalState = $this->_saveQueryState();
        if ($this->canAutoUseChunkById($originalState)) {
            return $this->chunkById($size, $callback);
        }

        $previousSuppressQueryCache = $this->suppressQueryCache;
        $this->suppressQueryCache = true;

        try {
            $offset = 0;

            // Store the original query state
            // Check if a limit was set before chunking
            $maxLimit = $this->extractStreamingLimit($originalState['limit'] ?? null);

            $totalFetched = 0;

            while (true) {
                // Restore the original query state
                $this->_restoreQueryState($originalState);

                $this->_setProfilerIdentifier('chunk_size' . $size . '_offset' . $offset);

                // Calculate the chunk size based on max limit if set
                $currentChunkSize = $size;
                if ($maxLimit !== null) {
                    $remaining = $maxLimit - $totalFetched;
                    if ($remaining <= 0) {
                        break; // Reached the limit
                    }
                    $currentChunkSize = min($size, $remaining);
                }

                // Apply limit and offset
                $this->limit($currentChunkSize)->offset($offset);

                // Get results
                $results = $this->get();

                if (empty($results)) {
                    break;
                }

                $totalFetched += count($results);

                if (call_user_func($callback, $results) === false) {
                    break;
                }

                // If we've fetched less than the chunk size or reached max limit, we're done
                if (count($results) < $currentChunkSize || ($maxLimit !== null && $totalFetched >= $maxLimit)) {
                    unset($results);
                    break;
                }

                $offset += $currentChunkSize;

                // Clear the results to free memory
                unset($results);

                // Suggest garbage collection on large chunks
                if ($size >= 1000 && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            // Unset the variables to free memory
            unset($originalState, $maxLimit, $totalFetched, $currentChunkSize, $offset);

            // Reset internal properties for next query
            $this->reset();

            return $this;
        } finally {
            $this->suppressQueryCache = $previousSuppressQueryCache;
        }
    }

    /**
     * Lazily yield rows using chunked queries to keep memory usage bounded.
     *
     * @param int $chunkSize
     * @return \Generator
     */
    public function cursor($chunkSize = 1000)
    {
        $chunkSize = $this->normalizeStreamingChunkSize($chunkSize, __FUNCTION__);
        $originalState = $this->_saveQueryState();
        if ($this->canAutoUseChunkById($originalState)) {
            yield from $this->lazyById($chunkSize);
            return;
        }

        $previousSuppressQueryCache = $this->suppressQueryCache;
        $this->suppressQueryCache = true;

        try {
            $offset = 0;

            // Store the original query state
            // Check if a limit was set before chunking
            $maxLimit = $this->extractStreamingLimit($originalState['limit'] ?? null);

            $totalFetched = 0;

            while (true) {
                // Restore the original query state
                $this->_restoreQueryState($originalState);

                $this->_setProfilerIdentifier('cursor_size' . $chunkSize . '_offset' . $offset);

                // Calculate the chunk size based on max limit if set
                $currentChunkSize = $chunkSize;
                if ($maxLimit !== null) {
                    $remaining = $maxLimit - $totalFetched;
                    if ($remaining <= 0) {
                        break; // Reached the limit
                    }
                    $currentChunkSize = min($chunkSize, $remaining);
                }

                // Apply limit and offset
                $this->limit($currentChunkSize)->offset($offset);

                // Get results
                $results = $this->get();

                if (empty($results)) {
                    break;
                }

                foreach ($results as $row) {
                    yield $row;
                    $totalFetched++;

                    // Stop yielding if we've reached the max limit
                    if ($maxLimit !== null && $totalFetched >= $maxLimit) {
                        break 2; // Break out of both foreach and while
                    }
                }

                // If we've fetched less than the chunk size, we're done
                if (count($results) < $currentChunkSize) {
                    break;
                }

                $offset += $currentChunkSize;

                // Clear the results to free memory
                unset($results);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            // Unset the variables to free memory
            unset($originalState);

            // Reset internal properties for next query
            $this->reset();
        } finally {
            $this->suppressQueryCache = $previousSuppressQueryCache;
        }
    }

    /**
     * Return a LazyCollection backed by chunked reads.
     *
     * @param int $chunkSize
     * @return LazyCollection
     */
    public function lazy($chunkSize = 1000)
    {
        try {
            $chunkSize = $this->normalizeStreamingChunkSize($chunkSize, __FUNCTION__);

            // Store the original query state before lazy loading
            $originalState = $this->_saveQueryState();

            if ($this->canAutoUseChunkById($originalState)) {
                return $this->lazyById($chunkSize);
            }

            $maxLimit = $this->extractStreamingLimit($originalState['limit'] ?? null);

            $totalFetched = 0;

            // Data source function
            $source = function ($size, $offset) use ($originalState, $maxLimit, &$totalFetched) {
                $previousSuppressQueryCache = $this->suppressQueryCache;
                $this->suppressQueryCache = true;

                try {
                    // Restore the original query state
                    $this->_restoreQueryState($originalState);

                    // Calculate the chunk size based on max limit if set
                    $currentChunkSize = $size;
                    if ($maxLimit !== null) {
                        $remaining = $maxLimit - $totalFetched;
                        if ($remaining <= 0) {
                            return []; // No more data to fetch
                        }
                        $currentChunkSize = min($size, $remaining);
                    }

                    // Apply limit and offset
                    $this->limit($currentChunkSize)->offset($offset);

                    // Execute the query
                    $results = $this->get();

                    if (empty($results)) {
                        return [];
                    }

                    $totalFetched += count($results);

                    return is_array($results) ? $results : [$results];
                } finally {
                    $this->suppressQueryCache = $previousSuppressQueryCache;
                }
            };

            // Create LazyCollection
            $collection = new LazyCollection($source);
            $collection->setChunkSize($chunkSize);

            if (function_exists('gc_collect_cycles')) gc_collect_cycles();

            return $collection;
        } catch (\Exception $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }
    }

    /**
     * Chunk the results using ID-based pagination (keyset pagination).
     * Significantly more efficient than chunk() for large datasets because it
     * avoids large OFFSETs. Requires an indexed, unique, monotonic $column.
     *
     * @param int $size Chunk size
     * @param callable $callback Invoked with each chunk; return false to stop
     * @param string $column ID column (must be unique and indexed)
     * @param string|null $alias Optional alias for the column in results
     * @return $this
     */
    public function chunkById(int $size, callable $callback, string $column = 'id', ?string $alias = null)
    {
        $size = $this->normalizeStreamingChunkSize($size, __FUNCTION__);
        $this->validateColumn($column, 'Chunk column');
        $this->_forbidRawQuery($column, 'Raw SQL is not allowed in chunkById() column names.');

        $previousSuppressQueryCache = $this->suppressQueryCache;
        $this->suppressQueryCache = true;

        try {
            $alias = $alias ?: $column;
            $this->assertValidStreamingAlias($alias, __FUNCTION__);
            $lastId = null;
            $originalState = $this->_saveQueryState();
            $maxLimit = $this->extractStreamingLimit($originalState['limit'] ?? null);
            $totalFetched = 0;

            while (true) {
                $this->_restoreQueryState($originalState);
                $this->_setProfilerIdentifier('chunkById_size' . $size . '_lastId' . ($lastId ?? '0'));

                $currentChunkSize = $this->resolveStreamingChunkSize($size, $maxLimit, $totalFetched);
                if ($currentChunkSize === null) {
                    break;
                }

                if ($lastId !== null) {
                    $this->where($column, '>', $lastId);
                }

                $this->orderBy($column, 'ASC')->limit($currentChunkSize);

                $results = $this->get();

                if (empty($results)) {
                    break;
                }

                $count = count($results);
                $last = $results[$count - 1];
                $lastId = is_array($last) ? ($last[$alias] ?? null) : ($last->{$alias} ?? null);

                if ($lastId === null) {
                    throw new \RuntimeException("chunkById: column '{$alias}' not present in result set.");
                }

                $totalFetched += $count;

                if (call_user_func($callback, $results) === false) {
                    unset($results);
                    break;
                }

                if ($count < $currentChunkSize || ($maxLimit !== null && $totalFetched >= $maxLimit)) {
                    unset($results);
                    break;
                }

                unset($results);

                if ($size >= 1000 && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            unset($originalState, $maxLimit, $totalFetched);
            $this->reset();
            return $this;
        } finally {
            $this->suppressQueryCache = $previousSuppressQueryCache;
        }
    }

    /**
     * Return a LazyCollection that iterates using ID-based pagination.
     * See chunkById() for requirements on the $column.
     *
     * @param int $chunkSize
     * @param string $column Indexed monotonic key column.
     * @param string|null $alias Result-set key column name when the selected
     *                           key uses a different alias.
     * @return LazyCollection
     */
    public function lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null)
    {
        try {
            $chunkSize = $this->normalizeStreamingChunkSize($chunkSize, __FUNCTION__);
            $this->validateColumn($column, 'Lazy keyset column');
            $this->_forbidRawQuery($column, 'Raw SQL is not allowed in lazyById() column names.');

            $alias = $alias ?: $column;
            $this->assertValidStreamingAlias($alias, __FUNCTION__);
            $originalState = $this->_saveQueryState();
            $maxLimit = $this->extractStreamingLimit($originalState['limit'] ?? null);
            $lastId = null;
            $totalFetched = 0;

            $source = function ($size, $offset) use ($originalState, $column, $alias, $maxLimit, &$lastId, &$totalFetched) {
                // $offset is ignored for keyset pagination; kept for LazyCollection signature
                $previousSuppressQueryCache = $this->suppressQueryCache;
                $this->suppressQueryCache = true;

                try {
                    $this->_restoreQueryState($originalState);

                    $currentChunkSize = $this->resolveStreamingChunkSize((int) $size, $maxLimit, $totalFetched);
                    if ($currentChunkSize === null) {
                        return [];
                    }

                    if ($lastId !== null) {
                        $this->where($column, '>', $lastId);
                    }

                    $this->orderBy($column, 'ASC')->limit($currentChunkSize);
                    $results = $this->get();

                    if (empty($results)) {
                        return [];
                    }

                    $results = is_array($results) ? $results : [$results];
                    $last = end($results);
                    reset($results);
                    $lastId = is_array($last) ? ($last[$alias] ?? null) : ($last->{$alias} ?? null);

                    if ($lastId === null) {
                        throw new \RuntimeException("lazyById: column '{$alias}' not present in result set.");
                    }

                    $totalFetched += count($results);

                    return $results;
                } finally {
                    $this->suppressQueryCache = $previousSuppressQueryCache;
                }
            };

            $collection = new LazyCollection($source);
            $collection->setChunkSize($chunkSize);

            if (function_exists('gc_collect_cycles')) gc_collect_cycles();

            return $collection;
        } catch (\Exception $e) {
            $this->db_error_log($e, __FUNCTION__);
            throw $e;
        }
    }

    /**
     * Normalize and validate the requested streaming chunk size.
     *
     * @param mixed $size
     * @param string $methodName
     * @return int
     */
    protected function normalizeStreamingChunkSize($size, string $methodName): int
    {
        $size = (int) $size;
        if ($size < 1) {
            throw new \InvalidArgumentException("{$methodName}() requires a chunk size greater than zero.");
        }

        return $size;
    }

    /**
     * Extract a numeric LIMIT value from a driver-generated LIMIT clause.
     *
     * @param mixed $limitClause
     * @return int|null
     */
    protected function extractStreamingLimit($limitClause): ?int
    {
        if (!is_string($limitClause) || $limitClause === '') {
            return null;
        }

        if (preg_match('/\bLIMIT\s+(\d+)\b/i', $limitClause, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Determine the next chunk size, taking any original LIMIT into account.
     *
     * @param int $defaultSize
     * @param int|null $maxLimit
     * @param int $totalFetched
     * @return int|null
     */
    protected function resolveStreamingChunkSize(int $defaultSize, ?int $maxLimit, int $totalFetched): ?int
    {
        if ($maxLimit === null) {
            return $defaultSize;
        }

        $remaining = $maxLimit - $totalFetched;
        if ($remaining <= 0) {
            return null;
        }

        return min($defaultSize, $remaining);
    }

    /**
     * Validate a result-set alias used to read the keyset column from fetched rows.
     *
     * @param string $alias
     * @param string $methodName
     * @return void
     */
    protected function assertValidStreamingAlias(string $alias, string $methodName): void
    {
        if ($alias === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException("{$methodName}() alias must be a simple identifier.");
        }
    }

    /**
     * Keyset-based pagination suitable for UI page navigation.
     *
     * Unlike offset-based paginate(), this method uses a cursor token that encodes
     * the last-seen key value so deep pages remain O(log n) regardless of page depth.
     *
     * Returns:
     *   data        — rows for this page (up to $perPage)
     *   per_page    — requested page size
     *   has_more    — whether a next page exists
     *   next_cursor — opaque token to pass as $cursorToken for the next page (null on last page)
     *   prev_cursor — opaque token to navigate to the previous page (null on first page)
     *
     * Usage:
     *   $page = db()->table('users')->where('status', 1)->cursorPaginate(20, 'id', request()->input('cursor'));
     *   // Next page: ?cursor={$page['next_cursor']}
     *
     * @param int         $perPage     Rows per page (1–MAX_PAGINATE_LIMIT)
     * @param string      $column      Unique, indexed, monotonic key column (default 'id')
     * @param string|null $cursorToken Opaque cursor token from a previous response
     * @return array{data: array, per_page: int, has_more: bool, next_cursor: string|null, prev_cursor: string|null}
     */
    public function cursorPaginate(int $perPage = 15, string $column = 'id', ?string $cursorToken = null): array
    {
        $perPage = max(1, min($perPage, static::MAX_PAGINATE_LIMIT));
        $this->validateColumn($column, 'cursorPaginate column');
        $this->_forbidRawQuery($column, 'Raw SQL is not allowed in cursorPaginate() column names.');

        $afterId  = null;
        $beforeId = null;

        if ($cursorToken !== null && $cursorToken !== '') {
            $padded  = strtr($cursorToken, '-_', '+/');
            $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
            $decoded = json_decode((string) base64_decode($padded), true);
            if (is_array($decoded)) {
                $afterId  = $decoded['after']  ?? null;
                $beforeId = $decoded['before'] ?? null;
            }
        }

        $originalState              = $this->_saveQueryState();
        $previousSuppressQueryCache = $this->suppressQueryCache;
        $this->suppressQueryCache   = true;

        try {
            $this->_restoreQueryState($originalState);

            $direction = 'ASC';
            if ($beforeId !== null) {
                $this->where($column, '<', $beforeId);
                $direction = 'DESC';
            } elseif ($afterId !== null) {
                $this->where($column, '>', $afterId);
            }

            $this->orderBy($column, $direction)->limit($perPage + 1);

            $rows = $this->get();
            $rows = is_array($rows) ? $rows : [];

            // Reverse DESC results so rows are always returned in ASC order
            if ($direction === 'DESC') {
                $rows = array_reverse($rows);
            }

            $hasMore = count($rows) > $perPage;
            if ($hasMore) {
                array_pop($rows);
            }

            $nextCursor = null;
            $prevCursor = null;

            if (!empty($rows)) {
                $firstRow = reset($rows);
                $lastRow  = end($rows);

                $firstId = is_array($firstRow) ? ($firstRow[$column] ?? null) : ($firstRow->{$column} ?? null);
                $lastId  = is_array($lastRow)  ? ($lastRow[$column]  ?? null) : ($lastRow->{$column}  ?? null);

                if ($hasMore && $lastId !== null) {
                    $nextCursor = rtrim(strtr(base64_encode(json_encode(['after' => $lastId])), '+/', '-_'), '=');
                }

                if (($afterId !== null || $beforeId !== null) && $firstId !== null) {
                    $prevCursor = rtrim(strtr(base64_encode(json_encode(['before' => $firstId])), '+/', '-_'), '=');
                }
            }

            return [
                'data'        => $rows,
                'per_page'    => $perPage,
                'has_more'    => $hasMore,
                'next_cursor' => $nextCursor,
                'prev_cursor' => $prevCursor,
            ];
        } finally {
            $this->suppressQueryCache = $previousSuppressQueryCache;
            $this->reset();
        }
    }

    /**
     * Stream query results directly to a CSV download without loading the full
     * dataset into memory.
     *
     * Uses chunkById() when the query is keyset-eligible (recommended for large
     * tables) and falls back to offset-based chunk() otherwise.
     *
     * Sends Content-Type, Content-Disposition, and a UTF-8 BOM so the file opens
     * correctly in Excel. Call this method before any output has been sent.
     *
     * Usage:
     *   db()->table('users')->where('status', 1)->exportCsv('active-users.csv', ['id', 'name', 'email']);
     *   // or — let the first row define the columns:
     *   db()->table('orders')->exportCsv('orders.csv');
     *
     * @param string   $filename  Download filename (sanitized; .csv appended if missing)
     * @param string[] $columns   Ordered list of column keys to include. Empty = all columns from first row.
     * @param int      $chunkSize Rows fetched per round-trip (default 500)
     * @return void
     */
    public function exportCsv(string $filename, array $columns = [], int $chunkSize = 500): void
    {
        $chunkSize = max(1, $chunkSize);

        // Sanitize filename — allow alphanumeric, dash, underscore, dot only
        $safeFilename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename) ?? 'export';
        if (!str_ends_with(strtolower($safeFilename), '.csv')) {
            $safeFilename .= '.csv';
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
            header('Pragma: no-cache');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            // UTF-8 BOM — helps Excel auto-detect UTF-8 encoding
            echo "\xEF\xBB\xBF";
        }

        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        $headersWritten = false;
        $originalState  = $this->_saveQueryState();

        $writeChunk = function (array $rows) use ($output, $columns, $chunkSize, &$headersWritten): void {
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (array) $row;

                if (!$headersWritten) {
                    $header = !empty($columns) ? $columns : array_keys($row);
                    fputcsv($output, $header);
                    $headersWritten = true;
                }

                $values = !empty($columns)
                    ? array_map(static fn($col) => $row[$col] ?? '', $columns)
                    : array_values($row);

                fputcsv($output, $values);
            }

            if (function_exists('gc_collect_cycles') && $chunkSize >= 500) {
                gc_collect_cycles();
            }
        };

        try {
            if ($this->canAutoUseChunkById($originalState)) {
                $this->chunkById($chunkSize, $writeChunk);
            } else {
                $this->chunk($chunkSize, $writeChunk);
            }
        } finally {
            fclose($output);
        }
    }
}

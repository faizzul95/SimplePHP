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
}

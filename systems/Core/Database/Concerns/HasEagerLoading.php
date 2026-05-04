<?php

namespace Core\Database\Concerns;

use Core\Database\EagerLoadOptimizer;
use Core\Database\PerformanceMonitor;

/**
 * Trait HasEagerLoading
 *
 * Provides eager-loading infrastructure:
 * _processEagerLoading, _processEagerLoadingInBatches, buildEagerRowIndex,
 * _processEagerByChunk, allChunkValuesAreIntegers, attachEagerLoadedData.
 *
 * Consumed by: BaseDatabase
 *
 * @category Database
 * @package  Core\Database\Concerns
 */
trait HasEagerLoading
{
    /**
     * Process eager-loaded relations on a result set.
     *
     * @param array  $data
     * @param array  $relations
     * @param string $connectionName
     * @param string $typeFetch 'fetch' | 'get'
     * @return array|mixed
     */
    protected function _processEagerLoading(&$data, $relations, $connectionName, $typeFetch)
    {
        $data          = $typeFetch == 'fetch' ? [$data] : $data;
        $connectionObj = $this->getInstance()->connect($connectionName);

        $temp_secure_output = $this->_secureOutput;

        foreach ($relations as $alias => $eager) {
            $method   = $eager['type'];
            $config   = $eager['details'];

            $table    = $config['table'];
            $fk_id    = $config['foreign_key'];
            $pk_id    = $config['local_key'];
            $callback = $config['callback'];

            $primaryKeys = array_values(array_unique(array_column($data, $pk_id), SORT_REGULAR));

            if (EagerLoadOptimizer::shouldUseBatching(count($primaryKeys))) {
                $this->_processEagerLoadingInBatches($data, $primaryKeys, $table, $fk_id, $pk_id, $connectionName, $method, $alias, $callback);
            } else {
                $this->_setProfilerIdentifier('with_' . $alias);
                $relatedRecords = $this->_processEagerByChunk($primaryKeys, $callback, $connectionObj, $table, $fk_id);
                $this->attachEagerLoadedData($method, $data, $relatedRecords, $alias, $fk_id, $pk_id);
            }

            $this->safeOutput($temp_secure_output);
        }

        unset($temp_secure_output);

        return $typeFetch == 'fetch' ? $data[0] : $data;
    }

    /**
     * Eager-load related rows in chunks so large parent result sets stay memory-stable.
     *
     * @param array       $data
     * @param array       $primaryKeys
     * @param string      $table
     * @param string      $fk_id
     * @param string      $pk_id
     * @param string      $connectionName
     * @param string      $method
     * @param string      $alias
     * @param \Closure|null $callback
     * @return void
     */
    protected function _processEagerLoadingInBatches(&$data, $primaryKeys, $table, $fk_id, $pk_id, $connectionName, $method, $alias, ?\Closure $callback = null)
    {
        $primaryKeys        = EagerLoadOptimizer::optimizeInClause($primaryKeys);
        $preferIntegerRawIn = $this->allChunkValuesAreIntegers($primaryKeys);
        $monitoringEnabled  = PerformanceMonitor::isEnabled();

        $connectionObj = $this->getInstance()->connect($connectionName);

        $rowIndexByPk = $this->buildEagerRowIndex($data, $pk_id, $alias, $method);

        $chunkNumber = 0;
        foreach (EagerLoadOptimizer::yieldOptimalChunks($primaryKeys, $table) as $chunk) {
            $chunkNumber++;

            $this->_setProfilerIdentifier('with_' . $alias . '_' . $chunkNumber);

            $queryId   = null;
            $chunkSize = count($chunk);
            if ($monitoringEnabled) {
                $queryId = uniqid('eager_', true);
                PerformanceMonitor::startQuery($queryId, "Eager load: {$table}.{$fk_id} (chunk={$chunkSize})", []);
            }

            $startTime          = microtime(true);
            $chunkRelatedRecords = $this->_processEagerByChunk($chunk, $callback, $connectionObj, $table, $fk_id, $preferIntegerRawIn);
            $executionTime      = microtime(true) - $startTime;

            if ($monitoringEnabled && $queryId !== null) {
                PerformanceMonitor::endQuery($queryId, count($chunkRelatedRecords));
            }

            EagerLoadOptimizer::recordPerformance($table, $chunkSize, $executionTime);

            foreach ($chunkRelatedRecords as $relatedRow) {
                $fkValue = $relatedRow[$fk_id] ?? null;
                if ($fkValue === null || !isset($rowIndexByPk[$fkValue])) {
                    continue;
                }

                foreach ($rowIndexByPk[$fkValue] as $rowIndex) {
                    if ($method === 'fetch') {
                        if ($data[$rowIndex][$alias] === null) {
                            $data[$rowIndex][$alias] = $relatedRow;
                        }
                        continue;
                    }
                    $data[$rowIndex][$alias][] = $relatedRow;
                }
            }

            unset($chunkRelatedRecords);
        }
    }

    /**
     * Build a lookup from parent key to row indexes for eager-load attachment.
     *
     * @param array  $data
     * @param string $pk_id
     * @param string $alias
     * @param string $method
     * @return array
     */
    protected function buildEagerRowIndex(array &$data, string $pk_id, string $alias, string $method): array
    {
        $rowIndexByPk = [];

        foreach ($data as $rowIndex => $row) {
            $rowKey = $row[$pk_id] ?? null;
            if ($rowKey === null) {
                continue;
            }

            $rowIndexByPk[$rowKey][] = $rowIndex;
            if (!isset($data[$rowIndex][$alias])) {
                $data[$rowIndex][$alias] = $method === 'fetch' ? null : [];
            }
        }

        return $rowIndexByPk;
    }

    /**
     * Fetch one eager-load chunk from the related table.
     *
     * @param array        $chunk
     * @param \Closure|null $callback
     * @param mixed        $connectionObj
     * @param string       $table
     * @param string       $fk_id
     * @param bool|null    $preferIntegerRawIn
     * @return array
     */
    protected function _processEagerByChunk($chunk, ?\Closure $callback, $connectionObj, $table, $fk_id, ?bool $preferIntegerRawIn = null)
    {
        $relatedRecordsQuery = $connectionObj->table($table);

        $useIntegerRawIn = $preferIntegerRawIn ?? $this->allChunkValuesAreIntegers($chunk);
        if ($useIntegerRawIn) {
            $relatedRecordsQuery->whereIntegerInRaw($fk_id, $chunk);
        } else {
            $relatedRecordsQuery->whereIn($fk_id, $chunk);
        }

        if (!empty($callback) && $callback instanceof \Closure) {
            $callback($relatedRecordsQuery);
        }

        if ($this->_secureOutput) {
            $relatedRecordsQuery->safeOutput(true);
            if (!empty($this->_secureOutputExeception)) {
                $relatedRecordsQuery->safeOutputWithException($this->_secureOutputExeception);
            }
        }

        return $relatedRecordsQuery->get();
    }

    /**
     * Check whether all chunk values are integers (or integer strings).
     *
     * @param array $chunk
     * @return bool
     */
    protected function allChunkValuesAreIntegers(array $chunk): bool
    {
        if (empty($chunk)) {
            return false;
        }

        foreach ($chunk as $value) {
            if (is_int($value)) {
                continue;
            }
            if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * Attach already-fetched related rows onto the parent result set.
     *
     * @param string $method
     * @param array  $data
     * @param array  $relatedRecords
     * @param string $alias
     * @param string $fk_id
     * @param string $pk_id
     * @return void
     */
    protected function attachEagerLoadedData($method, &$data, &$relatedRecords, $alias, $fk_id, $pk_id)
    {
        $rowIndexByPk = $this->buildEagerRowIndex($data, $pk_id, $alias, $method);

        foreach ($relatedRecords as $relatedRow) {
            $fkValue = $relatedRow[$fk_id] ?? null;
            if ($fkValue === null || !isset($rowIndexByPk[$fkValue])) {
                continue;
            }

            foreach ($rowIndexByPk[$fkValue] as $rowIndex) {
                if ($method === 'fetch') {
                    if ($data[$rowIndex][$alias] === null) {
                        $data[$rowIndex][$alias] = $relatedRow;
                    }
                    continue;
                }
                $data[$rowIndex][$alias][] = $relatedRow;
            }
        }
    }
}

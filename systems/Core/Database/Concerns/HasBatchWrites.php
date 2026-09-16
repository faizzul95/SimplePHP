<?php

namespace Core\Database\Concerns;

/**
 * Trait HasBatchWrites
 *
 * Multi-row INSERT and per-row UPDATE batches.
 *
 * These lived on MySQLDriver only. MariaDBDriver declared them as stubs that
 * returned $this — and iterableWriteBatchSucceeded() treats any object without a
 * `code >= 400` property as success, so on a MariaDB connection every call to
 * insertInBatches(), updateInBatches(), Model::bulkInsert(), Model::bulkUpdate()
 * and Model::importInBatches() reported success and wrote nothing at all.
 *
 * The SQL here is plain enough that both engines accept it unchanged, so sharing
 * the implementation is both the fix and the guarantee that the two cannot drift
 * apart again.
 *
 * Consumed by: MySQLDriver, MariaDBDriver
 */
trait HasBatchWrites
{
    public function batchInsert($data)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to batch insert data', 'action' => 'batchInsert'];

        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Data must be a non-empty array of associative arrays.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        if ($data === []) {
            return $this->_returnResult(['code' => 200, 'affected_rows' => 0, 'message' => 'No data to insert', 'action' => 'batchInsert']);
        }

        // Ensure data is a list of rows
        $data = isset($data[0]) ? $data : [$data];

        $this->_startProfiler(__FUNCTION__);

        $validColumns = $this->getTableColumns();

        try {
            $this->beginTransaction();

            $totalAffectedRows = 0;
            $batchSize = 500;
            $chunks = array_chunk($data, $batchSize);

            foreach ($chunks as $chunk) {
                $sanitizedBatch = [];
                foreach ($chunk as $row) {
                    if (!is_array($row) || empty($row)) continue;

                    $cleanRow = array_intersect_key($row, array_flip($validColumns));
                    if ($this->_secureInput) {
                        $cleanRow = array_map(function ($value) {
                            return $value === '' ? null : $this->normalizeDatabaseValue($value);
                        }, $cleanRow);
                    } else {
                        $cleanRow = array_map(function ($value) {
                            return $value === '' ? null : $value;
                        }, $cleanRow);
                    }

                    if (!empty($cleanRow)) {
                        $sanitizedBatch[] = $cleanRow;
                    }
                }

                if (empty($sanitizedBatch)) continue;

                $columns = array_keys($sanitizedBatch[0]);
                $escapedColumns = array_map(fn ($col): string => $this->wrapIdentifier((string) $col), $columns);

                $escapedTable = $this->wrapCurrentTable();
                $placeholderRow = '(' . str_repeat('?,', count($columns) - 1) . '?)';
                $allPlaceholders = implode(',', array_fill(0, count($sanitizedBatch), $placeholderRow));

                $sql = "INSERT INTO $escapedTable (" . implode(',', $escapedColumns) . ") VALUES $allPlaceholders";

                $this->connectForOperation('write');
                $stmt = $this->resolvePdo('write')->prepare($sql);

                $bindValues = [];
                foreach ($sanitizedBatch as $row) {
                    foreach ($columns as $col) {
                        $bindValues[] = $row[$col] ?? null;
                    }
                }

                $stmt->execute($bindValues);
                $totalAffectedRows += $stmt->rowCount();
            }

            $this->commit();

            $response = [
                'code' => 201,
                'affected_rows' => $totalAffectedRows,
                'message' => 'Batch insert completed successfully',
                'action' => 'batchInsert'
            ];
        } catch (\Exception $e) {
            $this->rollback();
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();

        $this->reset();

        return $this->_returnResult($response);
    }

    public function batchUpdate($data)
    {
        // Default response
        $response = ['code' => 400, 'message' => 'Failed to batch update data', 'action' => 'batchUpdate'];

        if (empty($data) || !is_array($data)) {
            throw new \InvalidArgumentException('Data must be a non-empty array of associative arrays, each containing a primary key.');
        }

        if (empty($this->table)) {
            throw new \InvalidArgumentException('Please specify the table.');
        }

        if ($data === []) {
            return $this->_returnResult(['code' => 200, 'affected_rows' => 0, 'message' => 'No data to update', 'action' => 'batchUpdate']);
        }

        // Ensure data is a list of rows
        $data = isset($data[0]) ? $data : [$data];

        // One UPDATE per row inside a single transaction is the textbook deadlock
        // shape: two concurrent batches touching overlapping ids in opposite orders
        // each hold what the other is waiting for. Locking in key order makes the
        // cycle impossible — the second writer just waits.
        $data = $this->orderRowsForLocking($data);

        $this->_startProfiler(__FUNCTION__);

        $validColumns = $this->getTableColumns();

        try {
            $this->beginTransaction();

            $totalAffectedRows = 0;

            foreach ($data as $row) {
                if (!is_array($row) || empty($row)) continue;

                $cleanRow = array_intersect_key($row, array_flip($validColumns));
                if ($this->_secureInput) {
                    $cleanRow = array_map(function ($value) {
                        return $value === '' ? null : $this->normalizeDatabaseValue($value);
                    }, $cleanRow);
                } else {
                    $cleanRow = array_map(function ($value) {
                        return $value === '' ? null : $value;
                    }, $cleanRow);
                }

                if (empty($cleanRow)) continue;

                // Use the where conditions set on the builder, or require 'id' in each row
                if (!empty($this->where)) {
                    // Build UPDATE with existing where clause
                    $set = [];
                    $bindValues = [];
                    foreach ($cleanRow as $col => $val) {
                        $set[] = $this->wrapIdentifier((string) $col) . ' = ?';
                        $bindValues[] = $val;
                    }

                    $escapedTable = $this->wrapCurrentTable();
                    $sql = "UPDATE $escapedTable SET " . implode(', ', $set) . " WHERE " . $this->where;

                    $this->connectForOperation('write');
                    $stmt = $this->resolvePdo('write')->prepare($sql);
                    $stmt->execute(array_merge($bindValues, $this->_binds));
                    $totalAffectedRows += $stmt->rowCount();
                } elseif (isset($cleanRow['id'])) {
                    $id = $cleanRow['id'];
                    unset($cleanRow['id']);

                    if (empty($cleanRow)) continue;

                    $set = [];
                    $bindValues = [];
                    foreach ($cleanRow as $col => $val) {
                        $set[] = $this->wrapIdentifier((string) $col) . ' = ?';
                        $bindValues[] = $val;
                    }
                    $bindValues[] = $id;

                    $escapedTable = $this->wrapCurrentTable();
                    $sql = "UPDATE $escapedTable SET " . implode(', ', $set) . " WHERE " . $this->wrapIdentifier('id') . " = ?";

                    $this->connectForOperation('write');
                    $stmt = $this->resolvePdo('write')->prepare($sql);
                    $stmt->execute($bindValues);
                    $totalAffectedRows += $stmt->rowCount();
                } else {
                    throw new \InvalidArgumentException('Each row must contain an "id" key or set where conditions on the builder.');
                }
            }

            $this->commit();

            $response = [
                'code' => 200,
                'affected_rows' => $totalAffectedRows,
                'message' => 'Batch update completed successfully',
                'action' => 'batchUpdate'
            ];
        } catch (\Exception $e) {
            $this->rollback();
            $this->logDatabaseError($e, __FUNCTION__);
            throw $e;
        }

        $this->_stopProfiler();

        $this->reset();

        return $this->_returnResult($response);
    }
}

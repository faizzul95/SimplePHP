<?php

namespace Core\Database\Drivers;

/**
 * Database MariaDBDriver class
 *
 * @category Database
 * @package Core\Database
 * @author 
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link 
 * @version 0.0.1
 */

use Core\Database\BaseDatabase;

class MariaDBDriver extends BaseDatabase
{
    public function connect($connectionID = null)
    {
        $connectionName = !empty($connectionID) ? $connectionID : $this->connectionName;

        if (!isset($this->config[$connectionName])) {
            die("Configuration for $connectionName not found");
        }

        $this->setConnection($connectionName);
        $this->setDatabase($this->config[$connectionName]['database']);

        if (!isset($this->pdo[$connectionName])) {

            $dsn = "mysql:host={$this->config[$connectionName]['host']};dbname={$this->config[$connectionName]['database']}";

            if (isset($this->config[$connectionName]['charset'])) {
                $dsn .= ";charset={$this->config[$connectionName]['charset']}";
            }
            if (isset($this->config[$connectionName]['port'])) {
                $dsn .= ";port={$this->config[$connectionName]['port']}";
            }
            if (isset($this->config[$connectionName]['socket'])) {
                $dsn .= ";unix_socket={$this->config[$connectionName]['socket']}";
            }

            try {
                // Connection options
                $options = [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ];

                if (isset($this->config[$connectionName]['charset']) && !empty($this->config[$connectionName]['charset'])) {
                    $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . $this->config[$connectionName]['charset'];
                }

                $pdo = new \PDO($dsn, $this->config[$connectionName]['username'], $this->config[$connectionName]['password'], $options);
                $this->pdo[$connectionName] = $pdo;
            } catch (\PDOException $e) {
                throw new \Exception($e->getMessage());
            }
        }

        $this->driver = $this->config[$connectionName]['driver'];
        self::$_instance = $this;

        return $this;
    }

    public function whereDate($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereDate(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $formattedDate = $this->validateDate($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DATE_FORMAT($column, '%Y-%m-%d')", $formattedDate, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereDate($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereDate(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $formattedDate = $this->validateDate($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DATE_FORMAT($column, '%Y-%m-%d')", $formattedDate, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereDay($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereDay(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateDay($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DAY($column)", (int)$value, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereDay($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereDay(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateDay($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DAY($column)", (int)$value, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereMonth($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereMonth(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateMonth($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("MONTH($column)", (int)$value, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereMonth($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereMonth(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateMonth($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("MONTH($column)", (int)$value, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereYear($column, $operator = null, $value = null)
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereYear(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateYear($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("YEAR($column)", (int)$value, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereYear($column, $operator = null, $value = null)
    {
        try {

            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereYear(). Please use simpleQuery() function.');

            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $this->validateYear($value);
            $this->validateOperator($operator);

            $this->_buildWhereClause("YEAR($column)", (int)$value, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereJsonContains($columnName, $jsonPath, $value)
    {
        // Check if the column is not null
        $this->whereNotNull($columnName);

        // Construct the JSON search condition
        $jsonCondition = "JSON_CONTAINS($columnName, '" . json_encode([$jsonPath => $value]) . "', '$')";

        // Add the condition to the query builder
        $this->where($jsonCondition, null, 'JSON');
        return $this;
    }

    public function limit($limit)
    {
        // Try to cast the input to an integer
        $limit = filter_var($limit, FILTER_VALIDATE_INT);

        // Check if the input is not an integer after casting
        if ($limit === false) {
            throw new \InvalidArgumentException('Limit must be an integer.');
        }

        // Check if the input is less then 1
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be integer with higher then zero');
        }

        $this->limit =  " LIMIT $limit";
        return $this;
    }

    public function offset($offset)
    {
        // Try to cast the input to an integer
        $offset = filter_var($offset, FILTER_VALIDATE_INT);

        // Check if the input is not an integer after casting
        if ($offset === false) {
            throw new \InvalidArgumentException('Offset must be an integer.');
        }

        // Check if the input is less then 0
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be integer with higher or equal to zero');
        }

        $this->offset = " OFFSET $offset";
        return $this;
    }

    public function count()
    {
        try {

            // Start profiler for performance measurement
            $this->_startProfiler(__FUNCTION__);

            // Check if query is empty then generate it first.
            if (empty($this->_query)) {
                $this->_buildSelectQuery();
            }

            $lastFromPos = strrpos($this->_query, 'FROM');
            $sqlTotal = 'SELECT COUNT(*) as count ' . preg_replace(
                '/\s+(ORDER\s+BY|LIMIT|OFFSET)\s+.*?(?=\s+(GROUP\s+BY|HAVING|UNION|;)|$)/i',
                '',
                substr($this->_query, $lastFromPos)
            );

            // Execute the total count query
            $stmtTotal = $this->pdo[$this->connectionName]->prepare($sqlTotal);

            // Bind parameters if any
            if (!empty($this->_binds)) {
                $this->_bindParams($stmtTotal, $this->_binds);
            }

            // Log the query for debugging 
            $this->_profiler['profiling'][__FUNCTION__]['query'] = $sqlTotal;

            // Generate the full query string with bound values 
            $this->_generateFullQuery($sqlTotal, $this->_binds);

            $stmtTotal->execute();
            $totalResult = $stmtTotal->fetch(\PDO::FETCH_ASSOC);

            // Stop profiler
            $this->_stopProfiler();

            return $totalResult['count'] ?? 0;
        } catch (\PDOException $e) {
            // Log database errors
            $this->db_error_log($e, __FUNCTION__);
            throw $e; // Re-throw the exception
        }
    }

    public function _getLimitOffsetPaginate($query, $limit, $offset)
    {
        // Try to cast the input to an integer
        $limit = filter_var($limit, FILTER_VALIDATE_INT);
        $offset = filter_var($offset, FILTER_VALIDATE_INT);

        // Check if the input is not an integer after casting
        if ($offset === false) {
            throw new \InvalidArgumentException('Offset must be an integer.');
        }

        // Check if the input is less then 0
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be integer with higher or equal to zero');
        }

        // Check if the input is not an integer after casting
        if ($limit === false) {
            throw new \InvalidArgumentException('Limit must be an integer.');
        }

        // Check if the input is less then 1
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be integer with higher then zero');
        }

        return "$query LIMIT $limit OFFSET $offset";
    }

    protected function sanitizeColumn($data)
    {
        $columns_table = $this->getTableColumns();

        // Filter $data array based on $columns_table
        $data = array_intersect_key($data, array_flip($columns_table));

        if ($this->_secureInput) {
            $data = array_map(function ($value) {
                if ($value === '') {
                    return null;
                }

                // Sanitize non-empty values
                return $this->sanitize($value);
            }, $data);
        } else {
            // Even without sanitization, empty string should be null
            $data = array_map(function ($value) {
                return $value === '' ? null : $value;
            }, $data);
        }

        return $data;
    }

    public function batchInsert($data)
    {
        return $this;
    }

    public function batchUpdate($data)
    {
        return $this;
    }

    public function upsert($values, $uniqueBy = 'id', $updateColumns = null, $batchSize = 2000)
    {
        // Start profiler for performance measurement 
        $this->_startProfiler(__FUNCTION__);

        try {
            // Input validation
            if (empty($values) || empty($uniqueBy)) {
                throw new \InvalidArgumentException('Values and uniqueBy are required');
            }

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
                throw new \InvalidArgumentException('Invalid table name');
            }

            $validColumns = $this->getTableColumns();
            if (empty($validColumns)) {
                throw new \InvalidArgumentException('Unable to retrieve table columns');
            }

            $uniqueByArray = (array) $uniqueBy;
            foreach ($uniqueByArray as $column) {
                if (!in_array($column, $validColumns, true)) {
                    throw new \InvalidArgumentException("Invalid uniqueBy column: $column");
                }
            }

            $values = isset($values[0]) ? $values : [$values];
            $totalRecords = count($values);

            if ($totalRecords === 0) {
                // Stop profiler 
                $this->_stopProfiler();

                // Reset internal properties for next query
                $this->reset();

                return $this->_returnResult(['code' => 200, 'affected_rows' => 0, 'message' => 'No data to process']);
            }

            // Skip database optimizations for small datasets (< 100 records)
            $skipOptimization = $totalRecords < 100;

            // Store original database settings for performance optimization
            $originalSettings = [];
            if (!$skipOptimization) {
                try {
                    $settingsQueries = [
                        'autocommit' => 'SELECT @@autocommit',
                        'unique_checks' => 'SELECT @@unique_checks',
                        'foreign_key_checks' => 'SELECT @@foreign_key_checks',
                        'bulk_insert_buffer_size' => 'SELECT @@bulk_insert_buffer_size'
                    ];

                    foreach ($settingsQueries as $key => $query) {
                        $stmt = $this->pdo[$this->connectionName]->query($query);
                        $originalSettings[$key] = $stmt->fetchColumn();
                    }

                    // Optimize for bulk operations
                    $this->pdo[$this->connectionName]->exec('SET autocommit = 0');
                    $this->pdo[$this->connectionName]->exec('SET unique_checks = 0');
                    $this->pdo[$this->connectionName]->exec('SET foreign_key_checks = 0');
                    $this->pdo[$this->connectionName]->exec('SET bulk_insert_buffer_size = 268435456');
                    $this->pdo[$this->connectionName]->beginTransaction();
                } catch (\Exception $e) {
                    error_log("Database optimization failed: " . $e->getMessage());
                }
            } else {
                // For small datasets, just start a simple transaction
                $this->beginTransaction();
            }

            try {
                $totalAffectedRows = 0;
                $batchCount = 0;
                $chunks = array_chunk($values, $batchSize);

                foreach ($chunks as $chunk) {
                    $batchCount++;

                    // Sanitize and filter batch data inline
                    $sanitizedBatch = [];
                    foreach ($chunk as $row) {
                        if (!is_array($row) || empty($row)) continue;

                        $cleanRow = array_intersect_key($row, array_flip($validColumns));

                        if ($this->_secureInput ?? false) {
                            $cleanRow = array_map(function ($value) {
                                return $value === '' ? null : $this->sanitize($value);
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

                    // Get column structure and prepare update columns
                    $firstRow = $sanitizedBatch[0];
                    $columns = array_keys($firstRow);

                    if ($updateColumns === null) {
                        $updateCols = array_diff($columns, $uniqueByArray);
                    } else {
                        foreach ($updateColumns as $column) {
                            if (!in_array($column, $validColumns, true)) {
                                throw new \InvalidArgumentException("Invalid update column: $column");
                            }
                        }
                        $updateCols = array_diff($updateColumns, $uniqueByArray);
                    }

                    // Build and execute query
                    $escapedColumns = array_map(function ($col) {
                        return '`' . str_replace('`', '``', $col) . '`';
                    }, $columns);

                    $escapedTable = '`' . str_replace('`', '``', $this->table) . '`';
                    $placeholderRow = '(' . str_repeat('?,', count($columns) - 1) . '?)';
                    $allPlaceholders = str_repeat($placeholderRow . ',', count($sanitizedBatch) - 1) . $placeholderRow;

                    $sql = "INSERT INTO $escapedTable (" . implode(',', $escapedColumns) . ") VALUES $allPlaceholders";

                    if (!empty($updateCols)) {
                        $updates = array_map(function ($col) {
                            $escapedCol = '`' . str_replace('`', '``', $col) . '`';
                            return "$escapedCol = VALUES($escapedCol)";
                        }, $updateCols);
                        $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
                    }

                    $stmt = $this->pdo[$this->connectionName]->prepare($sql);

                    // Flatten values for binding
                    $bindValues = [];
                    foreach ($sanitizedBatch as $row) {
                        foreach ($columns as $col) {
                            $bindValues[] = $row[$col] ?? null;
                        }
                    }

                    $stmt->execute($bindValues);
                    $totalAffectedRows += $stmt->rowCount();

                    // Memory management only for large datasets
                    if (!$skipOptimization && $batchCount % 10 === 0) {
                        gc_collect_cycles();
                    }

                    unset($sanitizedBatch, $bindValues); // Free memory
                }

                // Commit transaction
                if ($this->pdo[$this->connectionName]->inTransaction()) {
                    $this->commit();
                }

                $result = $this->_returnResult([
                    'code' => 200,
                    'affected_rows' => $totalAffectedRows,
                    'message' => $totalRecords > 100 ? "Bulk upsert completed successfully" : "Upsert completed successfully",
                    'batches_processed' => $batchCount,
                    'total_records' => $totalRecords
                ]);
            } catch (\Exception $e) {
                if ($this->pdo[$this->connectionName]->inTransaction()) {
                    $this->rollback();
                }
                throw $e;
            } finally {

                // Stop profiler 
                $this->_stopProfiler();

                // Reset internal properties for next query
                $this->reset();

                // Restore original database settings only if they were changed
                try {
                    if (!$skipOptimization && !empty($originalSettings)) {
                        $this->pdo[$this->connectionName]->exec("SET autocommit = {$originalSettings['autocommit']}");
                        $this->pdo[$this->connectionName]->exec("SET unique_checks = {$originalSettings['unique_checks']}");
                        $this->pdo[$this->connectionName]->exec("SET foreign_key_checks = {$originalSettings['foreign_key_checks']}");
                        $this->pdo[$this->connectionName]->exec("SET bulk_insert_buffer_size = {$originalSettings['bulk_insert_buffer_size']}");
                    }
                } catch (\Exception $e) {
                    error_log("Failed to restore database settings: " . $e->getMessage());
                }
            }

            return $result;
        } catch (\Exception $e) {

            // Stop profiler 
            $this->_stopProfiler();

            // Reset internal properties for next query
            $this->reset();

            $this->db_error_log($e, __FUNCTION__);
            return $this->_returnResult([
                'code' => 400,
                'message' => 'Upsert failed: ' . $e->getMessage()
            ]);
        }
    }
}

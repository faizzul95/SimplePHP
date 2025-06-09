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

    public function whereDate($column, $date, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereDate(). Please use simpleQuery() function.');

            $formattedDate = $this->validateDate($date);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DATE_FORMAT($column, '%Y-%m-%d')", $formattedDate, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereDate($column, $date, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereDate(). Please use simpleQuery() function.');

            $formattedDate = $this->validateDate($date);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DATE_FORMAT($column, '%Y-%m-%d')", $formattedDate, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereDay($column, $day, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereDay(). Please use simpleQuery() function.');

            $this->validateDay($day);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DAY($column)", (int)$day, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereDay($column, $day, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereDay(). Please use simpleQuery() function.');

            $this->validateDay($day);
            $this->validateOperator($operator);

            $this->_buildWhereClause("DAY($column)", (int)$day, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereMonth($column, $month, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereMonth(). Please use simpleQuery() function.');

            $this->validateMonth($month);
            $this->validateOperator($operator);

            $this->_buildWhereClause("MONTH($column)", (int)$month, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereMonth($column, $month, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereMonth(). Please use simpleQuery() function.');

            $this->validateMonth($month);
            $this->validateOperator($operator);

            $this->_buildWhereClause("MONTH($column)", (int)$month, $operator, 'OR');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function whereYear($column, $year, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in whereYear(). Please use simpleQuery() function.');

            $this->validateYear($year);
            $this->validateOperator($operator);

            $this->_buildWhereClause("YEAR($column)", (int)$year, $operator, 'AND');
            return $this;
        } catch (\InvalidArgumentException $e) {
            $this->db_error_log($e, __FUNCTION__);
        }
    }

    public function orWhereYear($column, $year, $operator = '=')
    {
        try {
            $this->validateColumn($column);

            // Check if variable contains a full SQL statement
            $this->_forbidRawQuery($column, 'Full/Sub SQL statements are not allowed in orWhereYear(). Please use simpleQuery() function.');

            $this->validateYear($year);
            $this->validateOperator($operator);

            $this->_buildWhereClause("YEAR($column)", (int)$year, $operator, 'OR');
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
        $stmt = $this->pdo[$this->connectionName]->prepare("DESCRIBE {$this->schema}.{$this->table}");
        $stmt->execute();
        $columns_table = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Filter $data array based on $columns_table
        $data = array_intersect_key($data, array_flip($columns_table));

        if ($this->_secureInput) {
            // Sanitize each value in the $data array
            $data = array_map(function ($value) {
                return !is_null($value) && $value !== '' ? $this->sanitize($value) : $value;
            }, $data);
        }

        return $data;
    }
}

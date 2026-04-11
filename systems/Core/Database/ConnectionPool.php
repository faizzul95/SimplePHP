<?php

namespace Core\Database;

/**
 * Database Connection Pool
 * 
 * Manages PDO connections efficiently by implementing connection pooling,
 * reducing connection overhead and improving performance for eager loading.
 * 
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.0.0
 */
class ConnectionPool
{
    /**
     * @var array Pool of active connections
     */
    protected static $pool = [];

    /**
     * @var array Connection configurations
     */
    protected static $configs = [];

    /**
     * @var array Connection statistics
     */
    protected static $stats = [
        'total_requests' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'active_connections' => 0
    ];

    /**
     * @var int Maximum number of connections per configuration
     */
    protected static $maxConnections = 10;

    /**
     * @var int Connection timeout in seconds
     */
    protected static $connectionTimeout = 30;

    /**
     * @var array Last activity time for each connection
     */
    protected static $lastActivity = [];

    /**
     * Register a connection configuration
     *
     * @param string $name Connection name
     * @param array  $config Connection configuration
     * @return void
     */
    public static function register($name, array $config)
    {
        self::$configs[$name] = $config;
    }

    /**
     * Get a connection from the pool or create a new one
     *
     * @param string $name Connection name
     * @param array  $config Connection configuration (optional)
     * @return \PDO
     * @throws \Exception
     */
    public static function getConnection($name, ?array $config = null)
    {
        self::$stats['total_requests']++;

        // Register config if provided
        if ($config !== null) {
            self::register($name, $config);
        }

        // Check if we have a valid connection in the pool
        if (isset(self::$pool[$name])) {
            if (self::isConnectionValid($name)) {
                self::$stats['cache_hits']++;
                self::$lastActivity[$name] = time();
                return self::$pool[$name];
            } else {
                // Remove stale connection
                self::removeConnection($name);
            }
        }

        self::$stats['cache_misses']++;

        // Enforce max connections limit — clean up idle ones first
        if (count(self::$pool) >= self::$maxConnections) {
            self::cleanupIdleConnections();
            if (count(self::$pool) >= self::$maxConnections) {
                throw new \Exception("Connection pool limit reached ({$name}): maximum " . self::$maxConnections . " connections allowed");
            }
        }

        // Create new connection
        return self::createConnection($name);
    }

    /**
     * Create a new PDO connection
     *
     * @param string $name Connection name
     * @return \PDO
     * @throws \Exception
     */
    protected static function createConnection($name)
    {
        if (!isset(self::$configs[$name])) {
            throw new \Exception("Configuration for connection '$name' not found");
        }

        $config = self::$configs[$name];

        // Build DSN with validated parameters
        $driver = $config['driver'] ?? 'mysql';

        // Validate DSN components to prevent injection
        $allowedDrivers = ['mysql', 'mariadb', 'pgsql', 'sqlite'];
        if (!in_array($driver, $allowedDrivers, true)) {
            throw new \Exception("Unsupported database driver: '$driver'");
        }

        // MariaDB uses the mysql PDO driver
        if ($driver === 'mariadb') {
            $driver = 'mysql';
        }

        $host = $config['host'] ?? 'localhost';
        if (!preg_match('/^[a-zA-Z0-9._\-:]+$/', $host)) {
            throw new \Exception("Invalid host format for connection '$name'");
        }

        $database = $config['database'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $database)) {
            throw new \Exception("Invalid database name format for connection '$name'");
        }

        $dsn = "{$driver}:host={$host};dbname={$database}";

        if (isset($config['charset']) && preg_match('/^[a-zA-Z0-9_]+$/', $config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }
        if (isset($config['port'])) {
            $port = filter_var($config['port'], FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                throw new \Exception("Invalid port number for connection '$name'");
            }
            $dsn .= ";port={$port}";
        }
        if (isset($config['socket']) && preg_match('/^[\/a-zA-Z0-9._\-]+$/', $config['socket'])) {
            $dsn .= ";unix_socket={$config['socket']}";
        }

        // Connection options - persistent connections disabled by default to prevent
        // connection leaks in long-running processes (CLI workers, queue consumers)
        $persistent = $config['persistent'] ?? false;
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_PERSISTENT => (bool) $persistent,
        ];

        if (isset($config['charset']) && !empty($config['charset'])) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$config['charset']}";
            }
        }

        try {
            $pdo = new \PDO($dsn, $config['username'], $config['password'], $options);
            
            // Store in pool
            self::$pool[$name] = $pdo;
            self::$lastActivity[$name] = time();
            self::$stats['active_connections']++;

            return $pdo;
        } catch (\PDOException $e) {
            // Never expose password in error messages
            $safeMessage = preg_replace('/password[=:][^;\s]+/i', 'password=***', $e->getMessage());
            throw new \Exception("Failed to create connection '$name': " . $safeMessage);
        }
    }

    /**
     * Check if a connection is still valid
     * Uses a lightweight time-based check first, only pinging the DB
     * if the connection has been idle for a significant period.
     *
     * @param string $name Connection name
     * @return bool
     */
    protected static function isConnectionValid($name)
    {
        if (!isset(self::$pool[$name])) {
            return false;
        }

        // Check timeout
        if (isset(self::$lastActivity[$name])) {
            $idle = time() - self::$lastActivity[$name];
            if ($idle > self::$connectionTimeout) {
                return false;
            }

            // If connection was used recently (within 5 seconds), skip the ping
            // This avoids unnecessary SELECT 1 queries on active connections
            if ($idle < 5) {
                return true;
            }
        }

        // Only ping the database if connection has been idle for a while
        try {
            self::$pool[$name]->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Remove a connection from the pool
     *
     * @param string $name Connection name
     * @return void
     */
    public static function removeConnection($name)
    {
        if (isset(self::$pool[$name])) {
            self::$pool[$name] = null;
            unset(self::$pool[$name]);
            unset(self::$lastActivity[$name]);
            self::$stats['active_connections']--;
        }
    }

    /**
     * Close all connections
     *
     * @return void
     */
    public static function closeAll()
    {
        foreach (array_keys(self::$pool) as $name) {
            self::removeConnection($name);
        }
        self::$pool = [];
        self::$lastActivity = [];
    }

    /**
     * Clean up idle connections
     *
     * @return int Number of connections closed
     */
    public static function cleanupIdleConnections()
    {
        $closed = 0;
        $now = time();

        foreach (self::$lastActivity as $name => $lastTime) {
            if (($now - $lastTime) > self::$connectionTimeout) {
                self::removeConnection($name);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Get connection statistics
     *
     * @return array
     */
    public static function getStats()
    {
        return array_merge(self::$stats, [
            'pool_size' => count(self::$pool),
            'hit_rate' => self::$stats['total_requests'] > 0 
                ? round((self::$stats['cache_hits'] / self::$stats['total_requests']) * 100, 2) 
                : 0
        ]);
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    public static function resetStats()
    {
        self::$stats = [
            'total_requests' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'active_connections' => count(self::$pool)
        ];
    }

    /**
     * Set maximum connections per configuration
     *
     * @param int $max Maximum connections
     * @return void
     */
    public static function setMaxConnections($max)
    {
        self::$maxConnections = max(1, (int)$max);
    }

    /**
     * Set connection timeout
     *
     * @param int $seconds Timeout in seconds
     * @return void
     */
    public static function setTimeout($seconds)
    {
        self::$connectionTimeout = max(1, (int)$seconds);
    }
}

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
    public static function getConnection($name, array $config = null)
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

        // Build DSN
        $driver = $config['driver'] ?? 'mysql';
        $dsn = "{$driver}:host={$config['host']};dbname={$config['database']}";

        if (isset($config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }
        if (isset($config['port'])) {
            $dsn .= ";port={$config['port']}";
        }
        if (isset($config['socket'])) {
            $dsn .= ";unix_socket={$config['socket']}";
        }

        // Connection options
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_PERSISTENT => true, // Enable persistent connections for MySQL prepared statement cache
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
            throw new \Exception("Failed to create connection '$name': " . $e->getMessage());
        }
    }

    /**
     * Check if a connection is still valid
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
        }

        // Test connection with a simple query
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

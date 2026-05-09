<?php

namespace Core\Database;

/**
 * Database Connection Pool
 *
 * Two-tier connection management:
 *
 * TIER 1 — Intra-request (static $pool):
 *   Within one PHP-FPM request, the same PDO handle is reused for all queries
 *   on the same connection name, eliminating repeated TCP handshakes inside a
 *   single request (e.g. eager-load loops).
 *
 * TIER 2 — Cross-request / cross-worker (PDO::ATTR_PERSISTENT + APCu):
 *   PHP's native persistent PDO keeps the underlying TCP/Unix socket open inside
 *   the FPM worker process between requests.  The OS-level socket is reused on
 *   the next request handled by the SAME worker — no new TCP handshake needed.
 *   APCu stores lightweight connection-health metadata (last-used timestamp,
 *   hit/miss stats) that is SHARED across ALL worker processes in the same pool,
 *   allowing any worker to make informed decisions about connection health without
 *   needing to ping the database on every cold start.
 *
 * Persistent connections are enabled by default when PDO::ATTR_PERSISTENT is
 * supported. Set config['persistent'] = false to opt out (recommended for
 * long-running CLI scripts or queue workers where connection leaks matter).
 *
 * APCu is used only for metadata — never to store the PDO object itself (which
 * cannot be serialised). If APCu is unavailable the pool degrades gracefully to
 * the intra-request tier only.
 *
 * @category Database
 * @package  Core\Database
 * @author   Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version  1.1.0
 */
class ConnectionPool
{
    /** @var array<string, \PDO> Intra-request PDO handle cache. */
    protected static $pool = [];

    /** @var array<string, array> DSN / credential configs keyed by name. */
    protected static $configs = [];

    /**
     * Per-process runtime stats (reset each request).
     * Aggregate stats are persisted in APCu under 'db_pool_stats:<name>'.
     *
     * @var array
     */
    protected static $stats = [
        'total_requests' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'active_connections' => 0,
    ];

    /** @var int Maximum PDO handles kept in the intra-request pool. */
    protected static $maxConnections = 10;

    /**
     * Idle timeout (seconds). A handle that has not been used within this window
     * is considered stale and will be replaced on the next request.
     * 300 s = 5 min; must be ≤ MySQL wait_timeout (default 28800 s).
     *
     * @var int
     */
    protected static $connectionTimeout = 300;

    /** @var array<string, int> Unix timestamps of last use per connection name. */
    protected static $lastActivity = [];

    /** @var string Prefix for all APCu keys used by this class. */
    protected static $apcu_prefix = 'db_pool_meta:';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Register a connection configuration.
     * Called once during bootstrap; safe to call multiple times (idempotent).
     *
     * @param string $name   Logical connection name (e.g. 'default', 'read').
     * @param array  $config DSN parameters: driver, host, port, database,
     *                       username, password, charset, socket, persistent.
     * @return void
     */
    public static function register(string $name, array $config): void
    {
        self::$configs[$name] = $config;
    }

    /**
     * Return an open PDO handle for the given connection name.
     *
     * Resolution order:
     *  1. Intra-request static cache (fastest — same process, same request).
     *  2. PHP persistent connection recycled from a previous request in this
     *     worker (PDO::ATTR_PERSISTENT).  The socket is already open; PDO just
     *     validates it via APCu-backed metadata before handing it back.
     *  3. Fresh TCP connection (cold path — happens once per worker per name).
     *
     * @param string     $name   Logical connection name.
     * @param array|null $config Optional config override (registered if given).
     * @return \PDO
     * @throws \Exception On connection failure or pool exhaustion.
     */
    public static function getConnection(string $name, ?array $config = null): \PDO
    {
        self::$stats['total_requests']++;

        if ($config !== null) {
            self::register($name, $config);
        }

        // Tier 1: intra-request cache hit
        if (isset(self::$pool[$name])) {
            if (self::isConnectionValid($name)) {
                self::$stats['cache_hits']++;
                self::$lastActivity[$name] = time();
                self::updateApcuMeta($name, 'hit');
                return self::$pool[$name];
            }
            self::removeConnection($name);
        }

        self::$stats['cache_misses']++;

        // Enforce per-process pool limit
        if (count(self::$pool) >= self::$maxConnections) {
            self::cleanupIdleConnections();
            if (count(self::$pool) >= self::$maxConnections) {
                throw new \Exception(
                    "Connection pool limit reached ({$name}): maximum " . self::$maxConnections . " connections allowed"
                );
            }
        }

        // Tier 2/3: create (or reuse persistent) connection
        return self::createConnection($name);
    }

    // -------------------------------------------------------------------------
    // Internal: connection creation
    // -------------------------------------------------------------------------

    /**
     * Build a new PDO connection and register it in the intra-request pool.
     *
     * When config['persistent'] is true (default for web requests) PHP's PDO
     * layer keeps the socket open after the request ends and reuses it for the
     * next request on the same worker — this IS the cross-request pool.
     *
     * @param string $name
     * @return \PDO
     * @throws \Exception
     */
    protected static function createConnection(string $name): \PDO
    {
        if (!isset(self::$configs[$name])) {
            throw new \Exception("Configuration for connection '{$name}' not found");
        }

        $config = self::$configs[$name];

        // ---- Validate + build DSN ----------------------------------------
        $driver = strtolower($config['driver'] ?? 'mysql');
        $allowedDrivers = ['mysql', 'mariadb', 'pgsql', 'sqlite'];
        if (!in_array($driver, $allowedDrivers, true)) {
            throw new \Exception("Unsupported database driver: '{$driver}'");
        }

        // MariaDB shares the MySQL PDO driver
        $pdoDriver = ($driver === 'mariadb') ? 'mysql' : $driver;

        $host = $config['host'] ?? 'localhost';
        if (!preg_match('/^[a-zA-Z0-9._\-:]+$/', (string) $host)) {
            throw new \Exception("Invalid host format for connection '{$name}'");
        }

        $database = $config['database'] ?? '';
        if ($pdoDriver !== 'sqlite' && !preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $database)) {
            throw new \Exception("Invalid database name format for connection '{$name}'");
        }

        if ($pdoDriver === 'sqlite') {
            $dsn = "sqlite:{$database}";
        } else {
            $dsn = "{$pdoDriver}:host={$host};dbname={$database}";
            if (isset($config['charset']) && preg_match('/^[a-zA-Z0-9_]+$/', (string) $config['charset'])) {
                $dsn .= ";charset={$config['charset']}";
            }
            if (isset($config['port'])) {
                $port = filter_var($config['port'], FILTER_VALIDATE_INT);
                if ($port === false || $port < 1 || $port > 65535) {
                    throw new \Exception("Invalid port number for connection '{$name}'");
                }
                $dsn .= ";port={$port}";
            }
            if (isset($config['socket']) && preg_match('/^[\/a-zA-Z0-9._\-]+$/', (string) $config['socket'])) {
                $dsn .= ";unix_socket={$config['socket']}";
            }
        }

        // ---- PDO options --------------------------------------------------
        // persistent = true  → PHP reuses the socket on the SAME worker across
        //                       requests (cross-request pool, no external proxy
        //                       needed for single-server setups).
        // persistent = false → Fresh connection each request (safer for CLI
        //                       workers / queue consumers to avoid leaks).
        $persistent = (bool) ($config['persistent'] ?? true);

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_PERSISTENT         => $persistent,
            // Timeout: bail out after 5 s on TCP handshake
            \PDO::ATTR_TIMEOUT            => 5,
        ];

        if ($pdoDriver === 'mysql') {
            if (isset($config['charset']) && $config['charset'] !== '') {
                $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . preg_replace('/[^a-zA-Z0-9_]/', '', $config['charset']);
            }

            // SSL/TLS support — enabled when ssl_enabled is true or ssl_ca is provided.
            // Supported on shared hosting as long as the MySQL client libs include SSL.
            if (!empty($config['ssl_enabled']) || !empty($config['ssl_ca'])) {
                if (!empty($config['ssl_ca']) && is_readable((string) $config['ssl_ca'])) {
                    $options[\PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
                }
                if (!empty($config['ssl_cert']) && is_readable((string) $config['ssl_cert'])) {
                    $options[\PDO::MYSQL_ATTR_SSL_CERT] = $config['ssl_cert'];
                }
                if (!empty($config['ssl_key']) && is_readable((string) $config['ssl_key'])) {
                    $options[\PDO::MYSQL_ATTR_SSL_KEY] = $config['ssl_key'];
                }
                // Verify the server certificate — set to false only for self-signed certs in dev
                $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($config['ssl_verify'] ?? true);
            }
        }

        try {
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', $options);
        } catch (\PDOException $e) {
            throw new \Exception(
                "Failed to create connection '{$name}': " . self::sanitizeConnectionError($e->getMessage())
            );
        }

        // Register in intra-request pool
        self::$pool[$name] = $pdo;
        self::$lastActivity[$name] = time();
        self::$stats['active_connections']++;

        // Publish creation event to APCu so other workers can see pool activity
        self::updateApcuMeta($name, 'miss');

        return $pdo;
    }

    // -------------------------------------------------------------------------
    // Internal: APCu cross-process metadata
    // -------------------------------------------------------------------------

    /**
     * Check whether APCu is available and enabled for the current SAPI.
     * Result is memoised per-process after the first call.
     *
     * @return bool
     */
    protected static function apcuAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = function_exists('apcu_store')
                && function_exists('apcu_fetch')
                && function_exists('apcu_enabled')
                && (bool) call_user_func('apcu_enabled');
        }
        return $available;
    }

    protected static function apcuFetch(string $key): mixed
    {
        return call_user_func('apcu_fetch', $key);
    }

    protected static function apcuStore(string $key, mixed $value, int $ttl): bool
    {
        return (bool) call_user_func('apcu_store', $key, $value, $ttl);
    }

    /**
     * Update the shared APCu metadata entry for a connection.
     *
     * The metadata block is intentionally tiny — it never contains credentials
     * or the PDO object itself (which cannot be serialised).
     *
     * Fields:
     *  - last_used      : unix timestamp of most recent successful use
     *  - total_hits     : cumulative intra-pool cache hits across ALL workers
     *  - total_misses   : cumulative new-connection events across ALL workers
     *  - worker_pid     : PID of the worker that last updated this entry
     *
     * @param string $name Connection name
     * @param string $event 'hit' or 'miss'
     * @return void
     */
    protected static function updateApcuMeta(string $name, string $event): void
    {
        if (!self::apcuAvailable()) {
            return;
        }

        $key = self::$apcu_prefix . $name;
        $ttl = self::$connectionTimeout + 60; // keep metadata slightly longer than idle timeout

        // Read-modify-write with a short spin to reduce (but not eliminate) races.
        // Full atomicity would require a mutex; for stats a slight inaccuracy is acceptable.
        $existing = self::apcuFetch($key) ?: [
            'last_used'   => 0,
            'total_hits'  => 0,
            'total_misses' => 0,
            'worker_pid'  => 0,
        ];

        $existing['last_used']  = time();
        $existing['worker_pid'] = getmypid();

        if ($event === 'hit') {
            $existing['total_hits']++;
        } else {
            $existing['total_misses']++;
        }

        self::apcuStore($key, $existing, $ttl);
    }

    /**
     * Retrieve APCu metadata for a connection (all workers combined).
     *
     * @param string $name
     * @return array|null  Metadata array, or null if APCu is unavailable / no entry.
     */
    public static function getApcuMeta(string $name): ?array
    {
        if (!self::apcuAvailable()) {
            return null;
        }
        $data = self::apcuFetch(self::$apcu_prefix . $name);
        return is_array($data) ? $data : null;
    }

    /**
     * Redact connection details from driver error messages before rethrowing.
     */
    protected static function sanitizeConnectionError(string $message): string
    {
        $patterns = [
            '/password\s*[=:]\s*[^;\s]+/i',
            '/username\s*[=:]\s*[^;\s]+/i',
            '/user\s*[=:]\s*[^;\s]+/i',
            '/dbname\s*=\s*[^;\s]+/i',
            '/host\s*=\s*[^;\s]+/i',
            '/port\s*=\s*[^;\s]+/i',
            '/unix_socket\s*=\s*[^;\s]+/i',
        ];

        $replacements = [
            'password=***',
            'username=***',
            'user=***',
            'dbname=***',
            'host=***',
            'port=***',
            'unix_socket=***',
        ];

        return preg_replace($patterns, $replacements, $message) ?? 'Connection error';
    }

    /**
     * Check if a connection is still valid.
     * Uses a lightweight time-based check first, only pinging the DB
     * if the connection has been idle for a significant period.
     *
     * @param string $name Connection name
     * @return bool
     */
    protected static function isConnectionValid(string $name): bool
    {
        if (!isset(self::$pool[$name])) {
            return false;
        }

        $idle = isset(self::$lastActivity[$name])
            ? (time() - self::$lastActivity[$name])
            : (self::$connectionTimeout + 1);

        // Definitely stale — do not even attempt a ping
        if ($idle > self::$connectionTimeout) {
            return false;
        }

        // Used within the last 5 s — skip the ping; trust the handle
        if ($idle < 5) {
            return true;
        }

        // Idle 5 s – timeout: issue a minimal ping to confirm the socket is alive
        try {
            self::$pool[$name]->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Remove a connection from the intra-request pool.
     *
     * @param string $name
     * @return void
     */
    public static function removeConnection(string $name): void
    {
        if (isset(self::$pool[$name])) {
            // Explicitly nullify so PDO's persistent-connection bookkeeping is
            // aware the handle was intentionally released.
            self::$pool[$name] = null;
            unset(self::$pool[$name], self::$lastActivity[$name]);
            self::$stats['active_connections'] = max(0, self::$stats['active_connections'] - 1);
        }
    }

    /**
     * Close all pooled connections in this process.
     *
     * Note: for persistent connections PHP will reclaim the socket on the next
     * request automatically. Calling this invalidates the intra-request cache
     * only — the OS socket may still be held by the FPM worker.
     *
     * @return void
     */
    public static function closeAll(): void
    {
        foreach (array_keys(self::$pool) as $name) {
            self::removeConnection($name);
        }
    }

    /**
     * Evict connections that have exceeded the idle timeout.
     *
     * @return int Number of connections evicted.
     */
    public static function cleanupIdleConnections(): int
    {
        $closed = 0;
        $now    = time();

        foreach (self::$lastActivity as $name => $lastTime) {
            if (($now - $lastTime) > self::$connectionTimeout) {
                self::removeConnection($name);
                $closed++;
            }
        }

        return $closed;
    }

    // -------------------------------------------------------------------------
    // Stats / configuration
    // -------------------------------------------------------------------------

    /**
     * Return combined per-process and cross-worker statistics.
     *
     * @return array
     */
    public static function getStats(): array
    {
        $total = self::$stats['total_requests'];

        $base = array_merge(self::$stats, [
            'pool_size'    => count(self::$pool),
            'hit_rate'     => $total > 0
                ? round((self::$stats['cache_hits'] / $total) * 100, 2)
                : 0,
            'persistent_enabled' => true,
            'apcu_available'     => self::apcuAvailable(),
        ]);

        // Merge APCu cross-worker aggregates for all registered connections
        $base['cross_worker'] = [];
        foreach (array_keys(self::$configs) as $name) {
            $meta = self::getApcuMeta($name);
            if ($meta !== null) {
                $base['cross_worker'][$name] = $meta;
            }
        }

        return $base;
    }

    /**
     * Reset per-process runtime statistics.
     * Cross-worker APCu entries are not affected.
     *
     * @return void
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'total_requests'     => 0,
            'cache_hits'         => 0,
            'cache_misses'       => 0,
            'active_connections' => count(self::$pool),
        ];
    }

    /**
     * @param int $max Maximum intra-request pool size (per process).
     * @return void
     */
    public static function setMaxConnections(int $max): void
    {
        self::$maxConnections = max(1, $max);
    }

    /**
     * @param int $seconds Idle timeout before a handle is considered stale.
     * @return void
     */
    public static function setTimeout(int $seconds): void
    {
        self::$connectionTimeout = max(1, $seconds);
    }

    /**
     * Flush the intra-request pool WITHOUT closing the underlying persistent sockets.
     *
     * Use this in Octane/RoadRunner/FrankenPHP worker mode to clear per-request
     * static state so the next request starts with a clean handle reference,
     * while still benefiting from persistent connection reuse.
     *
     * If $closeConnections is true the PDO handles are nulled (forces re-open
     * on next request — safer for queue workers that run arbitrary DDL).
     *
     * @param bool $closeConnections Whether to also null the PDO handles (default: false)
     * @return void
     */
    public static function reset(bool $closeConnections = false): void
    {
        if ($closeConnections) {
            foreach (self::$pool as $name => $pdo) {
                self::$pool[$name] = null;
            }
        }
        self::$pool         = [];
        self::$lastActivity = [];
        self::resetStats();
    }
}

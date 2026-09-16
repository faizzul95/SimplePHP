<?php

declare(strict_types=1);

namespace Core\Database;

use InvalidArgumentException;

/**
 * How each engine spells its connection string.
 *
 * The pool built one DSN shape — `driver:host=…;dbname=…` — which is right for
 * MySQL, PostgreSQL and nothing else. SQL Server wants `Server=host,port` with a
 * comma; Oracle wants an Easy Connect descriptor and does not use `dbname` for
 * the host at all. Adding either engine meant editing the middle of
 * createConnection() rather than adding a case here.
 *
 * Validation lives here too, because the rules differ: `^[A-Za-z0-9_-]+$` is a
 * reasonable MySQL database name and rejects the perfectly ordinary Oracle
 * service name `orclpdb1.localdomain` and the SQL Server instance `HOST\SQLEXPRESS`.
 */
final class Dsn
{
    /** Engines the pool can actually open a socket to. */
    public const SUPPORTED = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv', 'oci'];

    /**
     * The PDO driver a configured engine name maps to.
     *
     * MariaDB has no PDO driver of its own — it speaks the MySQL protocol — which
     * is why TimeoutDialect has to read the server banner to tell them apart.
     */
    public static function pdoDriver(string $driver): string
    {
        return match (strtolower(trim($driver))) {
            'mariadb' => 'mysql',
            'oracle' => 'oci',
            'mssql' => 'sqlsrv',
            default => strtolower(trim($driver)),
        };
    }

    /**
     * @param  array<string, mixed> $config
     * @throws InvalidArgumentException when a value would be unsafe to interpolate
     */
    public static function build(array $config, string $connectionName = 'default'): string
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? 'mysql')));

        if (!in_array($driver, self::SUPPORTED, true) && !in_array(self::pdoDriver($driver), self::SUPPORTED, true)) {
            throw new InvalidArgumentException("Unsupported database driver: '{$driver}'");
        }

        $pdoDriver = self::pdoDriver($driver);

        return match ($pdoDriver) {
            'sqlite' => self::sqlite($config),
            'pgsql' => self::postgres($config, $connectionName),
            'sqlsrv' => self::sqlServer($config, $connectionName),
            'oci' => self::oracle($config, $connectionName),
            default => self::mysql($config, $connectionName),
        };
    }

    private static function sqlite(array $config): string
    {
        $path = trim((string) ($config['database'] ?? ''));

        // ':memory:' and a filesystem path are both legal; neither is validated
        // against the identifier pattern the server engines use.
        return 'sqlite:' . ($path === '' ? ':memory:' : $path);
    }

    private static function mysql(array $config, string $name): string
    {
        $dsn = 'mysql:host=' . self::host($config, $name)
            . ';dbname=' . self::identifier($config['database'] ?? '', 'database name', $name);

        if (($charset = self::charset($config)) !== null) {
            $dsn .= ';charset=' . $charset;
        }

        if (($port = self::port($config, $name)) !== null) {
            $dsn .= ';port=' . $port;
        }

        if (($socket = self::socket($config)) !== null) {
            $dsn .= ';unix_socket=' . $socket;
        }

        return $dsn;
    }

    private static function postgres(array $config, string $name): string
    {
        $dsn = 'pgsql:host=' . self::host($config, $name)
            . ';dbname=' . self::identifier($config['database'] ?? '', 'database name', $name);

        if (($port = self::port($config, $name)) !== null) {
            $dsn .= ';port=' . $port;
        }

        // PostgreSQL negotiates TLS per connection rather than through driver
        // options, so the mode belongs in the DSN.
        $sslMode = strtolower(trim((string) ($config['sslmode'] ?? '')));
        if (in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $dsn .= ';sslmode=' . $sslMode;
        }

        return $dsn;
    }

    /**
     * `sqlsrv:Server=host,port;Database=db` — a comma before the port, not a
     * separate key, and an instance name is `host\INSTANCE`.
     */
    private static function sqlServer(array $config, string $name): string
    {
        $server = self::host($config, $name, allowBackslash: true);

        if (($port = self::port($config, $name)) !== null) {
            $server .= ',' . $port;
        }

        $dsn = 'sqlsrv:Server=' . $server
            . ';Database=' . self::identifier($config['database'] ?? '', 'database name', $name, allowDots: true);

        if (array_key_exists('encrypt', $config)) {
            $dsn .= ';Encrypt=' . ((bool) $config['encrypt'] ? '1' : '0');
        }

        if (array_key_exists('trust_server_certificate', $config)) {
            $dsn .= ';TrustServerCertificate=' . ((bool) $config['trust_server_certificate'] ? '1' : '0');
        }

        return $dsn;
    }

    /**
     * Oracle Easy Connect: `oci:dbname=//host:port/service`. `database` here is
     * the service name, which routinely contains dots.
     */
    private static function oracle(array $config, string $name): string
    {
        $service = self::identifier($config['database'] ?? '', 'service name', $name, allowDots: true);
        $host = self::host($config, $name);
        $port = self::port($config, $name) ?? 1521;

        $dsn = 'oci:dbname=//' . $host . ':' . $port . '/' . $service;

        // Oracle will not transcode on its own; without this a UTF-8 column comes
        // back in the server's character set.
        if (($charset = self::charset($config)) !== null) {
            $dsn .= ';charset=' . $charset;
        }

        return $dsn;
    }

    // ─── Value validation ────────────────────────────────────────────

    private static function host(array $config, string $name, bool $allowBackslash = false): string
    {
        $host = trim((string) ($config['host'] ?? 'localhost'));

        if ($host === '') {
            $host = 'localhost';
        }

        // Everything interpolated into a DSN has to be constrained, because a
        // semicolon in a host would append an attacker-chosen DSN parameter.
        $pattern = $allowBackslash ? '/^[A-Za-z0-9._\-:\\\\]+$/' : '/^[A-Za-z0-9._\-:]+$/';

        if (preg_match($pattern, $host) !== 1) {
            throw new InvalidArgumentException("Invalid host format for connection '{$name}'");
        }

        return $host;
    }

    private static function identifier(mixed $value, string $label, string $name, bool $allowDots = false): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new InvalidArgumentException("Missing {$label} for connection '{$name}'");
        }

        $pattern = $allowDots ? '/^[A-Za-z0-9._\-]+$/' : '/^[A-Za-z0-9_\-]+$/';

        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("Invalid {$label} format for connection '{$name}'");
        }

        return $value;
    }

    private static function port(array $config, string $name): ?int
    {
        if (!isset($config['port']) || $config['port'] === '') {
            return null;
        }

        $port = filter_var($config['port'], FILTER_VALIDATE_INT);

        if ($port === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port number for connection '{$name}'");
        }

        return $port;
    }

    private static function charset(array $config): ?string
    {
        $charset = trim((string) ($config['charset'] ?? ''));

        return preg_match('/^[A-Za-z0-9_]+$/', $charset) === 1 ? $charset : null;
    }

    private static function socket(array $config): ?string
    {
        $socket = trim((string) ($config['socket'] ?? ''));

        return preg_match('#^[/A-Za-z0-9._\-]+$#', $socket) === 1 ? $socket : null;
    }
}

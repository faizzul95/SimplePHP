<?php

namespace Core\Database;

use Core\Database\Query\Grammars\QueryGrammar;
use Core\Database\Schema\Grammars\SchemaGrammar;
use InvalidArgumentException;

class DriverRegistry
{
    /** @var array<string, array{class: string, capabilities: DriverCapabilities, schema_grammar: class-string<SchemaGrammar>, query_grammar: class-string<QueryGrammar>}> */
    private static array $drivers = [];

    private static bool $bootstrapped = false;

    public static function register(string $name, string $driverClass, ?DriverCapabilities $capabilities = null, ?string $schemaGrammarClass = null, ?string $queryGrammarClass = null): void
    {
        $normalizedName = strtolower(trim($name));
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Database driver name cannot be empty.');
        }

        $resolvedSchemaGrammarClass = $schemaGrammarClass ?? \Core\Database\Schema\Grammars\MySQLGrammar::class;
        $resolvedQueryGrammarClass = $queryGrammarClass ?? \Core\Database\Query\Grammars\MySQLGrammar::class;

        self::$drivers[$normalizedName] = [
            'class' => $driverClass,
            'capabilities' => $capabilities ?? new DriverCapabilities($normalizedName),
            'schema_grammar' => $resolvedSchemaGrammarClass,
            'query_grammar' => $resolvedQueryGrammarClass,
        ];
    }

    public static function resolveClass(string $name): string
    {
        self::bootstrap();

        $normalizedName = strtolower(trim($name));
        if (!isset(self::$drivers[$normalizedName])) {
            throw new InvalidArgumentException("Unsupported database driver: {$normalizedName}");
        }

        $class = self::$drivers[$normalizedName]['class'];

        // A grammar-only registration: the SQL is written, the connection driver
        // is not. Saying so beats a class-not-found further down the stack.
        if ($class === '') {
            throw new InvalidArgumentException(sprintf(
                'Database driver [%s] has a query grammar but no connection driver yet. '
                . 'Register one with DriverRegistry::register(\'%s\', YourDriver::class).',
                $normalizedName,
                $normalizedName
            ));
        }

        return $class;
    }

    public static function capabilities(string $name): DriverCapabilities
    {
        self::bootstrap();

        $normalizedName = strtolower(trim($name));
        if (!isset(self::$drivers[$normalizedName])) {
            throw new InvalidArgumentException("Unsupported database driver: {$normalizedName}");
        }

        return self::$drivers[$normalizedName]['capabilities'];
    }

    public static function schemaGrammar(string $name): SchemaGrammar
    {
        self::bootstrap();

        $normalizedName = strtolower(trim($name));
        if (!isset(self::$drivers[$normalizedName])) {
            throw new InvalidArgumentException("Unsupported database driver: {$normalizedName}");
        }

        $grammarClass = self::$drivers[$normalizedName]['schema_grammar'];

        return new $grammarClass();
    }

    public static function queryGrammar(string $name): QueryGrammar
    {
        self::bootstrap();

        $normalizedName = strtolower(trim($name));
        if (!isset(self::$drivers[$normalizedName])) {
            throw new InvalidArgumentException("Unsupported database driver: {$normalizedName}");
        }

        $grammarClass = self::$drivers[$normalizedName]['query_grammar'];

        return new $grammarClass();
    }

    public static function all(): array
    {
        self::bootstrap();

        $drivers = array_keys(self::$drivers);
        sort($drivers);

        return $drivers;
    }

    private static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        self::register('mysql', 'Core\\Database\\Drivers\\MySQLDriver', new DriverCapabilities('mysql', [
            'date_functions' => true,
            'json_contains' => true,
            'retryable_deadlocks' => true,
            'upsert' => true,
        ], 'MySQL'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\MySQLGrammar::class);

        self::register('mariadb', 'Core\\Database\\Drivers\\MariaDBDriver', new DriverCapabilities('mariadb', [
            'date_functions' => true,
            'json_contains' => true,
            'retryable_deadlocks' => true,
            'upsert' => true,
        ], 'MariaDB'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\MariaDBGrammar::class);

        /*
        | SQLite is a real driver, not a grammar-only registration. It is the
        | second engine the query builder can actually be run against, which is
        | what turns the grammar layer from a claim about generated strings into
        | something a test can prove.
        |
        | No row locking: SQLite locks the database, so FOR UPDATE does not
        | parse. No RETURNING declared either — it exists from 3.35, but
        | lastInsertId() works on every build and answers the same question.
        */
        self::register('sqlite', 'Core\\Database\\Drivers\\SqliteDriver', new DriverCapabilities('sqlite', [
            'date_functions' => true,
            'json_contains' => true,
            'retryable_deadlocks' => false,
            'upsert' => true,
            'returning' => false,
            'skip_locked' => false,
        ], 'SQLite'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\SqliteGrammar::class);

        /*
        | PostgreSQL, SQL Server and Oracle have a query grammar but no connection
        | driver yet, so resolveClass() still refuses them — connecting to an
        | engine whose driver does not exist should fail loudly, not silently
        | behave like MySQL.
        |
        | Registering them here means the SQL each one needs is written, tested,
        | and reachable through queryGrammar(), so adding an engine is a driver
        | class rather than an audit of every backtick in the builder.
        */
        self::register('pgsql', '', new DriverCapabilities('pgsql', [
            'date_functions' => true,
            'json_contains' => true,
            'retryable_deadlocks' => true,
            'upsert' => true,
            'returning' => true,
            'skip_locked' => true,
        ], 'PostgreSQL'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\PostgresGrammar::class);

        self::register('sqlsrv', '', new DriverCapabilities('sqlsrv', [
            'date_functions' => true,
            'json_contains' => false,
            'retryable_deadlocks' => true,
            // MERGE only, which is a statement rather than a trailing clause.
            'upsert' => false,
            'returning' => true,
            'skip_locked' => true,
        ], 'SQL Server'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\SqlServerGrammar::class);

        self::register('oci', '', new DriverCapabilities('oci', [
            'date_functions' => true,
            'json_contains' => false,
            'retryable_deadlocks' => true,
            'upsert' => false,
            'returning' => true,
            'skip_locked' => true,
        ], 'Oracle'), \Core\Database\Schema\Grammars\MySQLGrammar::class, \Core\Database\Query\Grammars\OracleGrammar::class);
    }
}
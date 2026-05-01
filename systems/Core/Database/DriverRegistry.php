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

        return self::$drivers[$normalizedName]['class'];
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
    }
}
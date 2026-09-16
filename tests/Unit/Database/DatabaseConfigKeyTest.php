<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * app/config/database.php assigns $config['db'], so every config('database.*')
 * lookup returned null and silently fell back to a hardcoded constant —
 * DB_PAGINATION_MAX_LIMIT had never taken effect.
 */
final class DatabaseConfigKeyTest extends TestCase
{
    private string $baseDatabase;

    protected function setUp(): void
    {
        $this->baseDatabase = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/BaseDatabase.php'
        );
    }

    public function testNothingReadsTheNonExistentDatabasePrefix(): void
    {
        self::assertStringNotContainsString(
            "config('database.",
            $this->baseDatabase,
            "app/config/database.php defines \$config['db'], so config('database.*') always "
            . 'resolves to null and the caller silently uses its default.'
        );
    }

    public function testPaginationLimitsReadTheDbPrefix(): void
    {
        foreach (['db.pagination.max_limit', 'db.pagination.default_limit'] as $key) {
            self::assertStringContainsString("config('{$key}'", $this->baseDatabase);
        }
    }

    public function testTheConfigFileDefinesThoseKeys(): void
    {
        $config = [];
        require dirname(__DIR__, 3) . '/app/config/database.php';

        self::assertArrayHasKey('db', $config);
        self::assertArrayHasKey('pagination', $config['db']);
        self::assertArrayHasKey('max_limit', $config['db']['pagination']);
        self::assertArrayHasKey('default_limit', $config['db']['pagination']);
    }

    public function testMigrationsAndRollbacksBothDropTheColumnCache(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Schema/MigrationRunner.php'
        );

        self::assertSame(
            2,
            substr_count($runner, 'clearTableColumnsCache()'),
            'Both up() and down() change the schema, so both must drop the cached column list.'
        );
    }
}

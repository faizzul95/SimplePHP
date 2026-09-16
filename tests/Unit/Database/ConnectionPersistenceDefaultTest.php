<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * pdo_mysql does not reset session state when a persistent connection is reused,
 * so a request that dies mid-transaction leaves it open for the next one.
 * Opening a real connection needs a database, so this pins the default in source.
 */
final class ConnectionPersistenceDefaultTest extends TestCase
{
    private string $poolSource;

    protected function setUp(): void
    {
        $this->poolSource = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/ConnectionPool.php'
        );
    }

    public function testPersistenceDefaultsToFalse(): void
    {
        self::assertMatchesRegularExpression(
            '/\$persistent\s*=\s*\(bool\)\s*\(\$config\[\'persistent\'\]\s*\?\?\s*false\)/',
            $this->poolSource,
            'ConnectionPool no longer defaults persistent connections to false. A connection '
            . 'reused across requests carries its previous session state, including an open '
            . 'transaction left behind by a request that died.'
        );
    }

    public function testPersistenceIsNotHardcodedOn(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\$config\[\'persistent\'\]\s*\?\?\s*true/',
            $this->poolSource,
            'Persistent connections are enabled by default again.'
        );

        self::assertDoesNotMatchRegularExpression(
            '/PDO::ATTR_PERSISTENT\s*=>\s*true/',
            $this->poolSource,
            'PDO::ATTR_PERSISTENT is hardcoded on, bypassing the config entirely.'
        );
    }

    public function testTheAttributeStillReadsFromTheResolvedVariable(): void
    {
        self::assertMatchesRegularExpression(
            '/PDO::ATTR_PERSISTENT\s*=>\s*\$persistent/',
            $this->poolSource,
            'PDO::ATTR_PERSISTENT is no longer wired to the $persistent variable, so the '
            . 'config value has no effect.'
        );
    }

    public function testPreparedStatementEmulationStaysOff(): void
    {
        // Not part of this change, but it is the single most important SQL-injection
        // control in the framework and it lives three lines away from what was edited.
        self::assertMatchesRegularExpression(
            '/PDO::ATTR_EMULATE_PREPARES\s*=>\s*false/',
            $this->poolSource,
            'ATTR_EMULATE_PREPARES must stay false — real prepared statements are what make '
            . 'bound values injection-proof.'
        );
    }

    public function testTheDefaultConnectionExposesPersistenceAsAConfigKey(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 3) . '/app/config/database.php');

        self::assertStringContainsString(
            "'persistent'",
            $config,
            'app/config/database.php should surface the persistent option so it is discoverable '
            . 'rather than buried as a ConnectionPool default.'
        );
        self::assertStringContainsString(
            "env('DB_PERSISTENT', false)",
            $config,
            'The persistent option should read DB_PERSISTENT and default to false.'
        );
    }
}

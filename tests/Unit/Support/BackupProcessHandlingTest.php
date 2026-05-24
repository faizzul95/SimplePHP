<?php

declare(strict_types=1);

use Components\Backup;
use PHPUnit\Framework\TestCase;

final class BackupProcessHandlingProbe extends Backup
{
    public bool $openProcessResult = false;
    public array $loggedMessages = [];

    protected function openProcess(array $command, array $descriptors, array &$pipes)
    {
        $pipes = [];
        return $this->openProcessResult;
    }

    protected function logError(string $message): void
    {
        $this->loggedMessages[] = $message;
    }

    public function invokeTryMysqldump(array $config, string $outputFile): bool
    {
        $method = new ReflectionMethod(Backup::class, 'tryMysqldump');
        $method->setAccessible(true);

        return $method->invoke($this, $config, $outputFile);
    }

    public function invokeParentLogError(string $message): void
    {
        parent::logError($message);
    }
}

final class BackupProcessHandlingTest extends TestCase
{
    public function testTryMysqldumpLogsWhenProcessCannotStart(): void
    {
        $backup = new BackupProcessHandlingProbe([
            'mysqldump_path' => PHP_BINARY,
        ]);

        $result = $backup->invokeTryMysqldump([
            'host' => 'localhost',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
            'database' => 'example_db',
        ], ROOT_DIR . 'storage/cache/phpunit-backup-process.sql');

        self::assertFalse($result);
        self::assertContains('mysqldump failed to start. Falling back to the PHP database dumper.', $backup->loggedMessages);
    }

    public function testLogErrorFallsBackWhenLoggerHelperServiceIsUnavailable(): void
    {
        $backup = new BackupProcessHandlingProbe();

        $backup->invokeParentLogError('fallback test message');

        self::assertTrue(true);
    }
}
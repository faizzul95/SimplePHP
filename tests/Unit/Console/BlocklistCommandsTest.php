<?php

declare(strict_types=1);

use App\Console\Commands\BlocklistAddCommand;
use App\Console\Commands\BlocklistImportCommand;
use App\Console\Commands\BlocklistListCommand;
use App\Console\Commands\BlocklistPruneCommand;
use Core\Console\Kernel;
use Core\Security\IpBlocklist;
use PHPUnit\Framework\TestCase;

final class BlocklistCommandsTest extends TestCase
{
    public function testAddCommandRejectsInvalidExpiryFormat(): void
    {
        $command = new class extends BlocklistAddCommand {
            protected function blocklist(): IpBlocklist
            {
                return new IpBlocklist(['enabled' => true]);
            }
        };

        $kernel = new Kernel();
        ob_start();
        $exitCode = $command->handle(['203.0.113.15'], ['reason' => 'Manual', 'expires' => 'never-ish'], $kernel);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --expires value', $output);
    }

    public function testListCommandOutputsJsonSummary(): void
    {
        $fake = new class extends IpBlocklist {
            public function all(): array
            {
                return [[
                    'ip_address' => '203.0.113.15',
                    'reason' => 'Manual block',
                    'auto_added' => 0,
                    'expires_at' => null,
                    'blocked_at' => '2026-05-16 10:00:00',
                ]];
            }
        };

        $command = new class($fake) extends \App\Console\Commands\BlocklistListCommand {
            public function __construct(private IpBlocklist $fake)
            {
            }

            protected function blocklist(): IpBlocklist
            {
                return $this->fake;
            }
        };

        $kernel = new Kernel();
    ob_start();
        $exitCode = $command->handle([], ['format' => 'json'], $kernel);
    $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
    self::assertStringContainsString('"total": 1', $output);
    self::assertStringContainsString('203.0.113.15', $output);
    }

    public function testPruneAndImportCommandsUseBlocklistService(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ibl');
        file_put_contents($tempFile, "203.0.113.15\ninvalid\n198.51.100.8\n");

        $fake = new class extends IpBlocklist {
            public int $pruned = 2;
            public ?array $importArgs = null;

            public function prune(): int
            {
                return $this->pruned;
            }

            public function import(string $filePath, string $reason, ?string $expiresAt = null): int
            {
                $this->importArgs = compact('filePath', 'reason', 'expiresAt');
                return 2;
            }
        };

        $pruneCommand = new class($fake) extends \App\Console\Commands\BlocklistPruneCommand {
            public function __construct(private IpBlocklist $fake)
            {
            }

            protected function blocklist(): IpBlocklist
            {
                return $this->fake;
            }
        };

        $importCommand = new class($fake) extends \App\Console\Commands\BlocklistImportCommand {
            public function __construct(private IpBlocklist $fake)
            {
            }

            protected function blocklist(): IpBlocklist
            {
                return $this->fake;
            }
        };

        $kernel = new Kernel();
    ob_start();
        $pruneExit = $pruneCommand->handle([], [], $kernel);
        $importExit = $importCommand->handle([$tempFile], ['reason' => 'Imported'], $kernel);
    $output = (string) ob_get_clean();

        unlink($tempFile);

        self::assertSame(0, $pruneExit);
        self::assertSame(0, $importExit);
        self::assertSame($tempFile, $fake->importArgs['filePath']);
        self::assertSame('Imported', $fake->importArgs['reason']);
        self::assertStringContainsString('Pruned expired blocklist entries: 2', $output);
        self::assertStringContainsString('Imported blocked IPs: 2', $output);
    }
}
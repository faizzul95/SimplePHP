<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Database\Schema\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * deploy.json is the only record of what has been applied, and two things could
 * destroy it.
 *
 * It was rewritten in place, so an interrupted write — a killed deploy, a full
 * disk, a container stopped mid-migration — truncated it, and the next run then
 * believed nothing had ever been applied and replayed every migration against a
 * populated schema.
 *
 * And nothing guarded the read-modify-write across a whole run. Two deploys
 * starting together saw the same pending list and both applied it; the per-write
 * LOCK_EX made each write atomic, not the sequence.
 */
final class MigrationLedgerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/myth-migrations-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->basePath . 'app/database/migrations', 0775, true);
        mkdir($this->basePath . 'app/database/seeders', 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($this->basePath);
        }

        parent::tearDown();
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->basePath);
    }

    private function ledgerPath(): string
    {
        return $this->basePath . 'app/database/deploy.json';
    }

    private function lockPath(): string
    {
        return $this->ledgerPath() . '.lock';
    }

    // ─── The ledger survives ─────────────────────────────────────────

    public function testAnEmptyProjectHasNothingToMigrate(): void
    {
        $result = $this->runner()->migrate();

        self::assertSame([], $result['migrated']);
        self::assertSame([], $result['errors']);
    }

    /**
     * The write is temp-file-plus-rename, so the ledger on disk is either the
     * old content or the new one — never a fragment.
     */
    public function testTheLedgerIsReplacedAtomically(): void
    {
        $runner = $this->runner();
        $runner->migrate();

        // Nothing to migrate, so nothing is written yet.
        self::assertFileDoesNotExist($this->ledgerPath());

        $save = new \ReflectionMethod(MigrationRunner::class, 'saveDeployData');
        $save->setAccessible(true);
        $save->invoke($runner, [['file' => 'a.php', 'type' => 'migrate', 'batch' => 1, 'status' => 'migrated']]);

        self::assertFileExists($this->ledgerPath());

        $decoded = json_decode((string) file_get_contents($this->ledgerPath()), true);
        self::assertSame('a.php', $decoded[0]['file']);
    }

    /** A replacement write must not leave the temp file behind. */
    public function testNoTemporaryFileSurvivesAWrite(): void
    {
        $runner = $this->runner();
        $save = new \ReflectionMethod(MigrationRunner::class, 'saveDeployData');
        $save->setAccessible(true);

        $save->invoke($runner, [['file' => 'a.php']]);
        $save->invoke($runner, [['file' => 'a.php'], ['file' => 'b.php']]);

        self::assertSame([], glob($this->basePath . 'app/database/deploy.json.*.tmp') ?: []);

        $decoded = json_decode((string) file_get_contents($this->ledgerPath()), true);
        self::assertCount(2, $decoded);
    }

    /** A truncated or corrupt ledger must not throw; the runner starts from empty. */
    public function testACorruptLedgerIsToleratedRatherThanFatal(): void
    {
        file_put_contents($this->ledgerPath(), '{ this is not json');

        $result = $this->runner()->migrate();

        self::assertSame([], $result['errors']);
    }

    // ─── Concurrency ─────────────────────────────────────────────────

    /**
     * The second deploy is told the first is running rather than queueing behind
     * it — waiting would only mean applying the same migrations afterwards.
     */
    public function testASecondRunIsRefusedWhileTheFirstHoldsTheLock(): void
    {
        $handle = fopen($this->lockPath(), 'c+b');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $this->runner()->migrate();
            self::fail('A concurrent migration run should be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Another migration run is in progress', $e->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->lockPath());
        }
    }

    /** @return array<string, array{0:string}> */
    public static function guardedOperationProvider(): array
    {
        return [
            'migrate' => ['migrate'],
            'rollback' => ['rollback'],
            'reset' => ['reset'],
            'seed' => ['seed'],
        ];
    }

    /**
     * Every operation that mutates the ledger has to be behind the lock, not
     * just migrate: a rollback racing a migrate corrupts it just as thoroughly.
     *
     * @param string $operation
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guardedOperationProvider')]
    public function testEveryMutatingOperationTakesTheLock(string $operation): void
    {
        $handle = fopen($this->lockPath(), 'c+b');
        self::assertNotFalse($handle);
        flock($handle, LOCK_EX | LOCK_NB);

        try {
            $this->runner()->{$operation}();
            self::fail($operation . '() should be refused while the ledger is locked.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Another migration run is in progress', $e->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->lockPath());
        }
    }

    /** The lock has to be released afterwards, or the next deploy is refused forever. */
    public function testTheLockIsReleasedWhenTheRunFinishes(): void
    {
        $this->runner()->migrate();

        self::assertFileDoesNotExist($this->lockPath());
        // And a second run is not refused.
        self::assertSame([], $this->runner()->migrate()['errors']);
    }

    /**
     * reset() delegates to the rollback worker, and fresh() to the migrate
     * worker. Calling the public methods there would try to take a lock this
     * process already holds — flock is per handle, so the non-blocking acquire
     * fails and the run reports a conflict against itself.
     */
    public function testResetDoesNotDeadlockAgainstItsOwnLock(): void
    {
        $result = $this->runner()->reset();

        self::assertSame([], $result['errors']);
        self::assertFileDoesNotExist($this->lockPath());
    }

    public function testStatusDoesNotRequireTheLock(): void
    {
        $handle = fopen($this->lockPath(), 'c+b');
        flock($handle, LOCK_EX | LOCK_NB);

        try {
            // Reading is safe while a run is in progress and must not be refused.
            $status = $this->runner()->status();
            self::assertIsArray($status);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->lockPath());
        }
    }
}

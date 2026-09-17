<?php

declare(strict_types=1);

namespace Tests\Unit\Components;

use Components\Backup;
use PHPUnit\Framework\TestCase;

/**
 * The dump writer ignored every fwrite() return.
 *
 * On a full volume fwrite() returns a short count or false, the dump truncates
 * mid-statement, and createDump() returned the path as though it had worked.
 * For a backup that is the worst failure mode there is: you find out when you
 * try to restore.
 */
final class BackupWriteFailureTest extends TestCase
{
    private function writeOrFail(): \ReflectionMethod
    {
        return new \ReflectionMethod(Backup::class, 'writeOrFail');
    }

    private function backup(): Backup
    {
        return (new \ReflectionClass(Backup::class))->newInstanceWithoutConstructor();
    }

    public function testAGoodWriteReturnsQuietly(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);

        $this->writeOrFail()->invoke($this->backup(), $handle, "SELECT 1;\n");

        rewind($handle);
        self::assertSame("SELECT 1;\n", stream_get_contents($handle));

        fclose($handle);
    }

    public function testAnEmptyWriteIsANoOp(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);

        $this->writeOrFail()->invoke($this->backup(), $handle, '');

        rewind($handle);
        self::assertSame('', stream_get_contents($handle));

        fclose($handle);
    }

    /**
     * A read-only handle refuses the write, which is the same observable
     * outcome as a full volume: fewer bytes written than asked for.
     */
    public function testAFailedWriteThrowsRatherThanTruncatingSilently(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'myth-backup-');
        self::assertNotFalse($path);

        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Backup write failed');

            @$this->writeOrFail()->invoke($this->backup(), $handle, "-- a statement that will not land\n");
        } finally {
            fclose($handle);
            @unlink($path);
        }
    }

    /** The message should say what happened, not just that something did. */
    public function testTheFailureNamesTheLikelyCause(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'myth-backup-');
        $handle = fopen((string) $path, 'rb');

        try {
            @$this->writeOrFail()->invoke($this->backup(), $handle, "payload\n");
            self::fail('expected the write to be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString('volume is probably full', $e->getMessage());
            self::assertStringContainsString('bytes', $e->getMessage());
        } finally {
            fclose($handle);
            @unlink((string) $path);
        }
    }
}

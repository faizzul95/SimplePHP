<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Telemetry\Entry;
use Core\Telemetry\FileStore;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/myth-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function store(array $config = []): FileStore
    {
        return new FileStore(array_merge(['path' => $this->directory], $config));
    }

    /** @param array<string, mixed> $payload */
    private function entry(string $type = Entry::TYPE_QUERY, array $payload = [], string $requestId = 'req-1', ?int $userId = null): Entry
    {
        return Entry::make($type, $requestId, $payload, 1.0, [], $userId);
    }

    public function testWritingNothingSucceedsWithoutTouchingDisk(): void
    {
        self::assertTrue($this->store()->write([]));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testAnEntrySurvivesTheRoundTrip(): void
    {
        $this->store()->write([$this->entry(payload: ['sql' => 'select 1'])]);

        $read = $this->store()->read();

        self::assertCount(1, $read);
        self::assertSame('select 1', $read[0]->payload['sql']);
        self::assertSame('req-1', $read[0]->requestId);
    }

    /**
     * The bar shows the latest activity, and the file is read backwards to get
     * it — which is the part most likely to be subtly wrong.
     */
    public function testEntriesComeBackNewestFirst(): void
    {
        $store = $this->store();

        for ($i = 0; $i < 5; $i++) {
            $store->write([$this->entry(payload: ['n' => $i])]);
        }

        $read = $store->read();

        self::assertCount(5, $read);
        self::assertSame(4, $read[0]->payload['n']);
        self::assertSame(0, $read[4]->payload['n']);
    }

    /** More entries than fit in one 8KB read chunk, so the boundary logic runs. */
    public function testReadingBackwardsSpansChunkBoundaries(): void
    {
        $store = $this->store();
        $batch = [];

        for ($i = 0; $i < 400; $i++) {
            $batch[] = $this->entry(payload: ['n' => $i, 'pad' => str_repeat('x', 200)]);
        }
        $store->write($batch);

        $read = $store->read(400);

        self::assertCount(400, $read);
        self::assertSame(399, $read[0]->payload['n'], 'newest first across chunks');
        self::assertSame(0, $read[399]->payload['n'], 'oldest last, nothing lost at a boundary');

        $seen = array_map(static fn(Entry $e): int => $e->payload['n'], $read);
        self::assertCount(400, array_unique($seen), 'no entry duplicated at a boundary');
    }

    public function testTheLimitIsRespected(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 20; $i++) {
            $store->write([$this->entry(payload: ['n' => $i])]);
        }

        self::assertCount(5, $store->read(5));
    }

    // ─── Filters ─────────────────────────────────────────────────────

    public function testFilteringByType(): void
    {
        $store = $this->store();
        $store->write([
            $this->entry(Entry::TYPE_QUERY),
            $this->entry(Entry::TYPE_MAIL),
            $this->entry(Entry::TYPE_QUERY),
        ]);

        self::assertCount(2, $store->read(100, ['types' => [Entry::TYPE_QUERY]]));
        self::assertCount(1, $store->read(100, ['types' => [Entry::TYPE_MAIL]]));
    }

    public function testFilteringByRequestId(): void
    {
        $store = $this->store();
        $store->write([
            $this->entry(requestId: 'req-a'),
            $this->entry(requestId: 'req-b'),
        ]);

        self::assertCount(1, $store->read(100, ['request_id' => 'req-b']));
    }

    /** The per-user gate relies on this, so it is a security boundary. */
    public function testFilteringByUserId(): void
    {
        $store = $this->store();
        $store->write([
            $this->entry(userId: 7),
            $this->entry(userId: 9),
            $this->entry(userId: 7),
        ]);

        $mine = $store->read(100, ['user_id' => 7]);

        self::assertCount(2, $mine);
        foreach ($mine as $entry) {
            self::assertSame(7, $entry->userId);
        }
    }

    public function testFilteringBySearchLooksInThePayload(): void
    {
        $store = $this->store();
        $store->write([
            $this->entry(payload: ['sql' => 'select * from users']),
            $this->entry(payload: ['sql' => 'select * from orders']),
        ]);

        self::assertCount(1, $store->read(100, ['search' => 'orders']));
        self::assertCount(2, $store->read(100, ['search' => 'SELECT']), 'search is case-insensitive');
    }

    public function testFilteringByAfterReturnsOnlyNewerEntries(): void
    {
        $store = $this->store();
        $store->write([$this->entry(payload: ['n' => 1])]);

        $cutoff = $store->read()[0]->recordedAt;

        usleep(2000);
        $store->write([$this->entry(payload: ['n' => 2])]);

        $newer = $store->read(100, ['after' => $cutoff]);

        self::assertCount(1, $newer);
        self::assertSame(2, $newer[0]->payload['n']);
    }

    // ─── Bounds and housekeeping ─────────────────────────────────────

    /** A debug tool must not be able to fill the disk. */
    public function testWritingStopsOnceTheDailyCapIsReached(): void
    {
        $store = $this->store(['max_bytes_per_day' => 500]);

        $store->write([$this->entry(payload: ['pad' => str_repeat('x', 2000)])]);

        self::assertFalse($store->write([$this->entry(payload: ['n' => 'dropped'])]));
        self::assertCount(1, $store->read());
    }

    public function testPurgeRemovesEverything(): void
    {
        $store = $this->store();
        $store->write([$this->entry()]);

        self::assertSame(1, $store->purge());
        self::assertSame([], $store->read());
    }

    public function testPruneKeepsFilesInsideTheRetentionWindow(): void
    {
        $store = $this->store(['retention_days' => 2]);
        $store->write([$this->entry()]);

        $old = $this->directory . '/telemetry-' . date('Y-m-d', strtotime('-10 days')) . '.jsonl';
        file_put_contents($old, "{}\n");

        self::assertSame(1, $store->prune());
        self::assertFileDoesNotExist($old);
        self::assertCount(1, $store->read(), "today's file is untouched");
    }

    public function testReadingAMissingDirectoryReturnsNothing(): void
    {
        self::assertSame([], $this->store()->read());
    }

    /** A half-written line must cost that line, not the file. */
    public function testACorruptLineIsSkipped(): void
    {
        $store = $this->store();
        $store->write([$this->entry(payload: ['n' => 1])]);

        file_put_contents(
            $this->directory . '/telemetry-' . date('Y-m-d') . '.jsonl',
            "{not json at all\n",
            FILE_APPEND
        );

        $store->write([$this->entry(payload: ['n' => 2])]);

        $read = $store->read();

        self::assertCount(2, $read);
        self::assertSame(2, $read[0]->payload['n']);
    }
}

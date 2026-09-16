<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Telemetry\Entry;
use Core\Telemetry\FileStore;
use Core\Telemetry\Gate;
use Core\Telemetry\Recorder;
use PHPUnit\Framework\TestCase;

final class RecorderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/myth-telemetry-' . bin2hex(random_bytes(6));
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
    private function recorder(array $config = []): Recorder
    {
        return new Recorder(array_merge([
            'enabled' => true,
            'mode' => Gate::MODE_ALL,
            'storage' => ['path' => $this->directory],
        ], $config));
    }

    // ─── Gating ──────────────────────────────────────────────────────

    public function testNothingIsRecordedWhenTheGateSaysNo(): void
    {
        $recorder = $this->recorder(['enabled' => false]);
        $recorder->record(Entry::TYPE_QUERY, ['sql' => 'select 1']);

        self::assertSame([], $recorder->entries());
    }

    public function testIdentifyingTheUserCanTurnRecordingOn(): void
    {
        $recorder = $this->recorder(['mode' => Gate::MODE_USERS, 'user_ids' => [42]]);

        $recorder->record(Entry::TYPE_QUERY, ['sql' => 'before login']);
        self::assertCount(0, $recorder->entries());

        $recorder->identify(42);
        $recorder->record(Entry::TYPE_QUERY, ['sql' => 'after login']);

        self::assertCount(1, $recorder->entries());
        self::assertSame(42, $recorder->entries()[0]->userId);
    }

    public function testOnlyConfiguredTypesAreRecorded(): void
    {
        $recorder = $this->recorder(['types' => [Entry::TYPE_QUERY]]);

        $recorder->record(Entry::TYPE_QUERY, ['sql' => 'kept']);
        $recorder->record(Entry::TYPE_MAIL, ['subject' => 'dropped']);

        self::assertCount(1, $recorder->entries());
        self::assertSame(Entry::TYPE_QUERY, $recorder->entries()[0]->type);
    }

    // ─── Bounds ──────────────────────────────────────────────────────

    /** A request issuing thousands of queries must cost a fixed amount. */
    public function testEntriesAreCappedAndTheOverflowIsCounted(): void
    {
        $recorder = $this->recorder(['max_entries_per_request' => 5]);

        for ($i = 0; $i < 50; $i++) {
            $recorder->record(Entry::TYPE_QUERY, ['sql' => 'select ' . $i]);
        }

        self::assertCount(5, $recorder->entries());
        self::assertSame(45, $recorder->droppedCount());
    }

    public function testLongValuesAreTruncated(): void
    {
        $recorder = $this->recorder(['max_value_length' => 100]);
        $recorder->record(Entry::TYPE_QUERY, ['sql' => str_repeat('x', 5000)]);

        $sql = $recorder->entries()[0]->payload['sql'];

        self::assertLessThan(200, strlen($sql));
        self::assertStringContainsString('more bytes', $sql);
    }

    public function testDeeplyNestedPayloadsStopRatherThanRecurseForever(): void
    {
        $deep = ['level' => 0];
        $cursor = &$deep;
        for ($i = 1; $i < 40; $i++) {
            $cursor['child'] = ['level' => $i];
            $cursor = &$cursor['child'];
        }
        unset($cursor);

        $recorder = $this->recorder();
        $recorder->record(Entry::TYPE_LOG, $deep);

        self::assertCount(1, $recorder->entries());
        self::assertIsArray($recorder->entries()[0]->payload);
    }

    public function testAnObjectInThePayloadBecomesALabelRatherThanBreakingJson(): void
    {
        $recorder = $this->recorder();
        $recorder->record(Entry::TYPE_LOG, ['obj' => new \stdClass()]);

        self::assertSame('[object stdClass]', $recorder->entries()[0]->payload['obj']);
        self::assertIsString(json_encode($recorder->entries()[0]->toArray()));
    }

    // ─── Redaction ───────────────────────────────────────────────────

    /** A recorded login body contains the password unless something removes it. */
    public function testSecretsAreRedacted(): void
    {
        $recorder = $this->recorder();
        $recorder->record(Entry::TYPE_REQUEST, [
            'input' => [
                'email' => 'user@example.test',
                'password' => 'hunter2',
                'password_confirmation' => 'hunter2',
                'api_key' => 'sk-live-123',
                'csrf_token' => 'abc',
            ],
        ]);

        $input = $recorder->entries()[0]->payload['input'];

        self::assertSame('user@example.test', $input['email']);
        self::assertSame('[redacted]', $input['password']);
        self::assertSame('[redacted]', $input['password_confirmation']);
        self::assertSame('[redacted]', $input['api_key']);
        self::assertSame('[redacted]', $input['csrf_token']);
    }

    public function testRedactionMatchesRegardlessOfCase(): void
    {
        $recorder = $this->recorder();
        $recorder->record(Entry::TYPE_REQUEST, ['Authorization' => 'Bearer x', 'X-API-KEY' => 'y']);

        $payload = $recorder->entries()[0]->payload;

        self::assertSame('[redacted]', $payload['Authorization']);
        self::assertSame('[redacted]', $payload['X-API-KEY']);
    }

    // ─── Typed recorders ─────────────────────────────────────────────

    public function testASlowQueryIsTagged(): void
    {
        $recorder = $this->recorder(['slow_query_ms' => 50]);

        $recorder->recordQuery('select 1', [], 10.0);
        $recorder->recordQuery('select sleep(1)', [], 900.0);

        self::assertSame([], $recorder->entries()[0]->tags);
        self::assertSame(['slow'], $recorder->entries()[1]->tags);
    }

    public function testResponseStatusBecomesATag(): void
    {
        $recorder = $this->recorder();

        $recorder->recordRequest(['status' => 500], 12.0);
        $recorder->recordRequest(['status' => 404], 8.0);
        $recorder->recordRequest(['status' => 200, 'ajax' => true], 5.0);

        self::assertContains('server-error', $recorder->entries()[0]->tags);
        self::assertContains('client-error', $recorder->entries()[1]->tags);
        self::assertContains('ajax', $recorder->entries()[2]->tags);
    }

    public function testAnExceptionIsRecordedWithABoundedTrace(): void
    {
        $recorder = $this->recorder(['max_trace_frames' => 3]);
        $recorder->recordException(new \RuntimeException('boom'));

        $payload = $recorder->entries()[0]->payload;

        self::assertSame(\RuntimeException::class, $payload['class']);
        self::assertSame('boom', $payload['message']);
        self::assertLessThanOrEqual(3, count($payload['trace']));
    }

    // ─── Flush ───────────────────────────────────────────────────────

    public function testFlushWritesAndClears(): void
    {
        $recorder = $this->recorder();
        $recorder->recordQuery('select 1', [], 1.0);

        self::assertTrue($recorder->flush());
        self::assertSame([], $recorder->entries());

        $stored = (new FileStore(['path' => $this->directory]))->read();

        self::assertCount(1, $stored);
        self::assertSame('select 1', $stored[0]->payload['sql']);
    }

    public function testFlushingTwiceDoesNotDuplicate(): void
    {
        $recorder = $this->recorder();
        $recorder->recordQuery('select 1', [], 1.0);
        $recorder->flush();
        $recorder->flush();

        self::assertCount(1, (new FileStore(['path' => $this->directory]))->read());
    }

    public function testDiscardThrowsTheBufferAway(): void
    {
        $recorder = $this->recorder();
        $recorder->recordQuery('select 1', [], 1.0);
        $recorder->discard();
        $recorder->flush();

        self::assertSame([], (new FileStore(['path' => $this->directory]))->read());
    }

    /** Every entry from one request shares an id, so the bar can group them. */
    public function testEntriesShareTheRequestId(): void
    {
        $recorder = $this->recorder();
        $recorder->recordQuery('select 1', [], 1.0);
        $recorder->recordMail(['subject' => 'Hi']);

        $ids = array_map(static fn($e) => $e->requestId, $recorder->entries());

        self::assertCount(1, array_unique($ids));
        self::assertNotSame('', $ids[0]);
    }

    /** Telemetry must never be the reason a request fails. */
    public function testRecordingToAnUnwritableStoreDoesNotThrow(): void
    {
        $recorder = new Recorder([
            'enabled' => true,
            'mode' => Gate::MODE_ALL,
            'storage' => ['path' => "\0invalid"],
        ]);

        $recorder->recordQuery('select 1', [], 1.0);

        self::assertFalse($recorder->flush());
    }
}

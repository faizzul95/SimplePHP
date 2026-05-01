<?php

declare(strict_types=1);

use Core\Queue\Job;
use PHPUnit\Framework\TestCase;

final class QueueJobPayloadTest extends TestCase
{
    public function testFromPayloadRejectsMissingSerializedData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid job payload data.');

        QueueJobPayloadDummy::fromPayload([
            'class' => QueueJobPayloadDummy::class,
        ]);
    }

    public function testFromPayloadRestoresExpectedSubclass(): void
    {
        $job = new QueueJobPayloadDummy('payload-ok');

        $restored = QueueJobPayloadDummy::fromPayload($job->toPayload());

        self::assertInstanceOf(QueueJobPayloadDummy::class, $restored);
        self::assertSame('payload-ok', $restored->value);
    }
}

final class QueueJobPayloadDummy extends Job
{
    public function __construct(public string $value)
    {
    }

    public function handle(): void
    {
    }
}
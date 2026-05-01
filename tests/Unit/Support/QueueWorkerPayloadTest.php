<?php

declare(strict_types=1);

use Core\Queue\Job;
use Core\Queue\Worker;
use PHPUnit\Framework\TestCase;

final class QueueWorkerPayloadTest extends TestCase
{
    public function testWorkerDecodePayloadRejectsMissingPayloadString(): void
    {
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($worker, 'decodePayload');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid queue payload.');

        $method->invoke($worker, ['attempts' => 1]);
    }

    public function testWorkerDecodePayloadRejectsMalformedJson(): void
    {
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($worker, 'decodePayload');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid queue payload.');

        $method->invoke($worker, ['payload' => '{bad-json}', 'attempts' => 1]);
    }

    public function testWorkerDecodePayloadReturnsStructuredPayload(): void
    {
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($worker, 'decodePayload');
        $job = new QueueWorkerPayloadDummy('queued');

        $payload = $method->invoke($worker, [
            'payload' => json_encode($job->toPayload(), JSON_UNESCAPED_UNICODE),
            'attempts' => 1,
        ]);

        self::assertIsArray($payload);
        self::assertSame(QueueWorkerPayloadDummy::class, $payload['class']);
        self::assertIsString($payload['data']);
    }
}

final class QueueWorkerPayloadDummy extends Job
{
    public function __construct(public string $value)
    {
    }

    public function handle(): void
    {
    }
}
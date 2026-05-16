<?php

declare(strict_types=1);

use Core\Queue\Job;
use PHPUnit\Framework\TestCase;

final class QueuePriorityTest extends TestCase
{
    public function testPriorityFluentHelpersSetExpectedLevels(): void
    {
        $job = new QueuePriorityDummyJob();

        self::assertSame(Job::PRIORITY_CRITICAL, $job->critical()->priority);
        self::assertSame(Job::PRIORITY_HIGH, $job->high()->priority);
        self::assertSame(Job::PRIORITY_NORMAL, $job->normal()->priority);
        self::assertSame(Job::PRIORITY_LOW, $job->low()->priority);
        self::assertSame(Job::PRIORITY_BULK, $job->bulk()->priority);
    }

    public function testPriorityIsIncludedInPayloadAndNormalized(): void
    {
        $job = (new QueuePriorityDummyJob())->priority(99);

        $payload = $job->toPayload();

        self::assertSame(Job::PRIORITY_BULK, $payload['priority']);
        self::assertSame(Job::PRIORITY_BULK, QueuePriorityDummyJob::fromPayload($payload)->priority);
    }
}

final class QueuePriorityDummyJob extends Job
{
    public function handle(): void
    {
    }
}
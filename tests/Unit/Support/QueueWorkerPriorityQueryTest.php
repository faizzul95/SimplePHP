<?php

declare(strict_types=1);

use Core\Queue\Worker;
use PHPUnit\Framework\TestCase;

final class QueueWorkerPriorityQueryTest extends TestCase
{
    public function testBuildPopQueryOrdersByPriorityBeforeAvailability(): void
    {
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($worker, 'buildPopQuery');

        $result = $method->invoke($worker, 'system_jobs', 'default', '2026-05-16 10:00:00', '2026-05-16 10:01:00', null);

        self::assertStringContainsString('ORDER BY `priority` ASC, `available_at` ASC, `created_at` ASC', $result['sql']);
        self::assertSame(['default', '2026-05-16 10:00:00', '2026-05-16 10:01:00'], $result['bindings']);
    }

    public function testBuildPopQueryCanRestrictToMaximumPriority(): void
    {
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($worker, 'buildPopQuery');

        $result = $method->invoke($worker, 'system_jobs', 'emails', '2026-05-16 10:00:00', '2026-05-16 10:01:00', 2);

        self::assertStringContainsString('AND `priority` <= ?', $result['sql']);
        self::assertSame(['emails', '2026-05-16 10:00:00', '2026-05-16 10:01:00', 2], $result['bindings']);
    }
}
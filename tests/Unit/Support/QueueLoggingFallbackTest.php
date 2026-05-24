<?php

declare(strict_types=1);

use Core\Queue\Dispatcher;
use Core\Queue\Worker;
use PHPUnit\Framework\TestCase;

final class QueueLoggingDispatcherProbe extends Dispatcher
{
    public function __construct()
    {
    }
}

final class QueueLoggingWorkerProbe extends Worker
{
    public function __construct()
    {
    }
}

final class QueueLoggingFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices();
        reset_framework_service('logger');
    }

    public function testDispatcherLogFallbackDoesNotRequireRegisteredLoggerService(): void
    {
        $probe = new QueueLoggingDispatcherProbe();
        $method = new ReflectionMethod(Dispatcher::class, 'logQueueError');
        $method->setAccessible(true);

        $method->invoke($probe, 'dispatcher fallback test');

        self::assertTrue(true);
    }

    public function testWorkerLogFallbackDoesNotRequireRegisteredLoggerService(): void
    {
        $probe = new QueueLoggingWorkerProbe();
        $method = new ReflectionMethod(Worker::class, 'logQueueError');
        $method->setAccessible(true);

        $method->invoke($probe, 'worker fallback test');

        self::assertTrue(true);
    }
}
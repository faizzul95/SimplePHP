<?php

declare(strict_types=1);

use App\Support\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();
        reset_event_dispatcher();
    }

    public function testDispatcherInvokesRegisteredListenersInOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $observed = [];

        $dispatcher->listen('request.captured', function (array $payload) use (&$observed) {
            $observed[] = 'first:' . $payload['request_id'];
            return 'first';
        });

        $dispatcher->listen('request.captured', function (array $payload) use (&$observed) {
            $observed[] = 'second:' . $payload['request_id'];
            return 'second';
        });

        $responses = $dispatcher->dispatch('request.captured', ['request_id' => 'req-123']);

        self::assertSame(['first:req-123', 'second:req-123'], $observed);
        self::assertSame(['first', 'second'], $responses);
    }

    public function testEventHelperAndProviderShareSameDispatcherInstance(): void
    {
        bootstrapTestFrameworkServices();

        self::assertSame(event_dispatcher(), framework_service('events'));

        $observed = [];
        on_event('providers.booted', function () use (&$observed) {
            $observed[] = 'booted';
        });

        dispatch_event('providers.booted');

        self::assertSame(['booted'], $observed);
    }
}
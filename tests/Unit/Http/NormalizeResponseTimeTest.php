<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\NormalizeResponseTime;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class NormalizeResponseTimeTest extends TestCase
{
    public function testMiddlewareSleepsForRemainingBudget(): void
    {
        $middleware = new class () extends NormalizeResponseTime {
            private array $times = [0, 50_000_000];
            public int $sleptMicros = 0;

            protected function now(): int
            {
                return array_shift($this->times) ?? 50_000_000;
            }

            protected function sleepMicros(int $micros): void
            {
                $this->sleptMicros = $micros;
            }
        };

        $middleware->setParameters(['200']);
        $request = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/auth/login'], []);

        $result = $middleware->handle($request, static fn(Request $request): array => ['code' => 400]);

        self::assertSame(['code' => 400], $result);
        self::assertSame(150000, $middleware->sleptMicros);
    }

    public function testMiddlewareDoesNotSleepWhenBudgetAlreadyConsumed(): void
    {
        $middleware = new class () extends NormalizeResponseTime {
            private array $times = [0, 250_000_000];
            public int $sleptMicros = 0;

            protected function now(): int
            {
                return array_shift($this->times) ?? 250_000_000;
            }

            protected function sleepMicros(int $micros): void
            {
                $this->sleptMicros = $micros;
            }
        };

        $middleware->setParameters(['200']);
        $request = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/auth/login'], []);
        $middleware->handle($request, static fn(Request $request): string => 'ok');

        self::assertSame(0, $middleware->sleptMicros);
    }
}
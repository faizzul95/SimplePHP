<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\Drivers\MySQLDriver;
use PHPUnit\Framework\TestCase;

final class OrderByAllowedBuilderStub extends MySQLDriver
{
    public function getOrderByClauses(): array
    {
        return $this->orderBy ?? [];
    }
}

/**
 * Tests for HasAggregates::orderByAllowed() (SEC-15)
 *
 * Uses a minimal in-memory stub — no real DB connection needed.
 */
class OrderByAllowedTest extends TestCase
{
    protected function setUp(): void
    {
        // We need a concrete DB class that uses HasAggregates.
        // Skip if the class can't be bootstrapped without a DB connection.
        if (!class_exists(\Core\Database\Concerns\HasAggregates::class)) {
            $this->markTestSkipped('HasAggregates trait not available without full bootstrap.');
        }
    }

    public function test_order_by_allowed_accepts_valid_column(): void
    {
        $builder = new OrderByAllowedBuilderStub();

        $result = $builder->orderByAllowed('created_at', 'DESC', ['created_at', 'name', 'id']);

        $this->assertSame($builder, $result); // Returns $this for chaining
        $this->assertNotEmpty($builder->getOrderByClauses());
    }

    public function test_order_by_allowed_throws_for_unlisted_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not in the sort allowlist/');

        $builder = new OrderByAllowedBuilderStub();

        $builder->orderByAllowed('password', 'ASC', ['created_at', 'name', 'id']);
    }

    public function test_order_by_allowed_throws_for_sql_injection_attempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $builder = new OrderByAllowedBuilderStub();

        $builder->orderByAllowed("1; DROP TABLE users--", 'ASC', ['created_at']);
    }

    public function test_order_by_direction_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ASC.*DESC|direction/i');

        $builder = new OrderByAllowedBuilderStub();

        $builder->orderByAllowed('created_at', 'INVALID', ['created_at']);
    }
}

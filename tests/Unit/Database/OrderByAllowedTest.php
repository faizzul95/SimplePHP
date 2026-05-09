<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Tests for HasAggregates::orderByAllowed() (SEC-15)
 *
 * Uses a minimal in-memory stub — no real DB connection needed.
 */
class OrderByAllowedTest extends TestCase
{
    private object $builder;

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
        // Create an anonymous class that uses the trait
        $builder = new class {
            use \Core\Database\Concerns\HasAggregates;
            public array $orderBy = [];
            protected function _sanitizeColumnName(string $col): string { return $col; }
        };

        $result = $builder->orderByAllowed('created_at', 'DESC', ['created_at', 'name', 'id']);

        $this->assertSame($builder, $result); // Returns $this for chaining
        $this->assertNotEmpty($builder->orderBy);
    }

    public function test_order_by_allowed_throws_for_unlisted_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not in the sort allowlist/');

        $builder = new class {
            use \Core\Database\Concerns\HasAggregates;
            public array $orderBy = [];
            protected function _sanitizeColumnName(string $col): string { return $col; }
        };

        $builder->orderByAllowed('password', 'ASC', ['created_at', 'name', 'id']);
    }

    public function test_order_by_allowed_throws_for_sql_injection_attempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $builder = new class {
            use \Core\Database\Concerns\HasAggregates;
            public array $orderBy = [];
            protected function _sanitizeColumnName(string $col): string { return $col; }
        };

        $builder->orderByAllowed("1; DROP TABLE users--", 'ASC', ['created_at']);
    }

    public function test_order_by_direction_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ASC.*DESC|direction/i');

        $builder = new class {
            use \Core\Database\Concerns\HasAggregates;
            public array $orderBy = [];
            protected function _sanitizeColumnName(string $col): string { return $col; }
        };

        $builder->orderByAllowed('created_at', 'INVALID', ['created_at']);
    }
}

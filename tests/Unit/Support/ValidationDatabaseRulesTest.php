<?php

declare(strict_types=1);

use Components\Validation;
use PHPUnit\Framework\TestCase;

require_once ROOT_DIR . 'systems/app.php';

/**
 * Records the where()/whereIn() chain and answers exists()/count() from a
 * fixed in-memory table, so the rules can be exercised without MySQL.
 */
final class ValidationRuleBuilderStub
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    public ?string $table = null;

    /** @var list<array{0:string,1:string,2:mixed}> */
    public array $conditions = [];

    /** @var list<array{0:string,1:array<int,mixed>}> */
    public array $inConditions = [];

    public int $existsCalls = 0;

    public function table(string $table): self
    {
        $this->table = $table;
        $this->conditions = [];
        $this->inConditions = [];

        return $this;
    }

    public function where($column, $operator = null, $value = null): self
    {
        if ($value === null && $operator !== null) {
            [$operator, $value] = ['=', $operator];
        }

        $this->conditions[] = [(string) $column, (string) $operator, $value];

        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->inConditions[] = [$column, array_values($values)];

        return $this;
    }

    public function exists(): bool
    {
        $this->existsCalls++;

        return $this->matches() !== [];
    }

    public function count(): int
    {
        return count($this->matches());
    }

    /** @return list<array<string,mixed>> */
    private function matches(): array
    {
        $matched = array_filter($this->rows, function (array $row): bool {
            foreach ($this->conditions as [$column, $operator, $value]) {
                $actual = $row[$column] ?? null;

                $ok = $operator === '!='
                    ? (string) $actual !== (string) $value
                    : (string) $actual === (string) $value;

                if (!$ok) {
                    return false;
                }
            }

            foreach ($this->inConditions as [$column, $values]) {
                $needle = array_map('strval', $values);
                if (!in_array((string) ($row[$column] ?? null), $needle, true)) {
                    return false;
                }
            }

            return true;
        });

        return array_values($matched);
    }
}

final class ValidationRuleRuntimeStub
{
    public function __construct(public ValidationRuleBuilderStub $builder)
    {
    }

    public function connection(string $connectionName = 'default'): ValidationRuleBuilderStub
    {
        return $this->builder;
    }
}

/**
 * Validation shipped 70 rules but no `unique:` or `exists:`, which is why
 * UserController hand-rolled a SELECT-then-INSERT uniqueness check and migration
 * 20260824_017 had to add the index that closes the resulting race.
 */
final class ValidationDatabaseRulesTest extends TestCase
{
    private ValidationRuleBuilderStub $builder;

    protected function setUp(): void
    {
        parent::setUp();

        reset_framework_service();

        $this->builder = new ValidationRuleBuilderStub();
        $this->builder->rows = [
            ['id' => 1, 'email' => 'taken@example.com', 'username' => 'alice', 'tenant_id' => 7, 'deleted_at' => null],
            ['id' => 2, 'email' => 'gone@example.com',  'username' => 'bob',   'tenant_id' => 7, 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'email' => 'other@example.com', 'username' => 'alice', 'tenant_id' => 9, 'deleted_at' => null],
        ];

        register_framework_service('database.runtime', fn() => new ValidationRuleRuntimeStub($this->builder));
    }

    protected function tearDown(): void
    {
        reset_framework_service();

        parent::tearDown();
    }

    private function validate(array $data, array $rules): Validation
    {
        $validator = new Validation($data, $rules);
        $validator->validate();

        return $validator;
    }

    public function testUniqueFailsWhenTheValueIsTaken(): void
    {
        $validator = $this->validate(
            ['email' => 'taken@example.com'],
            ['email' => 'required|unique:users,email']
        );

        self::assertFalse($validator->passed());
        self::assertSame('users', $this->builder->table);
    }

    public function testUniquePassesWhenTheValueIsFree(): void
    {
        $validator = $this->validate(
            ['email' => 'fresh@example.com'],
            ['email' => 'required|unique:users,email']
        );

        self::assertTrue($validator->passed(), json_encode($validator->getErrors()));
    }

    public function testUniqueDefaultsTheColumnToTheFieldName(): void
    {
        $validator = $this->validate(
            ['email' => 'taken@example.com'],
            ['email' => 'unique:users']
        );

        self::assertFalse($validator->passed());
        self::assertSame([['email', '=', 'taken@example.com']], $this->builder->conditions);
    }

    public function testUniqueIgnoresTheGivenIdOnUpdate(): void
    {
        $validator = $this->validate(
            ['email' => 'taken@example.com'],
            ['email' => 'unique:users,email,1']
        );

        self::assertTrue($validator->passed(), json_encode($validator->getErrors()));
        self::assertContains(['id', '!=', '1'], $this->builder->conditions);
    }

    public function testUniqueIgnoresByACustomColumn(): void
    {
        $validator = $this->validate(
            ['email' => 'taken@example.com'],
            ['email' => 'unique:users,email,alice,username']
        );

        self::assertTrue($validator->passed(), json_encode($validator->getErrors()));
        self::assertContains(['username', '!=', 'alice'], $this->builder->conditions);
    }

    /**
     * A plain UNIQUE index does not exclude soft-deleted rows, so neither can the
     * rule — otherwise validation passes and the INSERT dies on duplicate key.
     */
    public function testUniqueCountsSoftDeletedRows(): void
    {
        $validator = $this->validate(
            ['email' => 'gone@example.com'],
            ['email' => 'unique:users,email']
        );

        self::assertFalse($validator->passed());
    }

    public function testUniqueSkipsEmptyValuesWithoutQuerying(): void
    {
        $validator = $this->validate(
            ['email' => ''],
            ['email' => 'nullable|unique:users,email']
        );

        self::assertTrue($validator->passed());
        self::assertSame(0, $this->builder->existsCalls);
    }

    public function testUniqueWithChecksACombinationOfColumns(): void
    {
        $taken = $this->validate(
            ['username' => 'alice', 'tenant_id' => 7],
            ['username' => 'unique_with:users,username,tenant_id']
        );

        self::assertFalse($taken->passed());

        $free = $this->validate(
            ['username' => 'alice', 'tenant_id' => 11],
            ['username' => 'unique_with:users,username,tenant_id']
        );

        self::assertTrue($free->passed(), json_encode($free->getErrors()));
    }

    public function testUniqueWithHonoursTheIgnoreToken(): void
    {
        $validator = $this->validate(
            ['username' => 'alice', 'tenant_id' => 7],
            ['username' => 'unique_with:users,username,tenant_id,ignore:1']
        );

        self::assertTrue($validator->passed(), json_encode($validator->getErrors()));
    }

    public function testExistsPassesForAKnownRow(): void
    {
        $validator = $this->validate(
            ['id' => 3],
            ['id' => 'exists:users,id']
        );

        self::assertTrue($validator->passed(), json_encode($validator->getErrors()));
    }

    public function testExistsFailsForAnUnknownRow(): void
    {
        $validator = $this->validate(
            ['id' => 999],
            ['id' => 'exists:users,id']
        );

        self::assertFalse($validator->passed());
    }

    public function testExistsRequiresEveryValueOfAnArray(): void
    {
        $all = $this->validate(
            ['id' => [1, 3]],
            ['id' => 'exists:users,id']
        );

        self::assertTrue($all->passed(), json_encode($all->getErrors()));

        $partial = $this->validate(
            ['id' => [1, 999]],
            ['id' => 'exists:users,id']
        );

        self::assertFalse($partial->passed());
    }

    public function testDatabaseFailureFailsClosed(): void
    {
        register_framework_service('database.runtime', function (): object {
            return new class {
                public function connection(string $connectionName = 'default'): object
                {
                    throw new RuntimeException('connection refused');
                }
            };
        });

        $validator = $this->validate(
            ['email' => 'fresh@example.com'],
            ['email' => 'unique:users,email']
        );

        self::assertFalse($validator->passed(), 'A broken lookup must not silently allow a duplicate.');
    }
}

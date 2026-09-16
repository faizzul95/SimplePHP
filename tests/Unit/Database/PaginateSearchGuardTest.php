<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Core\Database\BaseDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * paginate() used to fall back to DESCRIBE and search every column with a
 * leading-wildcard LIKE, which cannot use an index — two full scans per keystroke
 * on a large table. It also passed the raw search term through, so a user typing
 * "%" matched every row.
 */
final class PaginateSearchGuardTest extends TestCase
{
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            /** @var list<array{string,string,mixed}> */
            public array $conditions = [];
            public bool $describeCalled = false;

            public function getTableColumns($table = null)
            {
                $this->describeCalled = true;

                return ['id', 'name', 'email', 'notes', 'created_at'];
            }

            public function where($columnName, $operator = null, $value = null)
            {
                if (is_callable($columnName)) {
                    $columnName($this);

                    return $this;
                }

                $this->conditions[] = ['where', (string) $columnName, $value];

                return $this;
            }

            public function orWhere($columnName, $operator = null, $value = null)
            {
                $this->conditions[] = ['orWhere', (string) $columnName, $value];

                return $this;
            }
        };
    }

    /** Drive the extracted filter step directly; paginate() itself needs a connection. */
    private function search($db, string $term): void
    {
        $filterValue = new \ReflectionProperty(BaseDatabase::class, '_paginateFilterValue');
        $filterValue->setValue($db, $term);

        (new ReflectionMethod(BaseDatabase::class, 'applyPaginateSearchFilter'))->invoke($db);
    }

    private function escapeLikeWildcards(string $value): string
    {
        $method = new ReflectionMethod(BaseDatabase::class, 'escapeLikeWildcards');

        return (string) $method->invoke($this->builder(), $value);
    }

    public function testSearchingWithoutDeclaredColumnsThrows(): void
    {
        $db = $this->builder();
        $db->setPaginateFilterColumn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/setPaginateFilterColumn/');

        $this->search($db, 'alice');
    }

    public function testTheErrorNamesTheFixRatherThanFailingSilently(): void
    {
        try {
            $this->search($this->builder(), 'alice');
            self::fail('Expected a RuntimeException when no filter columns are declared.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('setPaginateFilterColumn', $e->getMessage());
            self::assertStringContainsString('index', $e->getMessage());
        }
    }

    public function testNoDescribeIsIssuedForTheSearchPath(): void
    {
        $db = $this->builder();

        try {
            $this->search($db, 'alice');
        } catch (RuntimeException) {
            // expected: no columns declared
        }

        self::assertFalse(
            $db->describeCalled,
            'paginate still calls DESCRIBE to derive search columns, which is the round-trip '
            . 'and the full scan this change removes.'
        );
    }

    public function testAnEmptySearchNeedsNoDeclaredColumns(): void
    {
        $db = $this->builder();
        $this->search($db, '');

        self::assertSame([], $db->conditions);
        self::assertFalse($db->describeCalled);
    }

    /** @return list<array{0:string,1:string}> */
    public static function wildcardProvider(): array
    {
        return [
            ['50%',        '50\%'],
            ['a_b',        'a\_b'],
            ['%',          '\%'],
            ['100%_done',  '100\%\_done'],
            ['C:\\path',   'C:\\\\path'],
            ['plain text', 'plain text'],
        ];
    }

    #[DataProvider('wildcardProvider')]
    public function testLikeWildcardsInTheSearchTermAreEscaped(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->escapeLikeWildcards($raw));
    }

    public function testABareWildcardNoLongerMatchesEverything(): void
    {
        self::assertSame(
            '%\%%',
            '%' . $this->escapeLikeWildcards('%') . '%',
            'A search for "%" must look for a literal percent sign.'
        );
    }

    public function testDeclaredColumnsBuildOneOrGroup(): void
    {
        $db = $this->builder();
        $db->setPaginateFilterColumn(['name', 'email']);
        $this->search($db, 'alice');

        self::assertSame(
            [['where', 'name', '%alice%'], ['orWhere', 'email', '%alice%']],
            $db->conditions
        );
    }

    public function testDeclaredColumnsAreTrimmedAndBlanksDropped(): void
    {
        $db = $this->builder();
        $db->setPaginateFilterColumn(['  name  ', '', '  ', 'email']);
        $this->search($db, 'bob');

        self::assertSame(
            [['where', 'name', '%bob%'], ['orWhere', 'email', '%bob%']],
            $db->conditions
        );
    }

    public function testTheSearchTermIsEscapedBeforeBinding(): void
    {
        $db = $this->builder();
        $db->setPaginateFilterColumn(['name']);
        $this->search($db, '100%');

        self::assertSame([['where', 'name', '%100\%%']], $db->conditions);
    }
}

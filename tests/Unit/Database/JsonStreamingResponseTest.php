<?php

declare(strict_types=1);

use Core\Database\BaseDatabase;
use Core\Http\StreamedResponse;
use PHPUnit\Framework\TestCase;

final class JsonStreamingResponseProbe extends BaseDatabase
{
    public int $cursorChunkSize = 0;
    public array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->table = 'users';
        $this->column = '*';
    }

    public function connect($connectionID = null) { return $this; }
    public function whereDate($column, $operator = null, $value = null) { return $this; }
    public function orWhereDate($column, $operator = null, $value = null) { return $this; }
    public function whereDay($column, $operator = null, $value = null) { return $this; }
    public function orWhereDay($column, $operator = null, $value = null) { return $this; }
    public function whereMonth($column, $operator = null, $value = null) { return $this; }
    public function orWhereMonth($column, $operator = null, $value = null) { return $this; }
    public function whereYear($column, $operator = null, $value = null) { return $this; }
    public function orWhereYear($column, $operator = null, $value = null) { return $this; }
    public function whereTime($column, $operator = null, $value = null) { return $this; }
    public function orWhereTime($column, $operator = null, $value = null) { return $this; }
    public function whereJsonContains($columnName, $jsonPath, $value) { return $this; }
    public function limit($limit) { return $this; }
    public function offset($offset) { return $this; }
    public function count($table = null) { return count($this->rows); }
    public function exists($table = null) { return !empty($this->rows); }
    public function _getLimitOffsetPaginate($query, $limit, $offset) { return $query; }
    public function batchInsert($data) { return []; }
    public function batchUpdate($data) { return []; }
    public function upsert($values, $uniqueBy = 'id', $updateColumns = null) { return []; }
    protected function sanitizeColumn($data): array { return is_array($data) ? $data : []; }

    public function cursor($chunkSize = 1000): Generator
    {
        $this->cursorChunkSize = (int) $chunkSize;

        foreach ($this->rows as $row) {
            yield $row;
        }
    }
}

final class JsonStreamingResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'framework' => [
                'view_path' => 'tests/Fixtures/views',
                'view_cache_path' => 'storage/cache/views-test',
                'maintenance' => [],
            ],
        ]);
    }

    public function testStreamJsonResponseUsesRequestedChunkSizeAndReturnsStreamedResponse(): void
    {
        $probe = new JsonStreamingResponseProbe([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        $streamed = $probe->streamJsonResponse(250, ['X-Source' => 'db']);
        $output = $this->captureStreamedOutput($streamed);

        self::assertInstanceOf(StreamedResponse::class, $streamed);
        self::assertSame(250, $probe->cursorChunkSize);
        self::assertSame('application/json; charset=UTF-8', $streamed->headers()['Content-Type']);
        self::assertSame('db', $streamed->headers()['X-Source']);
        self::assertSame('[{"id":1,"name":"alpha"},{"id":2,"name":"beta"}]', $output);
    }

    public function testStreamJsonResponseAvoidsJsonReturnTypeChunkBug(): void
    {
        $probe = new JsonStreamingResponseProbe([
            ['id' => 7, 'name' => 'gamma'],
        ]);

        $streamed = $probe->toJson()->streamJsonResponse(10);

        self::assertSame('[{"id":7,"name":"gamma"}]', $this->captureStreamedOutput($streamed));
    }

    private function captureStreamedOutput(StreamedResponse $streamed): string
    {
        $reflection = new ReflectionClass($streamed);
        $property = $reflection->getProperty('callback');
        $property->setAccessible(true);
        $callback = $property->getValue($streamed);

        ob_start();
        ob_start();
        $callback();
        ob_end_clean();
        return (string) ob_get_clean();
    }
}
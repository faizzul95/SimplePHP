<?php

declare(strict_types=1);

namespace Tests\Unit\Diagnostics;

use Core\Diagnostics\MemoryProfiler;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class MemoryProfilerTest extends TestCase
{
    private string $backup = '';
    private bool $hadOriginal = false;

    protected function setUp(): void
    {
        parent::setUp();

        $path = MemoryProfiler::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->hadOriginal = is_file($path);
        if ($this->hadOriginal) {
            $this->backup = (string) file_get_contents($path);
        }

        MemoryProfiler::clear();
    }

    protected function tearDown(): void
    {
        $path = MemoryProfiler::path();
        if ($this->hadOriginal) {
            file_put_contents($path, $this->backup);
        } elseif (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testProfilerWritesRequestEntry(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard'], []);
        $handle = MemoryProfiler::begin($request);
        MemoryProfiler::end($handle, $request);

        $rows = MemoryProfiler::readAll();

        self::assertCount(1, $rows);
        self::assertSame('/dashboard', $rows[0]['path']);
        self::assertSame('GET', $rows[0]['method']);
    }
}
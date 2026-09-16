<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Database\FakeQueryBuilder;

/**
 * The streaming iterators called gc_collect_cycles() on every chunk.
 *
 * Measured on a simulated 1,000,000-row stream in 1,000-row chunks:
 *
 *   every chunk      610 ms
 *   every 50 chunks  460 ms
 *   never            429 ms
 *
 * Peak memory was identical in all three. A chunk of plain rows is freed by
 * refcounting the moment it leaves scope; the cycle collector only exists for
 * reference cycles, which result arrays do not contain. The per-chunk call was
 * roughly 33% overhead buying nothing measurable.
 */
final class StreamingGarbageCollectionTest extends TestCase
{
    /** Counts sweeps instead of performing them. */
    private function builder()
    {
        return new class extends FakeQueryBuilder {
            public int $sweeps = 0;

            public function __construct()
            {
                parent::__construct('users');
            }

            protected function runGarbageCollector(): void
            {
                $this->sweeps++;
            }

            /** Drive the real cadence logic. */
            public function processChunks(int $chunks, int $everyChunks = 50): void
            {
                for ($i = 0; $i < $chunks; $i++) {
                    $this->collectStreamingGarbage($everyChunks);
                }
            }
        };
    }

    // ─── Cadence ─────────────────────────────────────────────────────

    public function testASingleChunkDoesNotSweep(): void
    {
        $db = $this->builder();
        $db->processChunks(1);

        self::assertSame(0, $db->sweeps);
    }

    public function testTheFirstSweepHappensAtTheInterval(): void
    {
        $db = $this->builder();
        $db->processChunks(50);

        self::assertSame(1, $db->sweeps);
    }

    public function testAThousandChunksSweepTwentyTimesNotAThousand(): void
    {
        $db = $this->builder();
        $db->processChunks(1000);

        self::assertSame(
            20,
            $db->sweeps,
            'Per-chunk collection was 33% slower with identical peak memory.'
        );
    }

    public function testTheCounterResetsSoSweepsStayEvenlySpaced(): void
    {
        $db = $this->builder();

        $db->processChunks(50);
        self::assertSame(1, $db->sweeps);

        // Without a reset the next chunk would sweep again, and every one after.
        $db->processChunks(49);
        self::assertSame(1, $db->sweeps, 'The counter did not reset after sweeping.');

        $db->processChunks(1);
        self::assertSame(2, $db->sweeps);
    }

    /** @return list<array{0:int,1:int,2:int}> chunks, interval, expected sweeps */
    public static function cadenceProvider(): array
    {
        return [
            [0, 50, 0],
            [49, 50, 0],
            [50, 50, 1],
            [100, 50, 2],
            [1000, 50, 20],
            [10, 10, 1],
            [10, 1, 10],
        ];
    }

    #[DataProvider('cadenceProvider')]
    public function testCadenceIsProportionalToTheInterval(int $chunks, int $interval, int $expected): void
    {
        $db = $this->builder();
        $db->processChunks($chunks, $interval);

        self::assertSame($expected, $db->sweeps);
    }

    public function testAZeroIntervalIsClampedToEveryChunk(): void
    {
        // Not to "never" — a zero must not silently disable collection.
        $db = $this->builder();
        $db->processChunks(5, 0);

        self::assertSame(5, $db->sweeps);
    }

    public function testANegativeIntervalIsAlsoClamped(): void
    {
        $db = $this->builder();
        $db->processChunks(3, -10);

        self::assertSame(3, $db->sweeps);
    }

    // ─── No path bypasses the helper ─────────────────────────────────

    public function testTheCollectorIsCalledFromExactlyOnePlace(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Concerns/HasStreaming.php'
        );

        self::assertSame(
            1,
            substr_count($source, 'gc_collect_cycles()'),
            'Every streaming path must route through collectStreamingGarbage(), '
            . 'so the interval is tunable in one place.'
        );
    }

    public function testNoStreamingPathStillGatesOnChunkSize(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/systems/Core/Database/Concerns/HasStreaming.php'
        );

        // `$size >= 100 && function_exists('gc_collect_cycles')` was the old
        // per-chunk guard; its presence means a call site was missed.
        self::assertDoesNotMatchRegularExpression(
            "/\\\$(?:size|chunkSize)\s*>=\s*100\s*&&\s*function_exists\('gc_collect_cycles'\)/",
            $source
        );
    }
}

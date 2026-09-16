<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Core\Http\ResponseEmitted;
use PHPUnit\Framework\TestCase;

/**
 * ResponseEmitted is a Throwable, so a `catch (\Throwable)` around controller logic
 * swallows the response. In UploadController that turned a successful upload into a
 * 500 *and* deleted the stored file, because the catch treated the success response
 * as a failure and ran its cleanup.
 *
 * This scans for the pattern rather than relying on anyone remembering it.
 */
final class ResponseEmittedNotSwallowedTest extends TestCase
{
    /** @return list<string> absolute paths of files that emit responses */
    private function emittingFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (['app/http/controllers', 'app/http/middleware', 'systems/Middleware'] as $dir) {
            $path = $root . '/' . $dir;

            if (!is_dir($path)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'jsonResponse(')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    /**
     * A broad catch is only dangerous when a jsonResponse() call sits inside the same
     * try. Brace depth is enough to tell them apart without parsing PHP properly.
     *
     * @return list<int> line numbers of unguarded broad catches
     */
    private function unguardedCatches(string $source): array
    {
        $lines = preg_split('/\r?\n/', $source) ?: [];
        $depth = 0;
        $tryDepth = [];
        $emitsAtDepth = [];
        $unguarded = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/\btry\s*\{/', $line)) {
                $tryDepth[] = $depth;
                $emitsAtDepth[$depth] = false;
            }

            if (str_contains($line, 'jsonResponse(') && $tryDepth !== []) {
                $emitsAtDepth[end($tryDepth)] = true;
            }

            if (preg_match('/\}\s*catch\s*\(\s*\\\\?(Throwable|Exception)\b/', $line) && $tryDepth !== []) {
                $openedAt = end($tryDepth);

                if (($emitsAtDepth[$openedAt] ?? false) && !$this->hasEmittedGuardBefore($lines, $index)) {
                    $unguarded[] = $index + 1;
                }
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            if ($tryDepth !== [] && $depth < end($tryDepth)) {
                array_pop($tryDepth);
            }
        }

        return $unguarded;
    }

    /**
     * A guard only counts if it re-throws. Catching ResponseEmitted and then doing
     * something else still swallows the response.
     *
     * @param list<string> $lines
     */
    private function hasEmittedGuardBefore(array $lines, int $catchIndex): bool
    {
        $window = implode("\n", array_slice($lines, max(0, $catchIndex - 6), min(6, $catchIndex)));

        return str_contains($window, 'ResponseEmitted') && preg_match('/\bthrow\s+\$/', $window) === 1;
    }

    public function testNoBroadCatchSwallowsAnEmittedResponse(): void
    {
        $offenders = [];

        foreach ($this->emittingFiles() as $file) {
            $lines = $this->unguardedCatches((string) file_get_contents($file));

            foreach ($lines as $line) {
                $offenders[] = basename($file) . ':' . $line;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A catch(\\Throwable) wraps a jsonResponse() call without re-throwing ResponseEmitted "
            . 'first, so successful responses are treated as failures. Add '
            . '"catch (\\Core\\Http\\ResponseEmitted $e) { throw $e; }" above it. Offenders: '
            . implode(', ', $offenders)
        );
    }

    public function testTheScannerActuallyDetectsThePattern(): void
    {
        $bad = <<<'PHP'
        <?php
        function handler() {
            try {
                jsonResponse(['code' => 200]);
            } catch (\Throwable $e) {
                jsonResponse(['code' => 500]);
            }
        }
        PHP;

        self::assertNotSame([], $this->unguardedCatches($bad), 'The scanner cannot see the bug it exists to catch.');
    }

    public function testTheScannerAcceptsAGuardedCatch(): void
    {
        $good = <<<'PHP'
        <?php
        function handler() {
            try {
                jsonResponse(['code' => 200]);
            } catch (\Core\Http\ResponseEmitted $emitted) {
                throw $emitted;
            } catch (\Throwable $e) {
                jsonResponse(['code' => 500]);
            }
        }
        PHP;

        self::assertSame([], $this->unguardedCatches($good));
    }

    public function testAnEmittedResponseIsStillAThrowable(): void
    {
        // The reason the guard is needed at all.
        self::assertInstanceOf(\Throwable::class, new ResponseEmitted(['code' => 200]));
    }
}

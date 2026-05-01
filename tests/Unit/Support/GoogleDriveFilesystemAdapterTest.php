<?php

declare(strict_types=1);

use App\Support\Filesystem\GoogleDriveFilesystemAdapter;
use App\Support\Filesystem\GoogleDriveTransportInterface;
use PHPUnit\Framework\TestCase;

final class GoogleDriveFilesystemAdapterTest extends TestCase
{
    public function testAdapterDelegatesCrudOperationsThroughConfiguredTransport(): void
    {
        $transport = new FakeGoogleDriveTransport();
        $adapter = new GoogleDriveFilesystemAdapter([
            'root_id' => 'drive-root-123',
            'base_url' => 'https://drive.example.test/shared',
            'transport' => $transport,
        ]);

        self::assertTrue($adapter->makeDirectory('exports/2026'));
        self::assertTrue($adapter->put('exports/2026/report.csv', 'alpha,beta'));
        self::assertTrue($adapter->exists('exports/2026/report.csv'));
        self::assertSame('alpha,beta', $adapter->get('exports/2026/report.csv'));
        self::assertSame('gdrive://drive-root-123/exports/2026/report.csv', $adapter->path('exports/2026/report.csv'));
        self::assertSame('https://drive.example.test/shared/exports/2026/report.csv', $adapter->url('exports/2026/report.csv'));

        self::assertTrue($adapter->copy('exports/2026/report.csv', 'exports/2026/report-copy.csv'));
        self::assertTrue($adapter->move('exports/2026/report-copy.csv', 'exports/2026/report-final.csv'));

        self::assertSame([
            'exports/2026/report-final.csv',
            'exports/2026/report.csv',
        ], $adapter->files('exports/2026'));
        self::assertSame([
            'exports/2026/report-final.csv',
            'exports/2026/report.csv',
        ], $adapter->allFiles('exports'));

        self::assertTrue($adapter->delete('exports/2026/report-final.csv'));
        self::assertTrue($adapter->deleteDirectory('exports/2026'));
        self::assertFalse($adapter->exists('exports/2026/report.csv'));
    }

    public function testAdapterCanStreamLargePayloadsWithoutMaterializingTransportState(): void
    {
        $transport = new FakeGoogleDriveTransport();
        $adapter = new GoogleDriveFilesystemAdapter([
            'root_id' => 'drive-root-123',
            'transport' => $transport,
        ]);

        $payload = str_repeat('0123456789abcdef', 524288);
        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');
        fwrite($stream, $payload);
        rewind($stream);

        try {
            self::assertTrue($adapter->writeStream('archives/large-dump.bin', $stream));
        } finally {
            fclose($stream);
        }

        $readStream = $adapter->readStream('archives/large-dump.bin');
        try {
            self::assertSame(strlen($payload), strlen((string) stream_get_contents($readStream)));
        } finally {
            fclose($readStream);
        }
    }
}

final class FakeGoogleDriveTransport implements GoogleDriveTransportInterface
{
    private array $directories = [''];
    private array $files = [];

    public function writeStream(string $path, $stream): bool
    {
        $directory = $this->directoryName($path);
        $this->makeDirectory($directory);

        $target = fopen('php://temp/maxmemory:2097152', 'r+b');
        if ($target === false) {
            return false;
        }

        stream_copy_to_stream($stream, $target);
        rewind($target);
        $this->files[$path] = $target;

        return true;
    }

    public function readStream(string $path)
    {
        if (!isset($this->files[$path])) {
            throw new RuntimeException('Missing file: ' . $path);
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');
        rewind($this->files[$path]);
        stream_copy_to_stream($this->files[$path], $stream);
        rewind($stream);

        return $stream;
    }

    public function delete(string|array $paths): bool
    {
        foreach ((array) $paths as $path) {
            unset($this->files[$path]);
        }

        return true;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]) || in_array($path, $this->directories, true);
    }

    public function copy(string $from, string $to): bool
    {
        $stream = $this->readStream($from);
        try {
            return $this->writeStream($to, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function move(string $from, string $to): bool
    {
        if (!$this->copy($from, $to)) {
            return false;
        }

        return $this->delete($from);
    }

    public function makeDirectory(string $directory): bool
    {
        $directory = trim($directory, '/');
        if ($directory === '') {
            return true;
        }

        $segments = explode('/', $directory);
        $current = '';
        foreach ($segments as $segment) {
            $current = ltrim(($current !== '' ? $current . '/' : '') . $segment, '/');
            if (!in_array($current, $this->directories, true)) {
                $this->directories[] = $current;
            }
        }

        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        $directory = trim($directory, '/');
        if ($directory === '') {
            $this->files = [];
            $this->directories = [''];

            return true;
        }

        foreach (array_keys($this->files) as $path) {
            if ($path === $directory || str_starts_with($path, $directory . '/')) {
                unset($this->files[$path]);
            }
        }

        $this->directories = array_values(array_filter($this->directories, static function (string $path) use ($directory): bool {
            return $path === '' || ($path !== $directory && !str_starts_with($path, $directory . '/'));
        }));

        return true;
    }

    public function files(string $directory = ''): array
    {
        $directory = trim($directory, '/');
        $results = [];

        foreach (array_keys($this->files) as $path) {
            if ($this->directoryName($path) === $directory) {
                $results[] = $path;
            }
        }

        sort($results);

        return $results;
    }

    public function allFiles(string $directory = ''): array
    {
        $directory = trim($directory, '/');
        $results = [];

        foreach (array_keys($this->files) as $path) {
            if ($directory === '' || $path === $directory || str_starts_with($path, $directory . '/')) {
                $results[] = $path;
            }
        }

        sort($results);

        return $results;
    }

    private function directoryName(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : trim($directory, '/');
    }
}
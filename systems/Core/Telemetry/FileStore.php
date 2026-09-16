<?php

declare(strict_types=1);

namespace Core\Telemetry;

/**
 * Append-only JSON-lines storage, one file per day.
 *
 * A file rather than a table because the bar has to work before anybody runs a
 * migration, and because the access pattern — append many, read the tail — is
 * what an append-only file is best at. One line per entry means a truncated
 * write costs one entry, not the file.
 */
final class FileStore
{
    private string $directory;

    private int $maxBytesPerDay;

    private int $retentionDays;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        // Relative by default, matching app/config/cache.php's 'storage/cache/app'.
        // The entry point runs from the project root, so it resolves there.
        $directory = (string) ($config['path'] ?? '');
        if ($directory === '') {
            $directory = 'storage/telemetry';
        }

        $this->directory = rtrim($directory, '/\\');
        $this->maxBytesPerDay = max(0, (int) ($config['max_bytes_per_day'] ?? 64 * 1024 * 1024));
        $this->retentionDays = max(1, (int) ($config['retention_days'] ?? 3));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Append entries. Never throws: telemetry must not be able to fail a
     * request it is only observing.
     *
     * @param list<Entry> $entries
     */
    public function write(array $entries): bool
    {
        if ($entries === []) {
            return true;
        }

        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                return false;
            }

            $file = $this->fileForToday();

            clearstatcache(true, $file);

            if ($this->maxBytesPerDay > 0 && is_file($file) && filesize($file) >= $this->maxBytesPerDay) {
                // Full for today. Dropping is correct — growing without bound
                // on a busy site is how a debug tool takes the disk down.
                return false;
            }

            $buffer = '';
            foreach ($entries as $entry) {
                $line = json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($line === false) { continue; }
                $buffer .= $line . "\n";
            }

            if ($buffer === '') {
                return true;
            }

            // LOCK_EX so concurrent requests interleave whole lines, not bytes.
            return @file_put_contents($file, $buffer, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Most recent entries first.
     *
     * @param array{types?: list<string>, request_id?: string, user_id?: int, after?: float, search?: string} $filters
     * @return list<Entry>
     */
    public function read(int $limit = 200, array $filters = []): array
    {
        $limit = max(1, min($limit, 2000));
        $entries = [];

        foreach ($this->filesNewestFirst() as $file) {
            foreach ($this->readLinesReversed($file) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) { continue; }

                $entry = Entry::fromArray($row);
                if (!$this->matches($entry, $filters)) { continue; }

                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /** @return array<string, int> */
    public function counts(array $filters = []): array
    {
        $counts = array_fill_keys(Entry::TYPES, 0);

        foreach ($this->read(2000, $filters) as $entry) {
            $counts[$entry->type] = ($counts[$entry->type] ?? 0) + 1;
        }

        return $counts;
    }

    public function purge(): int
    {
        $removed = 0;

        foreach (glob($this->directory . '/telemetry-*.jsonl') ?: [] as $file) {
            if (@unlink($file)) { $removed++; }
        }

        return $removed;
    }

    /** Drop files older than the retention window. */
    public function prune(): int
    {
        $cutoff = strtotime('-' . $this->retentionDays . ' days');
        $removed = 0;

        foreach (glob($this->directory . '/telemetry-*.jsonl') ?: [] as $file) {
            if (!preg_match('/telemetry-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $m)) { continue; }

            $stamp = strtotime($m[1]);
            if ($stamp !== false && $cutoff !== false && $stamp < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function fileForToday(): string
    {
        return $this->directory . '/telemetry-' . date('Y-m-d') . '.jsonl';
    }

    /** @return list<string> */
    private function filesNewestFirst(): array
    {
        $files = glob($this->directory . '/telemetry-*.jsonl') ?: [];
        rsort($files);

        return array_values($files);
    }

    /**
     * Yield a file's lines newest-first without loading it.
     *
     * The bar asks for the last couple of hundred entries out of a file that
     * may be tens of megabytes, so reading it forwards — or at all — would be
     * the wrong shape.
     *
     * @return \Generator<int, string>
     */
    private function readLinesReversed(string $file): \Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $chunkSize = 8192;

            /*
            | PHP caches stat results per path, and write() has usually just
            | called filesize() on this same file to check the daily cap. Left
            | cached, the reader sizes the file as it was before the last
            | append and never sees the newest entries — the ones the bar
            | exists to show.
            */
            clearstatcache(true, $file);
            $position = filesize($file) ?: 0;
            $remainder = '';

            while ($position > 0) {
                $read = (int) min($chunkSize, $position);
                $position -= $read;

                if (fseek($handle, $position) !== 0) { break; }
                $chunk = (string) fread($handle, $read);

                $buffer = $chunk . $remainder;
                $lines = explode("\n", $buffer);

                // The first piece may be a partial line; hold it for the next chunk.
                $remainder = $position > 0 ? (string) array_shift($lines) : '';

                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $line = trim($lines[$i]);
                    if ($line !== '') { yield $line; }
                }
            }

            $remainder = trim($remainder);
            if ($remainder !== '') { yield $remainder; }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $filters */
    private function matches(Entry $entry, array $filters): bool
    {
        $types = $filters['types'] ?? null;
        if (is_array($types) && $types !== [] && !in_array($entry->type, $types, true)) {
            return false;
        }

        $requestId = $filters['request_id'] ?? null;
        if (is_string($requestId) && $requestId !== '' && $entry->requestId !== $requestId) {
            return false;
        }

        $userId = $filters['user_id'] ?? null;
        if (is_int($userId) && $entry->userId !== $userId) {
            return false;
        }

        $after = $filters['after'] ?? null;
        if (is_float($after) && $entry->recordedAt <= $after) {
            return false;
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $haystack = strtolower(json_encode($entry->payload) ?: '');
            if (!str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        return true;
    }
}

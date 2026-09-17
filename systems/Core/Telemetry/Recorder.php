<?php

declare(strict_types=1);

namespace Core\Telemetry;

/**
 * Collects entries for the current request and flushes them once at the end.
 *
 * Two rules govern everything here, because this runs inside every request:
 *
 *   1. It never throws. An observer that can break the thing it observes is
 *      worse than no observer.
 *   2. It is bounded. Entry count, payload size and string length are all
 *      capped, so a request issuing 50,000 queries costs a fixed amount of
 *      memory rather than the process.
 *
 * Buffering and flushing once beats writing per entry: one file append per
 * request instead of hundreds.
 */
final class Recorder
{
    /** @var list<Entry> */
    private array $entries = [];

    private Gate $gate;

    private FileStore $store;

    /** @var array<string, mixed> */
    private array $config;

    private string $requestId;

    private ?int $userId = null;

    private float $startedAt;

    private bool $flushed = false;

    private int $dropped = 0;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?Gate $gate = null, ?FileStore $store = null)
    {
        $this->config = $config;
        $this->gate = $gate ?? new Gate($config);
        $this->store = $store ?? new FileStore((array) ($config['storage'] ?? []));
        $this->startedAt = microtime(true);
        $this->requestId = $this->resolveRequestId();
    }

    public function enabled(): bool
    {
        return $this->gate->allows($this->userId);
    }

    public function gate(): Gate
    {
        return $this->gate;
    }

    public function store(): FileStore
    {
        return $this->store;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    /** Elapsed milliseconds since the recorder was constructed. */
    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }

    /**
     * Identify the actor. Called after authentication, because the gate's
     * "these user ids" mode cannot decide before it knows who this is.
     */
    public function identify(?int $userId): void
    {
        $this->userId = $userId !== null && $userId > 0 ? $userId : null;
        $this->gate->forget();
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /**
     * Record one event.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $tags
     */
    public function record(string $type, array $payload, ?float $durationMs = null, array $tags = []): void
    {
        try {
            if (!$this->enabled() || !$this->typeEnabled($type)) {
                return;
            }

            if (count($this->entries) >= $this->maxEntries()) {
                $this->dropped++;

                return;
            }

            $this->entries[] = Entry::make(
                $type,
                $this->requestId,
                $this->sanitize($payload),
                $durationMs,
                $tags,
                $this->userId
            );
        } catch (\Throwable) {
            // Swallowed on purpose: see the class docblock.
        }
    }

    // ─── Typed recorders ─────────────────────────────────────────────

    /** @param array<string, mixed> $bindings */
    public function recordQuery(string $sql, array $bindings, float $durationMs, ?string $connection = null, ?string $origin = null): void
    {
        $tags = [];
        if ($durationMs >= $this->slowQueryMs()) {
            $tags[] = 'slow';
        }

        $this->record(Entry::TYPE_QUERY, [
            'sql' => $sql,
            'bindings' => $bindings,
            'connection' => $connection ?? 'default',
            'origin' => $origin,
        ], $durationMs, $tags);
    }

    /** @param array<string, mixed> $payload */
    public function recordRequest(array $payload, float $durationMs): void
    {
        $tags = [];
        $status = (int) ($payload['status'] ?? 0);
        if ($status >= 500) { $tags[] = 'server-error'; }
        elseif ($status >= 400) { $tags[] = 'client-error'; }
        if (($payload['ajax'] ?? false) === true) { $tags[] = 'ajax'; }

        $this->record(Entry::TYPE_REQUEST, $payload, $durationMs, $tags);
    }

    public function recordException(\Throwable $e): void
    {
        $this->record(Entry::TYPE_EXCEPTION, [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->formatTrace($e),
        ], null, ['exception']);
    }

    /** @param array<string, mixed> $payload */
    public function recordMail(array $payload, ?float $durationMs = null): void
    {
        $this->record(Entry::TYPE_MAIL, $payload, $durationMs);
    }

    /** @param array<string, mixed> $payload */
    public function recordQueue(array $payload, ?float $durationMs = null): void
    {
        $this->record(Entry::TYPE_QUEUE, $payload, $durationMs);
    }

    /** @param array<string, mixed> $context */
    public function recordLog(string $level, string $message, array $context = []): void
    {
        $this->record(Entry::TYPE_LOG, [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], null, [$level]);
    }

    // ─── Developer-facing debugging ──────────────────────────────────

    /**
     * Show a value in the bar instead of printing it.
     *
     * This is the point of the whole type: `var_dump()` into a JSON endpoint
     * corrupts the response, and into an HTML page moves the layout. Recording
     * it leaves the response byte-identical.
     *
     * @param string $origin file:line of the dbg() call, not of this method.
     */
    public function recordDump(mixed $value, string $label = '', string $origin = ''): void
    {
        $this->record(Entry::TYPE_DUMP, [
            'label' => $label,
            'origin' => $origin,
            'type' => get_debug_type($value),
            'value' => $this->describe($value),
        ], null, ['dump']);
    }

    /** @var array<string, float> Open spans, keyed by name. */
    private array $timers = [];

    /** @var array<string, int> */
    private array $counters = [];

    public function startTimer(string $name): void
    {
        $this->timers[$name] = microtime(true);
    }

    /**
     * Close a span and record it. Returns the elapsed milliseconds, or null if
     * the timer was never started — a stop without a start is a bug in the
     * caller, and silently recording 0ms would hide it.
     */
    public function stopTimer(string $name, string $origin = ''): ?float
    {
        if (!isset($this->timers[$name])) {
            return null;
        }

        $elapsedMs = (microtime(true) - $this->timers[$name]) * 1000;
        unset($this->timers[$name]);

        $this->record(Entry::TYPE_TIMER, [
            'name' => $name,
            'kind' => 'span',
            'origin' => $origin,
        ], $elapsedMs, ['timer']);

        return $elapsedMs;
    }

    /**
     * Bump a named counter. Only the final value is recorded, at flush, so
     * counting inside a hot loop costs an array increment rather than an entry.
     */
    public function increment(string $name, int $by = 1): int
    {
        return $this->counters[$name] = ($this->counters[$name] ?? 0) + $by;
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Group this request's queries by shape.
     *
     * Answers "how many times did this run" directly: literals are replaced so
     * `where id = 1` and `where id = 2` collapse to one row with a count. A
     * count in the dozens against one shape is the signature of an N+1.
     *
     * @return list<array{sql: string, count: int, total_ms: float}>
     */
    public function queryShapes(): array
    {
        $shapes = [];

        foreach ($this->entries as $entry) {
            if ($entry->type !== Entry::TYPE_QUERY) {
                continue;
            }

            $key = self::fingerprint((string) ($entry->payload['sql'] ?? ''));
            if (!isset($shapes[$key])) {
                $shapes[$key] = ['sql' => $key, 'count' => 0, 'total_ms' => 0.0];
            }

            $shapes[$key]['count']++;
            $shapes[$key]['total_ms'] += (float) ($entry->durationMs ?? 0);
        }

        usort($shapes, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_values($shapes);
    }

    /**
     * Collapse a statement to its shape.
     *
     * Quoted strings, numbers and IN-lists become placeholders, so the only
     * thing left is the structure — which is what makes two executions of the
     * same query recognisably the same.
     */
    public static function fingerprint(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace("/'[^']*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"[^"]*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql) ?? $sql;

        return $sql;
    }

    /**
     * A renderable description of any value.
     *
     * Arrays stay arrays so the bar can pretty-print them; objects become a
     * label plus their public state, because dumping a full object graph is
     * how a debug tool turns into a memory problem.
     */
    private function describe(mixed $value): mixed
    {
        if (is_array($value) || is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \Throwable) {
            return [
                '_class' => $value::class,
                'message' => $value->getMessage(),
                'at' => $value->getFile() . ':' . $value->getLine(),
            ];
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                try {
                    return ['_class' => $value::class] + (array) $value->toArray();
                } catch (\Throwable) {
                    // Fall through to the property view.
                }
            }

            return ['_class' => $value::class] + get_object_vars($value);
        }

        return '[' . get_debug_type($value) . ']';
    }

    // ─── Flush ───────────────────────────────────────────────────────

    /** Write and clear. Safe to call twice; the second call does nothing. */
    public function flush(): bool
    {
        if (!$this->flushed) {
            $this->emitCounters();
            $this->emitUnclosedTimers();
        }

        if ($this->flushed || $this->entries === []) {
            $this->flushed = true;
            $this->entries = [];

            return true;
        }

        $this->flushed = true;
        $written = $this->store->write($this->entries);
        $this->entries = [];

        return $written;
    }

    public function discard(): void
    {
        $this->entries = [];
        $this->timers = [];
        $this->counters = [];
        $this->flushed = true;
    }

    /** One entry per counter at the end, rather than one per increment. */
    private function emitCounters(): void
    {
        foreach ($this->counters as $name => $total) {
            $this->record(Entry::TYPE_TIMER, [
                'name' => $name,
                'kind' => 'counter',
                'count' => $total,
            ], null, ['counter']);
        }

        $this->counters = [];
    }

    /**
     * A timer started and never stopped usually means the code path that would
     * have stopped it threw. Recording it as unclosed says so, which is more
     * useful than the span silently not existing.
     */
    private function emitUnclosedTimers(): void
    {
        foreach ($this->timers as $name => $startedAt) {
            $this->record(Entry::TYPE_TIMER, [
                'name' => $name,
                'kind' => 'unclosed',
                'note' => 'started but never stopped',
            ], (microtime(true) - $startedAt) * 1000, ['timer', 'unclosed']);
        }

        $this->timers = [];
    }

    // ─── Internals ───────────────────────────────────────────────────

    private function typeEnabled(string $type): bool
    {
        $watching = (array) ($this->config['types'] ?? []);

        return $watching === [] || in_array($type, $watching, true);
    }

    private function maxEntries(): int
    {
        return max(1, (int) ($this->config['max_entries_per_request'] ?? 300));
    }

    private function slowQueryMs(): float
    {
        return (float) ($this->config['slow_query_ms'] ?? 100);
    }

    private function maxStringLength(): int
    {
        return max(64, (int) ($this->config['max_value_length'] ?? 2000));
    }

    /**
     * Trim and redact a payload.
     *
     * Redaction is not a nicety: a recorded request body contains whatever was
     * posted to the login form. Keys are matched by substring so `password`,
     * `password_confirmation` and `current_password` are all covered by one
     * rule.
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function sanitize(array $payload, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['_truncated' => 'max depth'];
        }

        $redactKeys = $this->redactedKeys();
        $clean = [];
        $seen = 0;

        foreach ($payload as $key => $value) {
            if (++$seen > 200) {
                $clean['_truncated'] = 'more keys omitted';
                break;
            }

            if ($this->shouldRedact((string) $key, $redactKeys)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->sanitize($value, $depth + 1),
                is_string($value) => $this->trimString($value),
                is_scalar($value), $value === null => $value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                is_object($value) => '[object ' . $value::class . ']',
                default => '[' . gettype($value) . ']',
            };
        }

        return $clean;
    }

    /**
     * @param list<string> $redactKeys
     */
    private function shouldRedact(string $key, array $redactKeys): bool
    {
        /*
        | Separators are normalised before matching, because the same secret
        | arrives under several spellings: `api_key` as a form field,
        | `X-API-KEY` as a header, `apiKey` from a JSON body. Matching the
        | literal needle caught the first and missed the other two.
        */
        $key = strtolower(str_replace(['-', '.', ' '], '_', $key));
        $collapsed = str_replace('_', '', $key);

        foreach ($redactKeys as $needle) {
            if ($needle === '') {
                continue;
            }

            if (str_contains($key, $needle) || str_contains($collapsed, str_replace('_', '', $needle))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function redactedKeys(): array
    {
        $configured = (array) ($this->config['redact'] ?? []);
        $keys = [];

        foreach ($configured as $key) {
            if (is_string($key) && trim($key) !== '') {
                $keys[] = strtolower(trim($key));
            }
        }

        return $keys === [] ? self::defaultRedactedKeys() : $keys;
    }

    /** @return list<string> */
    public static function defaultRedactedKeys(): array
    {
        return [
            'password', 'passwd', 'secret', 'token', 'authorization', 'auth',
            'api_key', 'apikey', 'credit_card', 'card_number', 'cvv', 'ssn',
            'csrf', 'cookie', 'session', 'private_key', 'signature',
        ];
    }

    private function trimString(string $value): string
    {
        $max = $this->maxStringLength();

        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '… [' . (strlen($value) - $max) . ' more bytes]';
    }

    /** @return list<array<string, mixed>> */
    private function formatTrace(\Throwable $e): array
    {
        $frames = [];
        $limit = max(1, (int) ($this->config['max_trace_frames'] ?? 20));

        foreach ($e->getTrace() as $frame) {
            if (count($frames) >= $limit) { break; }

            $frames[] = [
                'file' => (string) ($frame['file'] ?? '[internal]'),
                'line' => (int) ($frame['line'] ?? 0),
                'function' => (string) ($frame['function'] ?? ''),
                'class' => (string) ($frame['class'] ?? ''),
            ];
        }

        return $frames;
    }

    private function resolveRequestId(): string
    {
        // Reuse the id LogContext already stamps on every log line, so a
        // telemetry entry and a log entry for the same request can be joined.
        if (class_exists(\Core\Support\LogContext::class)) {
            try {
                $id = \Core\Support\LogContext::get('request_id');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            } catch (\Throwable) {
                // Fall through to a generated id.
            }
        }

        return bin2hex(random_bytes(8));
    }
}

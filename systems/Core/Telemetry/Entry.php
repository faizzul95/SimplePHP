<?php

declare(strict_types=1);

namespace Core\Telemetry;

/**
 * One recorded event.
 *
 * Deliberately flat and JSON-shaped: entries are written to a store, read back
 * by a different process, and rendered by JavaScript, so anything that does not
 * survive json_encode has no business in here.
 */
final class Entry
{
    public const TYPE_REQUEST = 'request';
    public const TYPE_QUERY = 'query';
    public const TYPE_MAIL = 'mail';
    public const TYPE_QUEUE = 'queue';
    public const TYPE_EXCEPTION = 'exception';
    public const TYPE_LOG = 'log';
    public const TYPE_CACHE = 'cache';
    public const TYPE_HTTP = 'http';
    public const TYPE_EVENT = 'event';

    /** A value a developer asked to see — dbg(). */
    public const TYPE_DUMP = 'dump';

    /** A measured span — dbg_start()/dbg_stop(), or a counter. */
    public const TYPE_TIMER = 'timer';

    /** Every type the bar knows how to group. */
    public const TYPES = [
        self::TYPE_REQUEST,
        self::TYPE_QUERY,
        self::TYPE_MAIL,
        self::TYPE_QUEUE,
        self::TYPE_EXCEPTION,
        self::TYPE_LOG,
        self::TYPE_CACHE,
        self::TYPE_HTTP,
        self::TYPE_EVENT,
        self::TYPE_DUMP,
        self::TYPE_TIMER,
    ];

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $type,
        public readonly string $requestId,
        public readonly float $recordedAt,
        public readonly array $payload = [],
        public readonly ?float $durationMs = null,
        public readonly array $tags = [],
        public readonly ?int $userId = null,
        public readonly string $id = '',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $tags
     */
    public static function make(
        string $type,
        string $requestId,
        array $payload = [],
        ?float $durationMs = null,
        array $tags = [],
        ?int $userId = null
    ): self {
        return new self(
            type: in_array($type, self::TYPES, true) ? $type : self::TYPE_LOG,
            requestId: $requestId,
            recordedAt: microtime(true),
            payload: $payload,
            durationMs: $durationMs,
            tags: array_values(array_unique(array_filter($tags, 'is_string'))),
            userId: $userId,
            id: bin2hex(random_bytes(8)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'request_id' => $this->requestId,
            'recorded_at' => round($this->recordedAt, 6),
            'duration_ms' => $this->durationMs === null ? null : round($this->durationMs, 3),
            'tags' => $this->tags,
            'user_id' => $this->userId,
            'payload' => $this->payload,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            type: (string) ($row['type'] ?? self::TYPE_LOG),
            requestId: (string) ($row['request_id'] ?? ''),
            recordedAt: (float) ($row['recorded_at'] ?? 0.0),
            payload: is_array($row['payload'] ?? null) ? $row['payload'] : [],
            durationMs: isset($row['duration_ms']) && $row['duration_ms'] !== null ? (float) $row['duration_ms'] : null,
            tags: array_values(array_filter((array) ($row['tags'] ?? []), 'is_string')),
            userId: isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            id: (string) ($row['id'] ?? ''),
        );
    }
}

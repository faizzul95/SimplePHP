<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * The ambient facts every log line should carry.
 *
 * A response already hands the client `X-Request-Id: req_ab12…` and repeats it in
 * the JSON body, but nothing wrote it to the log — so a user reporting "I got a
 * 500, here's the id" gave you a value that appears nowhere you can grep. The
 * gap between "the client knows the id" and "the log knows the id" is why an
 * error report used to start with guessing which of the day's lines was yours.
 *
 * Everything here is set once per request by AttachRequestFingerprint and read by
 * Logger on every write. Worker runtimes clear it through WorkerState::flush(),
 * or request B inherits request A's identity.
 */
final class LogContext
{
    /** @var array<string, scalar> */
    private static array $context = [];

    /** Order matters: this is the order the tag renders in. */
    private const TAG_KEYS = ['request_id', 'method', 'path', 'user_id', 'trace_id'];

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            self::put((string) $key, $value);
        }
    }

    public static function put(string $key, mixed $value): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        if ($value === null || $value === '') {
            unset(self::$context[$key]);
            return;
        }

        if (is_array($value) || is_object($value)) {
            return;
        }

        self::$context[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }

    /**
     * Set a value whose resolution can fail.
     *
     * Enriching a log line is a convenience. A user lookup that throws must not
     * take down the request that just authenticated successfully — losing the id
     * from one log line is strictly better than a 500.
     */
    public static function putSafely(string $key, callable $resolver): void
    {
        try {
            self::put($key, $resolver());
        } catch (\Throwable) {
            // Deliberately swallowed; see above.
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$context[$key] ?? $default;
    }

    /** @return array<string, scalar> */
    public static function all(): array
    {
        return self::$context;
    }

    public static function reset(): void
    {
        self::$context = [];
    }

    /**
     * The compact prefix Logger stamps on each line.
     *
     * Returns '' when nothing is known — a CLI command should not be paying for
     * an empty pair of brackets on every line.
     */
    public static function tag(): string
    {
        $parts = [];

        foreach (self::TAG_KEYS as $key) {
            $value = self::$context[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $key === 'user_id' ? 'u:' . $value : (string) $value;
        }

        // Anything set beyond the known keys still belongs in the line; a caller
        // that put it there wanted to find it later.
        foreach (self::$context as $key => $value) {
            if (!in_array($key, self::TAG_KEYS, true)) {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === [] ? '' : '[' . implode(' ', $parts) . ']';
    }

    /**
     * Populate from the current request.
     *
     * Reads $_SERVER rather than the Request object so it also works from an
     * error handler that fired before — or after — the request was built.
     */
    public static function fromServer(): void
    {
        self::set([
            'request_id' => $_SERVER['MYTH_REQUEST_ID'] ?? null,
            'trace_id' => $_SERVER['MYTH_TRACE_ID'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'path' => self::pathOf($_SERVER['REQUEST_URI'] ?? null),
        ]);
    }

    /** Query strings carry tokens and search terms; the path alone identifies the route. */
    private static function pathOf(mixed $requestUri): ?string
    {
        if (!is_string($requestUri) || $requestUri === '') {
            return null;
        }

        $path = strtok($requestUri, '?');

        return $path === false ? null : $path;
    }
}

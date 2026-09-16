<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Short-circuit the current request with a finished response.
 *
 * Every middleware used to end with the same block:
 *
 *     if ($request->expectsJson()) {
 *         Response::json([...], $status);
 *     }
 *     http_response_code($status);
 *     echo $message;
 *     exit;
 *
 * Fifteen copies of it, each one terminating the process rather than the request
 * and each one skipping the post-$next() half of every middleware further out.
 * These helpers throw instead, so the stack unwinds and Core\Http\Emitter writes
 * the response once.
 *
 * Every method returns `never`, so control flow after a call is unreachable —
 * exactly as it was with exit, and static analysis understands it.
 */
final class Abort
{
    /**
     * @param  array<mixed, mixed>  $payload
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function json(array $payload, int $status = 400, array $headers = []): never
    {
        throw new ResponseEmitted(
            new JsonResponse($payload, Emitter::normalizeStatus($status), $headers)
        );
    }

    /**
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function text(string $message, int $status = 400, array $headers = []): never
    {
        throw new ResponseEmitted(new HtmlResponse(
            $message,
            Emitter::normalizeStatus($status),
            array_merge(['Content-Type' => 'text/plain; charset=UTF-8'], $headers)
        ));
    }

    /**
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function html(string $content, int $status = 400, array $headers = []): never
    {
        throw new ResponseEmitted(new HtmlResponse(
            $content,
            Emitter::normalizeStatus($status),
            array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers)
        ));
    }

    /**
     * Abort with a body-less response — used where the point is the status code
     * (a blocked IP, a 304) and sending anything back is wasted bytes.
     *
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function status(int $status, array $headers = []): never
    {
        throw new ResponseEmitted(new HtmlResponse(
            '',
            Emitter::normalizeStatus($status),
            array_merge(['Content-Length' => '0'], $headers)
        ));
    }

    /** @throws ResponseEmitted always */
    public static function response(Responsable $response): never
    {
        throw new ResponseEmitted($response);
    }

    /**
     * Render a view file into an aborting response.
     *
     * Buffers the render so a view that itself throws cannot leave a half-written
     * body on the wire behind an already-sent 200.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function view(string $view, array $data = [], int $status = 403, array $headers = []): never
    {
        $level = ob_get_level();

        try {
            ob_start();
            render($view, $data);
            $content = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            // SafeLog, not logger(): this catch exists to survive a broken error
            // page, and logger() throws when no logger service is registered.
            \Core\Support\SafeLog::exception($e, 'Error view failed to render');

            $content = (string) ($data['title'] ?? $status) . ' - '
                . (string) ($data['message'] ?? 'Error');
        }

        self::html($content, $status, $headers);
    }

    /**
     * The 403 page, matching what show_403() renders.
     *
     * @throws ResponseEmitted always
     */
    public static function forbidden(string $message = 'Not authorize to view this page ⚠️'): never
    {
        self::view(
            defined('REDIRECT_403') ? REDIRECT_403 : 'app/views/errors/general_error.php',
            [
                'image' => 'general/images/nodata/403.png',
                'title' => '403',
                'message' => $message,
            ],
            403
        );
    }

    // ─── Content-negotiated aborts ───────────────────────────────────
    // The one place the JSON-or-HTML decision is made. Twenty-one middleware
    // each had their own copy, and the payload shape drifted between them.

    /**
     * A request the client got wrong: bad content type, oversized payload,
     * untrusted host, failed CSRF, rate limit.
     *
     * JSON for API clients, plain text otherwise.
     *
     * @param  array<string, mixed>  $extra   Merged into the JSON body
     * @param  array<string, string> $headers Sent either way
     * @throws ResponseEmitted always
     */
    public static function problem(
        Request $request,
        int $status,
        string $message,
        array $extra = [],
        array $headers = []
    ): never {
        if ($request->expectsJson()) {
            self::json(array_merge(['code' => $status, 'message' => $message], $extra), $status, $headers);
        }

        self::text($message, $status, $headers);
    }

    /**
     * Authenticated but not allowed: missing permission, role, ability, or a menu
     * entry the current state does not expose.
     *
     * JSON for API clients, the 403 page for browsers.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function denied(
        Request $request,
        string $message = 'Forbidden',
        array $extra = [],
        array $headers = []
    ): never {
        if ($request->expectsJson()) {
            self::json(array_merge(['code' => 403, 'message' => $message], $extra), 403, $headers);
        }

        self::forbidden($message);
    }

    /**
     * Not authenticated at all.
     *
     * JSON for API clients, a redirect to the login page for browsers. Pass
     * $forceJson for guards such as Basic and Digest, where a redirect would
     * swallow the WWW-Authenticate challenge the client is waiting for.
     *
     * @param  array<string, string> $headers
     * @throws ResponseEmitted always
     */
    public static function unauthenticated(
        Request $request,
        string $message = 'Unauthorized',
        bool $forceJson = false,
        array $headers = [],
        ?string $redirectTo = null
    ): never {
        if ($forceJson || $request->expectsJson()) {
            self::json(['code' => 401, 'message' => $message], 401, $headers);
        }

        $target = $redirectTo ?? (defined('REDIRECT_LOGIN') ? url(REDIRECT_LOGIN) : '/login');

        throw new ResponseEmitted(new RedirectResponse($target, 302, $headers));
    }
}

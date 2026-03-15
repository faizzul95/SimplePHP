<?php

namespace Core\Http;

class Response
{
    public static function cache(int $seconds, bool $public = true, bool $immutable = false): void
    {
        $seconds = max(0, $seconds);

        $directives = [
            $public ? 'public' : 'private',
            'max-age=' . $seconds,
            's-maxage=' . $seconds,
        ];

        if ($immutable && $seconds > 0) {
            $directives[] = 'immutable';
        }

        header('Cache-Control: ' . implode(', ', $directives));
        header('Pragma: cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function etag(string $etag): void
    {
        $safe = trim(str_replace(['"', "\r", "\n"], '', $etag));
        if ($safe === '') {
            return;
        }

        header('ETag: "' . $safe . '"');
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        // Prevent header injection by stripping newlines
        $safeUrl = str_replace(["\r", "\n", "\0"], '', $url);
        header('Location: ' . $safeUrl, true, $status);
        exit;
    }
}

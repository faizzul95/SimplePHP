<?php

namespace Core\Http;

class Response
{
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

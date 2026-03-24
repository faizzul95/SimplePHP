<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class VerifyCsrfToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $csrf = csrf();
        $currentToken = $csrf->getToken() ?: $csrf->init();
        $this->sendTokenHeader($currentToken);

        if ($csrf->validate($request->path())) {
            return $next($request);
        }

        $freshToken = $csrf->regenerate();
        $this->sendTokenHeader($freshToken);

        if ($request->expectsJson()) {
            Response::json([
                'code' => 419,
                'message' => 'CSRF token mismatch.',
                'csrf_token' => $freshToken,
            ], 419);
        }

        http_response_code(419);
        echo 'CSRF token mismatch.';
        exit;
    }

    private function sendTokenHeader(string $token): void
    {
        if ($token === '' || headers_sent()) {
            return;
        }

        header('X-CSRF-TOKEN: ' . $token);
    }
}
<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class VerifyCsrfToken implements MiddlewareInterface
{
    private bool $forceValidation = false;

    public function setParameters(array $parameters): void
    {
        $mode = strtolower(trim((string) ($parameters[0] ?? '')));
        $this->forceValidation = ($mode === 'force');
    }

    public function handle(Request $request, callable $next)
    {
        $csrf = csrf();
        $currentToken = $csrf->getToken() ?: $csrf->init();
        $this->sendTokenHeader($currentToken);

        if ($csrf->validate($request->path(), $this->forceValidation)) {
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